<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoIntelSearchChannelProviderPreflightCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_intel' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'seo_intel.connection' => 'seo_intel',
            'seo_intel.search_channel_queue.live_submission.allowed_channels' => ['indexnow', 'baidu_push'],
            'seo_intel.search_channel_queue.live_submission.allowed_hosts' => ['fermatmind.com'],
            'seo_intel.search_channel_queue.approved_source_authorities' => ['backend_cms'],
            'seo_intel.search_channel_queue.live_submission.indexnow.endpoint' => 'https://api.indexnow.test/indexnow',
            'seo_intel.search_channel_queue.live_submission.indexnow.allowed_endpoint_hosts' => ['api.indexnow.test'],
            'seo_intel.search_channel_queue.live_submission.indexnow.key' => 'secret-indexnow-key',
            'seo_intel.search_channel_queue.live_submission.indexnow.key_location' => 'https://fermatmind.com/indexnow.txt',
            'seo_intel.search_channel_queue.live_submission.baidu.endpoint' => 'https://data.zz.baidu.test/urls',
            'seo_intel.search_channel_queue.live_submission.baidu.allowed_endpoint_hosts' => ['data.zz.baidu.test'],
            'seo_intel.search_channel_queue.live_submission.baidu.site' => 'https://fermatmind.com',
            'seo_intel.search_channel_queue.live_submission.baidu.token' => 'secret-baidu-token',
        ]);

        DB::purge('seo_intel');
        Schema::connection('seo_intel')->create('seo_search_channel_queue_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 191)->nullable();
            $table->string('source_authority', 64);
            $table->string('source_table', 64)->nullable();
            $table->string('channel', 32);
            $table->string('eligibility_state', 32);
            $table->string('approval_state', 32);
            $table->string('execution_state', 32);
            $table->string('indexability_state', 32);
            $table->string('claim_boundary_state', 32);
            $table->boolean('private_flow')->default(false);
            $table->text('reason_codes')->nullable();
            $table->timestamp('lastmod')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('url_hash', 64);
            $table->string('idempotency_key', 64);
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_https_indexnow_and_baidu_are_submit_ready_without_external_calls(): void
    {
        Http::fake();
        $indexnowId = $this->seedQueueItem('indexnow', 'https://fermatmind.com/en/articles/example');
        $baiduId = $this->seedQueueItem('baidu_push', 'https://fermatmind.com/zh/articles/example');

        [$exitCode, $payload, $output] = $this->runPreflight($indexnowId.','.$baiduId, 'indexnow,baidu_push');

        $this->assertSame(0, $exitCode);
        $this->assertSame(['submit_ready', 'submit_ready'], array_column($payload['items'], 'status'));
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertStringNotContainsString('secret-indexnow-key', $output);
        $this->assertStringNotContainsString('secret-baidu-token', $output);
        Http::assertNothingSent();
    }

    public function test_insecure_baidu_is_hold_ready_without_request_or_token_output(): void
    {
        Http::fake();
        config(['seo_intel.search_channel_queue.live_submission.baidu.endpoint' => 'http://data.zz.baidu.test/urls']);
        $id = $this->seedQueueItem('baidu_push', 'https://fermatmind.com/zh/articles/example');

        [$exitCode, $payload, $output] = $this->runPreflight((string) $id, 'baidu_push');

        $this->assertSame(0, $exitCode);
        $this->assertSame('provider_security_hold_ready', data_get($payload, 'items.0.status'));
        $this->assertSame('record_audited_provider_security_hold', data_get($payload, 'items.0.next_action'));
        $this->assertFalse(data_get($payload, 'items.0.transport.https'));
        $this->assertTrue(data_get($payload, 'items.0.transport.tls_verification_enabled'));
        $this->assertStringNotContainsString('secret-baidu-token', $output);
        Http::assertNothingSent();
    }

    public function test_queue_and_credential_failures_block_before_provider_routing(): void
    {
        Http::fake();
        config(['seo_intel.search_channel_queue.live_submission.baidu.token' => '']);
        $id = $this->seedQueueItem('baidu_push', 'https://fermatmind.com/zh/articles/example', ['claim_boundary_state' => 'unsafe']);

        [$exitCode, $payload] = $this->runPreflight((string) $id, 'baidu_push');

        $this->assertSame(1, $exitCode);
        $this->assertContains('claim_unsafe_rejected', $payload['issues']);
        $this->assertContains('provider_credentials_missing', $payload['issues']);
        Http::assertNothingSent();
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} */
    private function runPreflight(string $ids, string $channels): array
    {
        $exitCode = Artisan::call('seo-intel:search-channel-provider-preflight', [
            '--queue-ids' => $ids,
            '--channels' => $channels,
            '--json' => true,
        ]);
        $output = trim(Artisan::output());

        return [$exitCode, json_decode($output, true, 512, JSON_THROW_ON_ERROR), $output];
    }

    /** @param array<string,mixed> $overrides */
    private function seedQueueItem(string $channel, string $canonicalUrl, array $overrides = []): int
    {
        return (int) DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insertGetId(array_merge([
            'batch_id' => 1,
            'canonical_url' => $canonicalUrl,
            'locale' => str_contains($canonicalUrl, '/zh/') ? 'zh-CN' : 'en',
            'page_entity_type' => 'article',
            'entity_type' => 'article',
            'entity_id' => 'article:fixture',
            'source_authority' => 'backend_cms',
            'source_table' => 'cms_articles',
            'channel' => $channel,
            'eligibility_state' => 'eligible',
            'approval_state' => 'approved',
            'execution_state' => 'dry_run_ready',
            'indexability_state' => 'indexable',
            'claim_boundary_state' => 'claim_safe',
            'private_flow' => false,
            'url_hash' => hash('sha256', $canonicalUrl),
            'idempotency_key' => hash('sha256', $canonicalUrl.'|'.$channel),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
