<?php

namespace EYOND\LaravelHttpReplay;

use Closure;

class ForPatternProxy
{
    public function __construct(
        protected ReplayBuilder|ReplayConfig $parent,
        protected string $pattern,
    ) {}

    /**
     * @param  string|Closure  ...$fields  Matchers for this URL pattern
     */
    public function matchBy(string|Closure ...$fields): ReplayBuilder|ReplayConfig
    {
        $this->parent->addPerPatternMatchBy($this->pattern, array_values($fields));

        return $this->parent;
    }
}
