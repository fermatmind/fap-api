<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueBoundedLiveExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnneagramIndexNowCanaryBatchTest extends TestCase
{
    use RefreshDatabase;

    private const ARTIFACT_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
            'seo_intel.indexnow_live_api_enabled' => true,
            'seo_intel.search_channel_queue.live_submission.enabled' => true,
            'seo_intel.search_channel_queue.live_submission.external_api_calls_enabled' => true,
            'seo_intel.search_channel_queue.live_submission.allowed_channels' => ['indexnow'],
            'seo_intel.search_channel_queue.live_submission.allowed_hosts' => ['fermatmind.com'],
            'seo_intel.search_channel_queue.live_submission.indexnow.endpoint' => 'https://api.indexnow.test/indexnow',
            'seo_intel.search_channel_queue.live_submission.indexnow.allowed_endpoint_hosts' => ['api.indexnow.test'],
            'seo_intel.search_channel_queue.live_submission.indexnow.key' => 'test-indexnow-key',
            'seo_intel.search_channel_queue.live_submission.indexnow.key_location' => 'https://fermatmind.com/indexnow-key.txt',
            'seo_intel.search_channel_queue.approved_source_authorities' => ['backend_cms'],
            'seo_intel.search_channel_queue.allowed_page_entity_types' => ['personality_public_content_asset'],
            'seo_intel.search_channel_queue.forbidden_page_entity_types' => [],
            'seo_intel.search_channel_queue.forbidden_source_authorities' => [],
        ]);
        DB::purge('seo_intel');
        $this->createTables();
    }

    #[Test]
    public function canary_dry_run_requires_exact_116_and_never_writes_or_calls_indexnow(): void
    {
        $this->seedQueueItems();
        Http::fake();
        $before = $this->counts();

        $result = $this->executor()->submitEnneagramIndexNow('canary', self::ARTIFACT_SHA, null, 'operator', false);

        $this->assertSame('dry_run_ready', $result['status']);
        $this->assertSame(116, $result['target_count']);
        $this->assertSame(1, $result['phase_target_count']);
        $this->assertSame(['pending' => 116, 'submitting' => 0, 'submitted' => 0, 'failed' => 0], $result['state_counts']);
        $this->assertFalse($result['external_calls_attempted']);
        $this->assertSame($before, $this->counts());
        Http::assertNothingSent();
    }

    #[Test]
    public function exact_artifact_bound_canary_submits_one_then_is_idempotent(): void
    {
        $this->seedQueueItems();
        Http::fake(['api.indexnow.test/*' => Http::response('', 202)]);

        $blocked = $this->executor()->submitEnneagramIndexNow('canary', self::ARTIFACT_SHA, 'wrong', 'operator', true);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertContains('artifact_bound_operator_token_mismatch', $blocked['issues']);
        Http::assertNothingSent();

        $result = $this->submitLive('canary');
        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['submitted_count']);
        $this->assertSame(202, $result['provider_http_status']);
        $this->assertSame(1, data_get($result, 'state_counts.submitted'));
        $this->assertSame(115, data_get($result, 'state_counts.pending'));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.indexnow.test/indexnow'
                && count((array) $request['urlList']) === 1
                && $request['key'] === 'test-indexnow-key';
        });

        $again = $this->submitLive('canary');
        $this->assertSame('already_completed', $again['status']);
        $this->assertSame(0, $again['submitted_count']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function batch_requires_successful_canary_then_submits_remaining_115_in_one_request(): void
    {
        $this->seedQueueItems();
        Http::fake(['api.indexnow.test/*' => Http::response('', 202)]);

        $beforeCanary = $this->executor()->submitEnneagramIndexNow('batch', self::ARTIFACT_SHA, null, 'operator', false);
        $this->assertSame('blocked', $beforeCanary['status']);
        $this->assertContains('batch_requires_one_successful_canary', $beforeCanary['issues']);

        $this->assertSame('success', $this->submitLive('canary')['status']);
        $dryRun = $this->executor()->submitEnneagramIndexNow('batch', self::ARTIFACT_SHA, null, 'operator', false);
        $this->assertSame('dry_run_ready', $dryRun['status']);
        $this->assertSame(115, $dryRun['phase_target_count']);

        $batch = $this->submitLive('batch');
        $this->assertSame('success', $batch['status']);
        $this->assertSame(115, $batch['submitted_count']);
        $this->assertSame(116, data_get($batch, 'state_counts.submitted'));
        $this->assertSame(0, data_get($batch, 'state_counts.pending'));
        $this->assertSame(0, data_get($batch, 'state_counts.failed'));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_indexnow_submissions')->count());

        $requestSizes = [];
        Http::assertSent(function (Request $request) use (&$requestSizes): bool {
            $requestSizes[] = count((array) $request['urlList']);

            return true;
        });
        $this->assertSame([1, 115], $requestSizes);

        $again = $this->submitLive('batch');
        $this->assertSame('already_completed', $again['status']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function invalid_count_hash_private_url_or_provider_failure_stays_fail_closed(): void
    {
        $this->seedQueueItems();
        Http::fake(['api.indexnow.test/*' => Http::response('', 500)]);
        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', 1)->update([
            'url_hash' => str_repeat('0', 64),
        ]);

        $invalid = $this->submitLive('canary');
        $this->assertSame('blocked', $invalid['status']);
        $this->assertContains('queue_url_hash_invalid', $invalid['issues']);
        Http::assertNothingSent();

        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', 1)->update([
            'url_hash' => hash('sha256', 'https://fermatmind.com/en/personality/enneagram/test-001'),
        ]);
        $failed = $this->submitLive('canary');
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(1, $failed['failed_count']);
        $this->assertSame(1, data_get($failed, 'state_counts.failed'));

        $batch = $this->submitLive('batch');
        $this->assertSame('blocked', $batch['status']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function test_retired_manual_workflow_cannot_be_reintroduced(): void
    {
        $this->assertFileDoesNotExist(base_path('../.github/workflows/enneagram-search-indexnow-production-submit.yml'));
        $this->assertStringContainsString('batch_requires_one_successful_canary', file_get_contents(base_path('app/Services/SeoIntel/SearchChannelQueue/SearchChannelQueueBoundedLiveExecutor.php')));
    }

    /** @return array<string, mixed> */
    private function submitLive(string $phase): array
    {
        return $this->executor()->submitEnneagramIndexNow(
            $phase,
            self::ARTIFACT_SHA,
            'ENNEAGRAM-SEARCH-INDEXNOW-SUBMIT-01:'.$phase.':'.self::ARTIFACT_SHA,
            'operator',
            true,
        );
    }

    private function executor(): SearchChannelQueueBoundedLiveExecutor
    {
        return app(SearchChannelQueueBoundedLiveExecutor::class);
    }

    private function seedQueueItems(): void
    {
        for ($index = 1; $index <= 116; $index++) {
            $url = sprintf('https://fermatmind.com/en/personality/enneagram/test-%03d', $index);
            DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert([
                'batch_id' => 1,
                'canonical_url' => $url,
                'locale' => $index <= 58 ? 'en' : 'zh-CN',
                'page_entity_type' => 'personality_public_content_asset',
                'entity_type' => 'personality_public_content_asset',
                'entity_id' => 'test-'.$index,
                'source_authority' => 'backend_cms',
                'source_table' => 'personality_public_content_assets',
                'channel' => 'indexnow',
                'eligibility_state' => 'eligible',
                'approval_state' => 'pending',
                'execution_state' => 'dry_run_ready',
                'indexability_state' => 'indexable',
                'claim_boundary_state' => 'claim_safe',
                'private_flow' => false,
                'reason_codes' => '[]',
                'lastmod' => now()->subHour(),
                'content_hash' => hash('sha256', 'content-'.$index),
                'url_hash' => hash('sha256', $url),
                'idempotency_key' => hash('sha256', 'indexnow|'.$url),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createTables(): void
    {
        Schema::connection('seo_intel')->create('seo_search_channel_queue_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 255)->nullable();
            $table->string('source_authority', 64);
            $table->string('source_table', 128)->nullable();
            $table->string('channel', 64);
            $table->string('eligibility_state', 64);
            $table->string('approval_state', 64);
            $table->string('execution_state', 64);
            $table->string('indexability_state', 64);
            $table->string('claim_boundary_state', 64);
            $table->boolean('private_flow');
            $table->json('reason_codes')->nullable();
            $table->timestamp('lastmod')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->char('url_hash', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('seo_intel')->create('seo_search_channel_queue_events', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('queue_item_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('event_type', 96);
            $table->json('event_payload')->nullable();
            $table->string('actor_type', 64);
            $table->string('actor_id', 128)->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::connection('seo_intel')->create('seo_indexnow_submissions', function ($table): void {
            $table->id();
            $table->timestamps();
        });
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'items' => DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count(),
            'events' => DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count(),
            'legacy_submissions' => DB::connection('seo_intel')->table('seo_indexnow_submissions')->count(),
        ];
    }
}
