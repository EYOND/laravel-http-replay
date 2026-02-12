<?php

namespace Pikant\LaravelEasyHttpFake;

use Illuminate\Http\Client\Request;

class ReplayNamer
{
    /**
     * @param  list<string>  $matchBy
     */
    public function __construct(
        protected array $matchBy = ['url', 'method'],
    ) {}

    public function fromRequest(Request $request): string
    {
        $replayAttribute = $request->attributes()['replay'] ?? null;

        if ($replayAttribute !== null) {
            return $this->fromAttribute($replayAttribute);
        }

        if (in_array('body', $this->matchBy)) {
            return $this->fromBodyHash($request);
        }

        return $this->defaultName($request);
    }

    public function fromAttribute(string $name): string
    {
        return $this->sanitize($name).'.json';
    }

    public function fromBodyHash(Request $request): string
    {
        $method = strtoupper($request->method());
        $parsed = parse_url($request->url());
        $host = $parsed['host'] ?? 'unknown';
        $path = trim($parsed['path'] ?? '', '/');

        $bodyHash = substr(md5(json_encode($request->body()) ?: ''), 0, 6);

        $name = $method.'_'.$this->sanitize($host.'_'.$path).'__'.$bodyHash;

        return $name.'.json';
    }

    public function defaultName(Request $request): string
    {
        $method = strtoupper($request->method());
        $parsed = parse_url($request->url());
        $host = $parsed['host'] ?? 'unknown';
        $path = trim($parsed['path'] ?? '', '/');

        $name = $method.'_'.$this->sanitize($host.($path ? '_'.$path : ''));

        return $name.'.json';
    }

    /**
     * Make a filename unique by appending a counter if the name already exists.
     *
     * @param  list<string>  $existingNames
     */
    public function makeUnique(string $filename, array $existingNames): string
    {
        if (! in_array($filename, $existingNames)) {
            return $filename;
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        $counter = 2;
        while (in_array($base.'__'.$counter.'.'.$ext, $existingNames)) {
            $counter++;
        }

        return $base.'__'.$counter.'.'.$ext;
    }

    protected function sanitize(string $value): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', str_replace('/', '_', $value));
    }
}
