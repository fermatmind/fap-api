<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiActionZhMbtiQueuePreflightTest extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'seo-growth-mbti-action-zh-mbti-queue-preflight.v1',
            $artifact['schema_version'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-ZH-MBTI-QUEUE-PREFLIGHT',
            $artifact['task'] ?? null
        );
    }

    #[Test]
    public function target_url_and_channel_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $artifact['target_url'] ?? null
        );
        $this->assertSame('indexnow', $artifact['channel'] ?? null);
    }

    #[Test]
    public function no_write_and_no_submission_boundaries_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['no_write_performed'] ?? false);
        $this->assertFalse($artifact['enqueue_performed'] ?? true);
        $this->assertFalse($artifact['live_submission_performed'] ?? true);
        $this->assertFalse($artifact['external_api_call_performed'] ?? true);
        $this->assertFalse($artifact['cms_mutation_performed'] ?? true);
        $this->assertFalse($artifact['sitemap_llms_authority_used'] ?? true);
        $this->assertFalse($artifact['frontend_fallback_authority_used'] ?? true);
        $this->assertTrue($artifact['research_deferred'] ?? false);
    }

    #[Test]
    public function url_truth_and_entity_mapping_state_are_backend_authoritative(): void
    {
        $artifact = $this->artifact();
        $urlTruth = $artifact['url_truth_state'] ?? [];
        $entity = $artifact['entity_mapping_state'] ?? [];

        $this->assertTrue($urlTruth['exists'] ?? false);
        $this->assertSame('zh-CN', $urlTruth['locale'] ?? null);
        $this->assertSame('test_detail', $urlTruth['page_entity_type'] ?? null);
        $this->assertSame('scale_catalog', $urlTruth['source_authority'] ?? null);
        $this->assertSame('indexable', $urlTruth['indexability_state'] ?? null);
        $this->assertFalse($urlTruth['private_flow'] ?? true);
        $this->assertFalse($urlTruth['uses_staging_host'] ?? true);
        $this->assertFalse($urlTruth['uses_www_host'] ?? true);

        $this->assertTrue($entity['exists'] ?? false);
        $this->assertSame('scales_registry', $entity['entity_source'] ?? null);
        $this->assertSame('observed', $entity['authority_status'] ?? null);
        $this->assertFalse($entity['frontend_fallback_authority_used'] ?? true);
        $this->assertFalse($entity['sitemap_llms_authority_used'] ?? true);
    }

    #[Test]
    public function public_runtime_and_claim_boundary_are_safe_observations(): void
    {
        $artifact = $this->artifact();
        $runtime = $artifact['public_runtime_state'] ?? [];
        $claim = $artifact['claim_boundary_state'] ?? [];

        $this->assertSame(200, $runtime['http_status'] ?? null);
        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $runtime['canonical'] ?? null
        );
        $this->assertFalse($runtime['has_noindex'] ?? true);
        $this->assertFalse($runtime['has_staging_canonical'] ?? true);
        $this->assertFalse($runtime['public_runtime_authority_used'] ?? true);

        $this->assertSame('claim_safe', $claim['backend_dry_run_state'] ?? null);
        $this->assertSame('test_detail', $claim['surface'] ?? null);
        $this->assertFalse($claim['research_surface'] ?? true);
        $this->assertSame([], $claim['forbidden_markers_found'] ?? null);
        $this->assertTrue($claim['safe_for_queue_preflight'] ?? false);
    }

    #[Test]
    public function duplicate_queue_and_queue_item_2_state_are_safe(): void
    {
        $artifact = $this->artifact();
        $duplicate = $artifact['duplicate_queue_state'] ?? [];
        $queueItem2 = $artifact['queue_item_2_state'] ?? [];

        $this->assertFalse($duplicate['existing_active_queue_item_for_target'] ?? true);
        $this->assertFalse($duplicate['existing_submitted_queue_item_for_target'] ?? true);
        $this->assertFalse($duplicate['duplicate_detected'] ?? true);
        $this->assertSame(1, $duplicate['planned_queue_count'] ?? null);

        $this->assertSame(2, $queueItem2['id'] ?? null);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $queueItem2['canonical_url'] ?? null
        );
        $this->assertSame('indexnow', $queueItem2['channel'] ?? null);
        $this->assertSame('approved', $queueItem2['approval_state'] ?? null);
        $this->assertSame('submitted', $queueItem2['execution_state'] ?? null);
        $this->assertTrue($queueItem2['unchanged'] ?? false);
        $this->assertTrue($queueItem2['not_part_of_target'] ?? false);
    }

    #[Test]
    public function search_channel_dry_run_is_one_item_no_write_ready(): void
    {
        $dryRun = $this->artifact()['dry_run_result'] ?? [];

        $this->assertTrue($dryRun['dry_run'] ?? false);
        $this->assertTrue($dryRun['no_write'] ?? false);
        $this->assertSame(1, $dryRun['candidate_count'] ?? null);
        $this->assertSame(1, $dryRun['eligible_count'] ?? null);
        $this->assertSame(1, $dryRun['planned_queue_count'] ?? null);
        $this->assertFalse($dryRun['duplicate_detected'] ?? true);
        $this->assertSame('indexnow', $dryRun['channel'] ?? null);
        $this->assertSame('test_detail', $dryRun['page_entity_type'] ?? null);
        $this->assertSame('scale_catalog', $dryRun['source_authority'] ?? null);
        $this->assertSame('claim_safe', $dryRun['claim_boundary_state'] ?? null);
        $this->assertFalse($dryRun['private_flow'] ?? true);
        $this->assertFalse($dryRun['writes_committed'] ?? true);
        $this->assertFalse($dryRun['enqueue_attempted'] ?? true);
        $this->assertFalse($dryRun['live_submission_attempted'] ?? true);
        $this->assertSame([], $dryRun['issues'] ?? null);
    }

    #[Test]
    public function gates_and_staging_sidecar_are_recorded(): void
    {
        $artifact = $this->artifact();
        $gates = $artifact['gate_state'] ?? [];
        $staging = $artifact['staging_sidecar_state'] ?? [];

        $this->assertFalse($gates['queue_write_enabled'] ?? true);
        $this->assertFalse($gates['live_submission_enabled'] ?? true);
        $this->assertFalse($gates['external_api_calls_enabled'] ?? true);
        $this->assertFalse($gates['indexnow_live_api_enabled'] ?? true);
        $this->assertTrue($gates['gates_remained_closed'] ?? false);

        $this->assertTrue($staging['staging_noindex_active'] ?? false);
        $this->assertFalse($staging['staging_used_as_url_truth'] ?? true);
        $this->assertFalse($staging['blocks_zh_mbti_queue_preflight'] ?? true);
    }

    #[Test]
    public function final_decision_next_task_and_approval_phrase_are_present(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'zh_mbti_queue_preflight_ready_for_human_approved_enqueue',
            $artifact['final_decision'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-ZH-MBTI-QUEUE',
            $artifact['next_task'] ?? null
        );
        $this->assertSame(
            'I explicitly approve Search Channel enqueue for the ZH MBTI test URL https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types via indexnow now. Do not perform live search submission. Do not enqueue any other URL.',
            $artifact['future_human_approval_phrase'] ?? null
        );
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-zh-mbti-queue-preflight.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
