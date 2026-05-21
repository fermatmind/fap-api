<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueLiveSubmissionExecutor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelLiveSubmissionExecutorTest extends TestCase
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
            'seo_intel.search_channel_queue.live_submission.enabled' => false,
            'seo_intel.search_channel_queue.live_submission.external_api_calls_enabled' => false,
            'seo_intel.search_channel_queue.live_submission.allowed_channels' => ['indexnow'],
            'seo_intel.search_channel_queue.live_submission.allowed_hosts' => ['fermatmind.com'],
            'seo_intel.search_channel_queue.live_submission.indexnow.endpoint' => 'https://api.indexnow.test/indexnow',
            'seo_intel.search_channel_queue.live_submission.indexnow.key' => null,
            'seo_intel.search_channel_queue.live_submission.indexnow.key_location' => null,
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
    public function dry_run_outputs_exact_phrase_without_external_call_or_writes(): void
    {
        Http::fake();
        $queueItemId = $this->seedQueueItem();

        [$exitCode, $payload] = $this->runSubmitCommand([
            '--queue-item-id' => $queueItemId,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertSame(
            'I explicitly approve SEARCH-CHANNEL-LIVE-02 live submission for queue item '.$queueItemId.' channel indexnow URL https://fermatmind.com/en.',
            $payload['approval_phrase'] ?? null,
        );
        $this->assertFalse((bool) ($payload['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));

        $item = DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $queueItemId)->first();
        $this->assertSame('pending', $item->approval_state);
        $this->assertSame('dry_run_ready', $item->execution_state);
        $this->assertNull($item->approved_by);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function live_submission_requires_exact_phrase_and_all_gates(): void
    {
        Http::fake();
        $queueItemId = $this->seedQueueItem();

        [$exitCode, $payload] = $this->runSubmitCommand([
            '--queue-item-id' => $queueItemId,
            '--approval-phrase' => 'wrong approval phrase',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('approval_phrase_mismatch', $payload['issues'] ?? []);
        $this->assertContains('live_submission_gate_disabled', $payload['issues'] ?? []);
        $this->assertContains('external_api_gate_disabled', $payload['issues'] ?? []);
        $this->assertContains('indexnow_live_api_disabled', $payload['issues'] ?? []);
        $this->assertContains('indexnow_key_missing', $payload['issues'] ?? []);
        $this->assertContains('indexnow_key_location_missing', $payload['issues'] ?? []);
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function approved_indexnow_submission_updates_queue_and_logs_sanitized_events(): void
    {
        config([
            'seo_intel.indexnow_live_api_enabled' => true,
            'seo_intel.search_channel_queue.live_submission.enabled' => true,
            'seo_intel.search_channel_queue.live_submission.external_api_calls_enabled' => true,
            'seo_intel.search_channel_queue.live_submission.indexnow.key' => 'secret-indexnow-key',
            'seo_intel.search_channel_queue.live_submission.indexnow.key_location' => 'https://fermatmind.com/indexnow.txt',
        ]);

        Http::fake([
            'api.indexnow.test/*' => Http::response('', 202),
        ]);

        $queueItemId = $this->seedQueueItem();
        $approvalPhrase = app(SearchChannelQueueLiveSubmissionExecutor::class)
            ->approvalPhrase($queueItemId, 'indexnow', 'https://fermatmind.com/en');

        [$exitCode, $payload, $rawOutput] = $this->runSubmitCommand([
            '--queue-item-id' => $queueItemId,
            '--approval-phrase' => $approvalPhrase,
            '--actor' => 'seo-ops@example.com',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['external_calls_attempted'] ?? false));
        $this->assertTrue((bool) ($payload['search_submission_attempted'] ?? false));
        $this->assertSame('accepted', $payload['submission_status'] ?? null);
        $this->assertSame('submitted', $payload['execution_state'] ?? null);
        $this->assertSame(202, $payload['http_status'] ?? null);
        $this->assertStringNotContainsString('secret-indexnow-key', $rawOutput);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.indexnow.test/indexnow'
                && $request['host'] === 'fermatmind.com'
                && $request['urlList'] === ['https://fermatmind.com/en']
                && $request['key'] === 'secret-indexnow-key'
                && $request['keyLocation'] === 'https://fermatmind.com/indexnow.txt';
        });

        $item = DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $queueItemId)->first();
        $this->assertSame('approved', $item->approval_state);
        $this->assertSame('submitted', $item->execution_state);
        $this->assertSame('seo-ops@example.com', $item->approved_by);
        $this->assertNotNull($item->approved_at);

        $events = DB::connection('seo_intel')
            ->table('seo_search_channel_queue_events')
            ->where('queue_item_id', $queueItemId)
            ->orderBy('id')
            ->get();

        $this->assertSame(['live_submission_approved', 'live_submission_response'], $events->pluck('event_type')->all());
        $this->assertSame('operator', $events[0]->actor_type);
        $this->assertSame('seo-ops@example.com', $events[0]->actor_id);
        $this->assertSame('system', $events[1]->actor_type);

        foreach ($events as $event) {
            $this->assertStringNotContainsString('secret-indexnow-key', (string) $event->event_payload);
            $this->assertStringNotContainsString('https://fermatmind.com/en', (string) $event->event_payload);
        }

        [$secondExitCode, $secondPayload] = $this->runSubmitCommand([
            '--queue-item-id' => $queueItemId,
            '--approval-phrase' => $approvalPhrase,
            '--actor' => 'seo-ops@example.com',
            '--json' => true,
        ]);

        $this->assertSame(1, $secondExitCode);
        $this->assertSame('blocked', $secondPayload['status'] ?? null);
        $this->assertContains('approval_state_not_pending', $secondPayload['issues'] ?? []);
        $this->assertContains('execution_state_not_dry_run_ready', $secondPayload['issues'] ?? []);
        Http::assertSentCount(1);
    }

    #[Test]
    public function unsafe_queue_item_is_rejected_without_writes_or_external_calls(): void
    {
        Http::fake();
        $queueItemId = $this->seedQueueItem([
            'canonical_url' => 'https://example.com/en',
            'indexability_state' => 'noindex',
        ]);

        [$exitCode, $payload] = $this->runSubmitCommand([
            '--queue-item-id' => $queueItemId,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('non_indexable_rejected', $payload['issues'] ?? []);
        $this->assertContains('host_not_allowed', $payload['issues'] ?? []);
        $this->assertFalse((bool) ($payload['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['writes_committed'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function generated_artifact_locks_executor_safety_boundary(): void
    {
        $artifactPath = base_path('docs/seo/generated/search-channel-live-02-executor.v1.json');
        $this->assertFileExists($artifactPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('search-channel-live-02-executor.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEARCH-CHANNEL-LIVE-02-EXECUTOR', $artifact['task_id'] ?? null);
        $this->assertSame('seo-intel:search-channel-submit', $artifact['command'] ?? null);
        $this->assertSame('SEARCH-CHANNEL-LIVE-02-PREFLIGHT', $artifact['next_task'] ?? null);
        $this->assertFalse((bool) ($artifact['live_submission_performed_in_this_task'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_registered'] ?? true));
        $this->assertFalse((bool) ($artifact['bulk_submission_supported'] ?? true));
        $this->assertTrue((bool) ($artifact['atomic_single_item_claim_required'] ?? false));
        $this->assertFalse((bool) ($artifact['raw_secret_output_allowed'] ?? true));
        $this->assertContains('indexnow', $artifact['supported_live_channels'] ?? []);
        $this->assertContains('fermatmind.com', $artifact['allowed_hosts'] ?? []);
        $this->assertContains('SEO_INTEL_INDEXNOW_LIVE_API_ENABLED', $artifact['required_env_gates'] ?? []);
        $this->assertSame('dry_run_ready', data_get($artifact, 'required_queue_item_state.execution_state'));
        $this->assertTrue((bool) data_get($artifact, 'protected_boundaries.no_full_url_in_audit_event_payload'));
        $this->assertTrue((bool) data_get($artifact, 'protected_boundaries.single_item_idempotency_guard'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0:int,1:array<string,mixed>,2:string}
     */
    private function runSubmitCommand(array $arguments): array
    {
        $exitCode = Artisan::call('seo-intel:search-channel-submit', $arguments);
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
            'page_entity_type' => $overrides['page_entity_type'] ?? 'home',
            'entity_type' => $overrides['entity_type'] ?? 'home',
            'entity_id' => $overrides['entity_id'] ?? 'home:en',
            'source_authority' => $overrides['source_authority'] ?? 'backend_public_surface',
            'source_table' => $overrides['source_table'] ?? 'backend_authority_canary_contract',
            'channel' => $channel,
            'eligibility_state' => $overrides['eligibility_state'] ?? 'eligible',
            'approval_state' => $overrides['approval_state'] ?? 'pending',
            'execution_state' => $overrides['execution_state'] ?? 'dry_run_ready',
            'indexability_state' => $overrides['indexability_state'] ?? 'indexable',
            'claim_boundary_state' => $overrides['claim_boundary_state'] ?? 'claim_safe',
            'private_flow' => (bool) ($overrides['private_flow'] ?? false),
            'reason_codes' => $overrides['reason_codes'] ?? null,
            'lastmod' => $now,
            'content_hash' => hash('sha256', 'home:en'),
            'url_hash' => hash('sha256', $canonicalUrl),
            'idempotency_key' => hash('sha256', $canonicalUrl.'|'.$channel),
            'approved_by' => null,
            'approved_at' => null,
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
            $table->string('actor_type', 64)->default('system');
            $table->string('actor_id', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
