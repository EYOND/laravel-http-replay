<?php

namespace Pikant\LaravelHttpReplay;

use Closure;
use Illuminate\Http\Client\Request;
use Pikant\LaravelHttpReplay\Matchers\BodyHashMatcher;
use Pikant\LaravelHttpReplay\Matchers\ClosureMatcher;
use Pikant\LaravelHttpReplay\Matchers\HostMatcher;
use Pikant\LaravelHttpReplay\Matchers\HttpAttributeMatcher;
use Pikant\LaravelHttpReplay\Matchers\HttpMethodMatcher;
use Pikant\LaravelHttpReplay\Matchers\NameMatcher;
use Pikant\LaravelHttpReplay\Matchers\SubdomainMatcher;
use Pikant\LaravelHttpReplay\Matchers\UrlMatcher;

class ReplayNamer
{
    /**
     * @param  list<string|Closure>  $matchBy
     */
    public function fromRequest(Request $request, array $matchBy): string
    {
        // Check for replay attribute — takes priority over matchers
        $replayAttribute = $request->attributes()['replay'] ?? null;

        if ($replayAttribute !== null) {
            return $this->sanitize($replayAttribute).'.json';
        }

        $matchers = $this->parseMatchers($matchBy);
        $parts = [];

        foreach ($matchers as $matcher) {
            $resolved = $matcher->resolve($request);
            if ($resolved !== null && $resolved !== '') {
                $parts[] = $this->sanitize($resolved);
            }
        }

        if ($parts === []) {
            return 'unknown.json';
        }

        return implode('_', $parts).'.json';
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

    /**
     * @param  list<string|Closure>  $matchBy
     * @return list<NameMatcher>
     */
    public function parseMatchers(array $matchBy): array
    {
        $matchers = [];

        foreach ($matchBy as $field) {
            if ($field instanceof Closure) {
                $matchers[] = new ClosureMatcher($field);

                continue;
            }

            $matchers[] = match (true) {
                $field === 'method' => new HttpMethodMatcher,
                $field === 'http_method' => new HttpMethodMatcher, // alias
                $field === 'subdomain' => new SubdomainMatcher,
                $field === 'host' => new HostMatcher,
                $field === 'url' => new UrlMatcher,
                $field === 'body_hash' => new BodyHashMatcher,
                $field === 'body' => new BodyHashMatcher, // alias
                str_starts_with($field, 'attribute:') => new HttpAttributeMatcher(
                    substr($field, strlen('attribute:'))
                ),
                str_starts_with($field, 'http_attribute:') => new HttpAttributeMatcher(
                    substr($field, strlen('http_attribute:'))
                ), // alias
                str_starts_with($field, 'body_hash:') => new BodyHashMatcher(
                    explode(',', substr($field, strlen('body_hash:')))
                ),
                default => throw new \InvalidArgumentException("Unknown matcher: {$field}"),
            };
        }

        return $matchers;
    }

    protected function sanitize(string $value): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', str_replace('/', '_', $value));
    }
}
