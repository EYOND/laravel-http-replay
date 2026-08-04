<?php

namespace EYOND\LaravelHttpReplay;

use stdClass;

final class CanonicalJson
{
    public const ENCODE_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    public function decode(string $json): mixed
    {
        return json_decode($json, flags: JSON_THROW_ON_ERROR);
    }

    public function encode(mixed $value): string
    {
        return json_encode($this->normalize($value), self::ENCODE_FLAGS);
    }

    public function canonicalize(string $json): string
    {
        return $this->encode($this->decode($json));
    }

    protected function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
            }

            ksort($value, SORT_STRING);

            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        if (is_object($value)) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            $normalized = new stdClass;
            foreach ($properties as $key => $property) {
                $normalized->{$key} = $this->normalize($property);
            }

            return $normalized;
        }

        return $value;
    }
}
