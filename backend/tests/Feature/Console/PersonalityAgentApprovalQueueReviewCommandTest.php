<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PersonalityAgentApprovalQueueReviewCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_lists_pending_recommendations_with_qa_risk_and_hash_context_without_writes(): void
    {
        $batchId = $this->createBatch('mbti64');
        $this->createItem($batchId, [
            'framework' => 'mbti64',
            'target_url' => 'https://fermatmind.com/en/personality/enfj-a',
            'path' => '/en/personality/enfj-a',
            'locale' => 'en',
            'page_type' => 'personality_profile_variant',
            'recommendation_id' => 'mbti64-next:/en/personality/enfj-a',
            'qa_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'approval_state' => 'pending',
            'recommendation_json' => $this->recommendation('https://fermatmind.com/en/personality/enfj-a'),
            'qa_json' => [
                'decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
                'eligible_for_approval_queue' => true,
                'eligible_for_cms_draft_path' => true,
                'blockers' => [],
                'failed_gates' => [],
            ],
        ]);
        $this->createItem($batchId, [
            'framework' => 'mbti64',
            'target_url' => 'https://fermatmind.com/zh/personality/intp-a',
            'path' => '/zh/personality/intp-a',
            'locale' => 'zh-CN',
            'page_type' => 'personality_profile_variant',
            'recommendation_id' => 'mbti64-next:/zh/personality/intp-a',
            'qa_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'approval_state' => 'approved',
            'approved_at' => now(),
            'recommendation_json' => $this->recommendation('https://fermatmind.com/zh/personality/intp-a'),
            'qa_json' => [
                'decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
                'eligible_for_approval_queue' => true,
                'eligible_for_cms_draft_path' => true,
            ],
        ]);

        $beforeItems = DB::table('personality_agent_approval_items')->count();
        $beforeBatches = DB::table('personality_agent_approval_batches')->count();

        $exitCode = Artisan::call('personality:agent-approval-queue-review', [
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('pending', $payload['filters']['approval_state']);
        $this->assertSame(1, $payload['summary']['matched_item_count']);
        $this->assertSame(1, $payload['summary']['returned_item_count']);
        $this->assertSame(['mbti64' => 1], $payload['summary']['by_framework']);
        $this->assertSame(['pending' => 1], $payload['summary']['by_approval_state']);
        $this->assertSame('https://fermatmind.com/en/personality/enfj-a', $payload['items'][0]['target_url']);
        $this->assertSame('PASS_READY_FOR_APPROVAL_QUEUE', $payload['items'][0]['qa_decision']);
        $this->assertSame('pending', $payload['items'][0]['approval_state']);
        $this->assertSame('source-sha-mbti64', $payload['items'][0]['source_package_sha256']);
        $this->assertSame('qa-sha-mbti64', $payload['items'][0]['qa_sha256']);
        $this->assertTrue($payload['items'][0]['recommendation_summary']['has_title']);
        $this->assertTrue($payload['items'][0]['qa_summary']['eligible_for_approval_queue']);
        $this->assertFalse($payload['safety_boundary']['approval_state_mutation_attempted']);
        $this->assertFalse($payload['safety_boundary']['cms_write_attempted']);
        $this->assertFalse($payload['safety_boundary']['search_release_attempted']);
        $this->assertSame($beforeItems, DB::table('personality_agent_approval_items')->count());
        $this->assertSame($beforeBatches, DB::table('personality_agent_approval_batches')->count());
    }

    public function test_review_filters_framework_state_and_exposes_risk_reasons(): void
    {
        $mbtiBatchId = $this->createBatch('mbti64');
        $bigFiveBatchId = $this->createBatch('big_five');
        $this->createItem($mbtiBatchId, [
            'framework' => 'mbti64',
            'target_url' => 'https://fermatmind.com/en/personality/enfp-a',
            'path' => '/en/personality/enfp-a',
            'locale' => 'en',
            'page_type' => 'personality_profile_variant',
            'recommendation_id' => 'mbti64-next:/en/personality/enfp-a',
            'qa_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'approval_state' => 'pending',
            'recommendation_json' => $this->recommendation('https://fermatmind.com/en/personality/enfp-a'),
            'qa_json' => [
                'decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
                'failed_gates' => [],
            ],
        ]);
        $this->createItem($bigFiveBatchId, [
            'framework' => 'big_five',
            'target_url' => 'https://fermatmind.com/en/personality/big-five/openness',
            'path' => '/en/personality/big-five/openness',
            'locale' => 'en',
            'page_type' => 'personality_public_content_asset',
            'recommendation_id' => 'big-five-next:/en/personality/big-five/openness',
            'qa_decision' => 'HOLD_REQUIRES_EVIDENCE_OR_EDITORIAL_REPAIR',
            'approval_state' => 'rejected',
            'rejected_at' => now(),
            'blocked_reason' => 'duplicate_template_risk',
            'recommendation_json' => $this->recommendation('https://fermatmind.com/en/personality/big-five/openness'),
            'qa_json' => [
                'decision' => 'HOLD_REQUIRES_EVIDENCE_OR_EDITORIAL_REPAIR',
                'failed_gates' => ['duplicate_template_risk', 'claim_review_needed'],
            ],
        ]);

        $exitCode = Artisan::call('personality:agent-approval-queue-review', [
            '--framework' => 'big_five',
            '--approval-state' => 'rejected',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertSame('big_five', $payload['filters']['framework']);
        $this->assertSame('rejected', $payload['filters']['approval_state']);
        $this->assertSame(1, $payload['summary']['matched_item_count']);
        $this->assertSame(['big_five' => 1], $payload['summary']['by_framework']);
        $this->assertSame(['duplicate_template_risk' => 1], $payload['summary']['by_blocked_reason']);
        $this->assertSame(
            ['duplicate_template_risk', 'claim_review_needed'],
            $payload['items'][0]['risk_reasons']
        );
    }

    public function test_review_fails_closed_for_unsupported_filters_without_mutating_queue(): void
    {
        $beforeItems = DB::table('personality_agent_approval_items')->count();
        $beforeBatches = DB::table('personality_agent_approval_batches')->count();

        $exitCode = Artisan::call('personality:agent-approval-queue-review', [
            '--framework' => 'unsupported',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('invalid_option', $payload['errors'][0]['code']);
        $this->assertFalse($payload['safety_boundary']['approval_state_mutation_attempted']);
        $this->assertFalse($payload['safety_boundary']['cms_write_attempted']);
        $this->assertSame($beforeItems, DB::table('personality_agent_approval_items')->count());
        $this->assertSame($beforeBatches, DB::table('personality_agent_approval_batches')->count());
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendation(string $targetUrl): array
    {
        return [
            'target_url' => $targetUrl,
            'recommendations' => [
                'title' => ['recommended' => 'Recommended title'],
                'description' => ['recommended' => 'Recommended description.'],
                'h1' => ['recommended' => 'Recommended H1'],
                'quick_answer' => ['recommended' => 'Recommended quick answer.'],
                'faq' => [
                    ['question' => 'What changes?', 'answer' => 'The public profile becomes clearer.'],
                ],
                'internal_links' => [
                    ['target_url' => 'https://fermatmind.com/en/tests/big-five-personality-test-ocean-model'],
                ],
                'differentiation_notes' => ['Keep this page distinct from close variants.'],
            ],
        ];
    }

    private function createBatch(string $framework): int
    {
        return (int) DB::table('personality_agent_approval_batches')->insertGetId([
            'framework' => $framework,
            'source_artifact' => 'personality-agent-next-batch-recommendations',
            'source_artifact_path' => 'docs/seo/personality/personality-agent-next-batch-recommendations.json',
            'source_package_sha256' => 'source-sha-'.$framework,
            'qa_artifact' => 'personality-agent-next-batch-qa',
            'qa_artifact_path' => 'docs/seo/personality/personality-agent-next-batch-qa.json',
            'qa_sha256' => 'qa-sha-'.$framework,
            'status' => 'pending_review',
            'planned_item_count' => 2,
            'queued_item_count' => 2,
            'blocked_item_count' => 0,
            'safety_holds_json' => (string) json_encode(['approval_queue_only' => true]),
            'summary_json' => (string) json_encode(['qa_final_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createItem(int $batchId, array $overrides): void
    {
        $recommendationJson = (string) json_encode($overrides['recommendation_json'] ?? [], JSON_UNESCAPED_SLASHES);
        DB::table('personality_agent_approval_items')->insert(array_merge([
            'batch_id' => $batchId,
            'framework' => 'mbti64',
            'target_url' => 'https://fermatmind.com/en/personality/enfj-a',
            'path' => '/en/personality/enfj-a',
            'locale' => 'en',
            'page_type' => 'personality_profile_variant',
            'recommendation_id' => 'recommendation-id',
            'recommendation_sha256' => hash('sha256', $recommendationJson),
            'qa_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'approval_state' => 'pending',
            'approved_at' => null,
            'rejected_at' => null,
            'blocked_reason' => null,
            'safety_holds_json' => (string) json_encode(['approval_queue_only' => true]),
            'recommendation_json' => $recommendationJson,
            'qa_json' => (string) json_encode($overrides['qa_json'] ?? [], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ], array_diff_key($overrides, array_flip(['recommendation_json', 'qa_json']))));
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
