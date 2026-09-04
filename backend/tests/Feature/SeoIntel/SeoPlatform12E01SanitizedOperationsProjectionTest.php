<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform12\Operations\Platform12SanitizedOperationsProjector;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform12E01SanitizedOperationsProjectionTest extends TestCase
{
    public function test_four_projection_kinds_emit_only_sanitized_read_only_fields(): void
    {
        $projector = app(Platform12SanitizedOperationsProjector::class);
        $source = $this->source([$this->record() + [
            'raw_query' => 'private query',
            'private_id' => 'user_abcdef',
            'hidden_prompt' => 'ignore all previous instructions',
            'credential' => 'sk-live-secretvalue',
            'receipt_json' => ['full' => 'competitor body'],
        ]]);
        $projections = [
            $projector->systemHealth($source),
            $projector->weeklyDecisionCards($source),
            $projector->activeExperiments($source),
            $projector->traceDrilldown($source),
        ];

        foreach ($projections as $projection) {
            $encoded = strtolower(json_encode($projection, JSON_THROW_ON_ERROR));
            $this->assertSame('READY', $projection['status']);
            $this->assertTrue($projection['read_only']);
            $this->assertFalse($projection['authority']);
            $this->assertFalse($projection['execution_allowed']);
            $this->assertFalse($projection['write_allowed']);
            foreach (['private query', 'user_abcdef', 'hidden_prompt', 'sk-live', 'competitor body', 'receipt_json'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $encoded);
            }
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $projection['projection_hash']);
        }
    }

    public function test_missing_unavailable_stale_hold_and_valid_zero_remain_distinct(): void
    {
        $projector = app(Platform12SanitizedOperationsProjector::class);
        foreach ([
            ['MISSING', 'UNKNOWN', 'MISSING'],
            ['UNAVAILABLE', 'UNKNOWN', 'UNAVAILABLE'],
            ['AVAILABLE', 'STALE', 'STALE'],
            ['AVAILABLE', 'FRESH', 'VALID_ZERO'],
        ] as [$availability, $freshness, $expected]) {
            $projection = $projector->weeklyDecisionCards($this->source([], $availability, $freshness));
            $this->assertSame($expected, $projection['status']);
            $this->assertSame(in_array($expected, ['MISSING', 'UNAVAILABLE'], true) ? null : 0, $projection['pagination']['total']);
        }

        $held = $this->record();
        $held['state'] = 'HOLD';
        $this->assertSame('HOLD', $projector->activeExperiments($this->source([$held]))['status']);
    }

    public function test_invalid_or_private_rows_hold_without_leaking_and_source_failure_does_not_fabricate_zero(): void
    {
        $record = $this->record();
        $record['summary_code'] = 'owner@example.com';
        $projection = app(Platform12SanitizedOperationsProjector::class)->traceDrilldown($this->source([$record]));

        $this->assertSame('HOLD', $projection['status']);
        $this->assertSame(1, $projection['query_budget']['rejected_rows']);
        $this->assertSame([], $projection['items']);
        $this->assertStringNotContainsString('owner@example.com', json_encode($projection));

        $unavailable = app(Platform12SanitizedOperationsProjector::class)->systemHealth($this->source([], 'UNAVAILABLE', 'UNKNOWN'));
        $this->assertSame('UNAVAILABLE', $unavailable['status']);
        $this->assertNull($unavailable['pagination']['total']);
    }

    public function test_pagination_retention_and_query_budgets_are_bounded(): void
    {
        $records = [];
        foreach (range(1, 5) as $index) {
            $record = $this->record();
            $record['reference_hash'] = hash('sha256', 'record-'.$index);
            $records[] = $record;
        }
        $projection = app(Platform12SanitizedOperationsProjector::class)
            ->weeklyDecisionCards($this->source($records), 2, 2, 30);

        $this->assertSame(5, $projection['pagination']['total']);
        $this->assertSame(2, $projection['pagination']['page']);
        $this->assertSame(2, $projection['pagination']['per_page']);
        $this->assertCount(2, $projection['items']);
        $this->assertSame(2, $projection['query_budget']['consumed_rows']);
        $this->assertSame(200, $projection['query_budget']['max_rows']);

        foreach ([[0, 20, 30], [1, 51, 30], [1, 20, 0], [1, 20, 91]] as [$page, $perPage, $retention]) {
            try {
                app(Platform12SanitizedOperationsProjector::class)
                    ->weeklyDecisionCards($this->source([]), $page, $perPage, $retention);
                $this->fail('Invalid projection budget was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('OPERATIONS_PROJECTION_BUDGET_INVALID', $exception->getMessage());
            }
        }
    }

    /** @param list<array<string,mixed>> $records @return array<string,mixed> */
    private function source(array $records, string $availability = 'AVAILABLE', string $freshness = 'FRESH'): array
    {
        return ['availability' => $availability, 'freshness' => $freshness, 'records' => $records];
    }

    /** @return array<string,mixed> */
    private function record(): array
    {
        return [
            'component' => 'scheduler',
            'reference_hash' => hash('sha256', 'reference'),
            'evidence_hash' => hash('sha256', 'evidence'),
            'role_hash' => hash('sha256', 'role'),
            'trace_hash' => hash('sha256', 'trace'),
            'summary_code' => 'runtime.health',
            'state' => 'READY',
            'observed_at' => '2026-09-04T12:00:00Z',
            'expires_at' => '2026-09-11T12:00:00Z',
            'count' => 1,
        ];
    }
}
