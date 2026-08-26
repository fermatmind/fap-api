<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Runtime\UnifiedRuntimeProbeEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform07UnifiedRuntimeProbeEvaluatorTest extends TestCase
{
    #[Test]
    public function healthy_direct_evidence_is_distinct_from_incident_and_no_data(): void
    {
        $snapshot = (new UnifiedRuntimeProbeEvaluator)->evaluate($this->healthyEvidence());

        $this->assertSame('success', $snapshot['state']);
        $this->assertNull($snapshot['severity']);
        $this->assertSame([], $snapshot['incidents']);
        $this->assertSame([], $snapshot['measurement_hold_reasons']);
        foreach ($snapshot['sources'] as $source) {
            $this->assertSame('success', $source['state']);
            $this->assertGreaterThan(0, $source['observed_count']);
        }
        foreach (['http_404', 'http_410', 'http_5xx', 'false_noindex', 'empty_shell', 'canonical_drift', 'hreflang_drift', 'schema_drift', 'latency_breach', 'timeout'] as $detector) {
            $this->assertSame('success', $snapshot['slis'][$detector]['state']);
            $this->assertSame(0, $snapshot['slis'][$detector]['numerator']);
            $this->assertGreaterThan(0, $snapshot['slis'][$detector]['denominator']);
        }
    }

    #[Test]
    public function it_detects_all_required_runtime_anomalies_from_direct_observation(): void
    {
        $evidence = $this->healthyEvidence();
        $evidence['core_entry']['results'] = [
            $this->coreResult(404, ['thin_shell', 'canonical_drift', 'hreflang_drift', 'schema_drift', 'ttfb_breach'], true),
            $this->coreResult(410, ['transport_error'], false),
        ];
        $evidence['public_content']['items'][0] = [
            ...$evidence['public_content']['items'][0],
            'request_count' => 20,
            'server_error_rate' => 0.1,
            'timeout_rate' => 0.05,
            'not_found_rate' => 0.05,
            'p95_ms' => 1200,
            'approved_p95_budget_ms' => 800,
        ];

        $snapshot = (new UnifiedRuntimeProbeEvaluator)->evaluate($evidence);

        $this->assertSame('incident', $snapshot['state']);
        $this->assertSame('P1', $snapshot['severity']);
        foreach (UnifiedRuntimeProbeEvaluator::DETECTORS as $detector) {
            $this->assertSame('incident', $snapshot['slis'][$detector]['state'], $detector);
            $this->assertGreaterThan(0, $snapshot['slis'][$detector]['numerator'], $detector);
        }
        $this->assertContains('P1', array_column($snapshot['incidents'], 'severity'));
        $this->assertContains('P2', array_column($snapshot['incidents'], 'severity'));
        $this->assertNotContains('P0', array_column($snapshot['incidents'], 'severity'));
        foreach ($snapshot['incidents'] as $incident) {
            $this->assertSame('direct_observation', $incident['evidence_state']);
        }
    }

    #[Test]
    public function missing_evidence_holds_and_preserves_null_instead_of_fabricating_zero(): void
    {
        $snapshot = (new UnifiedRuntimeProbeEvaluator)->evaluate([]);

        $this->assertSame(UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD, $snapshot['state']);
        $this->assertNull($snapshot['severity']);
        $this->assertCount(4, $snapshot['measurement_hold_reasons']);
        foreach ($snapshot['sources'] as $source) {
            $this->assertSame('no_data', $source['state']);
            $this->assertSame(0, $source['observed_count']);
        }
        foreach ($snapshot['slis'] as $sli) {
            $this->assertSame(UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD, $sli['state']);
            $this->assertNull($sli['numerator']);
            $this->assertNull($sli['denominator']);
            $this->assertNull($sli['rate']);
        }
    }

    #[Test]
    public function source_claimed_severity_cannot_infer_p0_or_p1_for_one_identity(): void
    {
        $evidence = $this->healthyEvidence();
        $evidence['core_entry']['results'] = [[
            ...$this->coreResult(503, [], false),
            'severity' => 'P0',
            'inferred_template_failure' => true,
            'affected_count' => 999,
        ]];

        $snapshot = (new UnifiedRuntimeProbeEvaluator)->evaluate($evidence);
        $incident = collect($snapshot['incidents'])->firstWhere('detector', 'http_5xx');

        $this->assertSame('P2', $incident['severity']);
        $this->assertSame(1, $incident['affected_count']);
        $this->assertTrue($snapshot['boundaries']['p0_p1_direct_evidence_only']);
        $this->assertFalse($snapshot['boundaries']['inferred_p0_p1_allowed']);
    }

    #[Test]
    public function repeated_crawler_hits_for_one_identity_do_not_become_template_p1(): void
    {
        $evidence = $this->healthyEvidence();
        $evidence['crawler'] = [
            'total_hits' => 999,
            'recent_rows' => [[
                'route_family' => 'articles_topics',
                'locale' => 'en',
                'http_status' => 503,
                'hit_count' => 999,
            ]],
        ];

        $snapshot = (new UnifiedRuntimeProbeEvaluator)->evaluate($evidence);
        $incident = collect($snapshot['incidents'])->first(
            static fn (array $candidate): bool => $candidate['source'] === 'crawler'
                && $candidate['detector'] === 'http_5xx',
        );

        $this->assertSame('P2', $incident['severity']);
        $this->assertSame(1, $incident['affected_count']);
        $this->assertSame(999, $snapshot['slis']['http_5xx']['numerator']);
    }

    #[Test]
    public function unified_output_never_exposes_raw_request_or_response_evidence(): void
    {
        $evidence = $this->healthyEvidence();
        $evidence['core_entry']['results'][0] += [
            'safe_path' => '/en/articles/private-looking',
            'raw_url' => 'https://fermatmind.com/en/articles/private-looking?token=secret',
            'query' => 'token=secret',
            'user_agent' => 'SensitiveCrawler/1.0',
            'response_body' => '<html>secret body</html>',
            'topology' => 'node-private-1',
        ];
        $evidence['crawler']['recent_rows'][0] += [
            'canonical_path' => '/en/articles/private-looking',
            'query_present' => true,
            'bot_variant' => 'SensitiveCrawler/1.0',
            'host' => 'private-node.internal',
        ];

        $encoded = json_encode((new UnifiedRuntimeProbeEvaluator)->evaluate($evidence), JSON_THROW_ON_ERROR);

        foreach (['private-looking', 'token=secret', 'SensitiveCrawler', 'secret body', 'node-private', 'private-node'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        foreach (['raw_url_emitted', 'query_emitted', 'user_agent_emitted', 'response_body_emitted', 'raw_topology_emitted'] as $boundary) {
            $this->assertFalse(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR)['boundaries'][$boundary]);
        }
    }

    /** @return array<string,mixed> */
    private function healthyEvidence(): array
    {
        return [
            'core_entry' => [
                'results' => [$this->coreResult(200, [], false)],
            ],
            'public_content' => [
                'items' => [[
                    'route_family' => 'public_content',
                    'locale' => 'en',
                    'request_count' => 20,
                    'not_found_rate' => 0.0,
                    'server_error_rate' => 0.0,
                    'timeout_rate' => 0.0,
                    'p95_ms' => 500,
                    'approved_p95_budget_ms' => 800,
                ]],
            ],
            'career_runtime' => [
                'sample_count' => 20,
                'status' => 'pass',
                'alerts' => [],
            ],
            'crawler' => [
                'total_hits' => 10,
                'recent_rows' => [[
                    'route_family' => 'articles_topics',
                    'locale' => 'en',
                    'http_status' => 200,
                    'hit_count' => 10,
                ]],
            ],
        ];
    }

    /** @param list<string> $categories @return array<string,mixed> */
    private function coreResult(int $status, array $categories, bool $falseNoindex): array
    {
        return [
            'target_id' => 'hashed-target',
            'page_family' => 'articles_topics',
            'locale' => 'en',
            'http' => ['status_code' => $status],
            'incident_categories' => $categories,
            'ssr_visible_content' => ['status' => in_array('thin_shell', $categories, true) ? 'missing_or_thin' : 'pass'],
            'robots' => ['status' => $falseNoindex ? 'drift' : 'pass'],
            'authority_indexable' => $falseNoindex,
        ];
    }
}
