<?php

namespace Pikant\LaravelEasyHttpFake;

use Illuminate\Support\Facades\File;

class ReplayStorage
{
    protected string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? $this->defaultBasePath();
    }

    public function getTestDirectory(): string
    {
        $testSuite = \Pest\TestSuite::getInstance();
        $filename = $testSuite->getFilename();
        $description = $testSuite->getDescription();

        // Get relative path from project root tests/ directory
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
     * @return array<string, array{status: int, headers: array<string, mixed>, body: mixed, recorded_at?: string, request?: array<string, mixed>}>
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

        return $responses;
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

        $recordedAt = \Carbon\Carbon::parse($data['recorded_at']);

        return $recordedAt->addDays($days)->isPast();
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    protected function defaultBasePath(): string
    {
        $configured = config('easy-http-fake.storage_path', 'tests/.http-replays');

        if (str_starts_with($configured, '/')) {
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
