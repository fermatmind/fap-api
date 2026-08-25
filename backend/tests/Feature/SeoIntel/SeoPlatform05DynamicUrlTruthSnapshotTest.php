<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\UrlTruth\BoundedPublicUrlEvidenceProbe;
use App\Services\SeoIntel\UrlTruth\UrlTruthReconciliationSnapshot;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform05DynamicUrlTruthSnapshotTest extends TestCase
{
    #[Test]
    public function snapshot_computes_effective_public_truth_and_consumer_differences_without_historical_counts(): void
    {
        $current = $this->record('https://fermatmind.com/en/articles/current', 'en', 'current');
        $missing = $this->record('https://fermatmind.com/zh/articles/missing', 'zh-CN', 'missing');
        $private = $this->record(
            'https://fermatmind.com/zh/results/private',
            'zh-CN',
            'private',
            isPrivate: true,
        );
        $unclassified = $this->record(
            'https://fermatmind.com/en/unknown/unclassified',
            'en',
            'unclassified',
            entitySource: 'unknown_source',
        );

        $truth = [
            $this->truthRow($current),
            [
                'canonical_url' => 'https://fermatmind.com/en/articles/retired',
                'locale' => 'en',
                'page_entity_type' => 'article',
                'entity_id_or_slug' => 'retired',
                'source_authority' => 'backend_cms',
                'indexability_state' => 'indexable',
                'is_private_flow' => false,
            ],
        ];
        $bindings = [
            $this->bindingRow($current),
            $this->bindingRow($missing, 'duplicate-binding'),
            $this->bindingRow($missing, 'duplicate-binding'),
        ];

        $snapshot = (new UrlTruthReconciliationSnapshot)->build(
            [$current, $missing, $private, $unclassified],
            $truth,
            $bindings,
            [
                'public_api' => [$current->canonicalUrl, $missing->canonicalUrl],
                'sitemap' => [$current->canonicalUrl, 'https://fermatmind.com/en/articles/sitemap-only'],
                'llms' => null,
                'llms_full' => [$current->canonicalUrl],
            ],
        );

        $this->assertSame(4, data_get($snapshot, 'counts.authority_total'));
        $this->assertSame(2, data_get($snapshot, 'counts.effective_public'));
        $this->assertSame(4, data_get($snapshot, 'counts.authority_revision_traceable'));
        $this->assertSame(0, data_get($snapshot, 'counts.authority_revision_untraceable'));
        $this->assertSame(1, data_get($snapshot, 'counts.url_truth_valid'));
        $this->assertSame(1, data_get($snapshot, 'counts.authority_missing_url_truth'));
        $this->assertSame(1, data_get($snapshot, 'counts.current_binding_duplicate'));
        $this->assertSame(1, data_get($snapshot, 'counts.retired_or_authority_missing'));
        $this->assertGreaterThan(1, data_get($snapshot, 'counts.private_negative_set'));
        $this->assertSame(1, data_get($snapshot, 'counts.private_authority_excluded'));
        $this->assertSame(1, data_get($snapshot, 'counts.unclassified'));
        $this->assertSame(2, data_get($snapshot, 'family_locale_distribution.articles_topics|en')
            + data_get($snapshot, 'family_locale_distribution.articles_topics|zh-CN'));
        $this->assertSame(0, data_get($snapshot, 'consumer_differences.public_api.missing'));
        $this->assertSame(1, data_get($snapshot, 'consumer_differences.sitemap.missing'));
        $this->assertSame(1, data_get($snapshot, 'consumer_differences.sitemap.extra'));
        $this->assertSame('measurement_hold', data_get($snapshot, 'consumer_differences.llms.state'));
        $this->assertSame(1, data_get($snapshot, 'difference_classification.authority_without_url_truth'));
        $this->assertSame(0, data_get($snapshot, 'difference_classification.url_truth_duplicate'));
        $this->assertSame(1, data_get($snapshot, 'difference_classification.current_binding_duplicate'));
        $this->assertSame(0, data_get($snapshot, 'difference_classification.canonical_host_or_path_error'));
        $this->assertSame(0, data_get($snapshot, 'difference_classification.stale_authority_revision'));
        $this->assertSame(1, data_get($snapshot, 'difference_classification.sitemap_without_authority'));
        $this->assertSame(1, data_get($snapshot, 'difference_classification.authority_omitted_by_consumer.sitemap'));
        $this->assertNull(data_get($snapshot, 'difference_classification.authority_omitted_by_consumer.llms'));
        $this->assertSame(1, data_get($snapshot, 'difference_classification.retired_or_historical_as_current'));
        $this->assertFalse((bool) data_get($snapshot, 'boundaries.consumers_create_authority', true));
        $this->assertFalse((bool) data_get($snapshot, 'boundaries.search_submission_allowed', true));
    }

    #[Test]
    public function missing_url_truth_tables_are_measurement_holds_not_fake_zeroes(): void
    {
        $snapshot = (new UrlTruthReconciliationSnapshot)->build(
            [$this->record('https://fermatmind.com/en/articles/current', 'en', 'current')],
            null,
            null,
            ['public_api' => null, 'sitemap' => null, 'llms' => null, 'llms_full' => null],
        );

        $this->assertSame('measurement_hold', data_get($snapshot, 'source_state.url_truth'));
        $this->assertSame('measurement_hold', data_get($snapshot, 'source_state.entity_bindings'));
        $this->assertNull(data_get($snapshot, 'counts.url_truth_total'));
        $this->assertNull(data_get($snapshot, 'counts.url_truth_valid'));
        $this->assertNull(data_get($snapshot, 'counts.authority_missing_url_truth'));
        $this->assertNull(data_get($snapshot, 'counts.current_binding_duplicate'));
    }

    #[Test]
    public function unavailable_authority_is_a_measurement_hold_not_an_empty_public_site(): void
    {
        $snapshot = (new UrlTruthReconciliationSnapshot)->build(
            [],
            [],
            [],
            ['public_api' => [], 'sitemap' => [], 'llms' => [], 'llms_full' => []],
            ['state' => 'measurement_hold'],
            ['authority' => 'measurement_hold'],
        );

        $this->assertNull(data_get($snapshot, 'counts.authority_total'));
        $this->assertNull(data_get($snapshot, 'counts.effective_public'));
        $this->assertNull(data_get($snapshot, 'counts.authority_revision_traceable'));
        $this->assertNull(data_get($snapshot, 'counts.url_truth_valid'));
        $this->assertSame('measurement_hold', data_get($snapshot, 'consumer_differences.sitemap.state'));
    }

    #[Test]
    public function live_probe_is_bounded_and_returns_a_sanitized_resume_cursor(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);
        config(['app.public_api_url' => 'https://api.fermatmind.com']);
        $records = [
            $this->record('https://fermatmind.com/en/articles/alpha', 'en', 'alpha'),
            $this->record('https://fermatmind.com/en/articles/bravo', 'en', 'bravo'),
            $this->record('https://fermatmind.com/en/articles/charlie', 'en', 'charlie'),
        ];
        $allUrls = array_column(array_map(static fn (UrlTruthInventoryRecord $record): array => [
            'loc' => $record->canonicalUrl,
        ], $records), 'loc');

        Http::fake(function (Request $request) use ($allUrls) {
            $url = $request->url();
            if (str_ends_with($url, '/api/v0.5/seo/sitemap-source')) {
                return Http::response(['ok' => true, 'items' => array_map(static fn (string $loc): array => ['loc' => $loc], $allUrls)]);
            }
            if (str_ends_with($url, '/sitemap.xml')) {
                return Http::response('<urlset>'.implode('', array_map(static fn (string $loc): string => '<url><loc>'.$loc.'</loc></url>', $allUrls)).'</urlset>');
            }
            if (str_ends_with($url, '/llms.txt') || str_ends_with($url, '/llms-full.txt')) {
                return Http::response(implode("\n", $allUrls));
            }

            return Http::response($this->html($url), 200);
        });

        $probe = (new BoundedPublicUrlEvidenceProbe)->collect($records, null, 1, 99, 99, 99);

        $this->assertSame(1, data_get($probe, 'live_http.requested_count'));
        $this->assertSame(4, data_get($probe, 'live_http.concurrency'));
        $this->assertSame(15, data_get($probe, 'live_http.timeout_seconds'));
        $this->assertSame(2, data_get($probe, 'live_http.max_retries'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($probe, 'live_http.next_resume_cursor'));
        $this->assertFalse((bool) data_get($probe, 'live_http.raw_url_emitted', true));
        $this->assertFalse((bool) data_get($probe, 'live_http.response_body_emitted', true));
        $this->assertCount(3, $probe['consumer_urls']['public_api'] ?? []);
        $this->assertCount(3, $probe['consumer_urls']['sitemap'] ?? []);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.fermatmind.com/api/v0.5/seo/sitemap-source');
    }

    #[Test]
    public function deploy_receipt_runs_after_public_dns_and_preserves_read_only_boundaries(): void
    {
        $deploy = (string) file_get_contents(dirname(__DIR__, 4).'/deploy.php');

        $this->assertStringContainsString("task('seo:url-truth-reconciliation-receipt'", $deploy);
        $this->assertStringContainsString("after('healthcheck:public-dns', 'seo:url-truth-reconciliation-receipt');", $deploy);
        $this->assertStringContainsString('seo-intel:url-truth-reconcile-snapshot', $deploy);
        $this->assertStringContainsString('$boundaries["search_submission_allowed"] ?? null) === false', $deploy);
        $this->assertStringContainsString('$sources["live_http"] ?? null) === "measurement_hold"', $deploy);
        $this->assertStringContainsString('$live["requested_count"] ?? 0) <= 10', $deploy);
        $this->assertStringNotContainsString('request-indexing', $deploy);
    }

    private function record(
        string $url,
        string $locale,
        string $slug,
        bool $isPrivate = false,
        string $entitySource = 'articles',
    ): UrlTruthInventoryRecord {
        return new UrlTruthInventoryRecord(
            canonicalUrl: $url,
            locale: $locale,
            pageEntityType: 'article',
            entityIdOrSlug: $slug,
            sourceAuthority: 'backend_cms',
            indexabilityState: 'indexable',
            lastmodAt: Carbon::parse('2026-08-25T00:00:00Z'),
            lastmodSource: 'published_revision',
            entitySource: $entitySource,
            authorityStatus: 'published_approved',
            sourceUpdatedAt: Carbon::parse('2026-08-25T00:00:00Z'),
            isPrivateFlow: $isPrivate,
            metadata: [
                'publication_state' => 'published',
                'robots' => 'index,follow',
                'canonical_self' => true,
                'authority_revision' => 'revision-'.$slug,
            ],
        );
    }

    /** @return array<string,mixed> */
    private function truthRow(UrlTruthInventoryRecord $record): array
    {
        return [
            'canonical_url' => $record->canonicalUrl,
            'locale' => $record->locale,
            'page_entity_type' => $record->pageEntityType,
            'entity_id_or_slug' => $record->entityIdOrSlug,
            'source_authority' => $record->sourceAuthority,
            'authority_revision' => hash('sha256', 'authority_revision|'.(string) ($record->metadata['authority_revision'] ?? '')),
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function bindingRow(UrlTruthInventoryRecord $record, ?string $hashSeed = null): array
    {
        return [
            'canonical_url_hash' => $hashSeed === null
                ? hash('sha256', rtrim($record->canonicalUrl, '/'))
                : hash('sha256', $hashSeed),
            'page_entity_type' => $record->pageEntityType,
            'entity_id_or_slug' => $record->entityIdOrSlug,
            'locale' => $record->locale,
            'authority_status' => 'published_approved',
        ];
    }

    private function html(string $canonical): string
    {
        return '<html><head>'
            .'<link rel="canonical" href="'.$canonical.'">'
            .'<link rel="alternate" hreflang="en" href="'.$canonical.'">'
            .'<meta name="robots" content="index,follow">'
            .'</head><body><h1>Public</h1></body></html>';
    }
}
