<?php

use EYOND\LaravelHttpReplay\Exceptions\ReplayBailException;
use EYOND\LaravelHttpReplay\Exceptions\ReusableResponseMismatchException;
use EYOND\LaravelHttpReplay\ReplayBuilder;
use EYOND\LaravelHttpReplay\ReplayStorage;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/http-replays-append-'.uniqid();
    $this->storage = new ReplayStorage($this->tempDir);
    $this->run = 0;

    $this->storeReplay = function (
        string $directory,
        string $filename,
        string $source,
        ?string $recordedAt = null,
    ): void {
        $this->storage->store([
            'status' => 200,
            'headers' => ['Content-Type' => ['application/json']],
            'body' => ['source' => $source],
            'recorded_at' => $recordedAt ?? now()->toIso8601String(),
            'request' => [
                'method' => 'GET',
                'url' => 'https://api.example.com/operation',
                'attributes' => ['replay' => pathinfo($filename, PATHINFO_FILENAME)],
            ],
        ], $directory, $filename);
    };

    // Starts a new Replay activation the way a new test run does. Requests that
    // Replay cannot serve are answered by a stand-in for the upstream service,
    // whose response names the run and the request number it answered.
    $this->activateReplay = function (?Closure $configure = null): void {
        $this->run++;
        $run = $this->run;

        Http::swap(new Factory(app('events')));

        $builder = new class($this->storage) extends ReplayBuilder
        {
            protected function registerSnapshotChange(string $filename): void {}
        };

        if ($configure !== null) {
            $configure($builder);
        }

        $request = 0;

        Http::fake(function () use ($run, &$request) {
            $request++;

            return Http::response(['source' => "run{$run}-request{$request}"], 200);
        });
    };

    $this->send = fn (string $replayName = 'operation', string $url = 'https://api.example.com/operation'): string => Http::withAttributes(['replay' => $replayName])
        ->get($url)
        ->json('source');

    /** @return array<string, string> Response source per filename, in filename order */
    $this->sources = function (string $directory): array {
        if (! File::isDirectory($directory)) {
            return [];
        }

        $sources = [];

        foreach (File::files($directory) as $file) {
            $sources[$file->getFilename()] = json_decode(File::get($file->getPathname()), true)['body']['source'];
        }

        ksort($sources);

        return $sources;
    };

    /** @return array<string, string> File content per filename, in filename order */
    $this->contents = function (string $directory): array {
        if (! File::isDirectory($directory)) {
            return [];
        }

        $contents = [];

        foreach (File::files($directory) as $file) {
            $contents[$file->getFilename()] = File::get($file->getPathname());
        }

        ksort($contents);

        return $contents;
    };
});

afterEach(function () {
    if (File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('appends after an exhausted sequence instead of overwriting the base file', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'stored-1');
    ($this->storeReplay)($directory, 'operation__2.json', 'stored-2');
    $before = ($this->contents)($directory);

    ($this->activateReplay)();

    $served = [($this->send)(), ($this->send)(), ($this->send)()];
    $after = ($this->contents)($directory);

    expect($served)->toBe(['stored-1', 'stored-2', 'run1-request3'])
        ->and(array_keys($after))->toBe(['operation.json', 'operation__2.json', 'operation__3.json'])
        ->and($after['operation.json'])->toBe($before['operation.json'])
        ->and($after['operation__2.json'])->toBe($before['operation__2.json'])
        ->and(($this->sources)($directory)['operation__3.json'])->toBe('run1-request3');
});

it('appends every response recorded after an exhausted sequence', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'stored-1');
    ($this->storeReplay)($directory, 'operation__2.json', 'stored-2');

    ($this->activateReplay)();

    ($this->send)();
    ($this->send)();
    ($this->send)();
    ($this->send)();

    expect(($this->sources)($directory))->toBe([
        'operation.json' => 'stored-1',
        'operation__2.json' => 'stored-2',
        'operation__3.json' => 'run1-request3',
        'operation__4.json' => 'run1-request4',
    ]);
});

it('appends to a single stored response', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'stored-1');

    ($this->activateReplay)();

    ($this->send)();
    ($this->send)();
    ($this->send)();

    expect(($this->sources)($directory))->toBe([
        'operation.json' => 'stored-1',
        'operation__2.json' => 'run1-request2',
        'operation__3.json' => 'run1-request3',
    ]);
});

it('replays an appended response on later runs without recording again', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'stored-1');
    ($this->storeReplay)($directory, 'operation__2.json', 'stored-2');

    ($this->activateReplay)();
    ($this->send)();
    ($this->send)();
    ($this->send)();
    $afterFirstRun = ($this->contents)($directory);

    foreach ([2, 3] as $run) {
        ($this->activateReplay)();

        expect([($this->send)(), ($this->send)(), ($this->send)()])
            ->toBe(['stored-1', 'stored-2', 'run1-request3'])
            ->and(($this->contents)($directory))->toBe($afterFirstRun);
    }
});

