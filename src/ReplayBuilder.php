<?php

namespace EYOND\LaravelHttpReplay;

use Closure;
use DateInterval;
use DateTimeImmutable;
use EYOND\LaravelHttpReplay\Exceptions\ReplayBailException;
use EYOND\LaravelHttpReplay\Exceptions\ReusableResponseMismatchException;
use EYOND\LaravelHttpReplay\Matchers\CanonicalBodyHashMatcher;
use EYOND\LaravelHttpReplay\Matchers\NameMatcher;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pest\TestSuite;

class ReplayBuilder
{
    /** @var list<string|Closure|NameMatcher> */
    protected array $matchByFields = ['method', 'url'];

    /** @var list<string>|null */
    protected ?array $onlyPatterns = null;

    /** @var array<string, PromiseInterface|Closure|int|string> */
    protected array $additionalFakes = [];

    /** @var list<string> */
    protected array $readFromNames = [];

    /** @var list<string> */
    protected array $fallbackToNames = [];

    protected ?string $writeToName = null;

    protected bool $isFresh = false;

    protected bool $isBail = false;

    protected ?string $freshPattern = null;

    protected ?int $expireDays = null;

    protected ReplayMode $mode = ReplayMode::Sequential;

    /** @var bool|list<string> */
    protected bool|array $responseHeaders = true;

    protected bool $initialized = false;

    /** @var list<string> */
    protected array $loadDirectories = [];

    protected string $saveDirectory = '';

    /** @var array<string, list<array{status: int, headers?: array<string, mixed>, body: mixed, body_encoding?: string}>> */
    protected array $responseQueues = [];

    /** @var list<string> */
    protected array $usedFilenames = [];

    /** @var array<string, int> */
    protected array $pendingRecordings = [];

    /** @var array<string, list<string|Closure|NameMatcher>> */
    protected array $perPatternMatchBy = [];

    /** @var array<string, ReplayMode> */
    protected array $perPatternModes = [];

    /** @var array<string, array{status: int, headers?: array<string, mixed>, body: mixed, body_encoding?: string}> */
    protected array $recordedReusableResponses = [];

    /** @var array<string, true> */
    protected array $mismatchedReusableResponses = [];

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
        $this->responseHeaders = $defaults->getDefaultResponseHeaders();
        $this->mode = $defaults->getDefaultMode();
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

        if ($config->getResponseHeaders() !== null) {
            $this->responseHeaders = $config->getResponseHeaders();
        }

        if ($config->getMode() !== null) {
            $this->mode = $config->getMode();
        }

        $this->perPatternMatchBy = array_merge(
            $config->getPerPatternMatchBy(),
            $this->perPatternMatchBy,
        );

