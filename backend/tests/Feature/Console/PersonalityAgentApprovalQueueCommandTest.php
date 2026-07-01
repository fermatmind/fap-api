<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityAgentApprovalQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_pending_approval_items_without_writes(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(2, $payload['planned_item_count']);
        $this->assertSame(0, $payload['blocked_item_count']);
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_next_batch_approval_review_pass_can_enter_human_approval_queue_dry_run_only(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->nextBatchThreePackage(), $this->nextBatchThreeQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('mbti64', $payload['framework']);
        $this->assertSame('PASS_READY_FOR_APPROVAL_REVIEW', $payload['qa_final_decision']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertFalse($payload['live_content_updated']);
        $this->assertSame(3, $payload['planned_item_count']);
        $this->assertSame(0, $payload['blocked_item_count']);
        $this->assertSame(0, $payload['created_item_count']);
        $this->assertSame([], $payload['errors']);
        $this->assertSame(
            [
                'https://fermatmind.com/zh/personality/intp-a',
                'https://fermatmind.com/zh/personality/esfp-a',
                'https://fermatmind.com/en/personality/enfj-a',
            ],
            array_map(
                static fn (array $item): string => (string) $item['target_url'],
                $payload['items']
            )
        );
        $this->assertSame(
            ['PASS_READY_FOR_APPROVAL_REVIEW'],
            array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['qa_decision'],
                $payload['items']
            )))
        );
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_competitor_gap_content_expansion_pass_can_enter_human_approval_queue_dry_run_only(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts(
            $this->competitorGapExpansionPackage(),
            $this->competitorGapExpansionQa()
        );

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('mbti64', $payload['framework']);
        $this->assertSame('PASS_READY_FOR_EDITORIAL_REVIEW_AND_APPROVAL_QUEUE_REPAIR', $payload['qa_final_decision']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertFalse($payload['live_content_updated']);
        $this->assertSame(6, $payload['planned_item_count']);
        $this->assertSame(0, $payload['blocked_item_count']);
        $this->assertSame(0, $payload['created_item_count']);
        $this->assertSame([], $payload['errors']);
        $this->assertSame(
            [
                'https://fermatmind.com/zh/personality/intp-a',
                'https://fermatmind.com/zh/personality/esfp-a',
                'https://fermatmind.com/en/personality/enfj-a',
                'https://fermatmind.com/en/personality/intp-a',
                'https://fermatmind.com/en/personality/esfp-a',
                'https://fermatmind.com/zh/personality/enfj-a',
            ],
            array_map(
                static fn (array $item): string => (string) $item['target_url'],
                $payload['items']
            )
        );
        $this->assertSame(
            ['PASS_READY_FOR_CONTENT_EXPANSION_REVIEW'],
            array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['qa_decision'],
                $payload['items']
            )))
        );
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_auto_qa_approval_handoff_pass_can_enter_human_approval_queue_dry_run_only(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->autoApprovalHandoffPackage(), $this->autoApprovalHandoffQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('big_five', $payload['framework']);
        $this->assertSame('PASS_READY_FOR_APPROVAL_HANDOFF_DRY_RUN', $payload['qa_final_decision']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertFalse($payload['live_content_updated']);
        $this->assertSame(2, $payload['planned_item_count']);
        $this->assertSame(0, $payload['blocked_item_count']);
        $this->assertSame(0, $payload['created_item_count']);
        $this->assertSame([], $payload['errors']);
        $this->assertSame(
            [
                'https://fermatmind.com/en/personality/big-five/agreeableness',
                'https://fermatmind.com/en/personality/big-five/facets',
            ],
            array_map(
                static fn (array $item): string => (string) $item['target_url'],
                $payload['items']
            )
        );
        $this->assertSame(
            ['PASS_READY_FOR_APPROVAL_HANDOFF'],
            array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['qa_decision'],
                $payload['items']
            )))
        );
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_mbti64_v85_v5_bilingual_package_can_enter_human_approval_queue_dry_run_only(): void
    {
        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $this->mbti64V85V5PackagePath(),
            '--qa' => $this->mbti64V85V5QaPath(),
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('mbti64', $payload['framework']);
        $this->assertSame('PASS_READY_FOR_FAP_API_ARTIFACT_SYNC', $payload['qa_final_decision']);
        $this->assertSame('a0fd058b82ec40940b8c92546c461086d3bfca7a4b0521aeb92e5cc8b0517b67', $payload['source_package_sha256']);
        $this->assertSame('a6757d87af71db28815446269eb3a6c5ab9e1fbe0a3176191f1ebc25be2933b4', $payload['qa_sha256']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertFalse($payload['live_content_updated']);
        $this->assertSame(64, $payload['planned_item_count']);
        $this->assertSame(0, $payload['blocked_item_count']);
        $this->assertSame(0, $payload['created_item_count']);
        $this->assertSame([], $payload['errors']);
        $this->assertCount(64, $payload['items']);
        $this->assertSame(
            ['mbti64'],
            array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['framework'],
                $payload['items']
            )))
        );
        $this->assertSame(
            ['personality_profile_variant'],
            array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['page_type'],
                $payload['items']
            )))
        );
        $this->assertSame(
            ['PASS_READY_FOR_FAP_API_ARTIFACT_SYNC'],
            array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['qa_decision'],
                $payload['items']
            )))
        );
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_mbti64_v85_v5_bilingual_write_creates_pending_items_only(): void
    {
        $exitCode = Artisan::call('personality:agent-approval-queue', $this->writeOptions(
            $this->mbti64V85V5PackagePath(),
            $this->mbti64V85V5QaPath()
        ));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(64, $payload['planned_item_count']);
        $this->assertSame(64, $payload['queued_item_count']);
        $this->assertSame(64, $payload['created_item_count']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(1, DB::table('personality_agent_approval_batches')->where('framework', 'mbti64')->count());
        $this->assertSame(64, DB::table('personality_agent_approval_items')->where('framework', 'mbti64')->where('approval_state', 'pending')->count());
        $this->assertSame(
            0,
            DB::table('personality_agent_approval_items')
                ->where('target_url', 'like', '%-a-vs-%')
                ->orWhere('target_url', 'like', '%/results/%')
                ->count()
        );
    }

    public function test_mbti64_v85_v5_bilingual_reencoded_artifact_fails_closed_on_hash_lock(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts(
            $this->mbti64V85V5Package(),
            $this->mbti64V85V5Qa()
        );

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('fail', $payload['status']);
        $this->assertSame(64, $payload['planned_item_count']);
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
        $this->assertContains(
            'mbti64_v85_v5_package_file_sha_mismatch',
            array_map(static fn (array $error): string => (string) $error['code'], $payload['errors'])
        );
        $this->assertContains(
            'mbti64_v85_v5_qa_file_sha_mismatch',
            array_map(static fn (array $error): string => (string) $error['code'], $payload['errors'])
        );
    }

    public function test_mbti64_v85_v5_bilingual_missing_target_fails_closed(): void
    {
        $package = $this->mbti64V85V5Package();
        $qa = $this->mbti64V85V5Qa();
        array_pop($package['recommendations']);
        array_pop($qa['page_results']);
        $package['target_count'] = 63;
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('fail', $payload['status']);
        $this->assertSame(63, $payload['planned_item_count']);
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
        $this->assertContains(
            'mbti64_v85_v5_target_set_mismatch',
            array_map(static fn (array $error): string => (string) $error['code'], $payload['errors'])
        );
        $this->assertContains(
            'mbti64_v85_v5_recommendation_count_mismatch',
            array_map(static fn (array $error): string => (string) $error['code'], $payload['errors'])
        );
    }

    public function test_fap_api_artifact_sync_decision_is_rejected_without_mbti64_v85_v5_contract_lock(): void
    {
        $qa = $this->validQa();
        $qa['final_decision'] = 'PASS_READY_FOR_FAP_API_ARTIFACT_SYNC';
        foreach ($qa['page_results'] as &$row) {
            $row['decision'] = 'PASS_READY_FOR_FAP_API_ARTIFACT_SYNC';
        }
        unset($row);
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $qa);

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('fail', $payload['status']);
        $this->assertSame(2, $payload['planned_item_count']);
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
        $this->assertContains(
            'fap_api_artifact_sync_decision_requires_mbti64_v85_v5_contract',
            array_map(static fn (array $error): string => (string) $error['code'], $payload['errors'])
        );
    }

    public function test_write_creates_pending_human_approval_queue_items_only(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertSame(2, $payload['planned_item_count']);
        $this->assertSame(2, $payload['created_item_count']);
        $this->assertSame(1, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(2, DB::table('personality_agent_approval_items')->count());

        $this->assertSame('pending_review', (string) DB::table('personality_agent_approval_batches')->value('status'));
        $this->assertSame(
            ['pending'],
            DB::table('personality_agent_approval_items')->distinct()->pluck('approval_state')->all()
        );
        $this->assertSame(0, DB::table('personality_agent_approval_items')->whereNotNull('approved_at')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->whereNotNull('rejected_at')->count());
    }

    public function test_approve_action_approves_explicit_pending_items_only_without_cms_side_effects(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->assertSame(0, Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath)));

        $ids = DB::table('personality_agent_approval_items')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $hashes = $this->approvalHashes();

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->approveOptions($ids, $hashes));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['approve']);
        $this->assertFalse($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertTrue($payload['writes_attempted']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertTrue($payload['approval_state_mutation_attempted']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['cms_live_promotion_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertSame(2, $payload['matched_item_count']);
        $this->assertSame(2, $payload['approved_item_count']);
        $this->assertSame(0, $payload['skipped_existing_approved_item_count']);
        $this->assertSame([], $payload['errors']);
        $this->assertSame(
            ['approved'],
            DB::table('personality_agent_approval_items')->distinct()->pluck('approval_state')->all()
        );
        $this->assertSame(2, DB::table('personality_agent_approval_items')->whereNotNull('approved_at')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->whereNotNull('rejected_at')->count());
    }

    public function test_approve_action_is_idempotent_when_all_requested_items_are_already_approved(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->assertSame(0, Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath)));

        $ids = DB::table('personality_agent_approval_items')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $hashes = $this->approvalHashes();

        $this->assertSame(0, Artisan::call('personality:agent-approval-queue', $this->approveOptions($ids, $hashes)));
        $secondExit = Artisan::call('personality:agent-approval-queue', $this->approveOptions($ids, $hashes));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['approved_item_count']);
        $this->assertSame(2, $payload['skipped_existing_approved_item_count']);
        $this->assertSame(2, DB::table('personality_agent_approval_items')->where('approval_state', 'approved')->count());
    }

    public function test_approve_action_fails_closed_for_missing_items_without_partial_updates(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->assertSame(0, Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath)));

        $ids = DB::table('personality_agent_approval_items')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ids[] = 999999;

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->approveOptions($ids, $this->approvalHashes()));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame('approval_item_ids_missing', $payload['errors'][0]['code']);
        $this->assertSame(2, DB::table('personality_agent_approval_items')->where('approval_state', 'pending')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->where('approval_state', 'approved')->count());
    }

    public function test_approve_action_fails_closed_for_rejected_items_without_partial_updates(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->assertSame(0, Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath)));

        $ids = DB::table('personality_agent_approval_items')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::table('personality_agent_approval_items')->where('id', $ids[0])->update([
            'approval_state' => 'rejected',
            'rejected_at' => now(),
        ]);

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->approveOptions($ids, $this->approvalHashes()));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertContains('approval_item_rejected', array_column($payload['errors'], 'code'));
        $this->assertSame(0, DB::table('personality_agent_approval_items')->where('approval_state', 'approved')->count());
        $this->assertSame(1, DB::table('personality_agent_approval_items')->where('approval_state', 'pending')->count());
    }

    public function test_approve_action_fails_closed_for_hash_or_framework_mismatch(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->assertSame(0, Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath)));

        $ids = DB::table('personality_agent_approval_items')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $hashes = $this->approvalHashes();
        $hashes['source_package_sha256'] = str_repeat('a', 64);

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->approveOptions($ids, $hashes));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertContains('approval_item_source_sha256_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(2, DB::table('personality_agent_approval_items')->where('approval_state', 'pending')->count());

        $hashes = $this->approvalHashes();
        $hashes['framework'] = 'big_five';
        $exitCode = Artisan::call('personality:agent-approval-queue', $this->approveOptions($ids, $hashes));
        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('approval_item_framework_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(2, DB::table('personality_agent_approval_items')->where('approval_state', 'pending')->count());
    }

    public function test_approve_action_fails_closed_for_mixed_pending_and_approved_items(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->assertSame(0, Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath)));

        $ids = DB::table('personality_agent_approval_items')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::table('personality_agent_approval_items')->where('id', $ids[0])->update([
            'approval_state' => 'approved',
            'approved_at' => now(),
        ]);

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->approveOptions($ids, $this->approvalHashes()));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame('approval_items_must_be_all_pending_or_all_approved', $payload['errors'][0]['code']);
        $this->assertSame(1, DB::table('personality_agent_approval_items')->where('approval_state', 'pending')->count());
        $this->assertSame(1, DB::table('personality_agent_approval_items')->where('approval_state', 'approved')->count());
    }

    public function test_approve_action_requires_exact_operator_token(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->assertSame(0, Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath)));

        $ids = DB::table('personality_agent_approval_items')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $options = $this->approveOptions($ids, $this->approvalHashes());
        $options['--operator-approved'] = 'WRONG';

        $exitCode = Artisan::call('personality:agent-approval-queue', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['approval_state_mutation_attempted']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString('PERSONALITY-AGENT-APPROVAL-QUEUE-APPROVE-CONTRACT-01', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(2, DB::table('personality_agent_approval_items')->where('approval_state', 'pending')->count());
    }

    public function test_second_write_is_idempotent_for_same_package_and_qa_hashes(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);

        $firstExit = Artisan::call('personality:agent-approval-queue', $options);
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:agent-approval-queue', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_item_count']);
        $this->assertSame(2, $payload['skipped_existing_item_count']);
        $this->assertSame(1, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(2, DB::table('personality_agent_approval_items')->count());
    }

    public function test_write_fails_closed_without_operator_approval_token(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--operator-approved'] = 'WRONG';

        $exitCode = Artisan::call('personality:agent-approval-queue', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString(
            '--operator-approved=PERSONALITY-AGENT-HUMAN-APPROVAL-QUEUE-01 is required',
            (string) ($payload['errors'][0]['message'] ?? '')
        );
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_failed_qa_and_private_routes_do_not_enter_approval_queue(): void
    {
        $package = $this->validPackage();
        $package['recommendations'][] = $this->recommendation(
            'mbti64-private',
            'https://fermatmind.com/en/results/lookup',
            'en'
        );
        [$packagePath, $qaPath] = $this->writeArtifacts($package, [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-QA-01',
            'final_decision' => 'CONDITIONAL_REQUIRES_EDITORIAL_REPAIR',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/en/personality/enfj-a', 'PASS_READY_FOR_CMS_DRAFT'),
                $this->qaRow('https://fermatmind.com/zh/personality/intp-a', 'NO_GO_BLOCKED_BY_QA', ['claim risk']),
                $this->qaRow('https://fermatmind.com/en/results/lookup', 'PASS_READY_FOR_CMS_DRAFT'),
            ],
        ]);

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(1, $payload['planned_item_count']);
        $this->assertSame(2, $payload['blocked_item_count']);
        $this->assertSame(1, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(1, DB::table('personality_agent_approval_items')->count());
        $this->assertSame(
            'https://fermatmind.com/en/personality/enfj-a',
            (string) DB::table('personality_agent_approval_items')->value('target_url')
        );
    }

    public function test_enneagram_dry_run_accepts_hub_centers_and_core_type_public_asset_paths(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validEnneagramPackage(), $this->validEnneagramQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('enneagram', $payload['framework']);
        $this->assertSame(3, $payload['planned_item_count']);
        $this->assertSame(0, $payload['blocked_item_count']);
        $this->assertSame(0, $payload['created_item_count']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertEquals(
            ['personality_public_content_asset'],
            array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['page_type'],
                $payload['items']
            )))
        );
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_enneagram_write_creates_pending_items_without_cms_or_search_side_effects(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validEnneagramPackage(), $this->validEnneagramQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('enneagram', $payload['framework']);
        $this->assertSame(3, $payload['planned_item_count']);
        $this->assertSame(3, $payload['created_item_count']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);

        $this->assertSame(1, DB::table('personality_agent_approval_batches')->where('framework', 'enneagram')->count());
        $this->assertSame(3, DB::table('personality_agent_approval_items')->where('framework', 'enneagram')->count());
        $this->assertSame(
            ['pending'],
            DB::table('personality_agent_approval_items')->distinct()->pluck('approval_state')->all()
        );
        $this->assertSame(
            ['personality_public_content_asset'],
            DB::table('personality_agent_approval_items')->distinct()->pluck('page_type')->all()
        );
    }

    public function test_enneagram_wing_instinct_and_tritype_urls_fail_closed(): void
    {
        $package = [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-PILOT-01',
            'version' => 'enneagram.agent_pilot.v1',
            'status' => 'pass_ready_for_qa_gates',
            'recommendations' => [
                $this->enneagramRecommendation(
                    'enneagram-agent:/en/personality/enneagram/type-1-wing-9',
                    'https://fermatmind.com/en/personality/enneagram/type-1-wing-9',
                    'en',
                    'wing'
                ),
                $this->enneagramRecommendation(
                    'enneagram-agent:/en/personality/enneagram/instinctual-subtypes',
                    'https://fermatmind.com/en/personality/enneagram/instinctual-subtypes',
                    'en',
                    'instinctual_subtype'
                ),
                $this->enneagramRecommendation(
                    'enneagram-agent:/zh/personality/enneagram/tritype',
                    'https://fermatmind.com/zh/personality/enneagram/tritype',
                    'zh-CN',
                    'tritype'
                ),
            ],
        ];
        [$packagePath, $qaPath] = $this->writeArtifacts($package, [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-QA-01',
            'final_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/en/personality/enneagram/type-1-wing-9', 'PASS_READY_FOR_APPROVAL_QUEUE'),
                $this->qaRow('https://fermatmind.com/en/personality/enneagram/instinctual-subtypes', 'PASS_READY_FOR_APPROVAL_QUEUE'),
                $this->qaRow('https://fermatmind.com/zh/personality/enneagram/tritype', 'PASS_READY_FOR_APPROVAL_QUEUE'),
            ],
        ]);

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(0, $payload['planned_item_count']);
        $this->assertSame(3, $payload['blocked_item_count']);
        $this->assertContains('no_queueable_qa_passed_items', array_column($payload['errors'], 'code'));
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_big_five_dry_run_plans_thirty_four_public_content_asset_items_without_writes(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validBigFivePackage(), $this->validBigFiveQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('big_five', $payload['framework']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(34, $payload['planned_item_count']);
        $this->assertSame(0, $payload['blocked_item_count']);
        $this->assertSame(0, $payload['created_item_count']);
        $this->assertSame('PASS_READY_FOR_APPROVAL_QUEUE', $payload['qa_final_decision']);
        $this->assertEquals(
            ['personality_public_content_asset'],
            array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['page_type'],
                $payload['items']
            )))
        );
        $this->assertEquals(
            ['en', 'zh-CN'],
            collect($payload['items'])->pluck('locale')->unique()->sort()->values()->all()
        );
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_big_five_write_creates_pending_items_only_and_is_idempotent_for_same_hashes(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validBigFivePackage(), $this->validBigFiveQa());
        $options = $this->writeOptions($packagePath, $qaPath);

        $firstExit = Artisan::call('personality:agent-approval-queue', $options);
        $firstPayload = $this->jsonOutput();

        $this->assertSame(0, $firstExit);
        $this->assertTrue($firstPayload['ok']);
        $this->assertSame('big_five', $firstPayload['framework']);
        $this->assertTrue($firstPayload['writes_committed']);
        $this->assertFalse($firstPayload['cms_write_attempted']);
        $this->assertFalse($firstPayload['cms_mutation_attempted']);
        $this->assertFalse($firstPayload['publish_attempted']);
        $this->assertFalse($firstPayload['index_attempted']);
        $this->assertFalse($firstPayload['sitemap_llms_release_attempted']);
        $this->assertFalse($firstPayload['search_release_attempted']);
        $this->assertFalse($firstPayload['enqueue_attempted']);
        $this->assertFalse($firstPayload['external_calls_attempted']);
        $this->assertSame(34, $firstPayload['created_item_count']);
        $this->assertSame(1, DB::table('personality_agent_approval_batches')->where('framework', 'big_five')->count());
        $this->assertSame(34, DB::table('personality_agent_approval_items')->where('framework', 'big_five')->count());
        $this->assertSame(
            ['pending'],
            DB::table('personality_agent_approval_items')->distinct()->pluck('approval_state')->all()
        );
        $this->assertSame(0, DB::table('personality_agent_approval_items')->whereNotNull('approved_at')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->whereNotNull('rejected_at')->count());
        $this->assertSame(
            ['personality_public_content_asset'],
            DB::table('personality_agent_approval_items')->distinct()->pluck('page_type')->all()
        );

        $firstItem = DB::table('personality_agent_approval_items')
            ->where('framework', 'big_five')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($firstItem);
        $this->assertSame('big_five', (string) $firstItem->framework);
        $this->assertSame('pending', (string) $firstItem->approval_state);
        $this->assertNotSame('', (string) $firstItem->recommendation_sha256);
        $this->assertStringStartsWith('https://fermatmind.com/', (string) $firstItem->target_url);
        $this->assertSame('pass', (string) $firstItem->qa_decision);

        $secondExit = Artisan::call('personality:agent-approval-queue', $options);
        $secondPayload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($secondPayload['ok']);
        $this->assertFalse($secondPayload['writes_committed']);
        $this->assertSame(0, $secondPayload['created_item_count']);
        $this->assertSame(34, $secondPayload['skipped_existing_item_count']);
        $this->assertSame(1, DB::table('personality_agent_approval_batches')->where('framework', 'big_five')->count());
        $this->assertSame(34, DB::table('personality_agent_approval_items')->where('framework', 'big_five')->count());
    }

    public function test_big_five_failed_qa_and_private_routes_do_not_enter_approval_queue(): void
    {
        $package = $this->validBigFivePackage();
        $package['recommendations'][0]['target_url'] = 'https://fermatmind.com/en/results/private-big-five';
        $qa = $this->validBigFiveQa();
        foreach ($qa['evaluations'] as $index => $evaluation) {
            if (($evaluation['target_url'] ?? null) === 'https://fermatmind.com/zh/personality/big-five') {
                $qa['evaluations'][$index] = $this->qaEvaluationRow(
                    'https://fermatmind.com/zh/personality/big-five',
                    'failed',
                    ['claim_safety_gate']
                );
                break;
            }
        }
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('big_five', $payload['framework']);
        $this->assertSame(32, $payload['planned_item_count']);
        $this->assertSame(2, $payload['blocked_item_count']);
        $this->assertSame(32, DB::table('personality_agent_approval_items')->where('framework', 'big_five')->count());
        $this->assertSame(
            0,
            DB::table('personality_agent_approval_items')
                ->where('target_url', 'like', '%/results/%')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('personality_agent_approval_items')
                ->where('target_url', 'https://fermatmind.com/zh/personality/big-five')
                ->count()
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function validPackage(): array
    {
        return [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-01',
            'version' => 'mbti64.agent_expansion_88_recommendations.v1',
            'status' => 'pass_ready_for_qa_gates',
            'recommendations' => [
                $this->recommendation(
                    'mbti64-agent-expansion-88:/en/personality/enfj-a',
                    'https://fermatmind.com/en/personality/enfj-a',
                    'en'
                ),
                $this->recommendation(
                    'mbti64-agent-expansion-88:/zh/personality/intp-a',
                    'https://fermatmind.com/zh/personality/intp-a',
                    'zh-CN'
                ),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validEnneagramPackage(): array
    {
        return [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-PILOT-01',
            'version' => 'enneagram.agent_pilot.v1',
            'status' => 'pass_ready_for_qa_gates',
            'recommendations' => [
                $this->enneagramRecommendation(
                    'enneagram-agent:/en/personality/enneagram',
                    'https://fermatmind.com/en/personality/enneagram',
                    'en',
                    'hub'
                ),
                $this->enneagramRecommendation(
                    'enneagram-agent:/zh/personality/enneagram/centers/gut',
                    'https://fermatmind.com/zh/personality/enneagram/centers/gut',
                    'zh-CN',
                    'center'
                ),
                $this->enneagramRecommendation(
                    'enneagram-agent:/en/personality/enneagram/type-1',
                    'https://fermatmind.com/en/personality/enneagram/type-1',
                    'en',
                    'core_type'
                ),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validBigFivePackage(): array
    {
        $paths = [
            'big-five',
            'big-five/agreeableness',
            'big-five/conscientiousness',
            'big-five/emotional-stability',
            'big-five/extraversion',
            'big-five/facets',
            'big-five/high-agreeableness',
            'big-five/high-conscientiousness',
            'big-five/high-extraversion',
            'big-five/high-neuroticism',
            'big-five/high-openness',
            'big-five/low-agreeableness',
            'big-five/low-conscientiousness',
            'big-five/low-extraversion',
            'big-five/low-neuroticism',
            'big-five/low-openness',
            'big-five/openness',
        ];
        $recommendations = [];
        foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $prefix) {
            foreach ($paths as $path) {
                $recommendations[] = $this->bigFiveRecommendation(
                    'big-five-agent:/'.$prefix.'/personality/'.$path,
                    'https://fermatmind.com/'.$prefix.'/personality/'.$path,
                    $locale,
                    $path === 'big-five' ? 'hub' : (str_contains($path, 'high-') || str_contains($path, 'low-') ? 'polarity' : ($path === 'big-five/facets' ? 'facet_hub' : 'domain'))
                );
            }
        }

        return [
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-PILOT-01',
            'version' => 'big_five.public_profile_agent_pilot.v1',
            'status' => 'pass_ready_for_qa_gates',
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validBigFiveQa(): array
    {
        return [
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-QA-01',
            'decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'evaluations' => array_map(
                fn (array $recommendation): array => $this->qaEvaluationRow(
                    (string) $recommendation['target_url'],
                    'pass',
                    []
                ),
                $this->validBigFivePackage()['recommendations']
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validEnneagramQa(): array
    {
        return [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-QA-01',
            'final_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/en/personality/enneagram', 'PASS_READY_FOR_APPROVAL_QUEUE'),
                $this->qaRow('https://fermatmind.com/zh/personality/enneagram/centers/gut', 'PASS_READY_FOR_APPROVAL_QUEUE'),
                $this->qaRow('https://fermatmind.com/en/personality/enneagram/type-1', 'PASS_READY_FOR_APPROVAL_QUEUE'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validQa(): array
    {
        return [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-QA-01',
            'final_decision' => 'PASS_READY_FOR_CMS_DRAFT',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/en/personality/enfj-a', 'PASS_READY_FOR_CMS_DRAFT'),
                $this->qaRow('https://fermatmind.com/zh/personality/intp-a', 'PASS_READY_FOR_CMS_DRAFT'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function nextBatchThreePackage(): array
    {
        return [
            'artifact' => 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-RECOMMENDATIONS-01',
            'status' => 'pass_next_batch_recommendations_ready_for_qa',
            'recommendations' => [
                $this->recommendation(
                    'personality-agent-next-batch:/zh/personality/intp-a',
                    'https://fermatmind.com/zh/personality/intp-a',
                    'zh-CN'
                ),
                $this->recommendation(
                    'personality-agent-next-batch:/zh/personality/esfp-a',
                    'https://fermatmind.com/zh/personality/esfp-a',
                    'zh-CN'
                ),
                $this->recommendation(
                    'personality-agent-next-batch:/en/personality/enfj-a',
                    'https://fermatmind.com/en/personality/enfj-a',
                    'en'
                ),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function nextBatchThreeQa(): array
    {
        return [
            'artifact' => 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-QA-01',
            'final_decision' => 'PASS_READY_FOR_APPROVAL_REVIEW',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/zh/personality/intp-a', 'PASS_READY_FOR_APPROVAL_REVIEW'),
                $this->qaRow('https://fermatmind.com/zh/personality/esfp-a', 'PASS_READY_FOR_APPROVAL_REVIEW'),
                $this->qaRow('https://fermatmind.com/en/personality/enfj-a', 'PASS_READY_FOR_APPROVAL_REVIEW'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function competitorGapExpansionPackage(): array
    {
        return [
            'artifact' => 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-01',
            'status' => 'pass',
            'recommendations' => [
                $this->recommendation(
                    'mbti64-next-batch-6-competitor-gap:/zh/personality/intp-a',
                    'https://fermatmind.com/zh/personality/intp-a',
                    'zh-CN'
                ),
                $this->recommendation(
                    'mbti64-next-batch-6-competitor-gap:/zh/personality/esfp-a',
                    'https://fermatmind.com/zh/personality/esfp-a',
                    'zh-CN'
                ),
                $this->recommendation(
                    'mbti64-next-batch-6-competitor-gap:/en/personality/enfj-a',
                    'https://fermatmind.com/en/personality/enfj-a',
                    'en'
                ),
                $this->recommendation(
                    'mbti64-next-batch-6-competitor-gap:/en/personality/intp-a',
                    'https://fermatmind.com/en/personality/intp-a',
                    'en'
                ),
                $this->recommendation(
                    'mbti64-next-batch-6-competitor-gap:/en/personality/esfp-a',
                    'https://fermatmind.com/en/personality/esfp-a',
                    'en'
                ),
                $this->recommendation(
                    'mbti64-next-batch-6-competitor-gap:/zh/personality/enfj-a',
                    'https://fermatmind.com/zh/personality/enfj-a',
                    'zh-CN'
                ),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function competitorGapExpansionQa(): array
    {
        return [
            'artifact' => 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-QA-01',
            'final_decision' => 'PASS_READY_FOR_EDITORIAL_REVIEW_AND_APPROVAL_QUEUE_REPAIR',
            'page_results' => [
                $this->qaDecisionRow('https://fermatmind.com/zh/personality/intp-a', 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW'),
                $this->qaDecisionRow('https://fermatmind.com/zh/personality/esfp-a', 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW'),
                $this->qaDecisionRow('https://fermatmind.com/en/personality/enfj-a', 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW'),
                $this->qaDecisionRow('https://fermatmind.com/en/personality/intp-a', 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW'),
                $this->qaDecisionRow('https://fermatmind.com/en/personality/esfp-a', 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW'),
                $this->qaDecisionRow('https://fermatmind.com/zh/personality/enfj-a', 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function autoApprovalHandoffPackage(): array
    {
        return [
            'artifact' => 'PERSONALITY-AGENT-AUTO-APPROVAL-HANDOFF-PACKAGE-01',
            'final_decision' => 'PASS_APPROVAL_HANDOFF_PACKAGE_READY_FOR_DRY_RUN',
            'recommendations' => [
                $this->bigFiveRecommendation(
                    'personality-agent-auto-runner:/en/personality/big-five/agreeableness',
                    'https://fermatmind.com/en/personality/big-five/agreeableness',
                    'en',
                    'domain'
                ),
                $this->bigFiveRecommendation(
                    'personality-agent-auto-runner:/en/personality/big-five/facets',
                    'https://fermatmind.com/en/personality/big-five/facets',
                    'en',
                    'facet_hub'
                ),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function autoApprovalHandoffQa(): array
    {
        return [
            'artifact' => 'PERSONALITY-AGENT-AUTO-QA-AND-APPROVAL-HANDOFF-01',
            'final_decision' => 'PASS_READY_FOR_APPROVAL_HANDOFF_DRY_RUN',
            'results' => [
                $this->qaRow('https://fermatmind.com/en/personality/big-five/agreeableness', 'PASS_READY_FOR_APPROVAL_HANDOFF'),
                $this->qaRow('https://fermatmind.com/en/personality/big-five/facets', 'PASS_READY_FOR_APPROVAL_HANDOFF'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function enneagramRecommendation(string $id, string $targetUrl, string $locale, string $entityType): array
    {
        return [
            'recommendation_id' => $id,
            'target_url' => $targetUrl,
            'framework' => 'enneagram',
            'locale' => $locale,
            'entity_type' => $entityType,
            'recommendations' => [
                'title' => 'Enneagram title',
                'description' => 'Enneagram description',
                'h1' => 'Enneagram H1',
                'quick_answer' => 'Reflective Enneagram quick answer.',
                'faq' => [],
                'internal_links' => [],
                'differentiation_notes' => [],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function bigFiveRecommendation(string $id, string $targetUrl, string $locale, string $entityType): array
    {
        return [
            'recommendation_id' => $id,
            'target_url' => $targetUrl,
            'framework' => 'big_five',
            'locale' => $locale,
            'entity_type' => $entityType,
            'recommendations' => [
                'title' => ['recommended' => 'Big Five public profile title'],
                'description' => ['recommended' => 'Big Five public profile description.'],
                'h1' => ['recommended' => 'Big Five public profile H1'],
                'quick_answer' => ['recommended' => 'Reflective Big Five quick answer.'],
                'faq' => [],
                'internal_links' => [],
                'differentiation_notes' => [],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendation(string $id, string $targetUrl, string $locale): array
    {
        return [
            'recommendation_id' => $id,
            'target_url' => $targetUrl,
            'framework' => 'mbti64',
            'locale' => $locale,
            'current_surface' => [
                'title' => 'Current title',
                'description' => 'Current description',
                'h1' => 'Current H1',
            ],
            'recommendations' => [
                'title' => ['recommended' => 'Recommended title | FermatMind'],
                'description' => ['recommended' => 'Recommended description.'],
                'h1' => ['recommended' => 'Recommended H1'],
                'quick_answer' => ['recommended' => 'Recommended quick answer.'],
                'faq' => [],
                'internal_links' => [],
                'differentiation_notes' => [],
            ],
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function qaRow(string $targetUrl, string $decision, array $blockers = []): array
    {
        return [
            'target_url' => $targetUrl,
            'decision' => $decision,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function qaDecisionRow(string $targetUrl, string $decision, array $blockers = []): array
    {
        return [
            'target_url' => $targetUrl,
            'qa_decision' => $decision,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  list<string>  $failedGates
     * @return array<string,mixed>
     */
    private function qaEvaluationRow(string $targetUrl, string $status, array $failedGates = []): array
    {
        return [
            'target_url' => $targetUrl,
            'qa_status' => $status,
            'failed_gates' => $failedGates,
            'eligible_for_approval_queue' => $status === 'pass' && $failedGates === [],
            'eligible_for_cms_draft_path' => $status === 'pass' && $failedGates === [],
        ];
    }

    private function mbti64V85V5PackagePath(): string
    {
        return base_path('docs/seo/personality/mbti64-zh32-en32-v8-5-v5-bilingual-package-2026-07-01.json');
    }

    private function mbti64V85V5QaPath(): string
    {
        return base_path('docs/seo/personality/mbti64-zh32-en32-v8-5-v5-bilingual-qa-2026-07-01.json');
    }

    /**
     * @return array<string,mixed>
     */
    private function mbti64V85V5Package(): array
    {
        return json_decode((string) File::get($this->mbti64V85V5PackagePath()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,mixed>
     */
    private function mbti64V85V5Qa(): array
    {
        return json_decode((string) File::get($this->mbti64V85V5QaPath()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array{0:string,1:string}
     */
    private function writeArtifacts(array $package, array $qa): array
    {
        $dir = storage_path('framework/testing/personality-agent-approval-queue');
        File::ensureDirectoryExists($dir);
        $packagePath = $dir.'/package.json';
        $qaPath = $dir.'/qa.json';
        File::put($packagePath, (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($qaPath, (string) json_encode($qa, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [$packagePath, $qaPath];
    }

    /**
     * @return array<string,mixed>
     */
    private function writeOptions(string $packagePath, string $qaPath): array
    {
        return [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--write' => true,
            '--operator-approved' => 'PERSONALITY-AGENT-HUMAN-APPROVAL-QUEUE-01',
            '--json' => true,
        ];
    }

    /**
     * @return array{framework:string,source_package_sha256:string,qa_sha256:string}
     */
    private function approvalHashes(): array
    {
        $batch = DB::table('personality_agent_approval_batches')->orderByDesc('id')->first();
        $this->assertNotNull($batch);

        return [
            'framework' => (string) $batch->framework,
            'source_package_sha256' => (string) $batch->source_package_sha256,
            'qa_sha256' => (string) $batch->qa_sha256,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  array{framework:string,source_package_sha256:string,qa_sha256:string}  $hashes
     * @return array<string,mixed>
     */
    private function approveOptions(array $ids, array $hashes): array
    {
        return [
            '--approve' => true,
            '--item-ids' => implode(',', $ids),
            '--framework' => $hashes['framework'],
            '--source-package-sha256' => $hashes['source_package_sha256'],
            '--qa-sha256' => $hashes['qa_sha256'],
            '--operator-approved' => 'PERSONALITY-AGENT-APPROVAL-QUEUE-APPROVE-CONTRACT-01',
            '--json' => true,
        ];
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
