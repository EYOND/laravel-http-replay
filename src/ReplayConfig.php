<?php

namespace EYOND\LaravelHttpReplay;

use Closure;

class ReplayConfig
{
    /** @var list<string|Closure>|null */
    protected ?array $matchByFields = null;

    /** @var array<string, list<string|Closure>> */
    protected array $perPatternMatchBy = [];

    /**
     * @param  string|Closure  ...$fields  Matchers for filename generation
     */
    public function matchBy(string|Closure ...$fields): self
    {
        $this->matchByFields = array_values($fields);

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
     * @param  list<string|Closure>  $fields
     */
    public function addPerPatternMatchBy(string $pattern, array $fields): void
    {
        $this->perPatternMatchBy[$pattern] = $fields;
    }

    /**
     * @return list<string|Closure>|null
     */
    public function getMatchByFields(): ?array
    {
        return $this->matchByFields;
    }

    /**
     * @return array<string, list<string|Closure>>
     */
    public function getPerPatternMatchBy(): array
    {
        return $this->perPatternMatchBy;
    }
}
