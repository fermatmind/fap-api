<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\Runtime\AuthorityDrivenCohortResolver;
use App\Services\SeoIntel\Runtime\ProductionCalibrationCloseoutService;
use App\Services\SeoIntel\Runtime\ProductionCalibrationProbeService;
use App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService;
use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform07ProductionCalibrationCloseoutTest extends TestCase
{
    #[Test]
    public function authority_driven_probe_observes_every_family_locale_and_the_private_negative_set_without_writes(): void
    {
        config(['app.git_sha' => str_repeat('a', 40)]);
        $this->app->instance(UrlTruthInventorySource::class, $this->authoritySource());
        Http::fake(static function (Request $request) {
            if (str_contains($request->url(), '/share/seo-platform-07-negative-set')) {
                return Http::response('<meta name="robots" content="noindex,nofollow">', 200);
            }

            return Http::response(
                '',
                str_contains($request->url(), 'seo-platform-07-negative-set') ? 404 : 200,
            );
        });

        $result = app(ProductionCalibrationProbeService::class)->observe();

        $this->assertSame('success', $result['state']);
        $this->assertSame(12, $result['expected_cell_count']);
        $this->assertSame(12, $result['observed_cell_count']);
        $this->assertTrue(data_get($result, 'private_negative_set.accepted'));
        $this->assertSame(0, data_get($result, 'private_negative_set.exposure_count'));
        $this->assertSame(1, data_get($result, 'private_negative_set.accepted_noindex_probe_count'));
        $this->assertSame(str_repeat('a', 40), $result['deploy_revision']);
        $this->assertFalse(data_get($result, 'boundaries.raw_url_emitted'));
        $this->assertFalse(data_get($result, 'boundaries.production_write_authorization_granted'));
        Http::assertSentCount(12 + count((new PageFamilyPolicyRegistry)->privatePathSegments()));
    }

    #[Test]
    public function deployed_revision_file_precedes_a_stale_cached_ci_sha(): void
    {
        $revisionPath = tempnam(sys_get_temp_dir(), 'seo-runtime-revision-');
        $this->assertIsString($revisionPath);
        file_put_contents($revisionPath, str_repeat('b', 40));
        config([
            'app.git_sha' => str_repeat('a', 40),
            'seo_council.release_revision_path' => $revisionPath,
        ]);
        $this->app->instance(UrlTruthInventorySource::class, $this->authoritySource());
        Http::fake(fn (Request $request) => Http::response(
            '',
            str_contains($request->url(), 'seo-platform-07-negative-set') ? 404 : 200,
        ));

        try {
            $result = app(ProductionCalibrationProbeService::class)->observe();
        } finally {
            unlink($revisionPath);
        }

        $this->assertSame(str_repeat('b', 40), $result['deploy_revision']);
    }

    #[Test]
    public function controlled_acceptance_can_observe_only_the_live_private_negative_set(): void
    {
        config(['app.git_sha' => str_repeat('a', 40)]);
        Http::fake(fn () => Http::response('', 404));

        $result = app(ProductionCalibrationProbeService::class)->observePrivateNegativeSet();

        $this->assertSame('MEASUREMENT_HOLD', $result['state']);
        $this->assertSame(0, $result['observed_cell_count']);
        $this->assertSame([], $result['cells']);
        $this->assertTrue(data_get($result, 'private_negative_set.checked'));
        $this->assertTrue(data_get($result, 'private_negative_set.accepted'));
        $this->assertSame(str_repeat('a', 40), $result['deploy_revision']);
        Http::assertSentCount(count((new PageFamilyPolicyRegistry)->privatePathSegments()));
    }

    #[Test]
    public function three_complete_natural_slots_bound_to_one_deploy_prove_production(): void
    {
        $storedWindow = json_decode(json_encode($this->window(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $result = (new ProductionCalibrationCloseoutService)->evaluate($storedWindow);

        $this->assertSame('production_proven', $result['state']);
        $this->assertTrue($result['direct_evidence_complete']);
        $this->assertSame([], $result['blockers']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['contract_projection_hash']);
        $this->assertTrue(data_get($result, 'boundaries.api_ui_receipt_share_one_projection'));
    }

    #[Test]
    public function database_json_object_key_reordering_does_not_invalidate_receipts(): void
    {
        $window = $this->window();
        $window['receipts'] = array_map($this->reverseObjectKeys(...), $window['receipts']);
        foreach ($window['receipts'] as &$receipt) {
            $receipt['crawler_source_receipt']['age_minutes'] = 11.314861916666668;
        }
        unset($receipt);

        $result = (new ProductionCalibrationCloseoutService)->evaluate($window);

        $this->assertSame('production_proven', $result['state']);
        $this->assertSame([], $result['blockers']);
    }

    #[Test]
    public function indexable_success_response_in_the_private_negative_set_fails_closed(): void
    {
        config(['app.git_sha' => str_repeat('a', 40)]);
        $this->app->instance(UrlTruthInventorySource::class, $this->authoritySource());
        Http::fake(static function (Request $request) {
            if (str_contains($request->url(), '/account/seo-platform-07-negative-set')) {
                return Http::response('<meta name="robots" content="index,follow">', 200);
            }

            return Http::response('', str_contains($request->url(), 'seo-platform-07-negative-set') ? 404 : 200);
        });

        $result = app(ProductionCalibrationProbeService::class)->observe();

        $this->assertSame('MEASUREMENT_HOLD', $result['state']);
        $this->assertFalse(data_get($result, 'private_negative_set.accepted'));
        $this->assertSame(1, data_get($result, 'private_negative_set.exposure_count'));
    }

    #[Test]
    public function manual_proven_state_or_incomplete_direct_evidence_cannot_close_the_window(): void
    {
        $window = $this->window();
        $window['receipts'][0]['trigger_mode'] = 'manual';
        $window['receipts'][1]['production_calibration']['cells']['tests|en']['success_count'] = 0;
        $window['receipts'][2]['production_calibration']['private_negative_set']['accepted'] = false;

        $result = (new ProductionCalibrationCloseoutService)->evaluate($window + ['state' => 'production_proven']);

        $this->assertSame('production_unproven', $result['state']);
        $this->assertFalse($result['direct_evidence_complete']);
        $this->assertContains('slot_1_receipt_invalid', $result['blockers']);
        $this->assertContains('slot_2_cell_tests_en_invalid', $result['blockers']);
        $this->assertContains('slot_3_private_negative_set_unaccepted', $result['blockers']);
    }

    #[Test]
    public function deploy_drift_and_missing_cells_fail_closed(): void
    {
        $window = $this->window();
        $window['receipts'][0]['production_calibration']['deploy_revision'] = str_repeat('b', 40);
        unset($window['receipts'][1]['production_calibration']['cells']['career|zh-CN']);

        $result = (new ProductionCalibrationCloseoutService)->evaluate($window);

        $this->assertSame('production_unproven', $result['state']);
        $this->assertContains('window_not_bound_to_one_deploy', $result['blockers']);
        $this->assertContains('slot_2_cell_set_incomplete', $result['blockers']);
    }

    #[Test]
    public function api_ui_and_receipts_share_the_single_closeout_projection_contract(): void
    {
        $api = (string) file_get_contents(app_path('Services/SeoIntel/OpsDashboard/SeoDashboardApiReadService.php'));
        $readModel = (string) file_get_contents(app_path('Services/SeoIntel/OpsDashboard/SeoTechnicalHealthReadService.php'));
        $ui = (string) file_get_contents(app_path('Filament/Ops/Support/SeoTechnicalHealthUiContract.php'));
        $blade = (string) file_get_contents(resource_path('views/filament/ops/components/ops-technical-health-workspace.blade.php'));

        $this->assertStringContainsString('new SeoTechnicalHealthReadService', $api);
        $this->assertStringContainsString("'contract_projection_hash' => \$closeout['contract_projection_hash']", $readModel);
        $this->assertStringContainsString('app(SeoTechnicalHealthReadService::class)->read()', $ui);
        $this->assertStringContainsString(':state="$snapshot[\'state\']"', $blade);
        $this->assertStringNotContainsString("manual_state_override_allowed' => true", $readModel);
    }

    /** @return array<string,mixed> */
    private function window(): array
    {
        return [
            'state' => 'complete',
            'slot_count' => 3,
            'consecutive' => true,
            'fresh' => true,
            'successful' => true,
            'receipts' => [
                $this->receipt('2026-08-26T09:20:00Z'),
                $this->receipt('2026-08-26T09:10:00Z'),
                $this->receipt('2026-08-26T09:00:00Z'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function receipt(string $scheduledFor): array
    {
        $receipt = [
            'schema_version' => ScheduledRuntimeProbeReceiptService::SCHEMA_VERSION,
            'trigger_mode' => 'scheduled',
            'status' => 'success',
            'scheduled_for' => $scheduledFor,
            'completed_at' => $scheduledFor,
            'crawler_source_receipt' => ['complete' => true, 'age_minutes' => 11.314861916666667],
            'production_calibration' => $this->calibration(),
        ];
        $receipt['receipt_hash'] = ScheduledRuntimeProbeReceiptService::contentHash($receipt);

        return $receipt;
    }

    private function reverseObjectKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->reverseObjectKeys(...), $value);
        }

        $reordered = [];
        foreach (array_reverse(array_keys($value)) as $key) {
            $reordered[$key] = $this->reverseObjectKeys($value[$key]);
        }

        return $reordered;
    }

    /** @return array<string,mixed> */
    private function calibration(): array
    {
        $registry = new PageFamilyPolicyRegistry;
        $cells = [];
        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $family) {
            foreach (AuthorityDrivenCohortResolver::LOCALES as $locale) {
                $cells[$family.'|'.$locale] = [
                    'family' => $family,
                    'locale' => $locale,
                    'state' => 'success',
                    'sample_count' => 1,
                    'success_count' => 1,
                    'failure_count' => 0,
                    'availability_rate' => 1.0,
                    'required_availability_rate' => 1.0,
                    'http_status' => 200,
                    'identity_hash' => hash('sha256', $family.'|'.$locale),
                    'authority_revision_hash' => hash('sha256', 'revision|'.$family.'|'.$locale),
                ];
            }
        }

        return [
            'schema_version' => ProductionCalibrationProbeService::SCHEMA_VERSION,
            'state' => 'success',
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_hash' => $registry->policyHash(),
            'expected_cell_count' => 12,
            'observed_cell_count' => 12,
            'cells' => $cells,
            'private_negative_set' => [
                'checked' => true,
                'accepted' => true,
                'contract_probe_count' => count($registry->negativeSetProbes()),
                'http_probe_count' => count($registry->privatePathSegments()),
                'accepted_http_probe_count' => count($registry->privatePathSegments()),
                'exposure_count' => 0,
                'unobserved_count' => 0,
                'unexpected_response_count' => 0,
            ],
            'deploy_revision' => str_repeat('a', 40),
        ];
    }

    private function authoritySource(): UrlTruthInventorySource
    {
        $registry = new PageFamilyPolicyRegistry;
        $records = [];
        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $familyId) {
            $family = $registry->families()[$familyId];
            foreach (AuthorityDrivenCohortResolver::LOCALES as $locale) {
                $path = collect((array) data_get($family, 'authority.route_authority.exact_static_templates'))
                    ->first(static fn (string $candidate): bool => $locale === 'en'
                        ? ($candidate === '/en' || str_starts_with($candidate, '/en/'))
                        : ($candidate === '/' || $candidate === '/zh' || str_starts_with($candidate, '/zh/')));
                $records[] = new UrlTruthInventoryRecord(
                    canonicalUrl: 'https://fermatmind.com'.$path,
                    locale: $locale,
                    pageEntityType: (string) data_get($family, 'authority.page_entity_types.0'),
                    entityIdOrSlug: $familyId.'-'.$locale,
                    sourceAuthority: (string) data_get($family, 'authority.source_authorities.0'),
                    entitySource: (string) data_get($family, 'authority.entity_sources.0'),
                    authorityStatus: 'published_approved',
                    metadata: ['authority_revision' => hash('sha256', $familyId.'|'.$locale)],
                );
            }
        }

        return new class($records) implements UrlTruthInventorySource
        {
            /** @param list<UrlTruthInventoryRecord> $records */
            public function __construct(private readonly array $records) {}

            public function candidates(): array
            {
                return $this->records;
            }

            public function metadata(): array
            {
                return ['source' => 'test_authority'];
            }
        };
    }
}
