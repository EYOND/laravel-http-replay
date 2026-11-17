<?php

namespace EYOND\LaravelHttpReplay;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Pest\TestSuite;

class ReplayStorage
{
    protected string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? $this->defaultBasePath();
    }

    public function getTestDirectory(): string
    {
        $testSuite = TestSuite::getInstance();
        $filename = $testSuite->getFilename();
        $description = $testSuite->getDescription();

        $testsDir = base_path('tests');
        $relative = str_replace($testsDir.DIRECTORY_SEPARATOR, '', $filename);
        $relative = str_replace('.php', '', $relative);

        // Sanitize description for directory name
        $description = (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $description);

        return $this->basePath.DIRECTORY_SEPARATOR.$relative.DIRECTORY_SEPARATOR.$description;
    }

    public function getSharedDirectory(string $name): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.'_shared'.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Returns the stored responses of a directory keyed by filename. The responses
     * of one sequence come in recording order: base file, `__2`, `__3`, ..., `__10`.
     *
     * @return array<string, array{status: int, headers?: array<string, mixed>, body: mixed, body_encoding?: string, recorded_at?: string, request?: array<string, mixed>}>
     */
    public function findStoredResponses(string $directory): array
    {
        if (! File::isDirectory($directory)) {
            return [];
        }

        $responses = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $content = File::get($file->getPathname());
            $data = json_decode($content, true);

            if (is_array($data)) {
                $responses[$file->getFilename()] = $data;
            }
        }

        // File::files() sorts by name as plain strings, which puts `__10` before `__2`.
        uksort($responses, function (string $a, string $b): int {
            [$baseA, $numberA] = $this->splitSequenceFilename($a);
            [$baseB, $numberB] = $this->splitSequenceFilename($b);

            return strcmp($baseA, $baseB) ?: $numberA <=> $numberB;
        });

        return $responses;
    }

    /**
     * Splits a filename into the base filename of its sequence and its position in
     * that sequence. The base file is position 1, `name__2.json` is position 2.
     *
     * @return array{string, int}
     */
    protected function splitSequenceFilename(string $filename): array
    {
        if (preg_match('/^(.*)__(\d+)\.json$/', $filename, $matches) === 1) {
            return [$matches[1].'.json', (int) $matches[2]];
        }

        return [$filename, 1];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data, string $directory, string $filename): void
    {
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    public function deleteResponse(string $directory, string $filename): void
    {
        File::delete($directory.DIRECTORY_SEPARATOR.$filename);
    }

    public function deleteDirectory(string $directory): void
    {
        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Delete files matching a URL pattern within a directory.
     */
    public function deleteByPattern(string $directory, string $pattern): void
    {
        if (! File::isDirectory($directory)) {
            return;
        }

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $content = File::get($file->getPathname());
            $data = json_decode($content, true);

            if (! is_array($data) || ! isset($data['request']['url'])) {
                continue;
            }

            if ($this->urlMatchesPattern($data['request']['url'], $pattern)) {
                File::delete($file->getPathname());
            }
        }
    }

    public function isExpired(string $filepath, int $days): bool
    {
        $content = File::get($filepath);
        $data = json_decode($content, true);

        if (! is_array($data) || ! isset($data['recorded_at'])) {
            return true;
        }

        $recordedAt = Carbon::parse($data['recorded_at']);

        return $recordedAt->addDays($days)->isPast();
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    protected function defaultBasePath(): string
    {
        $configured = config('http-replay.storage_path', 'tests/.laravel-http-replay');

        if (str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $configured)) {
            return $configured;
        }

        return base_path($configured);
    }

    protected function urlMatchesPattern(string $url, string $pattern): bool
    {
        // Convert wildcard pattern to regex using # as delimiter to avoid escaping /
        $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return (bool) preg_match('#'.$regex.'#', $url);
    }
}
