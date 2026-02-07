<?php

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\EventPayloadLimiter;
use Tests\TestCase;

class EventPayloadLimiterTest extends TestCase
{
    public function test_limit_truncates_long_strings(): void
    {
        config()->set('fap.events.max_top_keys', 200);
        config()->set('fap.events.max_depth', 4);
        config()->set('fap.events.max_list_length', 50);
        config()->set('fap.events.max_string_length', 10);

        $limiter = new EventPayloadLimiter();
        $limited = $limiter->limit([
            'long' => str_repeat('x', 64),
        ]);

        $this->assertSame(str_repeat('x', 10), $limited['long']);
    }

    public function test_limit_restricts_top_level_keys_and_list_items(): void
    {
        config()->set('fap.events.max_top_keys', 2);
        config()->set('fap.events.max_depth', 4);
        config()->set('fap.events.max_list_length', 2);
        config()->set('fap.events.max_string_length', 2048);

        $limiter = new EventPayloadLimiter();
        $limited = $limiter->limit([
            'list' => [1, 2, 3, 4],
            'keep' => 'ok',
            'drop' => 'no',
        ]);

        $this->assertSame(['list', 'keep'], array_keys($limited));
        $this->assertSame([1, 2], $limited['list']);
    }

    public function test_limit_returns_empty_array_when_depth_exceeds_limit(): void
    {
        config()->set('fap.events.max_top_keys', 200);
        config()->set('fap.events.max_depth', 2);
        config()->set('fap.events.max_list_length', 50);
        config()->set('fap.events.max_string_length', 2048);

        $limiter = new EventPayloadLimiter();
        $limited = $limiter->limit([
            'level1' => [
                'level2' => [
                    'level3' => ['value' => 'x'],
                ],
            ],
        ]);

        $this->assertSame([], $limited['level1']['level2']['level3']);
    }
}
