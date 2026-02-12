<?php

declare(strict_types=1);

namespace App\Services\SelfCheck;

use App\Services\SelfCheck\Content\SelfCheckContentEngine;

/**
 * Thin orchestration facade for content self-check IO.
 *
 * Existing check classes still call the same method names on SelfCheckIo;
 * unknown calls are forwarded to the extracted content engine.
 */
class SelfCheckIo
{
    private SelfCheckContentEngine $content;

    public function __construct(?SelfCheckContentEngine $content = null)
    {
        $this->content = $content ?? new SelfCheckContentEngine();
    }

    public function applyContext(SelfCheckContext $ctx): void
    {
        $this->content->applyContext($ctx);
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->content->{$name}(...$arguments);
    }
}
