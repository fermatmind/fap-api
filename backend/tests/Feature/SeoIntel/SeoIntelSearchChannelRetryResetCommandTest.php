<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueRetryResetter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelRetryResetCommandTest extends TestCase
{
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
            'seo_intel.search_channel_queue.live_submission.allowed_channels' => ['indexnow', 'baidu_push'],
        ]);

        DB::purge('seo_intel');
        $this->createSeoIntelTables();
    }

    #[Test]
    public function dry_run_accepts_only_approved_baidu_submit_failed_items_with_latest_over_quota_failure(): void
    {
        Http::fake();
        $queueItemId = $this->seedQueueItem();
        $this->seedBaiduFailureEvent($queueItemId, 'over quota');

        [$exitCode, $payload] = $this->runRetryResetCommand([
            '--queue-ids' => (string) $queueItemId,
            '--channels' => 'baidu_push',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertFalse((bool) ($payload['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        $this->assertSame(
            'I explicitly approve SEARCH-CHANNEL-QUEUE-RETRY-RESET reset for queue items '.$queueItemId.' channels baidu_push reason provider_quota_reset.',
            $payload['approval_phrase'] ?? null,
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($payload['approval_token'] ?? ''));
        $this->assertSame('submit_failed', DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $queueItemId)->value('execution_state'));
        Http::assertNothingSent();
    }

    #[Test]
    public function execute_requires_exact_approval_and_resets_to_dry_run_ready_with_audit_event(): void
    {
        Http::fake();
        $queueItemId = $this->seedQueueItem();
        $this->seedBaiduFailureEvent($queueItemId, 'over quota');
        $approvalPhrase = app(SearchChannelQueueRetryResetter::class)
            ->approvalPhrase([$queueItemId], ['baidu_push'], 'provider_quota_reset');

        [$exitCode, $payload] = $this->runRetryResetCommand([
            '--queue-ids' => (string) $queueItemId,
            '--channels' => 'baidu_push',
            '--approval-phrase' => $approvalPhrase,
            '--actor' => 'seo-ops@example.com',
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertFalse((bool) ($payload['dry_run'] ?? true));
        $this->assertTrue((bool) ($payload['writes_committed'] ?? false));
        $this->assertFalse((bool) ($payload['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['search_submission_attempted'] ?? true));
        $this->assertSame('dry_run_ready', DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $queueItemId)->value('execution_state'));

        $event = DB::connection('seo_intel')->table('seo_search_channel_queue_events')->where('event_type', 'search_channel_queue_retry_reset')->first();
        $this->assertNotNull($event);
        $this->assertSame($queueItemId, (int) $event->queue_item_id);
        $this->assertSame('operator', $event->actor_type);
        $this->assertSame('seo-ops@example.com', $event->actor_id);
        $this->assertStringContainsString('provider_quota_reset', (string) $event->event_payload);
        Http::assertNothingSent();
    }

    #[Test]
    public function execute_blocks_without_exact_approval_and_without_external_calls_or_writes(): void
    {
        Http::fake();
        $queueItemId = $this->seedQueueItem();
        $this->seedBaiduFailureEvent($queueItemId, 'over quota');

        [$exitCode, $payload] = $this->runRetryResetCommand([
            '--queue-ids' => (string) $queueItemId,
            '--channels' => 'baidu_push',
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('bounded_retry_reset_approval_required', $payload['issues'] ?? []);
        $this->assertSame('submit_failed', DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $queueItemId)->value('execution_state'));
        Http::assertNothingSent();
    }

    #[Test]
    public function dry_run_rejects_non_quota_failures_and_submitted_items(): void
    {
        Http::fake();
        $siteInitId = $this->seedQueueItem();
        $this->seedBaiduFailureEvent($siteInitId, 'site init fail');
        $submittedId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/zh/articles/submitted',
            'execution_state' => 'submitted',
        ]);
        $this->seedBaiduFailureEvent($submittedId, 'over quota');

        [$exitCode, $payload] = $this->runRetryResetCommand([
            '--queue-ids' => $siteInitId.','.$submittedId,
            '--channels' => 'baidu_push',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('latest_failure_not_provider_quota_exhausted', $payload['issues'] ?? []);
        $this->assertContains('execution_state_not_submit_failed', $payload['issues'] ?? []);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0:int,1:array<string,mixed>,2:string}
     */
    private function runRetryResetCommand(array $arguments): array
    {
        $exitCode = Artisan::call('seo-intel:search-channel-retry-reset', $arguments);
        $rawOutput = trim(Artisan::output());

        $this->assertNotSame('', $rawOutput);
        $payload = json_decode($rawOutput, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return [$exitCode, $payload, $rawOutput];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedQueueItem(array $overrides = []): int
    {
        $canonicalUrl = (string) ($overrides['canonical_url'] ?? 'https://fermatmind.com/zh/articles/iq-test-score-and-limits-explained');
        $now = now();

        return (int) DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insertGetId([
            'batch_id' => $overrides['batch_id'] ?? 1,
            'canonical_url' => $canonicalUrl,
            'locale' => $overrides['locale'] ?? 'zh',
            'page_entity_type' => $overrides['page_entity_type'] ?? 'article',
            'entity_type' => $overrides['entity_type'] ?? 'article',
            'entity_id' => $overrides['entity_id'] ?? 'article:fixture',
            'source_authority' => $overrides['source_authority'] ?? 'backend_cms',
            'source_table' => $overrides['source_table'] ?? 'cms_articles',
            'channel' => $overrides['channel'] ?? 'baidu_push',
            'eligibility_state' => $overrides['eligibility_state'] ?? 'eligible',
            'approval_state' => $overrides['approval_state'] ?? 'approved',
            'execution_state' => $overrides['execution_state'] ?? 'submit_failed',
            'indexability_state' => $overrides['indexability_state'] ?? 'indexable',
            'claim_boundary_state' => $overrides['claim_boundary_state'] ?? 'claim_safe',
            'private_flow' => (bool) ($overrides['private_flow'] ?? false),
            'reason_codes' => $overrides['reason_codes'] ?? null,
            'lastmod' => $now,
            'content_hash' => hash('sha256', $canonicalUrl),
            'url_hash' => hash('sha256', $canonicalUrl),
            'idempotency_key' => hash('sha256', $canonicalUrl.'|'.($overrides['channel'] ?? 'baidu_push')),
            'approved_by' => $overrides['approved_by'] ?? 'operator',
            'approved_at' => $overrides['approved_at'] ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedBaiduFailureEvent(int $queueItemId, string $message): void
    {
        DB::connection('seo_intel')->table('seo_search_channel_queue_events')->insert([
            'queue_item_id' => $queueItemId,
            'batch_id' => 1,
            'event_type' => 'bounded_live_submission_response',
            'event_payload' => json_encode([
                'channel' => 'baidu_push',
                'url_hash' => hash('sha256', 'fixture'),
                'endpoint_host' => 'data.zz.baidu.test',
                'http_status' => 400,
                'submission_status' => 'failed',
                'execution_state' => 'submit_failed',
                'exception_class' => null,
                'provider_error_code' => '400',
                'provider_error_message' => $message,
            ], JSON_THROW_ON_ERROR),
            'actor_type' => 'system',
            'actor_id' => 'seo-intel:search-channel-submit-approved',
            'created_at' => now(),
        ]);
    }

    private function createSeoIntelTables(): void
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
            $table->string('eligibility_state', 64)->default('eligible');
            $table->string('approval_state', 64)->default('pending');
            $table->string('execution_state', 64)->default('dry_run_ready');
            $table->string('indexability_state', 64);
            $table->string('claim_boundary_state', 64)->default('claim_safe');
            $table->boolean('private_flow')->default(false);
            $table->json('reason_codes')->nullable();
            $table->timestamp('lastmod')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->char('url_hash', 64);
            $table->char('idempotency_key', 64);
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
            $table->string('actor_type', 64)->default('system');
            $table->string('actor_id', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