it('fails on an exhausted sequence in bail mode without writing a file', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'stored-1');
    ($this->storeReplay)($directory, 'operation__2.json', 'stored-2');
    $before = ($this->contents)($directory);

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->bail());

    ($this->send)();
    ($this->send)();

    expect(fn () => ($this->send)())->toThrow(ReplayBailException::class)
        ->and(($this->contents)($directory))->toBe($before);
});

it('records a fresh sequence from the base filename', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'stored-1');
    ($this->storeReplay)($directory, 'operation__2.json', 'stored-2');

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->fresh());

    ($this->send)();

    expect(($this->sources)($directory))->toBe(['operation.json' => 'run1-request1']);
});

it('appends inside a shared directory used for reading and writing', function () {
    $shared = $this->storage->getSharedDirectory('shopify');
    ($this->storeReplay)($shared, 'operation.json', 'shared-1');
    ($this->storeReplay)($shared, 'operation__2.json', 'shared-2');

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->useShared('shopify'));

    ($this->send)();
    ($this->send)();
    ($this->send)();

    expect(($this->sources)($shared))->toBe([
        'operation.json' => 'shared-1',
        'operation__2.json' => 'shared-2',
        'operation__3.json' => 'run1-request3',
    ])->and(File::isDirectory($this->storage->getTestDirectory()))->toBeFalse();
});

it('never overwrites or extends a shared fallback with a test-local recording', function () {
    $local = $this->storage->getTestDirectory();
    $shared = $this->storage->getSharedDirectory('defaults');
    ($this->storeReplay)($shared, 'operation.json', 'shared-1');
    ($this->storeReplay)($shared, 'operation__2.json', 'shared-2');
    $sharedBefore = ($this->contents)($shared);

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->fallbackTo('defaults'));

    $served = [($this->send)(), ($this->send)(), ($this->send)()];

    // The suffix is counted in the save directory only. The exhausted shared
    // queue does not push the test-local recording to __3.
    expect($served)->toBe(['shared-1', 'shared-2', 'run1-request3'])
        ->and(($this->contents)($shared))->toBe($sharedBefore)
        ->and(($this->sources)($local))->toBe(['operation.json' => 'run1-request3']);
});

it('appends to a test-local sequence that shadows a longer shared fallback', function () {
    $local = $this->storage->getTestDirectory();
    $shared = $this->storage->getSharedDirectory('defaults');
    ($this->storeReplay)($local, 'operation.json', 'local-1');
    ($this->storeReplay)($local, 'operation__2.json', 'local-2');
    ($this->storeReplay)($shared, 'operation.json', 'shared-1');
    ($this->storeReplay)($shared, 'operation__2.json', 'shared-2');
    ($this->storeReplay)($shared, 'operation__3.json', 'shared-3');
    $sharedBefore = ($this->contents)($shared);

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->fallbackTo('defaults'));

    $served = [($this->send)(), ($this->send)(), ($this->send)()];

    expect($served)->toBe(['local-1', 'local-2', 'run1-request3'])
        ->and(($this->contents)($shared))->toBe($sharedBefore)
        ->and(($this->sources)($local))->toBe([
            'operation.json' => 'local-1',
            'operation__2.json' => 'local-2',
            'operation__3.json' => 'run1-request3',
        ]);
});

it('settles on one test-local sequence after a shared fallback was exhausted', function () {
    $local = $this->storage->getTestDirectory();
    $shared = $this->storage->getSharedDirectory('defaults');
    ($this->storeReplay)($shared, 'operation.json', 'shared-1');
    ($this->storeReplay)($shared, 'operation__2.json', 'shared-2');
    $sharedBefore = ($this->contents)($shared);

    foreach ([1, 2] as $run) {
        ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->fallbackTo('defaults'));
        ($this->send)();
        ($this->send)();
        ($this->send)();
    }

    $settled = ($this->contents)($local);

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->fallbackTo('defaults')->bail());
    ($this->send)();
    ($this->send)();
    ($this->send)();

    expect(array_keys($settled))->toBe(['operation.json', 'operation__2.json', 'operation__3.json'])
        ->and(($this->contents)($local))->toBe($settled)
        ->and(($this->contents)($shared))->toBe($sharedBefore);
});

