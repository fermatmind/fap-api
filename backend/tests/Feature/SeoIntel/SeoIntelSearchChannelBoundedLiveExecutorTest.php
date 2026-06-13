<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueBoundedLiveExecutor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelBoundedLiveExecutorTest extends TestCase
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
            'seo_intel.indexnow_live_api_enabled' => false,
            'seo_intel.baidu_live_api_enabled' => false,
            'seo_intel.search_channel_queue.live_submission.enabled' => false,
            'seo_intel.search_channel_queue.live_submission.external_api_calls_enabled' => false,
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
    public function default_dry_run_accepts_only_approved_dry_run_ready_items_without_global_live_gates(): void
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

        [$exitCode, $payload] = $this->runBoundedCommand([
            '--queue-ids' => $indexnowId.','.$baiduId,
            '--channels' => 'indexnow,baidu_push',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertFalse((bool) data_get($payload, 'safety_flags.global_live_gates_required'));
        $this->assertFalse((bool) ($payload['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        $this->assertSame(
            'I explicitly approve SEARCH-CHANNEL-BOUNDED-LIVE-EXECUTOR live submission for queue items '.$indexnowId.','.$baiduId.' channels indexnow,baidu_push.',
            $payload['approval_phrase'] ?? null,
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($payload['approval_token'] ?? ''));
        $this->assertCount(2, $payload['items'] ?? []);
        Http::assertNothingSent();
    }

    #[Test]
    public function dry_run_rejects_pending_or_already_submitted_items(): void
    {
        Http::fake();
        $pendingId = $this->seedQueueItem(['approval_state' => 'pending']);
        $submittedId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/en/articles/already-submitted',
            'execution_state' => 'submitted',
        ]);

        [$exitCode, $payload] = $this->runBoundedCommand([
            '--queue-ids' => $pendingId.','.$submittedId,
            '--channels' => 'indexnow',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('approval_state_not_approved', $payload['issues'] ?? []);
        $this->assertContains('queue_item_already_submitted_requeue_required', $payload['issues'] ?? []);
        Http::assertNothingSent();
    }

    #[Test]
    public function live_mode_requires_exact_phrase_or_token_and_approved_queue_state(): void
    {
        Http::fake();
        $queueItemId = $this->seedQueueItem();

        [$exitCode, $payload] = $this->runBoundedCommand([
            '--queue-ids' => (string) $queueItemId,
            '--channels' => 'indexnow',
            '--live' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('bounded_live_approval_required', $payload['issues'] ?? []);
        $this->assertFalse((bool) ($payload['external_calls_attempted'] ?? true));
        $this->assertSame('approved', DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $queueItemId)->value('approval_state'));
        $this->assertSame('dry_run_ready', DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $queueItemId)->value('execution_state'));
        Http::assertNothingSent();
    }

    #[Test]
    public function live_mode_submits_indexnow_and_baidu_without_global_live_gates_and_logs_sanitized_events(): void
    {
        Http::fake([
            'api.indexnow.test/*' => Http::response('', 202),
            'data.zz.baidu.test/*' => Http::response(['success' => 1, 'remain' => 99], 200),
        ]);

        $indexnowId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/en/articles/choose-career-using-personality-tests',
            'channel' => 'indexnow',
        ]);
        $baiduId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/zh/articles/career-confusion-test-map',
            'channel' => 'baidu_push',
        ]);
        $approvalPhrase = app(SearchChannelQueueBoundedLiveExecutor::class)
            ->approvalPhrase([$indexnowId, $baiduId], ['indexnow', 'baidu_push']);
        $approvalToken = hash('sha256', $approvalPhrase);

        [$exitCode, $payload, $rawOutput] = $this->runBoundedCommand([
            '--queue-ids' => $indexnowId.','.$baiduId,
            '--channels' => 'indexnow,baidu_push',
            '--approval-token' => $approvalToken,
            '--actor' => 'seo-ops@example.com',
            '--live' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertFalse((bool) ($payload['dry_run'] ?? true));
        $this->assertTrue((bool) ($payload['external_calls_attempted'] ?? false));
        $this->assertTrue((bool) ($payload['search_submission_attempted'] ?? false));
        $this->assertTrue((bool) ($payload['writes_committed'] ?? false));
        $this->assertStringNotContainsString('secret-indexnow-key', $rawOutput);
        $this->assertStringNotContainsString('secret-baidu-token', $rawOutput);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.indexnow.test/indexnow'
                && $request['key'] === 'secret-indexnow-key'
                && $request['urlList'] === ['https://fermatmind.com/en/articles/choose-career-using-personality-tests'];
        });
        Http::assertSent(function (Request $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return parse_url($request->url(), PHP_URL_HOST) === 'data.zz.baidu.test'
                && $query['site'] === 'https://fermatmind.com'
                && $query['token'] === 'secret-baidu-token'
                && $request->body() === 'https://fermatmind.com/zh/articles/career-confusion-test-map';
        });

        $items = DB::connection('seo_intel')
            ->table('seo_search_channel_queue_items')
            ->whereIn('id', [$indexnowId, $baiduId])
            ->orderBy('id')
            ->get();
        $this->assertSame(['submitted', 'submitted'], $items->pluck('execution_state')->all());
        $this->assertSame(['approved', 'approved'], $items->pluck('approval_state')->all());

        $events = DB::connection('seo_intel')->table('seo_search_channel_queue_events')->orderBy('id')->get();
        $this->assertSame([
            'bounded_live_submission_started',
            'bounded_live_submission_response',
            'bounded_live_submission_started',
            'bounded_live_submission_response',
        ], $events->pluck('event_type')->all());

        foreach ($events as $event) {
            $this->assertStringNotContainsString('secret-indexnow-key', (string) $event->event_payload);
            $this->assertStringNotContainsString('secret-baidu-token', (string) $event->event_payload);
            $this->assertStringNotContainsString('https://fermatmind.com/en/articles/choose-career-using-personality-tests', (string) $event->event_payload);
            $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/career-confusion-test-map', (string) $event->event_payload);
        }

        [$secondExitCode, $secondPayload] = $this->runBoundedCommand([
            '--queue-ids' => (string) $indexnowId,
            '--channels' => 'indexnow',
            '--approval-phrase' => app(SearchChannelQueueBoundedLiveExecutor::class)->approvalPhrase([$indexnowId], ['indexnow']),
            '--live' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $secondExitCode);
        $this->assertSame('blocked', $secondPayload['status'] ?? null);
        $this->assertContains('queue_item_already_submitted_requeue_required', $secondPayload['issues'] ?? []);
        Http::assertSentCount(2);
    }

    #[Test]
    public function baidu_site_initialization_failure_marks_platform_action_required_without_auto_retry(): void
    {
        Http::fake([
            'data.zz.baidu.test/*' => Http::response([
                'error' => 400,
                'message' => 'site not initialized for https://fermatmind.com token secret-baidu-token',
            ], 400),
        ]);

        $queueItemId = $this->seedQueueItem([
            'canonical_url' => 'https://fermatmind.com/zh/articles/career-confusion-test-map',
            'channel' => 'baidu_push',
        ]);
        $approvalPhrase = app(SearchChannelQueueBoundedLiveExecutor::class)
            ->approvalPhrase([$queueItemId], ['baidu_push']);

        [$exitCode, $payload] = $this->runBoundedCommand([
            '--queue-ids' => (string) $queueItemId,
            '--channels' => 'baidu_push',
            '--approval-phrase' => $approvalPhrase,
            '--live' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', $payload['status'] ?? null);
        $this->assertContains('platform_action_required', $payload['issues'] ?? []);
        $this->assertSame('platform_action_required', data_get($payload, 'items.0.execution_state'));
        $this->assertSame('site not initialized for [redacted] token [redacted]', data_get($payload, 'items.0.provider_error_message'));
        $this->assertSame('platform_action_required', DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $queueItemId)->value('execution_state'));

        [$secondExitCode, $secondPayload] = $this->runBoundedCommand([
            '--queue-ids' => (string) $queueItemId,
            '--channels' => 'baidu_push',
            '--approval-phrase' => $approvalPhrase,
            '--live' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $secondExitCode);
        $this->assertSame('blocked', $secondPayload['status'] ?? null);
        $this->assertContains('execution_state_not_dry_run_ready', $secondPayload['issues'] ?? []);
        Http::assertSentCount(1);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0:int,1:array<string,mixed>,2:string}
     */
    private function runBoundedCommand(array $arguments): array
    {
        $exitCode = Artisan::call('seo-intel:search-channel-submit-approved', $arguments);
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
        $canonicalUrl = (string) ($overrides['canonical_url'] ?? 'https://fermatmind.com/en');
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
            'approval_state' => $overrides['approval_state'] ?? 'approved',
            'execution_state' => $overrides['execution_state'] ?? 'dry_run_ready',
            'indexability_state' => $overrides['indexability_state'] ?? 'indexable',
            'claim_boundary_state' => $overrides['claim_boundary_state'] ?? 'claim_safe',
            'private_flow' => (bool) ($overrides['private_flow'] ?? false),
            'reason_codes' => $overrides['reason_codes'] ?? null,
            'lastmod' => $now,
            'content_hash' => hash('sha256', $canonicalUrl),
            'url_hash' => hash('sha256', $canonicalUrl),
            'idempotency_key' => hash('sha256', $canonicalUrl.'|'.$channel),
            'approved_by' => $overrides['approved_by'] ?? 'operator',
            'approved_at' => $overrides['approved_at'] ?? $now,
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
