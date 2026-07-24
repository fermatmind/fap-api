<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\SeoIntel\CoreEntrySloManifest;
use App\Services\SeoIntel\CoreEntrySloObserver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoCoreEntrySloObservabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seo_intel.public_canonical_host' => 'https://slo.fermatmind.test',
            'seo_intel.core_entry_slo.public_host_allowlist' => ['slo.fermatmind.test'],
        ]);
    }

    #[Test]
    public function deterministic_manifest_has_exact_l1_l2_l3_public_entry_scope(): void
    {
        $manifest = app(CoreEntrySloManifest::class)->resolve();
        $targets = $manifest['targets'];

        $this->assertCount(16, $targets);
        $this->assertSame(['L1', 'L2', 'L3'], $manifest['tier_order']);
        $this->assertSame(2, count(array_filter($targets, fn (array $target): bool => $target['tier'] === 'L1')));
        $this->assertSame(2, count(array_filter($targets, fn (array $target): bool => $target['tier'] === 'L2')));
        $this->assertSame(12, count(array_filter($targets, fn (array $target): bool => $target['tier'] === 'L3')));
        $this->assertSame(
            $manifest['manifest_sha256'],
            app(CoreEntrySloManifest::class)->resolve()['manifest_sha256']
        );

        $this->assertSame([
            '/en/tests/mbti-personality-test-16-personality-types',
            '/zh/tests/mbti-personality-test-16-personality-types',
        ], array_values(array_map(
            static fn (array $target): string => $target['path'],
            array_filter($targets, static fn (array $target): bool => $target['tier'] === 'L1')
        )));

        $families = array_values(array_unique(array_column($targets, 'page_family')));
        sort($families);
        $this->assertSame(['articles', 'career', 'test_detail'], $families);

        foreach ($targets as $target) {
            $this->assertMatchesRegularExpression('#^/(en|zh)/#', $target['path']);
            $this->assertMatchesRegularExpression('#^/(en|zh)/#', $target['alternate_path']);
            $this->assertStringNotContainsString('?', $target['path']);
            $this->assertStringNotContainsString('#', $target['path']);
            $this->assertNotEmpty($target['ssr_markers']);
            $this->assertNotEmpty($target['primary_cta_markers']);

            foreach (['result', 'attempt', 'order', 'recovery', 'payment', 'checkout', 'report'] as $private) {
                $this->assertStringNotContainsString('/'.$private.'/', strtolower($target['path']));
            }
        }
    }

    #[Test]
    public function manifest_fails_closed_before_http_when_a_private_path_is_injected(): void
    {
        $original = config('seo_intel.core_entry_slo.targets');
        $targets = $original;
        $targets[0]['path'] = '/en/results/private-attempt';
        config(['seo_intel.core_entry_slo.targets' => $targets]);
        Http::fake();

        try {
            app(CoreEntrySloManifest::class)->resolve();
            $this->fail('Expected private-path manifest rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('core_entry_slo_private_path_forbidden', $exception->getMessage());
        } finally {
            config(['seo_intel.core_entry_slo.targets' => $original]);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function manifest_fails_closed_for_normalized_loopback_hosts_before_http(): void
    {
        $original = config('seo_intel.public_canonical_host');
        Http::fake();

        try {
            foreach (['https://[::1]', 'https://localhost.'] as $privateHost) {
                config(['seo_intel.public_canonical_host' => $privateHost]);

                try {
                    app(CoreEntrySloManifest::class)->resolve();
                    $this->fail('Expected normalized private-host rejection for '.$privateHost);
                } catch (InvalidArgumentException $exception) {
                    $this->assertSame('core_entry_slo_public_base_url_private', $exception->getMessage());
                }
            }
        } finally {
            config(['seo_intel.public_canonical_host' => $original]);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function manifest_fails_closed_for_a_hostname_outside_the_public_allowlist_before_http(): void
    {
        $original = config('seo_intel.public_canonical_host');
        config(['seo_intel.public_canonical_host' => 'https://untrusted-public.example']);
        Http::fake();

        try {
            app(CoreEntrySloManifest::class)->resolve();
            $this->fail('Expected public-host allowlist rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('core_entry_slo_public_base_url_not_allowed', $exception->getMessage());
        } finally {
            config(['seo_intel.public_canonical_host' => $original]);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function command_probes_the_exact_manifest_with_bounded_concurrency_and_writes_only_a_sanitized_artifact(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://slo.fermatmind.test']);
        $manifest = app(CoreEntrySloManifest::class)->resolve();
        $targetsByPath = collect($manifest['targets'])->keyBy('path');

        Http::fake(function (Request $request) use ($targetsByPath) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $target = $targetsByPath->get($path);
            $this->assertIsArray($target);

            return Http::response($this->healthyHtml($target), 200, [
                'Server-Timing' => 'ttfb;dur=90',
                'X-FermatMind-Content-State' => 'fresh',
            ]);
        });

        $artifactDir = $this->artifactDir('healthy');
        $exitCode = Artisan::call('seo-intel:core-entry-slo-observe', [
            '--concurrency' => 3,
            '--timeout' => 6,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($summary['ok']);
        $this->assertSame('healthy', $summary['status']);
        $this->assertSame(16, $summary['target_count']);
        $this->assertSame(0, $summary['incident_count']);
        $this->assertTrue($summary['local_artifact_write']);
        Http::assertSentCount(16);
        Http::assertSent(function (Request $request): bool {
            $path = strtolower((string) parse_url($request->url(), PHP_URL_PATH));
            $headers = $request->headers();

            return $request->method() === 'GET'
                && preg_match('#^/(en|zh)/#', $path) === 1
                && ! preg_match('#/(results?|attempts?|orders?|payments?|reports?|checkout|recovery)(?:/|$)#', $path)
                && ! isset($headers['Authorization'])
                && ! isset($headers['Cookie']);
        });

        $artifact = $this->readArtifact((string) data_get($summary, 'artifact.path'));
        $this->assertSame(CoreEntrySloObserver::SCHEMA_VERSION, $artifact['schema_version']);
        $this->assertTrue($artifact['slo_met']);
        $this->assertSame(3, data_get($artifact, 'execution.concurrency'));
        $this->assertSame(16, data_get($artifact, 'execution.request_count'));
        $this->assertSame('healthy', data_get($artifact, 'ops_read_model.overall_status'));
        $this->assertSame(['L1', 'L2', 'L3'], data_get($artifact, 'ops_read_model.priority_order'));
        $this->assertSame(['fresh' => 16], data_get($artifact, 'ops_read_model.delivery_mode_counts'));

        foreach ($artifact['results'] as $result) {
            $this->assertSame('healthy', $result['status']);
            $this->assertSame('pass', data_get($result, 'ssr_visible_content.status'));
            $this->assertSame('pass', data_get($result, 'canonical.status'));
            $this->assertSame('pass', data_get($result, 'robots.status'));
            $this->assertSame('pass', data_get($result, 'hreflang.status'));
            $this->assertSame('pass', data_get($result, 'primary_cta.status'));
            $this->assertSame('healthy', data_get($result, 'dependency_state.upstream'));
        }

        $encoded = json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([
            'https://slo.fermatmind.test',
            '<html',
            '<body',
            'raw_html',
            'raw_headers',
            'Authorization',
            'Bearer ',
            'private-attempt',
            '/orders/',
            '/payments/',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }

        foreach ([
            'database_write',
            'cms_write',
            'cms_publish',
            'sitemap_write',
            'llms_write',
            'search_channel_enqueue',
            'search_channel_submit',
            'indexing_request',
            'scheduler_activation',
            'private_url_probe',
        ] as $field) {
            $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function artifact_prioritizes_l1_and_keeps_5xx_thin_shell_canonical_and_robots_incidents_distinct(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://slo.fermatmind.test']);
        $manifest = app(CoreEntrySloManifest::class)->resolve();
        $targetsByPath = collect($manifest['targets'])->keyBy('path');

        Http::fake(function (Request $request) use ($targetsByPath) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $target = $targetsByPath->get($path);
            $this->assertIsArray($target);
            $headers = [
                'Server-Timing' => $target['id'] === 'l3_articles_en' ? 'ttfb;dur=9000' : 'ttfb;dur=100',
                'X-FermatMind-Content-State' => $target['id'] === 'l3_career_en' ? 'last-known-good' : 'fresh',
            ];
            if ($target['id'] === 'l3_iq_en') {
                $headers['X-Robots-Tag'] = 'none';
            }

            return match ($target['id']) {
                'l1_mbti_en' => Http::response('', 503, $headers),
                'l2_big_five_en' => Http::response(
                    $this->healthyHtml($target, marker: 'data-testid="test-detail-minimal-shell"'),
                    200,
                    [...$headers, 'X-FermatMind-Content-State' => 'minimal-shell']
                ),
                'l3_riasec_en' => Http::response(
                    $this->healthyHtml($target, canonicalPath: '/en/tests/wrong-owner'),
                    200,
                    $headers
                ),
                'l3_eq_en' => Http::response(
                    $this->healthyHtml($target, robots: 'noindex, follow'),
                    200,
                    $headers
                ),
                default => Http::response($this->healthyHtml($target), 200, $headers),
            };
        });

        $artifactDir = $this->artifactDir('incidents');
        $exitCode = Artisan::call('seo-intel:core-entry-slo-observe', [
            '--concurrency' => 99,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $artifact = $this->readArtifact((string) data_get($summary, 'artifact.path'));

        $this->assertSame(1, $exitCode);
        $this->assertFalse($summary['slo_met']);
        $this->assertSame('incident', $summary['status']);
        $this->assertSame('L1', $summary['alert_priority']);
        $this->assertSame('critical', data_get($artifact, 'ops_read_model.overall_status'));
        $this->assertSame(4, data_get($artifact, 'execution.concurrency'));
        $this->assertSame(1, data_get($artifact, 'ops_read_model.incident_category_counts.http_5xx'));
        $this->assertSame(1, data_get($artifact, 'ops_read_model.incident_category_counts.thin_shell'));
        $this->assertSame(1, data_get($artifact, 'ops_read_model.incident_category_counts.canonical_drift'));
        $this->assertSame(2, data_get($artifact, 'ops_read_model.incident_category_counts.robots_drift'));
        $this->assertSame(1, data_get($artifact, 'ops_read_model.incident_category_counts.ttfb_breach'));
        $this->assertSame('critical', data_get($artifact, 'ops_read_model.tiers.L1.status'));
        $this->assertSame('high', data_get($artifact, 'ops_read_model.tiers.L2.status'));
        $this->assertSame('degraded', data_get($artifact, 'ops_read_model.tiers.L3.status'));
        $this->assertSame(1, data_get($artifact, 'ops_read_model.delivery_mode_counts.last_known_good'));
        $this->assertSame(1, data_get($artifact, 'ops_read_model.delivery_mode_counts.minimal_shell'));

        $results = collect($artifact['results'])->keyBy('target_id');
        $this->assertSame(['http_5xx'], $results['l1_mbti_en']['incident_categories']);
        $this->assertContains('thin_shell', $results['l2_big_five_en']['incident_categories']);
        $this->assertContains('canonical_drift', $results['l3_riasec_en']['incident_categories']);
        $this->assertContains('robots_drift', $results['l3_eq_en']['incident_categories']);
        $this->assertContains('robots_drift', $results['l3_iq_en']['incident_categories']);
        $this->assertContains('ttfb_breach', $results['l3_articles_en']['incident_categories']);
        $this->assertSame('last_known_good', data_get($results['l3_career_en'], 'dependency_state.delivery_mode'));
        $this->assertSame('degraded', data_get($results['l3_career_en'], 'dependency_state.cms_api'));
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function healthyHtml(
        array $target,
        ?string $canonicalPath = null,
        string $robots = 'index, follow',
        ?string $marker = null,
    ): string {
        $canonicalPath ??= (string) $target['path'];
        $marker ??= (string) $target['ssr_markers'][0];
        $ctaMarker = (string) $target['primary_cta_markers'][0];
        preg_match('/^(href|action)="([^"]+)"?$/', $ctaMarker, $ctaParts);
        $ctaAttribute = (string) ($ctaParts[1] ?? '');
        $ctaPath = (string) ($ctaParts[2] ?? '');
        if (! str_ends_with($ctaMarker, '"')) {
            $ctaPath .= 'fixture-entry';
        }
        $ctaUrl = $ctaAttribute === 'href'
            ? 'https://slo.fermatmind.test'.$ctaPath.'?source=slo'
            : $ctaPath.'?source=slo';
        $cta = $ctaAttribute === 'action'
            ? '<form action="'.$ctaUrl.'" method="get"><button type="submit">Continue</button></form>'
            : '<a href="'.$ctaUrl.'">Continue</a>';

        return '<!doctype html><html><head>'
            .'<link rel="canonical" href="https://slo.fermatmind.test'.$canonicalPath.'">'
            .'<link rel="alternate" hreflang="'.$target['alternate_hreflang'].'" href="https://slo.fermatmind.test'.$target['alternate_path'].'">'
            .'<meta name="robots" content="'.$robots.'">'
            .'</head><body><main><h1>Visible public entry</h1>'
            .'<section '.$marker.'>Backend-authoritative visible content.</section>'
            .$cta
            .'</main></body></html>';
    }

    private function artifactDir(string $suffix): string
    {
        $path = storage_path('framework/testing/seo-core-entry-slo-'.$suffix);
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readArtifact(string $path): array
    {
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
