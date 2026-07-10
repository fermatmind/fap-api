<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueProviderHoldRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelProviderHoldCommandTest extends TestCase
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
            'seo_intel.search_channel_queue.live_submission.baidu.endpoint' => 'http://data.zz.baidu.test/urls',
        ]);

        DB::purge('seo_intel');
        $this->createTables();
    }

    #[Test]
    public function command_is_registered(): void
    {
        $this->assertArrayHasKey('seo-intel:search-channel-provider-hold', Artisan::all());
    }

    #[Test]
    public function dry_run_proves_insecure_endpoint_without_writes_or_external_calls(): void
    {
        Http::fake();
        $id = $this->seedQueueItem();

        [$exitCode, $payload] = $this->runCommand(['--queue-ids' => (string) $id, '--json' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('configured_endpoint_not_https', data_get($payload, 'items.0.security_evidence.kind'));
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame('submit_failed', $this->executionState($id));
        $this->assertSame(
            'I explicitly approve SEARCH-CHANNEL-PROVIDER-HOLD record for queue items '.$id.' channels baidu_push reason transport_security_unavailable.',
            $payload['approval_phrase'],
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function execute_requires_exact_approval_and_records_audited_hold_without_external_calls(): void
    {
        Http::fake();
        $id = $this->seedQueueItem();
        $phrase = app(SearchChannelQueueProviderHoldRecorder::class)->approvalPhrase([$id], ['baidu_push'], 'transport_security_unavailable');

        [$blockedExit, $blocked] = $this->runCommand([
            '--queue-ids' => (string) $id,
            '--execute' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $blockedExit);
        $this->assertContains('provider_hold_approval_required', $blocked['issues']);
        $this->assertSame('submit_failed', $this->executionState($id));

        [$exitCode, $payload] = $this->runCommand([
            '--queue-ids' => (string) $id,
            '--approval-phrase' => $phrase,
            '--actor' => 'seo-ops@example.com',
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $payload['status']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame('provider_security_hold', $this->executionState($id));
        $event = DB::connection('seo_intel')->table('seo_search_channel_queue_events')
            ->where('event_type', 'search_channel_provider_security_hold_recorded')->first();
        $this->assertNotNull($event);
        $this->assertSame('seo-ops@example.com', $event->actor_id);
        $eventPayload = json_decode((string) $event->event_payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('transport_security_unavailable', $eventPayload['reason']);
        $this->assertFalse($eventPayload['external_calls_attempted']);
        $this->assertFalse($eventPayload['search_submission_attempted']);
        $this->assertArrayNotHasKey('token', $eventPayload);
        Http::assertNothingSent();
    }

    #[Test]
    public function verified_https_transport_failure_can_be_held_but_an_available_https_endpoint_cannot(): void
    {
        Http::fake();
        config(['seo_intel.search_channel_queue.live_submission.baidu.endpoint' => 'https://data.zz.baidu.test/urls']);
        $id = $this->seedQueueItem();

        [$blockedExit, $blocked] = $this->runCommand(['--queue-ids' => (string) $id, '--json' => true]);
        $this->assertSame(1, $blockedExit);
        $this->assertContains('baidu_secure_endpoint_available_use_live_submit', $blocked['issues']);

        $this->seedTransportFailure($id);
        [$exitCode, $payload] = $this->runCommand(['--queue-ids' => (string) $id, '--json' => true]);
        $this->assertSame(0, $exitCode);
        $this->assertSame('verified_https_transport_failure', data_get($payload, 'items.0.security_evidence.kind'));
        Http::assertNothingSent();
    }

    #[Test]
    public function rejects_non_baidu_private_or_already_submitted_items(): void
    {
        Http::fake();
        $indexNow = $this->seedQueueItem(['channel' => 'indexnow']);
        $private = $this->seedQueueItem(['canonical_url' => 'https://fermatmind.com/zh/articles/private', 'private_flow' => true]);
        $submitted = $this->seedQueueItem(['canonical_url' => 'https://fermatmind.com/zh/articles/submitted', 'execution_state' => 'submitted']);

        [$exitCode, $payload] = $this->runCommand([
            '--queue-ids' => implode(',', [$indexNow, $private, $submitted]),
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('baidu_push_channel_required', $payload['issues']);
        $this->assertContains('private_flow_rejected', $payload['issues']);
        $this->assertContains('execution_state_not_holdable', $payload['issues']);
        Http::assertNothingSent();
    }

    /** @param array<string,mixed> $arguments @return array{0:int,1:array<string,mixed>} */
    private function runCommand(array $arguments): array
    {
        $exitCode = Artisan::call('seo-intel:search-channel-provider-hold', $arguments);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return [$exitCode, $payload];
    }

    /** @param array<string,mixed> $overrides */
    private function seedQueueItem(array $overrides = []): int
    {
        $url = (string) ($overrides['canonical_url'] ?? 'https://fermatmind.com/zh/articles/provider-security-hold');
        $channel = (string) ($overrides['channel'] ?? 'baidu_push');

        return (int) DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insertGetId([
            'batch_id' => 1,
            'canonical_url' => $url,
            'locale' => 'zh',
            'page_entity_type' => 'article',
            'entity_type' => 'article',
            'entity_id' => 'fixture',
            'source_authority' => 'backend_cms',
            'source_table' => 'articles',
            'channel' => $channel,
            'eligibility_state' => $overrides['eligibility_state'] ?? 'eligible',
            'approval_state' => $overrides['approval_state'] ?? 'approved',
            'execution_state' => $overrides['execution_state'] ?? 'submit_failed',
            'indexability_state' => $overrides['indexability_state'] ?? 'indexable',
            'claim_boundary_state' => $overrides['claim_boundary_state'] ?? 'claim_safe',
            'private_flow' => (bool) ($overrides['private_flow'] ?? false),
            'reason_codes' => null,
            'lastmod' => now(),
            'content_hash' => hash('sha256', $url),
            'url_hash' => hash('sha256', $url),
            'idempotency_key' => hash('sha256', $url.'|'.$channel),
            'approved_by' => 'operator',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedTransportFailure(int $id): void
    {
        DB::connection('seo_intel')->table('seo_search_channel_queue_events')->insert([
            'queue_item_id' => $id,
            'batch_id' => 1,
            'event_type' => 'bounded_live_submission_response',
            'event_payload' => json_encode([
                'channel' => 'baidu_push',
                'endpoint_host' => 'data.zz.baidu.test',
                'http_status' => null,
                'execution_state' => 'submit_failed',
                'exception_class' => 'Illuminate\\Http\\Client\\ConnectionException',
            ], JSON_THROW_ON_ERROR),
            'actor_type' => 'system',
            'actor_id' => 'seo-intel:search-channel-submit-approved',
            'created_at' => now(),
        ]);
    }

    private function executionState(int $id): string
    {
        return (string) DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('id', $id)->value('execution_state');
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
