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
