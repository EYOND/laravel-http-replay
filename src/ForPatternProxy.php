<?php

namespace EYOND\LaravelHttpReplay;

use Closure;
use EYOND\LaravelHttpReplay\Matchers\NameMatcher;

class ForPatternProxy
{
    public function __construct(
        protected ReplayBuilder|ReplayConfig $parent,
        protected string $pattern,
    ) {}

    /**
     * @param  string|Closure|NameMatcher  ...$fields  Matchers for this URL pattern
     */
    public function matchBy(string|Closure|NameMatcher ...$fields): ReplayBuilder|ReplayConfig
    {
        $this->parent->addPerPatternMatchBy($this->pattern, array_values($fields));

        return $this->parent;
    }
}
