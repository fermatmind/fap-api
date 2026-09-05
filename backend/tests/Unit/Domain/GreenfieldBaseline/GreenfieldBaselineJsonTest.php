<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GreenfieldBaseline;

use App\Domain\GreenfieldBaseline\GreenfieldBaselineJson;
use PHPUnit\Framework\TestCase;

final class GreenfieldBaselineJsonTest extends TestCase
{
    public function test_normalization_ignores_only_nested_object_key_order(): void
    {
        $expected = ['body' => ['min' => 1, 'max' => 2], 'items' => ['a', 'b']];
        $readback = ['items' => ['a', 'b'], 'body' => ['max' => 2, 'min' => 1]];
        self::assertSame(GreenfieldBaselineJson::normalize($expected), GreenfieldBaselineJson::normalize($readback));

        foreach ([
            ['body' => ['min' => '1', 'max' => 2], 'items' => ['a', 'b']],
            ['body' => ['min' => 1.0, 'max' => 2], 'items' => ['a', 'b']],
            ['body' => ['min' => 1, 'max' => 2], 'items' => ['b', 'a']],
            ['body' => ['min' => 1, 'max' => 2, 'missing' => null], 'items' => ['a', 'b']],
        ] as $different) {
            self::assertNotSame(GreenfieldBaselineJson::normalize($expected), GreenfieldBaselineJson::normalize($different));
        }
    }
}
