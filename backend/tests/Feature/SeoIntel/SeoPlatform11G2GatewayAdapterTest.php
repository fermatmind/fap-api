<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceBoundaryGuard;
use App\Services\SeoAgentEvidence\Competitive\CompetitivePageProjector;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveSourceRegistry;
use App\Services\SeoAgentEvidence\External\ExternalContentGateway;
use App\Services\SeoAgentEvidence\External\ExternalContentTransport;
use App\Services\SeoAgentEvidence\External\ExternalDnsResolver;
use App\Services\SeoAgentEvidence\External\NativeExternalDnsResolver;
use App\Services\SeoAgentEvidence\External\PinnedTlsExternalContentTransport;
use App\Services\SeoAgentEvidence\External\RobotsPolicyEvaluator;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform11G2GatewayAdapterTest extends TestCase
{
    public function test_production_adapters_are_bound_and_transport_fails_closed_before_network(): void
    {
        $this->assertInstanceOf(NativeExternalDnsResolver::class, app(ExternalDnsResolver::class));
        $transport = app(ExternalContentTransport::class);
        $this->assertInstanceOf(PinnedTlsExternalContentTransport::class, $transport);
        $this->expectException(\RuntimeException::class);
        $transport->request('GET', 'http://example.com/', '93.184.216.34', 1, 1, 1024);
    }

    public function test_robots_uses_specific_group_wildcards_end_anchor_and_allow_tie(): void
    {
        $robots = new RobotsPolicyEvaluator;
        $body = "User-agent: *\nDisallow: /\n\nUser-agent: FermatMindCompetitiveEvidence\nDisallow: /private/*\nAllow: /private/public$\nDisallow: /same\nAllow: /same\n";

        $this->assertTrue($robots->allows($body, '/private/public'));
        $this->assertFalse($robots->allows($body, '/private/public/child'));
        $this->assertTrue($robots->allows($body, '/same'));
        $this->assertTrue($robots->allows($body, '/public'));
    }

    public function test_policy_hash_excludes_dynamic_site_chrome_but_tracks_policy_body(): void
    {
        $normalizer = new \ReflectionMethod(ExternalContentGateway::class, 'normalizedPolicyText');
        $gateway = app(ExternalContentGateway::class);
        $first = '<html><body><header>live count 1</header><main><h1>Terms</h1><p>Stable rule.</p></main><footer>build a</footer></body></html>';
        $chromeChanged = '<html><body><header>live count 2</header><main><h1>Terms</h1><p>Stable rule.</p></main><footer>build b</footer></body></html>';
        $policyChanged = '<html><body><header>live count 2</header><main><h1>Terms</h1><p>Changed rule.</p></main><footer>build b</footer></body></html>';

        $this->assertSame($normalizer->invoke($gateway, $first), $normalizer->invoke($gateway, $chromeChanged));
        $this->assertNotSame($normalizer->invoke($gateway, $first), $normalizer->invoke($gateway, $policyChanged));
    }

    public function test_competitive_gateway_reads_identical_terms_and_license_evidence_once(): void
    {
        $hasher = app(\App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher::class);
        $transport = new class implements ExternalContentTransport
        {
            /** @var list<string> */
            public array $requests = [];

            public function request(string $method, string $url, string $approvedIp, int $connectTimeoutSeconds, int $requestTimeoutSeconds, int $maxBytes): array
            {
                $this->requests[] = $url;
                $body = match ($url) {
                    'https://example.com/robots.txt' => "User-agent: *\nDisallow:",
                    'https://example.com/policy' => '<main><p>MIT license permits structural use.</p></main>',
                    default => '<main><section class="hero"><h1>Public Big Five</h1></section></main>',
                };

                return [
                    'status' => 200,
                    'headers' => [
                        'content-type' => str_ends_with($url, '/robots.txt') ? 'text/plain' : 'text/html',
                        'content-length' => (string) strlen($body),
                    ],
                    'body' => $body,
                    'connected_ip' => $approvedIp,
                ];
            }
        };
        $dns = new class implements ExternalDnsResolver
        {
            public function resolveAll(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        $policyUrl = 'https://example.com/policy';
        $policyEvidenceHash = $hasher->hash('mit license permits structural use.');
        config()->set('seo_agent_evidence.external_fetch_enabled', true);
        config()->set('seo_agent_evidence.agent_external_egress', false);
        config()->set('seo_agent_evidence.allowed_sources', ['public' => [
            'source_id' => 'public',
            'policy_id' => 'competitive.source.public.v3',
            'policy_version' => 3,
            'policy_hash' => str_repeat('a', 64),
            'exact_source_url' => 'https://example.com/facts',
            'allowed_hosts' => ['example.com'],
            'allowed_protocols' => ['https'],
            'allowed_ports' => [443],
            'allowed_path_prefixes' => ['/facts', '/policy', '/robots.txt'],
            'redirect_policy' => 0,
            'robots_required' => true,
            'robots_url' => 'https://example.com/robots.txt',
            'robots_evidence_hash' => $hasher->hash('user-agent: * disallow:'),
            'minimum_request_interval' => 1,
            'max_concurrency' => 1,
            'max_content_bytes' => 524288,
            'allowed_content_types' => ['text/html', 'text/plain'],
            'connect_timeout_seconds' => 3,
            'request_timeout_seconds' => 8,
            'terms_status' => 'approved',
            'terms_reviewed_at' => '2026-09-02T00:00:00Z',
            'terms_url' => $policyUrl,
            'terms_url_hash' => $hasher->hash($policyUrl),
            'terms_evidence_hash' => $policyEvidenceHash,
            'license_url' => $policyUrl,
            'license_url_hash' => $hasher->hash($policyUrl),
            'license_evidence_hash' => $policyEvidenceHash,
            'login_required' => false,
            'technical_restriction_state' => 'permitted',
            'license_class' => 'public_fact_permitted',
            'allowed_saved_fields' => ['structured_facts'],
            'max_snippet_chars' => 1,
            'retention_class' => 'external_structured_fact',
            'data_usage_purpose' => 'competitive_evidence',
            'collection_state' => 'approved',
            'expires_at' => '2026-10-02T00:00:00Z',
        ]]);
        $gateway = new ExternalContentGateway(
            $dns,
            $transport,
            app(\App\Services\SeoAgentEvidence\External\ExternalInjectionScanner::class),
            app(\App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner::class),
            $hasher,
            app(\App\Services\SeoAgentEvidence\Privacy\SeoQueryHmac::class),
            new RobotsPolicyEvaluator,
            app(CompetitivePageProjector::class),
        );

        $result = $gateway->fetchCompetitive('public', 'https://example.com/facts', [
            'cohort_id' => 'competitive.big-five.live.v2',
            'source_class' => 'competitor_public',
            'page_family' => 'tests',
            'locale' => 'en',
        ], app(CompetitiveSourceRegistry::class)->semanticRegistry());

        $this->assertSame('ready', $result['status']);
        $this->assertSame(4, $result['dependency_ingestion']['external_reads']);
        $this->assertSame(1, count(array_filter($transport->requests, static fn (string $url): bool => $url === $policyUrl)));
    }

    public function test_registry_is_hash_bound_exact_url_only_and_live(): void
    {
        $registry = app(CompetitiveSourceRegistry::class);
        $cohort = $registry->cohort('competitive.big-five.live.v2');
        $this->assertSame('approved', $cohort['collection_state']);
        $this->assertSame('NONE', $cohort['hold_reason']);
        $sources = $registry->sourceRegistry()['sources'];
        $this->assertSame(['123test', 'truity', '16personalities', 'bigfive-test', 'openpsychometrics', 'b5-allthethings'], array_values(array_map(
            static fn (array $source): string => $source['source_id'],
            array_filter($sources, static fn (array $source): bool => $source['source_class'] === 'competitor_public'),
        )));
        foreach ($sources as $source) {
            $this->assertStringStartsWith('https://', $source['url']);
            $this->assertNull(parse_url($source['url'], PHP_URL_QUERY));
            $this->assertNull(parse_url($source['url'], PHP_URL_FRAGMENT));
        }
    }

    public function test_projector_emits_only_registered_structural_projection(): void
    {
        $semantic = app(CompetitiveSourceRegistry::class)->semanticRegistry();
        $html = <<<'HTML'
<!doctype html><html><head>
<link rel="canonical" href="https://example.com/test">
<link rel="alternate" hreflang="en" href="https://example.com/test">
<script type="application/ld+json">{"@type":"WebPage","about":"Big Five personality","mainEntity":{"@type":"Quiz"},"aggregateRating":{"ratingValue":"5"}}</script>
</head><body><main><section class="hero"><h1>Copied competitor heading must not survive</h1></section><section class="faq"></section></main><a href="/personality/big-five">Related</a></body></html>
HTML;
        $projection = app(CompetitivePageProjector::class)->project([
            'source_id' => 'source-a', 'cohort_id' => 'competitive.big-five.live.v1', 'source_class' => 'competitor_public',
            'page_family' => 'tests', 'locale' => 'en', 'public_url' => 'https://example.com/test', 'body' => $html,
            'captured_at' => '2026-09-01T00:00:00Z',
            'source_policy_ref' => ['policy_id' => 'policy.source-a', 'policy_version' => 1, 'policy_hash' => str_repeat('a', 64), 'status' => 'approved', 'expires_at' => '2026-09-30T00:00:00Z'],
        ], $semantic);

        $this->assertSame('seo.competitive_page_projection.v2', $projection['version']);
        $this->assertTrue(app(CompetitiveEvidenceBoundaryGuard::class)->projection($projection));
        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Copied competitor', $encoded);
        $this->assertStringNotContainsString('<html', $encoded);
        $this->assertArrayHasKey('entity_relations', $projection['structure']);
        $this->assertSame('claim.vendor-rating', $projection['structure']['claim_signals'][0]['claim_id']);
    }

    public function test_projector_rejects_login_and_paywall_pages(): void
    {
        $input = [
            'source_id' => 'source-a', 'cohort_id' => 'competitive.big-five.live.v1', 'source_class' => 'competitor_public',
            'page_family' => 'tests', 'locale' => 'en', 'public_url' => 'https://example.com/test',
            'captured_at' => '2026-09-01T00:00:00Z',
            'source_policy_ref' => ['policy_id' => 'policy.source-a', 'policy_version' => 1, 'policy_hash' => str_repeat('a', 64), 'status' => 'approved', 'expires_at' => '2026-09-30T00:00:00Z'],
        ];
        foreach (['<html><input type="password"></html>', '<html><div data-paywall="true"></div></html>', '<script type="application/ld+json">{"isAccessibleForFree":false}</script>'] as $body) {
            try {
                app(CompetitivePageProjector::class)->project($input + ['body' => $body], app(CompetitiveSourceRegistry::class)->semanticRegistry());
                $this->fail('Expected login/paywall hold.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('COMPETITIVE_LOGIN_OR_PAYWALL', $exception->getMessage());
            }
        }
    }

    public function test_ingest_command_requires_measurement_and_controlled_write_boundary(): void
    {
        $this->artisan('seo:competitive-evidence-ingest', [
            '--cohort' => 'competitive.big-five.live.v2', '--dry-run' => true, '--no-write' => true, '--json' => true,
        ])->expectsOutputToContain('"SEO-PLATFORM-11G":"HOLD"')
            ->assertSuccessful();

        $this->artisan('seo:competitive-evidence-ingest', ['--cohort' => 'not-registered', '--dry-run' => true, '--json' => true])
            ->expectsOutputToContain('COMPETITIVE_REGISTRY_INVALID')
            ->assertFailed();
        $this->artisan('seo:competitive-evidence-ingest', ['--cohort' => 'competitive.big-five.live.v2', '--json' => true])
            ->expectsOutputToContain('COMPETITIVE_INGEST_MODE_INVALID')
            ->assertFailed();
    }
}
