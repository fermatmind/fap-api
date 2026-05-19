<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use Tests\TestCase;

final class RiasecActivityTaskExamplesPreflightTest extends TestCase
{
    private const EXPECTED_RECORD_COUNT = 360;

    private const VALID_DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    private const ALLOWED_SOURCE_STATUSES = [
        'content_example_not_registry_match',
        'commercial_expansion_candidate_not_runtime_imported',
    ];

    private const REQUIRED_FIELDS = [
        'schema_version',
        'asset_version',
        'asset_status',
        'activity_key',
        'dimensions',
        'activity_label',
        'task_examples',
        'low_risk_validation',
        'source_status',
        'not_a_recommendation',
        'frontend_fallback_allowed',
        'required_boundaries',
        'forbidden_claims',
        'review_status',
        'action_duration_options',
    ];

    private const FORBIDDEN_USER_CLAIMS = [
        'career match',
        'occupation match',
        'job fit',
        'fit score',
        'success prediction',
        'success probability',
        'recommended career',
        'best career',
        'career recommendation',
        'occupation ranking',
        'hiring suitability',
        'ability proof',
        'skill inference',
        '140Q more accurate',
        'more accurate',
        'raw score delta',
        '60Q wrong',
        '职业匹配',
        '岗位匹配',
        '匹配度',
        '适合度',
        '最适合',
        '推荐职业',
        '职业推荐',
        '岗位胜任',
        '成功概率',
        '职业成功',
        '更准确',
        '更准',
        '140题更准确',
        '60题错了',
        '推翻',
        '最终答案',
        '你就是',
        '天生适合',
        '能力证明',
        '技能证明',
        '招聘筛选',
        '录取依据',
        '晋升依据',
        '淘汰依据',
    ];

    public function test_v7_3_activity_task_fixture_is_complete_and_schema_compatible_for_preflight(): void
    {
        $records = $this->loadRecords();

        $this->assertCount(self::EXPECTED_RECORD_COUNT, $records);
        $this->assertSame(self::VALID_DIMENSIONS, array_values(array_unique(array_merge(...array_map(
            static fn (array $record): array => (array) $record['dimensions'],
            $records,
        )))));

        foreach ($records as $index => $record) {
            foreach (self::REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $record, 'line '.($index + 1)." missing {$field}");
            }

            $this->assertSame('riasec.activity_task_example.v1', $record['schema_version']);
            $this->assertSame('riasec_activity_task_examples_v1.zh-CN', $record['asset_version']);
            $this->assertContains($record['source_status'], self::ALLOWED_SOURCE_STATUSES);
            $this->assertTrue($record['not_a_recommendation']);
            $this->assertFalse($record['frontend_fallback_allowed']);
            $this->assertNotEmpty($record['activity_key']);
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $record['activity_key']);
            $this->assertIsList($record['dimensions']);
            $this->assertNotEmpty($record['dimensions']);
            $this->assertIsList($record['task_examples']);
            $this->assertGreaterThanOrEqual(3, count($record['task_examples']));
            $this->assertArrayHasKey('15min', $record['action_duration_options']);
            $this->assertArrayHasKey('30min', $record['action_duration_options']);
            $this->assertArrayHasKey('2h', $record['action_duration_options']);
            $this->assertArrayHasKey('1week', $record['action_duration_options']);

            foreach ($record['dimensions'] as $dimension) {
                $this->assertContains($dimension, self::VALID_DIMENSIONS);
            }

            $this->assertArrayNotHasKey('occupation_examples', $record);
            $this->assertArrayNotHasKey('occupation_example', $record);
            $this->assertArrayNotHasKey('source_url', $record);
            $this->assertArrayNotHasKey('onet_code', $record);
            $this->assertArrayNotHasKey('soc_code', $record);
            $this->assertArrayNotHasKey('fit_score', $record);
            $this->assertArrayNotHasKey('rank', $record);
        }
    }

    public function test_v7_3_activity_task_fixture_has_no_user_facing_forbidden_claims_or_technical_keys(): void
    {
        foreach ($this->loadRecords() as $index => $record) {
            $visibleText = implode("\n", array_filter([
                (string) $record['activity_label'],
                implode("\n", (array) $record['task_examples']),
                (string) $record['low_risk_validation'],
                implode("\n", array_values((array) $record['action_duration_options'])),
                (string) data_get($record, 'commercial_operations_internal_notes.validation_question', ''),
                (string) data_get($record, 'commercial_operations_internal_notes.boundary', ''),
            ]));

            foreach (self::FORBIDDEN_USER_CLAIMS as $claim) {
                $this->assertStringNotContainsString($claim, $visibleText, 'line '.($index + 1)." contains forbidden user claim {$claim}");
            }

            $this->assertDoesNotMatchRegularExpression(
                '/\b[a-z]+(?:_[a-z0-9]+)+\b/',
                $visibleText,
                'line '.($index + 1).' exposes a technical key in user-facing copy',
            );
        }
    }

    public function test_preflight_decision_is_conditional_go_with_documented_runtime_mapping_gap(): void
    {
        $records = $this->loadRecords();

        $this->assertCount(self::EXPECTED_RECORD_COUNT, $records);
        $this->assertFileExists(base_path('docs/riasec/activity-task-examples-pack-05-preflight.md'));
        $report = file_get_contents(base_path('docs/riasec/activity-task-examples-pack-05-preflight.md'));

        $this->assertIsString($report);
        $this->assertStringContainsString('Decision: CONDITIONAL GO', $report);
        $this->assertStringContainsString('ActivityExplorer mapping gap', $report);
        $this->assertStringContainsString('No occupation examples are imported in PACK-05', $report);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRecords(): array
    {
        $path = base_path('tests/Fixtures/Riasec/activity_task_examples_v1.zh-CN.jsonl');
        $this->assertFileExists($path);

        $records = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded, 'line '.($lineNumber + 1).' must decode to an object');
            $records[] = $decoded;
        }

        return $records;
    }
}
