<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class SeoIssueQueueProducer
{
    public function __construct(
        private readonly SeoIssueSanitizer $sanitizer = new SeoIssueSanitizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $observations
     * @return array{issues: list<array<string, mixed>>, metadata: array<string, mixed>}
     */
    public function produce(array $observations = []): array
    {
        $observations = $observations === [] ? $this->fixtureObservations() : $observations;
        $issues = array_map(fn (array $observation): array => $this->sanitizer->sanitize($observation), $observations);

        return [
            'issues' => $issues,
            'metadata' => [
                'issue_count' => count($issues),
                'cms_mutation_attempted' => false,
                'auto_publish_attempted' => false,
                'auto_pseo_attempted' => false,
                'external_calls_attempted' => false,
                'fixture_only' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureObservations(): array
    {
        return [
            [
                'issue_type' => 'metadata_drift',
                'severity' => 'warning',
                'source_system' => 'drift_foundation',
                'canonical_url' => 'https://fermatmind.com/zh/articles/example?utm_source=internal',
                'locale' => 'zh-CN',
                'page_entity_type' => 'article',
                'entity_id_or_slug' => 'example',
                'cluster' => 'career',
                'detected_at' => '2026-05-17T12:00:00Z',
                'summary' => 'Fixture metadata drift for public article.',
                'recommendation' => 'Review CMS SEO metadata manually.',
                'evidence' => [
                    'expected_hash' => hash('sha256', 'expected'),
                    'observed_hash' => hash('sha256', 'observed'),
                    'raw_payload' => 'must_not_persist',
                ],
            ],
            [
                'issue_type' => 'crawler_private_hit',
                'severity' => 'high',
                'source_system' => 'chinese_crawler_log_foundation',
                'source_engine' => 'baidu',
                'canonical_url' => 'https://fermatmind.com/zh/result/private?attempt_id=hidden',
                'locale' => 'zh-CN',
                'page_entity_type' => 'result',
                'detected_at' => '2026-05-17T12:01:00Z',
                'summary' => 'Crawler hit a private flow path; raw user detail is redacted.',
                'recommendation' => 'Review robots/noindex boundary manually; do not make the URL eligible.',
                'evidence' => [
                    'raw_ip' => '203.0.113.10',
                    'cookie' => 'secret',
                    'email' => 'qa@example.invalid',
                    'path_display_masked' => '/zh/result/private',
                ],
            ],
            [
                'issue_type' => 'claim_boundary_warning',
                'severity' => 'info',
                'source_system' => 'semantic_claim_linter_fixture',
                'canonical_url' => 'https://fermatmind.com/zh/tests/holland-career-interest-test-riasec',
                'locale' => 'zh-CN',
                'page_entity_type' => 'test_detail',
                'entity_id_or_slug' => 'holland-career-interest-test-riasec',
                'cluster' => 'career',
                'summary' => 'RIASEC copy requires manual claim-boundary review.',
                'recommendation' => 'Use career support language; do not label as full recommender runtime.',
            ],
        ];
    }
}
