<?php

namespace Pikant\LaravelHttpReplay\Matchers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;

class BodyHashMatcher implements NameMatcher
{
    /** @var list<string> */
    protected array $keys;

    /**
     * @param  list<string>  $keys  Specific body keys to hash. Empty = entire body.
     */
    public function __construct(array $keys = [])
    {
        $this->keys = $keys;
    }

    public function resolve(Request $request): ?string
    {
        $body = $request->body();

        if ($this->keys !== []) {
            $data = json_decode($body, true);

            if (! is_array($data)) {
                return substr(md5($body), 0, 6);
            }

            $subset = [];
            foreach ($this->keys as $key) {
                $subset[$key] = Arr::get($data, $key);
            }

            return substr(md5(json_encode($subset) ?: ''), 0, 6);
        }

        return substr(md5($body), 0, 6);
    }
}
