<?php

namespace Pikant\LaravelEasyHttpFake;

class LaravelEasyHttpFake
{
    public function getStoragePath(): string
    {
        $configured = config('easy-http-fake.storage_path', 'tests/.http-replays');

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
        return config('easy-http-fake.match_by', ['url', 'method']);
    }

    public function getDefaultExpireAfter(): ?int
    {
        return config('easy-http-fake.expire_after');
    }
}
