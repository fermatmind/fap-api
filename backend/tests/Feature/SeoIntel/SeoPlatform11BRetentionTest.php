<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleStore;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\Retention\SeoEvidenceRetentionJanitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Feature\SeoIntel\Concerns\BuildsSeoEvidenceBundle;
use Tests\TestCase;

final class SeoPlatform11BRetentionTest extends TestCase
{
    use BuildsSeoEvidenceBundle;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('seo_intel');
        (require database_path('migrations/seo_intel/2026_08_29_010000_create_seo_evidence_tables.php'))->up();
    }

    public function test_expiry_plan_delete_receipt_and_rerun_are_deterministic(): void
    {
        config()->set('seo_agent_evidence.bundle_write_enabled', true);
        config()->set('seo_agent_evidence.retention_delete_enabled', false);
        $bundle = $this->evidenceBundle(['captured_at' => '2026-01-01T00:00:00Z']);
        app(SeoEvidenceBundleStore::class)->create($bundle);
        $janitor = app(SeoEvidenceRetentionJanitor::class);
        $this->assertCount(0, $janitor->planExpired(CarbonImmutable::parse($bundle['expires_at'])->subSecond()));
        $plan = $janitor->planExpired(CarbonImmutable::parse($bundle['expires_at']));
        $this->assertCount(1, $plan);
        $this->assertSame(['deleted' => 0, 'receipts' => 0], $janitor->executeExpired($plan));
        config()->set('seo_agent_evidence.retention_delete_enabled', true);
        $this->assertSame(['deleted' => 1, 'receipts' => 1], $janitor->executeExpired($plan));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_evidence_bundles')->count());
        $receipt = (array) DB::connection('seo_intel')->table('seo_evidence_deletion_receipts')->first();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);
        $this->assertArrayNotHasKey('payload', $receipt);
        $receiptPayload = array_diff_key($receipt, array_flip(['id', 'receipt_hash', 'created_at']));
        $this->assertSame($receipt['receipt_hash'], app(SeoEvidenceCanonicalHasher::class)->hash($receiptPayload));
        $this->assertDoesNotMatchRegularExpression('/query|url|payload|source_body|email|token/i', json_encode($receipt, JSON_THROW_ON_ERROR));
        $this->assertSame(['deleted' => 0, 'receipts' => 0], $janitor->executeExpired($plan));
        $this->assertTrue(Schema::connection('seo_intel')->hasTable('seo_evidence_bundles'));
    }

    public function test_forged_plan_cannot_delete_an_active_bundle(): void
    {
        config()->set('seo_agent_evidence.bundle_write_enabled', true);
        config()->set('seo_agent_evidence.retention_delete_enabled', true);
        $bundle = $this->evidenceBundle(['captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z')]);
        app(SeoEvidenceBundleStore::class)->create($bundle);
        $row = (array) DB::connection('seo_intel')->table('seo_evidence_bundles')->first();

        $this->assertSame(['deleted' => 0, 'receipts' => 0], app(SeoEvidenceRetentionJanitor::class)->executeExpired([$row]));
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_evidence_bundles')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_evidence_deletion_receipts')->count());
    }

    public function test_versions_cannot_be_overwritten_skipped_or_revised_without_new_content_and_lineage(): void
    {
        config()->set('seo_agent_evidence.bundle_write_enabled', true);
        $store = app(SeoEvidenceBundleStore::class);
        $first = $this->evidenceBundle();
        $store->create($first);
        $noop = $this->evidenceBundle(['bundle_version' => 2, 'lineage_refs' => [$first['bundle_hash']]]);
        try {
            $store->create($noop);
            $this->fail('No-op revision was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('SEO_EVIDENCE_NOOP_REVISION', $exception->getMessage());
        }
        $changed = $this->evidenceBundle([
            'bundle_version' => 2,
            'lineage_refs' => [$first['bundle_hash']],
            'payload' => ['query_hmac' => str_repeat('b', 64), 'query_hmac_key_version' => 'k1', 'clicks' => 11],
        ]);
        $store->create($changed);
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_evidence_bundles')->count());
        $this->expectException(InvalidArgumentException::class);
        $store->create($this->evidenceBundle(['bundle_version' => 4, 'lineage_refs' => [$changed['bundle_hash']], 'payload' => ['clicks' => 12]]));
    }
}
