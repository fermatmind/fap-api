<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FreeAssessmentGscLiveSmokeEvidenceContractTest extends TestCase
{
    #[Test]
    public function contract_covers_the_six_free_assessment_landings_without_raw_gsc_outputs(): void
    {
        $artifact = $this->artifact();
        $surfaces = $artifact['free_assessment_surfaces'] ?? null;

        $this->assertSame('free-assessment-gsc-live-smoke-evidence-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('FA30-API-04', $artifact['task'] ?? null);
        $this->assertSame('gsc', $artifact['channel'] ?? null);
        $this->assertSame('free_assessment_landings', $artifact['surface_group'] ?? null);
        $this->assertIsArray($surfaces);
        $this->assertCount(6, $surfaces);

        $this->assertSameCanonicalSurfaces([
            'MBTI' => '/en/tests/mbti-personality-test-16-personality-types',
            'BIG5_OCEAN' => '/en/tests/big-five-personality-test-ocean-model',
            'RIASEC' => '/en/tests/holland-career-interest-test-riasec',
            'IQ_RAVEN' => '/en/tests/iq-test-intelligence-quotient-assessment',
            'EQ_60' => '/en/tests/eq-test-emotional-intelligence-assessment',
            'ENNEAGRAM' => '/en/tests/enneagram-personality-test-nine-types',
        ], $surfaces);

        foreach ($artifact['forbidden_evidence_fields'] ?? [] as $forbiddenField) {
            $this->assertNotContains($forbiddenField, $artifact['required_evidence_fields'] ?? []);
        }
    }

    #[Test]
    public function contract_locks_readonly_searchanalytics_and_no_submission_boundaries(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('google_search_console_searchanalytics_query', data_get($artifact, 'allowed_live_smoke.api'));
        $this->assertSame('read_only_searchanalytics', data_get($artifact, 'allowed_live_smoke.method'));
        $this->assertTrue((bool) data_get($artifact, 'allowed_live_smoke.requires_explicit_live_read_gate'));
        $this->assertTrue((bool) data_get($artifact, 'allowed_live_smoke.requires_safe_secret_channel'));
        $this->assertTrue((bool) data_get($artifact, 'allowed_live_smoke.requires_dry_run'));
        $this->assertTrue((bool) data_get($artifact, 'allowed_live_smoke.requires_no_write'));
        $this->assertSame(250, data_get($artifact, 'allowed_live_smoke.max_row_limit'));
        $this->assertSame(['query', 'page'], data_get($artifact, 'allowed_live_smoke.dimensions'));

        foreach ([
            'gsc_is_content_authority',
            'gsc_is_url_truth_authority',
            'gsc_creates_urls',
            'gsc_overrides_indexability',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, 'authority_boundary.'.$flag, true), $flag.' must remain false');
        }

        foreach ($artifact['negative_guarantees'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function future_live_smoke_evidence_must_be_sanitized_hash_and_aggregate_only(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'run_id',
            'observed_at',
            'date_window',
            'row_limit',
            'dimensions',
            'surface_count',
            'matched_surface_count',
            'rows_seen',
            'data_quality_gate',
            'safe_row_preview',
            'canonical_url_hash',
            'query_hash',
            'query_display_masked',
        ] as $field) {
            $this->assertContains($field, $artifact['required_evidence_fields'] ?? []);
        }

        foreach ([
            'raw_query',
            'raw_url',
            'access_token',
            'service_account_json',
            'private_key',
            'client_email',
            'credential_path',
            'cookie',
            'session',
            'email',
            'order_id',
            'attempt_id',
            'payment_id',
            'raw_ip',
        ] as $field) {
            $this->assertContains($field, $artifact['forbidden_evidence_fields'] ?? []);
        }

        $this->assertSame('contract_ready', data_get($artifact, 'acceptance.go_status'));
        $this->assertSame('GO_or_NO_GO', data_get($artifact, 'acceptance.future_live_smoke_must_report'));
        $this->assertTrue((bool) data_get($artifact, 'acceptance.future_live_smoke_must_not_submit_urls'));
        $this->assertTrue((bool) data_get($artifact, 'acceptance.future_live_smoke_must_not_publish_or_import'));
        $this->assertSame('FA30-WEB-05', $artifact['next_task'] ?? null);
    }

    /**
     * @param  array<string,string>  $expected
     * @param  list<array<string,mixed>>  $surfaces
     */
    private function assertSameCanonicalSurfaces(array $expected, array $surfaces): void
    {
        $actual = [];
        foreach ($surfaces as $surface) {
            $scaleCode = (string) ($surface['scale_code'] ?? '');
            $path = (string) ($surface['canonical_path'] ?? '');

            $this->assertSame('en', $surface['locale'] ?? null);
            $this->assertSame('test_landing_page', $surface['expected_page_type'] ?? null);
            $this->assertSame(
                hash('sha256', 'https://fermatmind.com'.$path),
                $surface['canonical_url_hash'] ?? null
            );

            $actual[$scaleCode] = $path;
        }

        $this->assertSame($expected, $actual);
    }

    /**
     * @return array<string,mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/free-assessment-gsc-live-smoke-evidence-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
