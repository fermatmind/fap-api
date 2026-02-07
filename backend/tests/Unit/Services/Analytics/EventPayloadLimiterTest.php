<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\EventPayloadLimiter;
use Tests\TestCase;

final class EventPayloadLimiterTest extends TestCase
{
    public function test_it_truncates_long_strings(): void
    {
        config()->set('fap.events.max_top_keys', 200);
        config()->set('fap.events.max_depth', 4);
        config()->set('fap.events.max_list_length', 50);
        config()->set('fap.events.max_string_length', 5);

        $limiter = new EventPayloadLimiter();
        $limited = $limiter->limit([
            'title' => 'abcdefgh',
            'nested' => [
                'note' => '123456',
            ],
        ]);

        $this->assertSame('abcde', $limited['title']);
        $this->assertSame('12345', $limited['nested']['note']);
    }

    public function test_it_limits_keys_and_list_length(): void
    {
        config()->set('fap.events.max_top_keys', 2);
        config()->set('fap.events.max_depth', 4);
        config()->set('fap.events.max_list_length', 2);
        config()->set('fap.events.max_string_length', 2048);

        $limiter = new EventPayloadLimiter();
        $limited = $limiter->limit([
            'a' => 1,
            'b' => [
                'items' => [1, 2, 3, 4],
            ],
            'c' => 3,
        ]);

        $this->assertSame(['a', 'b'], array_keys($limited));
        $this->assertSame([1, 2], $limited['b']['items']);
    }

    public function test_it_replaces_over_depth_array_with_empty_array(): void
    {
        config()->set('fap.events.max_top_keys', 200);
        config()->set('fap.events.max_depth', 2);
        config()->set('fap.events.max_list_length', 50);
        config()->set('fap.events.max_string_length', 2048);

        $limiter = new EventPayloadLimiter();
        $limited = $limiter->limit([
            'level1' => [
                'level2' => [
                    'level3' => [
                        'value' => 'too deep',
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $limited['level1']['level2']);
    }
}