it('keeps one test-local file per request when the save directory is not read', function () {
    $local = $this->storage->getTestDirectory();
    $shared = $this->storage->getSharedDirectory('defaults');
    ($this->storeReplay)($shared, 'operation.json', 'shared-1');
    ($this->storeReplay)($shared, 'operation__2.json', 'shared-2');
    $sharedBefore = ($this->contents)($shared);

    // readFrom() never reads the test-local directory, so every run records the
    // third response again. It must reuse its filename instead of piling up.
    foreach ([1, 2, 3] as $run) {
        ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->readFrom('defaults'));
        ($this->send)();
        ($this->send)();
        ($this->send)();

        expect(($this->sources)($local))->toBe(['operation.json' => "run{$run}-request3"]);
    }

    expect(($this->contents)($shared))->toBe($sharedBefore);
});

it('keeps one shared file per request when the writeTo target is not read', function () {
    $target = $this->storage->getSharedDirectory('target');

    foreach ([1, 2, 3] as $run) {
        ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->writeTo('target'));
        ($this->send)();
        ($this->send)();

        expect(($this->sources)($target))->toBe([
            'operation.json' => "run{$run}-request1",
            'operation__2.json' => "run{$run}-request2",
        ]);
    }
});

it('records over an expired response without touching the current base file', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'stored-1');
    ($this->storeReplay)($directory, 'operation__2.json', 'expired-2', now()->subDays(30)->toIso8601String());

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->expireAfter(days: 7));

    $served = [($this->send)(), ($this->send)()];

    expect($served)->toBe(['stored-1', 'run1-request2'])
        ->and(($this->sources)($directory))->toBe([
            'operation.json' => 'stored-1',
            'operation__2.json' => 'run1-request2',
        ]);
});

it('records over an expired base file without touching the current numbered file', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'expired-1', now()->subDays(30)->toIso8601String());
    ($this->storeReplay)($directory, 'operation__2.json', 'stored-2');

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->expireAfter(days: 7));

    $served = [($this->send)(), ($this->send)()];

    expect($served)->toBe(['stored-2', 'run1-request2'])
        ->and(($this->sources)($directory))->toBe([
            'operation.json' => 'run1-request2',
            'operation__2.json' => 'stored-2',
        ]);
});

it('never records for a reusable key that has a stored response', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'stored');
    $before = ($this->contents)($directory);

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->reusable());

    $served = [($this->send)(), ($this->send)(), ($this->send)()];

    expect($served)->toBe(['stored', 'stored', 'stored'])
        ->and(($this->contents)($directory))->toBe($before);
});

it('serves a reusable shared fallback without creating test-local files', function () {
    $local = $this->storage->getTestDirectory();
    $shared = $this->storage->getSharedDirectory('akeneo');
    ($this->storeReplay)($shared, 'operation.json', 'shared');
    $sharedBefore = ($this->contents)($shared);

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->reusable()->fallbackTo('akeneo'));

    $served = [($this->send)(), ($this->send)(), ($this->send)()];

    expect($served)->toBe(['shared', 'shared', 'shared'])
        ->and(($this->contents)($shared))->toBe($sharedBefore)
        ->and(File::isDirectory($local))->toBeFalse();
});

it('appends for sequential keys while reusable keys in the same directory stay untouched', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'product.json', 'product');
    ($this->storeReplay)($directory, 'order.json', 'order-1');

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay
        ->reusable()
        ->for('api.example.com/orders/*')->sequential());

    $products = [
        ($this->send)('product', 'https://api.example.com/products/1'),
        ($this->send)('product', 'https://api.example.com/products/1'),
    ];
    $orders = [
        ($this->send)('order', 'https://api.example.com/orders/1'),
        ($this->send)('order', 'https://api.example.com/orders/1'),
    ];

    expect($products)->toBe(['product', 'product'])
        ->and($orders)->toBe(['order-1', 'run1-request4'])
        ->and(($this->sources)($directory))->toBe([
            'order.json' => 'order-1',
            'order__2.json' => 'run1-request4',
            'product.json' => 'product',
        ]);
});

it('rejects differing stored responses for a reusable key without writing a file', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'operation.json', 'pending');
    ($this->storeReplay)($directory, 'operation__2.json', 'fulfilled');
    $before = ($this->contents)($directory);

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->reusable());

    expect(fn () => ($this->send)())->toThrow(ReusableResponseMismatchException::class, 'Use sequential()')
        ->and(($this->contents)($directory))->toBe($before);
});

it('removes only its own recording when a reusable key records differing responses', function () {
    $directory = $this->storage->getTestDirectory();
    ($this->storeReplay)($directory, 'other.json', 'other-1');
    ($this->storeReplay)($directory, 'other__2.json', 'other-2');
    $before = ($this->contents)($directory);

    ($this->activateReplay)(fn (ReplayBuilder $replay) => $replay->reusable());

    ($this->send)();

    expect(fn () => ($this->send)())->toThrow(ReusableResponseMismatchException::class, 'Use sequential()')
        ->and(($this->contents)($directory))->toBe($before);
});
