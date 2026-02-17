<?php

namespace EYOND\LaravelHttpReplay;

use Closure;
use DateInterval;
use DateTimeImmutable;
use EYOND\LaravelHttpReplay\Exceptions\ReplayBailException;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ReplayBuilder
{
    /** @var list<string|Closure> */
    protected array $matchByFields = ['method', 'url'];

    /** @var list<string>|null */
    protected ?array $onlyPatterns = null;

    /** @var array<string, \GuzzleHttp\Promise\PromiseInterface|Closure|int|string> */
    protected array $additionalFakes = [];

    /** @var list<string> */
    protected array $readFromNames = [];

    protected ?string $writeToName = null;

    protected bool $isFresh = false;

    protected ?string $freshPattern = null;

    protected ?int $expireDays = null;

    protected bool $initialized = false;

    /** @var list<string> */
    protected array $loadDirectories = [];

    protected string $saveDirectory = '';

    /** @var array<string, list<array{status: int, headers: array<string, mixed>, body: mixed}>> */
    protected array $responseQueues = [];

    /** @var list<string> */
    protected array $usedFilenames = [];

    /** @var array<string, int> */
    protected array $pendingRecordings = [];

    /** @var array<string, list<string|Closure>> */
    protected array $perPatternMatchBy = [];

    protected ReplayStorage $storage;

    protected ReplayNamer $namer;

    protected ResponseSerializer $serializer;

    public function __construct(
        ?ReplayStorage $storage = null,
        ?ResponseSerializer $serializer = null,
    ) {
        $this->storage = $storage ?? new ReplayStorage;
        $this->serializer = $serializer ?? new ResponseSerializer;
        $this->namer = new ReplayNamer;

        $this->applyDefaults();
        $this->applyConfig();

        $this->registerFakeCallback();
        $this->registerResponseListener();
    }

    protected function applyDefaults(): void
    {
        $defaults = app(LaravelHttpReplay::class);

        $this->matchByFields = $defaults->getDefaultMatchBy();
        $this->expireDays = $defaults->getDefaultExpireAfter();
    }

    protected function applyConfig(): void
    {
        $config = app(LaravelHttpReplay::class)->getConfig();

        if (! $config) {
            return;
        }

        if ($config->getMatchByFields() !== null) {
            $this->matchByFields = $config->getMatchByFields();
        }

        $this->perPatternMatchBy = array_merge(
            $config->getPerPatternMatchBy(),
            $this->perPatternMatchBy,
        );
    }

    /**
     * @param  string|Closure  ...$fields  Matchers for filename generation
     */
    public function matchBy(string|Closure ...$fields): self
    {
        $this->matchByFields = array_values($fields);

        return $this;
    }

    /**
     * Set a URL pattern for per-URL matcher configuration.
     */
    public function for(string $pattern): ForPatternProxy
    {
        return new ForPatternProxy($this, $pattern);
    }

    /**
     * @param  list<string|Closure>  $fields
     */
    public function addPerPatternMatchBy(string $pattern, array $fields): void
    {
        $this->perPatternMatchBy[$pattern] = $fields;
    }

    /**
     * @param  list<string>  $patterns  URL patterns to replay (e.g. ['shopify.com/*'])
     */
    public function only(array $patterns): self
    {
        $this->onlyPatterns = $patterns;

        return $this;
    }

    /**
     * @param  array<string, \GuzzleHttp\Promise\PromiseInterface|Closure|int|string>  $stubs
     */
    public function alsoFake(array $stubs): self
    {
        $this->additionalFakes = $stubs;

        return $this;
    }

    public function readFrom(string ...$names): self
    {
        $this->readFromNames = array_values($names);

        return $this;
    }

    public function writeTo(string $name): self
    {
        $this->writeToName = $name;

        return $this;
    }

    public function useShared(string $name): self
    {
        $this->readFromNames = [$name];
        $this->writeToName = $name;

        return $this;
    }

    public function fresh(?string $pattern = null): self
    {
        $this->isFresh = true;
        $this->freshPattern = $pattern;

        return $this;
    }

    public function expireAfter(int|DateInterval $days): self
    {
        if ($days instanceof DateInterval) {
            $now = new DateTimeImmutable;
            $this->expireDays = (int) ceil(($now->add($days)->getTimestamp() - $now->getTimestamp()) / 86400);
        } else {
            $this->expireDays = $days;
        }

        return $this;
    }

    protected function registerFakeCallback(): void
    {
        Http::fake(fn (Request $request, array $options) => $this->handleRequest($request));
    }

    protected function registerResponseListener(): void
    {
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
            $this->handleResponseReceived($event->request, $event->response);
        });
    }

    protected function initialize(): void
    {
        $this->initialized = true;

        $this->resolveDirectories();
        $this->handleFreshAndExpiry();
        $this->loadStoredResponses();
    }

    protected function resolveDirectories(): void
    {
        if ($this->readFromNames !== []) {
            $this->loadDirectories = array_map(
                fn (string $name) => $this->storage->getSharedDirectory($name),
                $this->readFromNames,
            );
        } else {
            $this->loadDirectories = [$this->storage->getTestDirectory()];
        }

        if ($this->writeToName !== null) {
            $this->saveDirectory = $this->storage->getSharedDirectory($this->writeToName);
        } else {
            $this->saveDirectory = $this->storage->getTestDirectory();
        }
    }

    protected function handleFreshAndExpiry(): void
    {
        if (! $this->shouldFresh()) {
            return;
        }

        foreach ($this->loadDirectories as $dir) {
            if ($this->freshPattern !== null) {
                $this->storage->deleteByPattern($dir, $this->freshPattern);
            } else {
                $this->storage->deleteDirectory($dir);
            }
        }
    }

    /**
     * Check if fresh mode is active (via fluent API, config, or environment).
     */
    protected function shouldFresh(): bool
    {
        return $this->isFresh
            || config('http-replay.fresh', false)
            || filter_var($_SERVER['REPLAY_FRESH'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function loadStoredResponses(): void
    {
        /** @var array<string, string> $seenFromDir */
        $seenFromDir = [];

        foreach ($this->loadDirectories as $dir) {
            $stored = $this->storage->findStoredResponses($dir);

            foreach ($stored as $filename => $data) {
                if ($this->expireDays !== null) {
                    $filepath = $dir.DIRECTORY_SEPARATOR.$filename;
                    if ($this->storage->isExpired($filepath, $this->expireDays)) {
                        continue;
                    }
                }

                $baseFilename = $this->getBaseFilename($filename);

                // First wins: skip if a previous directory already provided this base filename
                if (isset($seenFromDir[$baseFilename]) && $seenFromDir[$baseFilename] !== $dir) {
                    continue;
                }
                $seenFromDir[$baseFilename] = $dir;

                if (! isset($this->responseQueues[$baseFilename])) {
                    $this->responseQueues[$baseFilename] = [];
                }

                $this->responseQueues[$baseFilename][] = $data;
            }
        }
    }

    /**
     * @return \GuzzleHttp\Promise\PromiseInterface|null
     */
    protected function handleRequest(Request $request)
    {
        if (! $this->initialized) {
            $this->initialize();
        }

        // Check static fakes first (for non-replay URLs)
        if (! $this->shouldReplay($request)) {
            return $this->matchStaticFake($request);
        }

        // Try to serve from stored responses
        $matchBy = $this->resolveMatchBy($request);
        $baseFilename = $this->getBaseFilename($this->namer->fromRequest($request, $matchBy));

        if (isset($this->responseQueues[$baseFilename]) && count($this->responseQueues[$baseFilename]) > 0) {
            $data = array_shift($this->responseQueues[$baseFilename]);

            return $this->serializer->deserialize($data);
        }

        // No stored response — mark for recording, allow real call
        $key = $this->recordingKey($request);
        $this->pendingRecordings[$key] = ($this->pendingRecordings[$key] ?? 0) + 1;

        return null;
    }

    protected function handleResponseReceived(Request $request, Response $response): void
    {
        if (! $this->initialized) {
            return;
        }

        if (! $this->shouldReplay($request)) {
            return;
        }

        $key = $this->recordingKey($request);

        if (($this->pendingRecordings[$key] ?? 0) <= 0) {
            return;
        }

        $this->pendingRecordings[$key]--;

        // Bail mode — fail if attempting to write
        if ($this->shouldBail()) {
            $matchBy = $this->resolveMatchBy($request);
            $filename = $this->namer->fromRequest($request, $matchBy);

            throw new ReplayBailException(
                "Http Replay attempted to write [{$this->saveDirectory}/{$filename}] but bail mode is active. "
                .'Run tests locally to record new fakes.'
            );
        }

        $matchBy = $this->resolveMatchBy($request);
        $filename = $this->namer->fromRequest($request, $matchBy);
        $filename = $this->namer->makeUnique($filename, $this->usedFilenames);
        $this->usedFilenames[] = $filename;

        $data = $this->serializer->serialize($request, $response);
        $this->storage->store($data, $this->saveDirectory, $filename);

        // Mark test as incomplete when recording new responses
        if (class_exists(\Pest\TestSuite::class)) {
            \Pest\TestSuite::getInstance()->registerSnapshotChange(
                "Http replay recorded at [{$this->saveDirectory}/{$filename}]"
            );
        }
    }

    protected function shouldReplay(Request $request): bool
    {
        if ($this->onlyPatterns === null) {
            return true;
        }

        foreach ($this->onlyPatterns as $pattern) {
            if (Str::is(Str::start($pattern, '*'), $request->url())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \GuzzleHttp\Promise\PromiseInterface|null
     */
    protected function matchStaticFake(Request $request)
    {
        foreach ($this->additionalFakes as $pattern => $response) {
            if (Str::is(Str::start($pattern, '*'), $request->url())) {
                if ($response instanceof Closure) {
                    return $response($request);
                }

                return $response;
            }
        }

        return null;
    }

    /**
     * Resolve which matchBy fields to use for a given request.
     *
     * @return list<string|Closure>
     */
    protected function resolveMatchBy(Request $request): array
    {
        foreach ($this->perPatternMatchBy as $pattern => $matchBy) {
            if (Str::is(Str::start($pattern, '*'), $request->url())) {
                return $matchBy;
            }
        }

        return $this->matchByFields;
    }

    protected function recordingKey(Request $request): string
    {
        $matchBy = $this->resolveMatchBy($request);
        $key = $request->method().':'.$request->url();

        // Include body hash in key if any body-related matcher is active
        foreach ($matchBy as $field) {
            if ($field instanceof Closure) {
                continue;
            }
            if (in_array($field, ['body', 'body_hash']) || str_starts_with($field, 'body_hash:')) {
                $key .= ':'.md5(json_encode($request->body()) ?: '');
                break;
            }
        }

        return $key;
    }

    /**
     * Check if bail mode is active (via config or --replay-bail flag).
     */
    protected function shouldBail(): bool
    {
        return config('http-replay.bail', false)
            || filter_var($_SERVER['REPLAY_BAIL'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Strip __N counter suffix from filename.
     */
    protected function getBaseFilename(string $filename): string
    {
        return (string) preg_replace('/__\d+\.json$/', '.json', $filename);
    }
}
