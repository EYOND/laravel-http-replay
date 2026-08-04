<?php

namespace EYOND\LaravelHttpReplay;

use JsonException;
use stdClass;

final class CanonicalJson
{
    public const ENCODE_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    public function decode(string $json): mixed
    {
        $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $lossless = json_decode($json, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

        if ($this->containsLossyInteger($decoded, $lossless)) {
            throw new JsonException('JSON contains an integer outside the PHP integer range.');
        }

        return $decoded;
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

    protected function containsLossyInteger(mixed $decoded, mixed $lossless): bool
    {
        if (is_float($decoded) && is_string($lossless)) {
            return true;
        }

        if (is_array($decoded) && is_array($lossless)) {
            foreach ($decoded as $key => $value) {
                if ($this->containsLossyInteger($value, $lossless[$key])) {
                    return true;
                }
            }
        }

        if (is_object($decoded) && is_object($lossless)) {
            return $this->containsLossyInteger(get_object_vars($decoded), get_object_vars($lossless));
        }

        return false;
    }
}
