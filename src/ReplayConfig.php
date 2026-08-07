<?php

namespace EYOND\LaravelHttpReplay;

use Closure;
use EYOND\LaravelHttpReplay\Matchers\NameMatcher;

class ReplayConfig
{
    /** @var list<string|Closure|NameMatcher>|null */
    protected ?array $matchByFields = null;

    /** @var array<string, list<string|Closure|NameMatcher>> */
    protected array $perPatternMatchBy = [];

    /** @var bool|list<string>|null */
    protected bool|array|null $responseHeaders = null;

    /**
     * @param  string|Closure|NameMatcher  ...$fields  Matchers for filename generation
     */
    public function matchBy(string|Closure|NameMatcher ...$fields): self
    {
        $this->matchByFields = array_values($fields);

        return $this;
    }

    public function shopify(ShopifyProfile $profile = ShopifyProfile::Semantic): self
    {
        $this->addPerPatternMatchBy(ShopifyProfile::URL_PATTERN, $profile->matchers());

        return $this;
    }

    public function withResponseHeaders(string ...$headers): self
    {
        $this->responseHeaders = $headers === [] ? true : $headers;

        return $this;
    }

    public function withoutResponseHeaders(): self
    {
        $this->responseHeaders = false;

        return $this;
    }

    /**
     * Set a URL pattern for per-URL matcher configuration.
     */
    public function for(string $pattern): ForPatternProxy
    {
        return new ForPatternProxy($this, $pattern);
    }

    /**
     * @param  list<string|Closure|NameMatcher>  $fields
     */
    public function addPerPatternMatchBy(string $pattern, array $fields): void
    {
        $this->perPatternMatchBy[$pattern] = $fields;
    }

    /**
     * @return list<string|Closure|NameMatcher>|null
     */
    public function getMatchByFields(): ?array
    {
        return $this->matchByFields;
    }

    /**
     * @return array<string, list<string|Closure|NameMatcher>>
     */
    public function getPerPatternMatchBy(): array
    {
        return $this->perPatternMatchBy;
    }

    /**
     * @return bool|list<string>|null
     */
    public function getResponseHeaders(): bool|array|null
    {
        return $this->responseHeaders;
    }
}
