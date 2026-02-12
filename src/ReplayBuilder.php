<?php

namespace Pikant\LaravelEasyHttpFake;

use Closure;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ReplayBuilder
{
    /** @var list<string> */
    protected array $matchByFields = ['url', 'method'];

    /** @var list<string>|null */
    protected ?array $onlyPatterns = null;

    /** @var array<string, \GuzzleHttp\Promise\PromiseInterface|Closure|int|string> */
    protected array $additionalFakes = [];

    protected ?string $fromName = null;

    protected ?string $storeAsName = null;

    protected bool $isFresh = false;

    protected ?string $freshPattern = null;

    protected ?int $expireDays = null;

    protected bool $initialized = false;

    protected string $loadDirectory = '';

    protected string $saveDirectory = '';

    /** @var array<string, list<array{status: int, headers: array<string, mixed>, body: mixed}>> */
    protected array $responseQueues = [];

    /** @var list<string> */
    protected array $usedFilenames = [];

    /** @var array<string, int> */
    protected array $pendingRecordings = [];

    protected ReplayStorage $storage;

    protected ReplayNamer $namer;

    protected ResponseSerializer $serializer;

    public function __construct(
        ?ReplayStorage $storage = null,
        ?ResponseSerializer $serializer = null,
    ) {
        $this->storage = $storage ?? new ReplayStorage;
        $this->serializer = $serializer ?? new ResponseSerializer;
        $this->namer = new ReplayNamer($this->matchByFields);

        $this->registerFakeCallback();
        $this->registerResponseListener();
    }

    /**
     * @param  string  ...$fields  Fields to match by: 'url', 'method', 'body'
     */
    public function matchBy(string ...$fields): self
    {
        $this->matchByFields = array_values($fields);

        return $this;
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
    public function fake(array $stubs): self
    {
        $this->additionalFakes = $stubs;

        return $this;
    }

    public function from(string $name): self
    {
        $this->fromName = $name;

        return $this;
    }

    public function storeAs(string $name): self
    {
        $this->storeAsName = $name;

        return $this;
    }

    public function fresh(?string $pattern = null): self
    {
        $this->isFresh = true;
        $this->freshPattern = $pattern;

        return $this;
    }

    public function expireAfter(int $days): self
    {
        $this->expireDays = $days;

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
        $this->namer = new ReplayNamer($this->matchByFields);

        $this->resolveDirectories();
        $this->handleFreshAndExpiry();
        $this->loadStoredResponses();
    }

    protected function resolveDirectories(): void
    {
        if ($this->fromName !== null) {
            $this->loadDirectory = $this->storage->getSharedDirectory($this->fromName);
            // When using from() with fresh(), save back to shared (renewal)
            // Otherwise save to test-specific directory
            $this->saveDirectory = $this->isFresh
                ? $this->storage->getSharedDirectory($this->fromName)
                : $this->storage->getTestDirectory();
        } elseif ($this->storeAsName !== null) {
            $this->loadDirectory = $this->storage->getSharedDirectory($this->storeAsName);
            $this->saveDirectory = $this->storage->getSharedDirectory($this->storeAsName);
        } else {
            $this->loadDirectory = $this->storage->getTestDirectory();
            $this->saveDirectory = $this->storage->getTestDirectory();
        }
    }

    protected function handleFreshAndExpiry(): void
    {
        $isFresh = $this->isFresh || config('easy-http-fake.fresh', false);

        if ($isFresh) {
            if ($this->freshPattern !== null) {
                $this->storage->deleteByPattern($this->loadDirectory, $this->freshPattern);
            } else {
                $this->storage->deleteDirectory($this->loadDirectory);
            }
        }
    }

    protected function loadStoredResponses(): void
    {
        $stored = $this->storage->findStoredResponses($this->loadDirectory);

        foreach ($stored as $filename => $data) {
            // Skip expired responses
            if ($this->expireDays !== null) {
                $filepath = $this->loadDirectory.DIRECTORY_SEPARATOR.$filename;
                if ($this->storage->isExpired($filepath, $this->expireDays)) {
                    continue;
                }
            }

            // Group by base filename (strip __N counter)
            $baseFilename = $this->getBaseFilename($filename);

            if (! isset($this->responseQueues[$baseFilename])) {
                $this->responseQueues[$baseFilename] = [];
            }

            $this->responseQueues[$baseFilename][] = $data;
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
        $baseFilename = $this->getBaseFilename($this->namer->fromRequest($request));

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

        $filename = $this->namer->fromRequest($request);
        $filename = $this->namer->makeUnique($filename, $this->usedFilenames);
        $this->usedFilenames[] = $filename;

        $data = $this->serializer->serialize($request, $response);
        $this->storage->store($data, $this->saveDirectory, $filename);
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

    protected function recordingKey(Request $request): string
    {
        $key = $request->method().':'.$request->url();

        if (in_array('body', $this->matchByFields)) {
            $key .= ':'.md5(json_encode($request->body()) ?: '');
        }

        return $key;
    }

    /**
     * Strip __N counter suffix from filename.
     */
    protected function getBaseFilename(string $filename): string
    {
        return (string) preg_replace('/__\d+\.json$/', '.json', $filename);
    }
}
