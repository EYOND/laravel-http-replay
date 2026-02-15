<?php

namespace Pikant\LaravelHttpReplay;

use Illuminate\Support\Facades\File;

class LaravelHttpReplay
{
    public function getStoragePath(): string
    {
        $configured = config('http-replay.storage_path', 'tests/.laravel-http-replay');

        if (str_starts_with($configured, '/')) {
            return $configured;
        }

        return base_path($configured);
    }

    /**
     * @return list<string>
     */
    public function getDefaultMatchBy(): array
    {
        return config('http-replay.match_by', ['method', 'url']);
    }

    public function getDefaultExpireAfter(): ?int
    {
        return config('http-replay.expire_after');
    }

    /**
     * Load a single stored replay file for use in Http::fake().
     */
    public function getShared(string $path): \GuzzleHttp\Promise\PromiseInterface
    {
        $fullPath = $this->getStoragePath()
            .DIRECTORY_SEPARATOR.'_shared'
            .DIRECTORY_SEPARATOR.$path;

        $content = File::get($fullPath);
        $data = json_decode($content, true);

        return (new ResponseSerializer)->deserialize($data);
    }
}
