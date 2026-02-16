<?php

namespace EYOND\LaravelHttpReplay;

use Closure;

class ForPatternProxy
{
    public function __construct(
        protected ReplayBuilder $builder,
        protected string $pattern,
    ) {}

    /**
     * @param  string|Closure  ...$fields  Matchers for this URL pattern
     */
    public function matchBy(string|Closure ...$fields): ReplayBuilder
    {
        $this->builder->addPerPatternMatchBy($this->pattern, array_values($fields));

        return $this->builder;
    }
}