        $this->perPatternModes = array_merge(
            $config->getPerPatternModes(),
            $this->perPatternModes,
        );
    }

    /**
     * @param  string|Closure|NameMatcher  ...$fields  Matchers for filename generation
     */
    public function matchBy(string|Closure|NameMatcher ...$fields): self
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
     * @param  list<string|Closure|NameMatcher>  $fields
     */
    public function addPerPatternMatchBy(string $pattern, array $fields): void
    {
        $this->perPatternMatchBy[$pattern] = $fields;
    }

    public function reusable(): self
    {
        $this->mode = ReplayMode::Reusable;

        return $this;
    }

    public function sequential(): self
    {
        $this->mode = ReplayMode::Sequential;

        return $this;
    }

    public function addPerPatternMode(string $pattern, ReplayMode $mode): void
    {
        $this->perPatternModes[$pattern] = $mode;
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
     * @param  array<string, PromiseInterface|Closure|int|string>  $stubs
     */
    public function alsoFake(array $stubs): self
    {
        $this->additionalFakes = $stubs;

        return $this;
    }

    public function readFrom(string ...$names): self
    {
        $this->readFromNames = array_values($names);
        $this->fallbackToNames = [];

        return $this;
    }

    public function fallbackTo(string ...$names): self
    {
        $this->fallbackToNames = array_values($names);
        $this->readFromNames = [];

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
        $this->fallbackToNames = [];
        $this->writeToName = $name;

        return $this;
    }

    public function fresh(?string $pattern = null): self
    {
        $this->isFresh = true;
        $this->freshPattern = $pattern;

        return $this;
    }

    public function bail(): self
    {
        $this->isBail = true;

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

    public function withResponseHeaders(string ...$headers): self
    {
        $this->responseHeaders = $headers === [] ? true : $headers;

        return $this;
    }

    public function withoutResponseHeaders(): self
    {
        $this->responseHeaders = false;

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
        if ($this->fallbackToNames !== []) {
            $this->loadDirectories = [
                $this->storage->getTestDirectory(),
                ...array_map(
                    fn (string $name) => $this->storage->getSharedDirectory($name),
                    $this->fallbackToNames,
                ),
            ];
        } elseif ($this->readFromNames !== []) {
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

        $freshDirectories = $this->fallbackToNames === []
            ? $this->loadDirectories
            : [$this->loadDirectories[0]];

        foreach ($freshDirectories as $dir) {
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

                // A recording must not overwrite a response that was loaded from the
                // save directory, so its filename counts as used when the next free
                // suffix is picked. Files that were not loaded stay replaceable:
                // expired responses, and responses in a save directory that Replay
                // does not read.
                if ($dir === $this->saveDirectory) {
                    $this->usedFilenames[] = $filename;
                }
            }
        }
    }

    /**
     * @return PromiseInterface|null
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

        if ($this->resolveMode($request) === ReplayMode::Reusable) {
            $data = $this->resolveReusableResponse($baseFilename);

            if ($data !== null) {
                return $this->serializer->deserialize($data);
            }
        } elseif (isset($this->responseQueues[$baseFilename]) && count($this->responseQueues[$baseFilename]) > 0) {
            $data = array_shift($this->responseQueues[$baseFilename]);

            return $this->serializer->deserialize($data);
        }

        // No stored response — bail if active, otherwise mark for recording
        if ($this->shouldBail()) {
            throw new ReplayBailException(
                "Http Replay has no stored response for [{$this->saveDirectory}/{$baseFilename}] and bail mode is active. "
                .'Run tests locally to record new fakes.'
            );
        }

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

        $matchBy = $this->resolveMatchBy($request);
        $filename = $this->namer->fromRequest($request, $matchBy);
        $data = $this->serializer->serialize($request, $response, $this->responseHeaders);

        if ($this->resolveMode($request) === ReplayMode::Reusable) {
            $this->storeReusableResponse($filename, $data);

            return;
        }

        $filename = $this->namer->makeUnique($filename, $this->usedFilenames);
        $this->usedFilenames[] = $filename;

        $this->storage->store($data, $this->saveDirectory, $filename);
        $this->registerSnapshotChange($filename);
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
     * @return PromiseInterface|null
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
     * @return list<string|Closure|NameMatcher>
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

    protected function resolveMode(Request $request): ReplayMode
    {
        foreach ($this->perPatternModes as $pattern => $mode) {
            if (Str::is(Str::start($pattern, '*'), $request->url())) {
                return $mode;
            }
        }

        return $this->mode;
    }

    /**
     * @return array{status: int, headers?: array<string, mixed>, body: mixed, body_encoding?: string}|null
     */
    protected function resolveReusableResponse(string $baseFilename): ?array
    {
        $responses = $this->responseQueues[$baseFilename] ?? [];

        if ($responses === []) {
            return null;
        }

        $response = $responses[0];

        foreach ($responses as $candidate) {
            if (! $this->responsesAreEquivalent($response, $candidate)) {
                throw ReusableResponseMismatchException::forReplayKey($baseFilename);
            }
        }

        return $response;
    }

    /**
     * @param  array{status: int, headers?: array<string, mixed>, body: mixed, body_encoding?: string}  $data
     */
    protected function storeReusableResponse(string $filename, array $data): void
    {
        $baseFilename = $this->getBaseFilename($filename);

        if (isset($this->mismatchedReusableResponses[$baseFilename])) {
            return;
        }

        $recorded = $this->recordedReusableResponses[$baseFilename] ?? null;

        if ($recorded !== null) {
            if (! $this->responsesAreEquivalent($recorded, $data)) {
                $this->mismatchedReusableResponses[$baseFilename] = true;
                $this->storage->deleteResponse($this->saveDirectory, $baseFilename);
                unset($this->recordedReusableResponses[$baseFilename]);

                throw ReusableResponseMismatchException::forReplayKey($baseFilename);
            }

            return;
        }

        $this->recordedReusableResponses[$baseFilename] = $data;
        $this->usedFilenames[] = $baseFilename;
        $this->storage->store($data, $this->saveDirectory, $baseFilename);
        $this->registerSnapshotChange($baseFilename);
    }

    /**
     * @param  array{status: int, headers?: array<string, mixed>, body: mixed, body_encoding?: string}  $first
     * @param  array{status: int, headers?: array<string, mixed>, body: mixed, body_encoding?: string}  $second
     */
    protected function responsesAreEquivalent(array $first, array $second): bool
    {
        return $this->comparableResponse($first) === $this->comparableResponse($second);
    }

    /**
     * @param  array{status: int, headers?: array<string, mixed>, body: mixed, body_encoding?: string}  $data
     * @return array{status: int, headers: array<string, mixed>, body: mixed, body_encoding: mixed}
     */
    protected function comparableResponse(array $data): array
    {
        return [
            'status' => $data['status'],
            'headers' => $data['headers'] ?? [],
            'body' => $data['body'],
            'body_encoding' => $data['body_encoding'] ?? null,
        ];
    }

    protected function registerSnapshotChange(string $filename): void
    {
        if (class_exists(TestSuite::class)) {
            TestSuite::getInstance()->registerSnapshotChange(
                "Http replay recorded at [{$this->saveDirectory}/{$filename}]"
            );
        }
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
            if ($field instanceof CanonicalBodyHashMatcher
                || (is_string($field) && (in_array($field, ['body', 'body_hash']) || str_starts_with($field, 'body_hash:') || str_starts_with($field, 'canonical_body_hash')))
            ) {
                $key .= ':'.md5(json_encode($request->body()) ?: '');
                break;
            }
        }

        return $key;
    }

    /**
     * Check if bail mode is active (via fluent API, config, or environment).
     */
    protected function shouldBail(): bool
    {
        return $this->isBail
            || config('http-replay.bail', false)
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
