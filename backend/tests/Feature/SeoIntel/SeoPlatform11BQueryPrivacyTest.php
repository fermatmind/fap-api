<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Privacy\SeoEvidenceDiagnosticSanitizer;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoQueryHmac;
use App\Services\SeoAgentEvidence\Sources\GscAggregateEvidenceAdapter;
use App\Services\SeoIntel\GscQueryClassifier;
use App\Services\SeoIntel\GscSearchAnalyticsRowNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoPlatform11BQueryPrivacyTest extends TestCase
{
    public function test_query_identity_is_versioned_hmac_and_private_values_are_never_returned(): void
    {
        config()->set('seo_agent_evidence.query_hmac_key', str_repeat('k', 32));
        config()->set('seo_agent_evidence.query_hmac_key_version', 'k1');
        $first = app(SeoQueryHmac::class)->identify("  ＭＢＴＩ\tTest  ");
        $this->assertSame('available', $first['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['query_hmac']);
        $this->assertArrayNotHasKey('query', $first);
        config()->set('seo_agent_evidence.query_hmac_key', str_repeat('z', 32));
        config()->set('seo_agent_evidence.query_hmac_key_version', 'k2');
        $rotated = app(SeoQueryHmac::class)->identify('mbti test');
        $this->assertNotSame($first['query_hmac'], $rotated['query_hmac']);
        config()->set('seo_agent_evidence.query_hmac_key', null);
        $this->assertSame('SOURCE_CAPABILITY_UNAVAILABLE', app(SeoQueryHmac::class)->identify('secret query')['status']);

        $scan = app(SeoPrivateDataScanner::class)->scan(['attempt_id' => 'attempt_12345', 'email' => 'person@example.com', 'authorization' => 'Bearer abc.def.ghi']);
        $this->assertTrue($scan['private_data_present']);
        $this->assertArrayNotHasKey('matches', $scan);
        $this->assertFalse(app(SeoPrivateDataScanner::class)->scan(['aggregate_result_count' => 12])['private_data_present']);
        $this->assertTrue(app(SeoPrivateDataScanner::class)->scan('sk-live-abcdefgh12345678')['private_data_present']);
    }

    public function test_live_gsc_dual_write_is_nullable_versioned_and_never_backfills_legacy_hashes(): void
    {
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('seo_intel');
        Schema::connection('seo_intel')->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->char('query_hash', 64)->nullable();
        });
        DB::connection('seo_intel')->table('seo_gsc_daily')->insert(['query_hash' => str_repeat('a', 64)]);
        (require database_path('migrations/seo_intel/2026_08_29_020000_add_query_hmac_columns.php'))->up();
        $legacy = (array) DB::connection('seo_intel')->table('seo_gsc_daily')->first();
        $this->assertNull($legacy['query_hmac']);
        $this->assertNull($legacy['query_hmac_key_version']);

        config()->set('seo_agent_evidence.query_hmac_key', str_repeat('q', 32));
        config()->set('seo_agent_evidence.query_hmac_key_version', 'gsc-k1');
        config()->set('seo_agent_evidence.query_hmac_dual_write_enabled', true);
        $row = (new GscSearchAnalyticsRowNormalizer(new GscQueryClassifier, new SeoQueryHmac))->normalize(['query' => 'MBTI test']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['query_hmac']);
        $this->assertSame('gsc-k1', $row['query_hmac_key_version']);
        $this->assertNotSame($row['query_hash'], $row['query_hmac']);

        $adapter = app(GscAggregateEvidenceAdapter::class);
        $this->assertSame('unavailable', $adapter->adapt(['source_origin' => 'fixture', 'query_level' => true])['source_capability_state']);
        $this->assertSame('unavailable', $adapter->adapt(['source_origin' => 'live_gsc_api', 'query_level' => true, 'query_hash' => str_repeat('a', 64)])['source_capability_state']);
        $safe = $adapter->adapt(['source_origin' => 'live_gsc_api', 'query_level' => true, 'query_hmac' => $row['query_hmac'], 'query_hmac_key_version' => 'gsc-k1', 'clicks' => 2, 'impressions' => 20]);
        $this->assertSame('available', $safe['source_capability_state']);
        $this->assertSame(['query_hmac', 'query_hmac_key_version', 'clicks', 'impressions'], array_keys($safe['payload']));
    }

    public function test_unicode_camel_numeric_nested_and_object_pii_evasions_fail_closed(): void
    {
        $scanner = app(SeoPrivateDataScanner::class);
        foreach ([
            ['userId' => 42],
            ['accessToken' => 'opaque'],
            ['emailAddress' => 'person@example.com'],
            ['payment-id' => 4111111111111111],
            ['profile.user.id' => 42],
            ['nested' => ['accountRecovery' => 'opaque']],
            ['nested' => [['phone' => 13800138000]]],
            ['ｕｓｅｒ＿ｉｄ' => 42],
            (object) ['public' => true],
        ] as $probe) {
            $scan = $scanner->scan($probe);
            $this->assertTrue($scan['private_data_present']);
            $this->assertArrayNotHasKey('matches', $scan);
        }

        $this->assertFalse($scanner->scan(['aggregate_result_count' => 12, 'status_counts' => ['ready' => 2]])['private_data_present']);
        $diagnostic = app(SeoEvidenceDiagnosticSanitizer::class)->diagnostic('SEO_EVIDENCE_PRIVATE_DATA', [], 'attempt_12345', 1, str_repeat('a', 64));
        $this->assertSame(['safe_error_code', 'category_counts'], array_keys($diagnostic));
        $this->assertGreaterThan(0, array_sum($diagnostic['category_counts']));
        $this->assertStringNotContainsString('attempt_12345', json_encode($diagnostic, JSON_THROW_ON_ERROR));
    }

    public function test_payment_identifiers_fail_closed_and_only_exact_declared_hash_paths_are_exempt(): void
    {
        $scanner = app(SeoPrivateDataScanner::class);
        $paymentProbes = [
            '4111111111111111x',
            'x4111111111111111',
            'x4111111111111111y',
        ];

        foreach ($paymentProbes as $probe) {
            $this->assertTrue($scanner->scan($probe)['private_data_present'], $probe);
        }

        $validHash = str_repeat('a', 16).'4111111111111111'.str_repeat('b', 32);
        $this->assertFalse($scanner->scan(['query_hmac' => $validHash], ['query_hmac'])['private_data_present']);
        $adapted = app(GscAggregateEvidenceAdapter::class)->adapt([
            'source_origin' => 'live_gsc_api',
            'query_level' => true,
            'query_hmac' => $validHash,
            'query_hmac_key_version' => 'gsc-k1',
            'clicks' => 2,
            'impressions' => 20,
        ]);
        $this->assertSame('available', $adapted['source_capability_state']);
        $diagnostic = app(SeoEvidenceDiagnosticSanitizer::class)->diagnostic('SAFE_CODE', [], null, 1, $validHash);
        $this->assertArrayNotHasKey('payment_identifier', $diagnostic['category_counts']);

        foreach ([
            [$validHash, ['query_hmac']],
            [['summary' => $validHash], ['summary']],
            [['summary' => $validHash], SeoPrivateDataScanner::MINIMIZED_PAYLOAD_HASH_PATHS],
            [['query_hmac' => substr($validHash, 0, 63)], ['query_hmac']],
            [['query_hmac' => $validHash.'b'], ['query_hmac']],
            [['query_hmac' => strtoupper($validHash)], ['query_hmac']],
            [['nested' => ['query_hmac' => $validHash]], ['query_hmac']],
            [['private_data_present' => 'x4111111111111111y'], []],
            [['injection_scan_result' => 'x4111111111111111y'], []],
        ] as [$value, $paths]) {
            $this->assertTrue($scanner->scan($value, $paths)['private_data_present']);
        }
    }
}
