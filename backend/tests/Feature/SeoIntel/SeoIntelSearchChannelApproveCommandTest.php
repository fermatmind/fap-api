<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueApprovalExecutor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelApproveCommandTest extends TestCase
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
            'seo_intel.search_channel_queue.live_submission.allowed_hosts' => ['fermatmind.com'],
            'seo_intel.search_channel_queue.live_submission.baidu.endpoint' => 'https://data.zz.baidu.test/urls',
            'seo_intel.search_channel_queue.live_submission.baidu.site' => 'https://fermatmind.com',
            'seo_intel.search_channel_queue.live_submission.baidu.token' => 'secret-baidu-token',
            'seo_intel.search_channel_queue.live_submission.indexnow.endpoint' => 'https://api.indexnow.test/indexnow',
            'seo_intel.search_channel_queue.live_submission.indexnow.key' => 'secret-indexnow-key',
            'seo_intel.search_channel_queue.live_submission.indexnow.key_location' => 'https://fermatmind.com/indexnow.txt',
            'seo_intel.search_channel_queue.approved_source_authorities' => [
                'backend_cms',
                'backend_public_surface',
                'scale_catalog',
            ],
        ]);

        DB::purge('seo_intel');
        $this->createSeoIntelTables();
    }

    #[Test]
    public function default_dry_run_accepts_pending_dry_run_ready_items_without_writes_or_external_calls(): void
    {
        Http::fake();
        $indexnowId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/en/articles/choose-career-using-personality-tests',
            'channel' => 'indexnow',
        ]);
        $baiduId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/zh/articles/career-confusion-test-map',
            'channel' => 'baidu_push',
        ]);

        [$exitCode, $payload] = $this->runApproveCommand([
            '--queue-ids' => $indexnowId.','.$baiduId,
            '--channels' => 'indexnow,baidu_push',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertSame(
            'I explicitly approve SEARCH-CHANNEL-QUEUE-APPROVE approval for queue items '.$indexnowId.','.$baiduId.' channels indexnow,baidu_push.',
            $payload['approval_phrase'] ?? null,
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($payload['approval_token'] ?? ''));
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        $this->assertFalse((bool) ($payload['external_calls_attempted'] ?? true));
        $this->assertSame('pending', DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $indexnowId)->value('approval_state'));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function approve_mode_requires_exact_phrase_or_token(): void
    {
        Http::fake();
        $queueItemId = $this->seedQueueItem();

        [$exitCode, $payload] = $this->runApproveCommand([
            '--queue-ids' => (string) $queueItemId,
            '--channels' => 'indexnow',
            '--approve' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('bounded_queue_approval_required', $payload['issues'] ?? []);
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        $this->assertSame('pending', DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $queueItemId)->value('approval_state'));
        Http::assertNothingSent();
    }

    #[Test]
    public function approve_mode_marks_items_approved_and_logs_sanitized_events(): void
    {
        Http::fake();
        $indexnowId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/en/articles/choose-career-using-personality-tests',
            'channel' => 'indexnow',
        ]);
        $baiduId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/zh/articles/career-confusion-test-map',
            'channel' => 'baidu_push',
        ]);
        $approvalPhrase = app(SearchChannelQueueApprovalExecutor::class)
            ->approvalPhrase([$indexnowId, $baiduId], ['indexnow', 'baidu_push']);

        [$exitCode, $payload, $rawOutput] = $this->runApproveCommand([
            '--queue-ids' => $indexnowId.','.$baiduId,
            '--channels' => 'indexnow,baidu_push',
            '--approval-phrase' => $approvalPhrase,
            '--actor' => 'seo-ops@example.com',
            '--approve' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertFalse((bool) ($payload['dry_run'] ?? true));
        $this->assertTrue((bool) ($payload['writes_committed'] ?? false));
        $this->assertFalse((bool) ($payload['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['search_submission_attempted'] ?? true));
        $this->assertStringNotContainsString('secret-indexnow-key', $rawOutput);
        $this->assertStringNotContainsString('secret-baidu-token', $rawOutput);

        $items = DB::connection('seo_intel')
            ->table('seo_search_channel_queue_items')
            ->whereIn('id', [$indexnowId, $baiduId])
            ->orderBy('id')
            ->get();
        $this->assertSame(['approved', 'approved'], $items->pluck('approval_state')->all());
        $this->assertSame(['dry_run_ready', 'dry_run_ready'], $items->pluck('execution_state')->all());
        $this->assertSame(['seo-ops@example.com', 'seo-ops@example.com'], $items->pluck('approved_by')->all());
        $this->assertNotNull($items[0]->approved_at);
        $this->assertNotNull($items[1]->approved_at);

        $events = DB::connection('seo_intel')->table('seo_search_channel_queue_events')->orderBy('id')->get();
        $this->assertSame(['search_channel_queue_approved', 'search_channel_queue_approved'], $events->pluck('event_type')->all());

        foreach ($events as $event) {
            $this->assertStringNotContainsString('secret-indexnow-key', (string) $event->event_payload);
            $this->assertStringNotContainsString('secret-baidu-token', (string) $event->event_payload);
            $this->assertStringNotContainsString('https://fermatmind.com/en/articles/choose-career-using-personality-tests', (string) $event->event_payload);
            $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/career-confusion-test-map', (string) $event->event_payload);
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function approved_items_are_accepted_by_bounded_live_executor_dry_run(): void
    {
        Http::fake();
        $queueItemId = $this->seedQueueItem();
        $approvalToken = hash('sha256', app(SearchChannelQueueApprovalExecutor::class)->approvalPhrase([$queueItemId], ['indexnow']));

        [$approveExitCode, $approvePayload] = $this->runApproveCommand([
            '--queue-ids' => (string) $queueItemId,
            '--channels' => 'indexnow',
            '--approval-token' => $approvalToken,
            '--approve' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $approveExitCode);
        $this->assertSame('success', $approvePayload['status'] ?? null);

        $exitCode = Artisan::call('seo-intel:search-channel-submit-approved', [
            '--queue-ids' => (string) $queueItemId,
            '--channels' => 'indexnow',
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertSame([], $payload['issues'] ?? null);
        Http::assertNothingSent();
    }

    #[Test]
    public function command_rejects_already_approved_private_or_non_indexable_items(): void
    {
        Http::fake();
        $approvedId = $this->seedQueueItem([
            'approval_state' => 'approved',
            'approved_by' => 'operator',
            'approved_at' => now(),
        ]);
        $privateId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/en/private-flow',
            'private_flow' => true,
        ]);
        $noindexId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/en/noindex',
            'indexability_state' => 'noindex',
        ]);

        [$exitCode, $payload] = $this->runApproveCommand([
            '--queue-ids' => $approvedId.','.$privateId.','.$noindexId,
            '--channels' => 'indexnow',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('approval_state_not_pending', $payload['issues'] ?? []);
        $this->assertContains('private_flow_rejected', $payload['issues'] ?? []);
        $this->assertContains('non_indexable_rejected', $payload['issues'] ?? []);
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0:int,1:array<string,mixed>,2:string}
     */
    private function runApproveCommand(array $arguments): array
    {
        $exitCode = Artisan::call('seo-intel:search-channel-approve', $arguments);
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
        $canonicalUrl = (string) ($overrides['canonical_url'] ?? 'https://fermatmind.com/en/articles/search-channel-fixture');
        $channel = (string) ($overrides['channel'] ?? 'indexnow');
        $now = now();

        return (int) DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insertGetId([
            'batch_id' => $overrides['batch_id'] ?? 1,
            'canonical_url' => $canonicalUrl,
            'locale' => $overrides['locale'] ?? 'en',
            'page_entity_type' => $overrides['page_entity_type'] ?? 'article',
            'entity_type' => $overrides['entity_type'] ?? 'article',
            'entity_id' => $overrides['entity_id'] ?? 'article:fixture',
            'source_authority' => $overrides['source_authority'] ?? 'backend_cms',
            'source_table' => $overrides['source_table'] ?? 'cms_articles',
            'channel' => $channel,
            'eligibility_state' => $overrides['eligibility_state'] ?? 'eligible',
            'approval_state' => $overrides['approval_state'] ?? 'pending',
            'execution_state' => $overrides['execution_state'] ?? 'dry_run_ready',
            'indexability_state' => $overrides['indexability_state'] ?? 'indexable',
            'claim_boundary_state' => $overrides['claim_boundary_state'] ?? 'claim_safe',
            'private_flow' => (bool) ($overrides['private_flow'] ?? false),
            'reason_codes' => $overrides['reason_codes'] ?? null,
            'lastmod' => $now,
            'content_hash' => hash('sha256', $canonicalUrl),
            'url_hash' => hash('sha256', $canonicalUrl),
            'idempotency_key' => hash('sha256', $canonicalUrl.'|'.$channel),
            'approved_by' => $overrides['approved_by'] ?? null,
            'approved_at' => $overrides['approved_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
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
