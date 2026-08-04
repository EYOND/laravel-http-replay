<?php

namespace EYOND\LaravelHttpReplay\Matchers;

use EYOND\LaravelHttpReplay\CanonicalJson;
use Illuminate\Http\Client\Request;
use JsonException;
use stdClass;

final class CanonicalBodyHashMatcher implements NameMatcher
{
    /** @var list<string> */
    protected array $paths;

    /**
     * @param  list<string>  $paths  Dot-notation paths to hash. Empty hashes the complete JSON body.
     */
    public function __construct(array $paths = [])
    {
        $this->paths = $paths;
    }

    public function resolve(Request $request): string
    {
        $body = $request->body();
        $canonicalJson = new CanonicalJson;

        try {
            $data = $canonicalJson->decode($body);

            if ($this->paths !== []) {
                $data = $this->subset($data);
            }

            return substr(md5($canonicalJson->encode($data)), 0, 6);
        } catch (JsonException) {
            return substr(md5($body), 0, 6);
        }
    }

    protected function subset(mixed $data): stdClass
    {
        $subset = new stdClass;

        foreach ($this->paths as $path) {
            $subset->{$path} = $this->valueAtPath($data, $path);
        }

        return $subset;
    }

    protected function valueAtPath(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (is_object($value) && property_exists($value, $segment)) {
                $value = $value->{$segment};

                continue;
            }

            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];

                continue;
            }

            return null;
        }

        return $value;
    }
}
