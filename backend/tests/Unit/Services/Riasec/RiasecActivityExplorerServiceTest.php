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
        $this->assertSame('career_activity_registry_v0.1', $payload['content_version']);
        $this->assertSame('content_examples_only', $payload['status']);
        $this->assertSame('content_example_not_registry_match', $payload['source_status']);
        $this->assertFalse((bool) data_get($payload, 'boundary.registry_source_connected'));
        $this->assertFalse((bool) data_get($payload, 'boundary.fit_score_allowed'));
        $this->assertFalse((bool) data_get($payload, 'boundary.success_prediction_allowed'));
        $this->assertSame(['R', 'I', 'A'], array_column($payload['dimension_activity_families'], 'dimension'));
        $this->assertSame('not_available_for_code_v0_1', data_get($payload, 'code_activity_pack.status'));
        $this->assertSame([], data_get($payload, 'code_activity_pack.occupation_examples'));
    }

    public function test_ias_pack_labels_every_occupation_example_as_content_example_not_registry_match(): void
    {
        $payload = (new RiasecActivityExplorerService)->build('IAS', 'zh-CN');

        $this->assertSame('available', data_get($payload, 'code_activity_pack.status'));
        $this->assertSame('IAS', data_get($payload, 'code_activity_pack.code'));
        $activities = (array) data_get($payload, 'code_activity_pack.activities', []);
        $this->assertCount(6, $activities);

        $occupationCount = 0;
        foreach ($activities as $activity) {
            $this->assertSame('content_example_not_registry_match', data_get($activity, 'source_status'));
            $this->assertNotEmpty(data_get($activity, 'task_examples'));

            foreach ((array) data_get($activity, 'occupation_examples', []) as $example) {
                $occupationCount++;
                $this->assertSame('content_example_not_registry_match', data_get($example, 'source_status'));
                $this->assertSame('内容示例，非职业数据库匹配', data_get($example, 'display_label'));
                $this->assertTrue((bool) data_get($example, 'not_a_recommendation'));
                $this->assertIsArray(data_get($example, 'common_tasks'));
                $this->assertIsArray(data_get($example, 'skills_to_check'));
                $this->assertArrayNotHasKey('source_url', (array) $example);
                $this->assertArrayNotHasKey('onet_code', (array) $example);
                $this->assertArrayNotHasKey('soc_code', (array) $example);
                $this->assertArrayNotHasKey('fit_score', (array) $example);
                $this->assertArrayNotHasKey('rank', (array) $example);
            }
        }

        $this->assertGreaterThan(0, $occupationCount);
    }

    public function test_activity_explorer_payload_does_not_emit_forbidden_claim_copy(): void
    {
        $payload = (new RiasecActivityExplorerService)->build('IAS', 'zh-CN');
        $text = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Matches', $text);
        $this->assertStringNotContainsString('职业推荐', $text);
        $this->assertStringNotContainsString('岗位匹配', $text);
        $this->assertStringNotContainsString('适合度', $text);
        $this->assertStringNotContainsString('成功概率', $text);
        $this->assertStringNotContainsString('最佳职业', $text);
        $this->assertStringNotContainsString('推荐职业排名', $text);
        $this->assertStringContainsString('content_example_not_registry_match', $text);
    }
}
