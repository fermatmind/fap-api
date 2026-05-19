<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use Tests\TestCase;

final class RiasecOccupationExamplesPreflightTest extends TestCase
{
    private const EXPECTED_RECORD_COUNT = 360;

    private const VALID_DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    private const REQUIRED_FIELDS = [
        'schema_version',
        'asset_version',
        'asset_status',
        'record_id',
        'occupation_example',
        'primary_activity_dimension',
        'activity_key',
        'display_label',
        'why_it_may_appear',
        'common_tasks',
        'education_boundary',
        'skill_boundary',
        'qualification_boundary',
        'source_status',
        'source_url_allowed',
        'fit_score_allowed',
        'not_a_recommendation',
        'user_visible_boundary',
        'review_status',
        'required_boundaries',
        'forbidden_claims',
        'frontend_fallback_allowed',
        'reality_check',
        'task_examples',
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

    public function test_v7_3_occupation_fixture_is_complete_and_examples_only(): void
    {
        $records = $this->loadOccupationRecords();
        $activityRowsByDimension = $this->loadActivityTaskDimensions();

        $this->assertCount(self::EXPECTED_RECORD_COUNT, $records);

        foreach ($records as $index => $record) {
            foreach (self::REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $record, 'line '.($index + 1)." missing {$field}");
            }

            $this->assertSame('riasec.occupation_example_boundary.v1', $record['schema_version']);
            $this->assertSame('riasec_occupation_examples_boundary_v1.zh-CN', $record['asset_version']);
            $this->assertSame('content_example_not_registry_match', $record['source_status']);
            $this->assertFalse($record['source_url_allowed']);
            $this->assertFalse($record['fit_score_allowed']);
            $this->assertTrue($record['not_a_recommendation']);
            $this->assertFalse($record['frontend_fallback_allowed']);
            $this->assertContains($record['primary_activity_dimension'], self::VALID_DIMENSIONS);
            $this->assertArrayHasKey($record['primary_activity_dimension'], $activityRowsByDimension);
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $record['activity_key']);
            $this->assertStringEndsWith('_activity_family', $record['activity_key']);
            $this->assertNotEmpty($record['education_boundary']);
            $this->assertNotEmpty($record['skill_boundary']);
            $this->assertNotEmpty($record['qualification_boundary']);
            $this->assertIsList($record['common_tasks']);
            $this->assertGreaterThanOrEqual(3, count($record['common_tasks']));
            $this->assertIsList($record['task_examples']);
            $this->assertGreaterThanOrEqual(3, count($record['task_examples']));

            $this->assertArrayNotHasKey('source_url', $record);
            $this->assertArrayNotHasKey('onet_code', $record);
            $this->assertArrayNotHasKey('soc_code', $record);
            $this->assertArrayNotHasKey('fit_score', $record);
            $this->assertArrayNotHasKey('rank', $record);
            $this->assertArrayNotHasKey('success_prediction', $record);
        }
    }

    public function test_v7_3_occupation_fixture_has_no_user_facing_forbidden_claims_or_technical_keys(): void
    {
        foreach ($this->loadOccupationRecords() as $index => $record) {
            $visibleText = implode("\n", array_filter([
                (string) $record['occupation_example'],
                (string) $record['display_label'],
                (string) $record['why_it_may_appear'],
                implode("\n", (array) $record['common_tasks']),
                (string) $record['education_boundary'],
                (string) $record['skill_boundary'],
                (string) $record['qualification_boundary'],
                (string) $record['user_visible_boundary'],
                (string) $record['reality_check'],
                implode("\n", (array) $record['task_examples']),
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

    public function test_preflight_decision_is_conditional_go_with_documented_activity_mapping_gap(): void
    {
        $this->assertCount(self::EXPECTED_RECORD_COUNT, $this->loadOccupationRecords());
        $this->assertFileExists(base_path('docs/riasec/occupation-examples-pack-06-preflight.md'));

        $report = file_get_contents(base_path('docs/riasec/occupation-examples-pack-06-preflight.md'));
        $this->assertIsString($report);
        $this->assertStringContainsString('Decision: CONDITIONAL GO', $report);
        $this->assertStringContainsString('Activity/task mapping gap', $report);
        $this->assertStringContainsString('must not introduce a direct Holland Code to occupation example route', $report);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadOccupationRecords(): array
    {
        $path = base_path('tests/Fixtures/Riasec/occupation_examples_boundary_v1.zh-CN.jsonl');
        $this->assertFileExists($path);

        $records = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded, 'line '.($lineNumber + 1).' must decode to an object');
            $records[] = $decoded;
        }

        return $records;
    }

    /**
     * @return array<string,int>
     */
    private function loadActivityTaskDimensions(): array
    {
        $path = base_path('content_assets/riasec/activity_task_examples_v1.zh-CN.jsonl');
        $this->assertFileExists($path);

        $counts = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            foreach ((array) ($decoded['dimensions'] ?? []) as $dimension) {
                $counts[(string) $dimension] = ($counts[(string) $dimension] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
