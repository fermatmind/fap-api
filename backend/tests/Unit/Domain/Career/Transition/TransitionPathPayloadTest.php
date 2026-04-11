<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Transition;

use App\Domain\Career\Transition\TransitionPathPayload;
use PHPUnit\Framework\TestCase;

final class TransitionPathPayloadTest extends TestCase
{
    public function test_it_accepts_missing_payload_and_returns_an_empty_normalized_shape(): void
    {
        $this->assertSame([], TransitionPathPayload::from(null)->toArray());
        $this->assertSame([], TransitionPathPayload::from('invalid')->toArray());
        $this->assertSame([], TransitionPathPayload::from([])->toArray());
    }

    public function test_it_accepts_steps_as_a_list_of_strings(): void
    {
        $payload = TransitionPathPayload::from([
            'steps' => [' deepen system design ', 'lead more cross-team decisions'],
        ]);

        $this->assertSame([
            'steps' => [
                'deepen system design',
                'lead more cross-team decisions',
            ],
        ], $payload->toArray());
    }

    public function test_it_strips_invalid_steps_and_ignores_richer_narrative_fields(): void
    {
        $payload = TransitionPathPayload::from([
            'steps' => ['valid step', 42, '', null, ' second step '],
            'why_this_path' => 'fixture-only narrative',
            'what_is_lost' => 'fixture-only tradeoff copy',
            'bridge_steps_90d' => ['not authoritative'],
        ]);

        $this->assertSame([
            'steps' => [
                'valid step',
                'second step',
            ],
        ], $payload->toArray());
    }
}
