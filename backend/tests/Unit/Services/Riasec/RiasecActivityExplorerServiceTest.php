<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecActivityExplorerService;
use Tests\TestCase;

final class RiasecActivityExplorerServiceTest extends TestCase
{
    public function test_builds_dimension_activity_families_without_code_pack_for_unauthored_codes(): void
    {
        $payload = (new RiasecActivityExplorerService)->build('RIA', 'zh-CN');

        $this->assertSame('riasec.activity_explorer.v0.1', $payload['schema_version']);
        $this->assertSame('activity_task_examples_v1.zh-CN', $payload['content_version']);
        $this->assertSame('content_examples_only', $payload['status']);
        $this->assertSame('content_example_not_registry_match', $payload['source_status']);
        $this->assertFalse((bool) data_get($payload, 'boundary.registry_source_connected'));
        $this->assertFalse((bool) data_get($payload, 'boundary.fit_score_allowed'));
        $this->assertFalse((bool) data_get($payload, 'boundary.success_prediction_allowed'));
        $this->assertSame(['R', 'I', 'A'], array_column($payload['dimension_activity_families'], 'dimension'));
        $this->assertSame('available', data_get($payload, 'code_activity_pack.status'));
        $this->assertNotEmpty(data_get($payload, 'code_activity_pack.activities'));
        $this->assertSame([], data_get($payload, 'code_activity_pack.occupation_examples'));
    }

    public function test_ias_pack_uses_file_backed_activity_and_occupation_examples(): void
    {
        $payload = (new RiasecActivityExplorerService)->build('IAS', 'zh-CN');

        $this->assertSame('available', data_get($payload, 'code_activity_pack.status'));
        $this->assertSame('IAS', data_get($payload, 'code_activity_pack.code'));
        $this->assertSame('FermatTest RIASEC Activity Task Examples v1', data_get($payload, 'code_activity_pack.source_name'));
        $activities = (array) data_get($payload, 'code_activity_pack.activities', []);
        $this->assertCount(9, $activities);

        foreach ($activities as $activity) {
            $this->assertSame('content_example_not_registry_match', data_get($activity, 'source_status'));
            $this->assertNotEmpty(data_get($activity, 'task_examples'));
            $this->assertSame('activity_task_examples_v1.zh-CN', data_get($activity, 'content_version'));
            $this->assertSame('backend_authoritative_activity_task_asset', data_get($activity, 'evidence_level'));
            $occupationExamples = (array) data_get($activity, 'occupation_examples');
            $this->assertCount(2, $occupationExamples);

            foreach ($occupationExamples as $example) {
                $this->assertSame('content_example_not_registry_match', data_get($example, 'source_status'));
                $this->assertSame('活动场景例子，不是结果答案', data_get($example, 'display_label'));
                $this->assertSame('occupation_examples_boundary_v1.zh-CN', data_get($example, 'content_version'));
                $this->assertTrue((bool) data_get($example, 'not_a_recommendation'));
                $this->assertFalse((bool) data_get($example, 'fit_score_allowed'));
                $this->assertNotEmpty(data_get($example, 'education_boundary'));
                $this->assertNotEmpty(data_get($example, 'skill_boundary'));
                $this->assertNotEmpty(data_get($example, 'qualification_boundary'));
                $this->assertArrayNotHasKey('source_url', (array) $example);
                $this->assertArrayNotHasKey('onet_code', (array) $example);
                $this->assertArrayNotHasKey('soc_code', (array) $example);
                $this->assertArrayNotHasKey('fit_score', (array) $example);
                $this->assertArrayNotHasKey('rank', (array) $example);
                $this->assertArrayNotHasKey('success_prediction', (array) $example);
            }
        }
    }

    public function test_expanded_code_pack_uses_examples_only_boundaries(): void
    {
        $payload = (new RiasecActivityExplorerService)->build('RCE', 'zh-CN');

        $this->assertSame('available', data_get($payload, 'code_activity_pack.status'));
        $this->assertSame('RCE', data_get($payload, 'code_activity_pack.code'));
        $this->assertSame(
            ['r_field_observe_1', 'r_proto_1', 'r_debug_1'],
            array_slice((array) data_get($payload, 'code_activity_pack.activity_chain'), 0, 3),
        );
        $this->assertSame('content_example_not_registry_match', data_get($payload, 'code_activity_pack.source_status'));
        $this->assertFalse((bool) data_get($payload, 'boundary.registry_source_connected'));

        $activities = (array) data_get($payload, 'code_activity_pack.activities', []);
        $this->assertCount(9, $activities);

        foreach ($activities as $activity) {
            $this->assertSame('content_example_not_registry_match', data_get($activity, 'source_status'));
            $this->assertNotEmpty(data_get($activity, 'task_examples'));
            $this->assertNotEmpty(data_get($activity, 'occupation_examples'));
        }
    }

    public function test_selected_v0_1_codes_have_authored_examples_only_packs(): void
    {
        $service = new RiasecActivityExplorerService;

        foreach (['RCE', 'EAS', 'CRI', 'SIC', 'ERC', 'AIR', 'CSE'] as $code) {
            $payload = $service->build($code, 'zh-CN');

            $this->assertSame($code, data_get($payload, 'code_activity_pack.code'));
            $this->assertSame('available', data_get($payload, 'code_activity_pack.status'));
            $this->assertSame('content_example_not_registry_match', data_get($payload, 'code_activity_pack.source_status'));
            $this->assertCount(9, (array) data_get($payload, 'code_activity_pack.activity_chain'));
            $this->assertCount(9, (array) data_get($payload, 'code_activity_pack.activities'));
        }
    }

    public function test_uncovered_code_keeps_minimal_not_available_state(): void
    {
        $payload = (new RiasecActivityExplorerService)->build('RES', 'zh-CN');

        $this->assertSame(['R', 'E', 'S'], array_column($payload['dimension_activity_families'], 'dimension'));
        $this->assertSame('available', data_get($payload, 'code_activity_pack.status'));
        $this->assertCount(9, (array) data_get($payload, 'code_activity_pack.activities'));
        $this->assertSame([], data_get($payload, 'code_activity_pack.occupation_examples'));
    }

    public function test_activity_explorer_payload_does_not_emit_forbidden_claim_copy(): void
    {
        $service = new RiasecActivityExplorerService;
        $payloads = array_map(
            fn (string $code): array => $service->build($code, 'zh-CN'),
            ['IAS', 'RCE', 'EAS', 'CRI', 'SIC', 'ERC', 'AIR', 'CSE', 'RES'],
        );
        $text = json_encode($payloads, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Matches', $text);
        $this->assertStringNotContainsString('职业推荐', $text);
        $this->assertStringNotContainsString('岗位匹配', $text);
        $this->assertStringNotContainsString('适合度', $text);
        $this->assertStringNotContainsString('成功概率', $text);
        $this->assertStringNotContainsString('success prediction', $text);
        $this->assertStringNotContainsString('fit score', $text);
        $this->assertStringNotContainsString('source_url', $text);
        $this->assertStringNotContainsString('onet_code', $text);
        $this->assertStringNotContainsString('soc_code', $text);
        $this->assertStringNotContainsString('最佳职业', $text);
        $this->assertStringNotContainsString('推荐职业排名', $text);
        $this->assertStringContainsString('content_example_not_registry_match', $text);
    }
}
