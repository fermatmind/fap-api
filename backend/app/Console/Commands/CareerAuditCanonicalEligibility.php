<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerBaselineMetadataInventoryAuditor;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayerStatus;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use App\Domain\Career\Audit\CareerCanonicalEligibilityScope;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySidecar;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerIndexStateAuthorityAuditor;
use App\Domain\Career\Audit\CareerOccupationEntityInventoryAuditor;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanResolver;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use App\Domain\Career\Audit\CareerRuntimeProjectionTruthEligibilityAuditor;
use App\Domain\Career\Audit\CareerSeoGeoReadinessAuditor;
use App\Domain\Career\Audit\CareerSurfaceReadinessAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class CareerAuditCanonicalEligibility extends Command
{
    protected $signature = 'career:audit-canonical-eligibility
        {--scope=all : Audit scope: all, batch, or slugs}
        {--slugs= : Comma-separated canonical slugs when scope=slugs}
        {--locales= : Comma-separated locales, defaults to en,zh}
        {--public-resolution-plan= : Optional public-resolution planner JSON artifact}
        {--projection= : Optional runtime publish projection JSON artifact}
        {--truth= : Optional canonical runtime truth JSON artifact}
        {--ledger= : Optional full release ledger JSON artifact}
        {--json : Emit JSON output}
        {--output= : Optional output path for JSON payload}
        {--include-surfaces : Include surface layer context}
        {--include-live-html : Include optional live HTML surface context}
        {--base-url= : Required only when live HTML verification is requested later}';

    protected $description = 'Read-only Career canonical eligibility audit schema integration.';

    public function handle(): int
    {
        $scope = $this->scopeOption();
        $locales = $this->csvOption('locales', default: 'en,zh');
        $planRows = $this->planRowsForScope($scope);
        $slugs = $this->slugsFromPlanRows($planRows);
        $issues = [];
        $sidecars = [];

        if ($scope !== CareerCanonicalEligibilityScope::SLUGS) {
            $planPath = $this->stringOption('public-resolution-plan');
            if ($planPath === null) {
                $issues['public_resolution_plan_missing'] = 1;
                $sidecars[] = $this->contextSidecar(
                    sidecarId: 'public_resolution_plan_missing',
                    title: 'Public resolution planner path was not supplied.',
                    evidence: [['option' => '--public-resolution-plan']]
                );
            } else {
                $planResult = CareerPublicResolutionPlanResolver::fromPath($planPath);
                if ($planResult->issues !== []) {
                    $issues = $planResult->byReason();
                }
                $planRows = $planResult->rows();
                $slugs = $this->slugsFromPlanRows($planRows);
            }
        }

        $auditContext = $this->auditContext($scope, $planRows, $slugs, $locales);
        $rows = $auditContext['rows'];
        $sidecars = [...$sidecars, ...$auditContext['sidecars']];

        $byReason = $this->mergeReasons($issues, CareerCanonicalEligibilityReport::byReasonFromRows($rows));
        $blockedCount = count(array_filter(
            $rows,
            static fn (CareerCanonicalEligibilityAuditRow $row): bool => $row->overallStatus !== CareerCanonicalEligibilityStatus::PASS
        ));
        $eligibleCount = $this->eligibleSlugCount($rows);
        $report = new CareerCanonicalEligibilityReport(
            status: $byReason === [] && $blockedCount === 0 ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            scope: $scope,
            expectedOccupations: count(array_unique($slugs)),
            auditedOccupations: count(array_unique($slugs)),
            eligibleCount: $eligibleCount,
            blockedCount: $blockedCount,
            byReason: $byReason,
            rows: $rows,
            sidecars: $sidecars,
        );
        $payload = [
            ...$report->toArray(),
            'read_only' => true,
            'writes_database' => false,
            'audit_command' => 'career:audit-canonical-eligibility',
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            $this->error('failed to encode career canonical eligibility audit payload');

            return self::FAILURE;
        }

        $output = $this->stringOption('output');
        if ($output !== null) {
            File::put($output, $encoded.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line('status='.$payload['status']);
            $this->line('scope='.$payload['scope']);
            $this->line('audited_occupations='.(string) $payload['audited_occupations']);
            $this->line('blocked_count='.(string) $payload['blocked_count']);
        }

        return $payload['status'] === CareerCanonicalEligibilityStatus::PASS ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{rows: list<CareerCanonicalEligibilityAuditRow>, sidecars: list<CareerCanonicalEligibilitySidecar>}
     */
    private function auditContext(string $scope, array $planRows, array $slugs, array $locales): array
    {
        $sidecars = [];
        $plan = $this->planFromRows($planRows);

        $entityResult = (new CareerOccupationEntityInventoryAuditor)->auditSlugs($slugs, $scope);
        $baselineResult = (new CareerBaselineMetadataInventoryAuditor)->auditRows($planRows);
        $entityByReason = $entityResult->byReason();
        $entityContextMissing = array_key_exists('occupation_query_failed', $entityByReason);
        if ($entityContextMissing) {
            $sidecars[] = $this->contextSidecar(
                sidecarId: 'entity_db_context_missing',
                title: 'Occupation entity inventory DB context could not be queried.',
                scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_INSIDE,
                mayContinueTrain: false,
                evidence: [['reason' => 'occupation_query_failed']]
            );
        }

        try {
            $indexResult = (new CareerIndexStateAuthorityAuditor)->auditSlugs($slugs);
            $indexStatuses = $this->statusMapBySlug($indexResult->rows, 'canonicalSlug', 'indexStatus');
        } catch (Throwable $exception) {
            $indexResult = null;
            $indexStatuses = [];
            $sidecars[] = $this->contextSidecar(
                sidecarId: 'index_state_context_missing',
                title: 'Index-state authority context could not be queried.',
                scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_INSIDE,
                mayContinueTrain: false,
                evidence: [$this->exceptionEvidence($exception)]
            );
        }

        [$runtimeStatuses, $runtimeSidecars] = $this->runtimeStatuses($plan, $planRows, $slugs, $locales);
        [$surfaceStatuses, $surfaceSidecars] = $this->surfaceStatuses($planRows, $slugs, $locales);
        $seoGeoResult = (new CareerSeoGeoReadinessAuditor)->audit($planRows, $locales, $this->seoGeoArtifact($planRows, $locales));

        $sidecars = [
            ...$sidecars,
            ...($entityContextMissing ? [] : $entityResult->sidecars),
            ...$baselineResult->sidecars,
            ...($indexResult?->sidecars ?? []),
            ...$runtimeSidecars,
            ...$seoGeoResult->sidecars,
            ...$surfaceSidecars,
        ];

        $entityStatuses = $entityContextMissing
            ? $this->statusMapForSlugs($slugs, CareerCanonicalEligibilityLayer::ENTITY, ['entity_db_context_missing'], [['reason' => 'occupation_query_failed']], 'occupations')
            : $this->statusMapBySlug($entityResult->rows, 'canonicalSlug', 'entityStatus');
        $baselineStatuses = $this->statusMapBySlug($baselineResult->rows, 'canonicalSlug', 'baselineStatus');
        $seoGeoStatuses = $this->statusMapByKey($seoGeoResult->rows, 'canonicalSlug', 'locale', 'seoGeoStatus');

        $rows = [];
        foreach (array_values(array_unique($slugs)) as $slug) {
            foreach ($locales as $locale) {
                $runtimeStatus = $runtimeStatuses[$this->rowKey($slug, $locale)] ?? $this->unverifiedLayer(
                    CareerCanonicalEligibilityLayer::RUNTIME,
                    ['runtime_projection_context_missing', 'runtime_truth_context_missing'],
                    [['projection' => $this->stringOption('projection'), 'truth' => $this->stringOption('truth')]],
                    'runtime_projection_truth'
                );
                $surfaceStatus = $surfaceStatuses[$this->rowKey($slug, $locale)] ?? $this->unverifiedLayer(
                    CareerCanonicalEligibilityLayer::SURFACE,
                    ['surface_context_missing'],
                    [['include_surfaces' => (bool) $this->option('include-surfaces')]],
                    'surface_artifacts'
                );
                $rows[] = $this->auditRow(
                    scope: $scope,
                    slug: $slug,
                    locale: $locale,
                    entityStatus: $entityStatuses[$slug] ?? $this->unverifiedLayer(CareerCanonicalEligibilityLayer::ENTITY, ['entity_db_context_missing'], [['slug' => $slug]], 'occupations'),
                    baselineStatus: $baselineStatuses[$slug] ?? $this->unverifiedLayer(CareerCanonicalEligibilityLayer::BASELINE, ['baseline_context_missing'], [['slug' => $slug]], 'career_baselines'),
                    indexStatus: $indexStatuses[$slug] ?? $this->unverifiedLayer(CareerCanonicalEligibilityLayer::INDEX, ['index_state_context_missing'], [['slug' => $slug]], 'index_states'),
                    runtimeStatus: $runtimeStatus,
                    seoGeoStatus: $seoGeoStatuses[$this->rowKey($slug, $locale)] ?? $this->unverifiedLayer(CareerCanonicalEligibilityLayer::SEO_GEO, ['seo_geo_context_missing'], [['slug' => $slug, 'locale' => $locale]], 'seo_geo_artifacts'),
                    surfaceStatus: $surfaceStatus
                );
            }
        }

        return ['rows' => $rows, 'sidecars' => $sidecars];
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     */
    private function planFromRows(array $planRows): CareerPublicResolutionPlan
    {
        return new CareerPublicResolutionPlan(
            sourcePath: $this->stringOption('public-resolution-plan') ?? 'command_slugs',
            checksum: null,
            rows: $planRows,
        );
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{0: array<string, CareerCanonicalEligibilityLayerStatus>, 1: list<CareerCanonicalEligibilitySidecar>}
     */
    private function runtimeStatuses(CareerPublicResolutionPlan $plan, array $planRows, array $slugs, array $locales): array
    {
        $projectionPath = $this->stringOption('projection');
        $truthPath = $this->stringOption('truth');
        $ledgerPath = $this->stringOption('ledger');
        $sidecars = [];

        if ($projectionPath === null || $truthPath === null) {
            $reasons = [];
            $evidence = [];
            if ($projectionPath === null) {
                $reasons[] = 'runtime_projection_context_missing';
                $evidence[] = ['missing_option' => '--projection'];
                $sidecars[] = $this->contextSidecar(
                    sidecarId: 'runtime_projection_context_missing',
                    title: 'Runtime projection artifact was not supplied.',
                    evidence: [['missing_option' => '--projection']]
                );
            }
            if ($truthPath === null) {
                $reasons[] = 'runtime_truth_context_missing';
                $evidence[] = ['missing_option' => '--truth'];
                $sidecars[] = $this->contextSidecar(
                    sidecarId: 'runtime_truth_context_missing',
                    title: 'Runtime truth artifact was not supplied.',
                    evidence: [['missing_option' => '--truth']]
                );
            }

            return [$this->statusMapForSlugLocales($slugs, $locales, CareerCanonicalEligibilityLayer::RUNTIME, $reasons, $evidence, 'runtime_projection_truth'), $sidecars];
        }

        [$projection, $projectionSidecar] = $this->jsonArtifact($projectionPath, 'runtime_projection_context_missing', '--projection');
        [$truth, $truthSidecar] = $this->jsonArtifact($truthPath, 'runtime_truth_context_missing', '--truth');
        $sidecars = array_values(array_filter([$projectionSidecar, $truthSidecar]));
        $ledger = null;
        if ($ledgerPath !== null) {
            [$ledger, $ledgerSidecar] = $this->jsonArtifact($ledgerPath, 'runtime_ledger_context_missing', '--ledger');
            if ($ledgerSidecar !== null) {
                $sidecars[] = $ledgerSidecar;
            }
        }

        if ($projection === null || $truth === null) {
            return [$this->statusMapForSlugLocales($slugs, $locales, CareerCanonicalEligibilityLayer::RUNTIME, ['runtime_artifact_invalid'], [['projection' => $projectionPath, 'truth' => $truthPath]], 'runtime_projection_truth'), $sidecars];
        }

        $runtimeResult = (new CareerRuntimeProjectionTruthEligibilityAuditor)->auditPlan($plan, $locales, $projection, $truth, $ledger);

        return [$this->statusMapByKey($runtimeResult->rows, 'canonicalSlug', 'locale', 'runtimeStatus'), [...$sidecars, ...$runtimeResult->sidecars]];
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{0: array<string, CareerCanonicalEligibilityLayerStatus>, 1: list<CareerCanonicalEligibilitySidecar>}
     */
    private function surfaceStatuses(array $planRows, array $slugs, array $locales): array
    {
        $includeSurfaces = (bool) $this->option('include-surfaces') || (bool) $this->option('include-live-html');
        if (! $includeSurfaces) {
            return [
                $this->statusMapForSlugLocales($slugs, $locales, CareerCanonicalEligibilityLayer::SURFACE, ['surface_context_missing'], [['include_surfaces' => false]], 'surface_artifacts'),
                [
                    $this->contextSidecar(
                        sidecarId: 'surface_context_missing',
                        title: 'Surface artifact mode was not requested.',
                        evidence: [['option' => '--include-surfaces']]
                    ),
                ],
            ];
        }

        $includeLiveHtml = (bool) $this->option('include-live-html');
        $baseUrl = $this->stringOption('base-url');
        $sidecars = [];
        if ($includeLiveHtml && $baseUrl === null) {
            $sidecars[] = $this->contextSidecar(
                sidecarId: 'surface_live_html_context_missing',
                title: 'Live HTML validation was requested without a base URL.',
                evidence: [['missing_option' => '--base-url']]
            );
        }

        $surfaceResult = (new CareerSurfaceReadinessAuditor)->audit(
            planRows: $planRows,
            locales: $locales,
            apiArtifact: $this->surfaceApiArtifact($slugs, $locales),
            includeLiveHtml: $includeLiveHtml,
            baseUrl: $baseUrl,
            liveHtmlByKey: [],
        );

        return [$this->statusMapByKey($surfaceResult->rows, 'canonicalSlug', 'locale', 'surfaceStatus'), [...$sidecars, ...$surfaceResult->sidecars]];
    }

    private function auditRow(
        string $scope,
        string $slug,
        string $locale,
        CareerCanonicalEligibilityLayerStatus $entityStatus,
        CareerCanonicalEligibilityLayerStatus $baselineStatus,
        CareerCanonicalEligibilityLayerStatus $indexStatus,
        CareerCanonicalEligibilityLayerStatus $runtimeStatus,
        CareerCanonicalEligibilityLayerStatus $seoGeoStatus,
        CareerCanonicalEligibilityLayerStatus $surfaceStatus,
    ): CareerCanonicalEligibilityAuditRow {
        $safetyStatus = new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::SAFETY,
            status: CareerCanonicalEligibilityStatus::PASS,
            reasons: [],
            evidence: [['read_only' => true, 'writes_database' => false]],
            source: 'career_audit_command',
        );
        $layers = [$entityStatus, $baselineStatus, $indexStatus, $runtimeStatus, $seoGeoStatus, $surfaceStatus, $safetyStatus];
        $reasons = [];
        $evidence = [['slug' => $slug, 'locale' => $locale]];
        foreach ($layers as $layer) {
            $reasons = [...$reasons, ...$layer->reasons];
            if ($layer->evidence !== []) {
                $evidence[] = [$layer->layer => $layer->evidence];
            }
        }
        $reasons = array_values(array_unique($reasons));
        $overallStatus = $this->overallStatus($layers);

        return new CareerCanonicalEligibilityAuditRow(
            slug: $slug,
            locale: $locale,
            sourceScope: $scope,
            entityStatus: $entityStatus,
            baselineStatus: $baselineStatus,
            indexStatus: $indexStatus,
            runtimeStatus: $runtimeStatus,
            seoGeoStatus: $seoGeoStatus,
            surfaceStatus: $surfaceStatus,
            safetyStatus: $safetyStatus,
            overallStatus: $overallStatus,
            severity: $overallStatus === CareerCanonicalEligibilityStatus::PASS
                ? CareerCanonicalEligibilitySeverity::INFO
                : CareerCanonicalEligibilitySeverity::HIGH,
            reasons: $reasons,
            evidence: $evidence,
            sidecars: [],
        );
    }

    /**
     * @param  list<CareerCanonicalEligibilityLayerStatus>  $layers
     */
    private function overallStatus(array $layers): string
    {
        foreach ($layers as $layer) {
            if ($layer->status === CareerCanonicalEligibilityStatus::BLOCKED || $layer->status === CareerCanonicalEligibilityStatus::FAIL) {
                return CareerCanonicalEligibilityStatus::BLOCKED;
            }
        }

        foreach ($layers as $layer) {
            if ($layer->status === CareerCanonicalEligibilityStatus::UNVERIFIED) {
                return CareerCanonicalEligibilityStatus::BLOCKED;
            }
        }

        foreach ($layers as $layer) {
            if ($layer->status === CareerCanonicalEligibilityStatus::WARNING) {
                return CareerCanonicalEligibilityStatus::WARNING;
            }
        }

        return CareerCanonicalEligibilityStatus::PASS;
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     */
    private function eligibleSlugCount(array $rows): int
    {
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row->slug] ??= true;
            $bySlug[$row->slug] = $bySlug[$row->slug] && $row->overallStatus === CareerCanonicalEligibilityStatus::PASS;
        }

        return count(array_filter($bySlug));
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function statusMapForSlugs(array $slugs, string $layer, array $reasons, array $evidence, string $source): array
    {
        $statuses = [];
        foreach ($slugs as $slug) {
            $statuses[$slug] = $this->unverifiedLayer($layer, $reasons, $evidence, $source);
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function statusMapForSlugLocales(array $slugs, array $locales, string $layer, array $reasons, array $evidence, string $source): array
    {
        $statuses = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $statuses[$this->rowKey($slug, $locale)] = $this->unverifiedLayer($layer, $reasons, $evidence, $source);
            }
        }

        return $statuses;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function statusMapBySlug(array $rows, string $slugProperty, string $statusProperty): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (property_exists($row, $slugProperty) && property_exists($row, $statusProperty)) {
                $map[$row->{$slugProperty}] = $row->{$statusProperty};
            }
        }

        return $map;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function statusMapByKey(array $rows, string $slugProperty, string $localeProperty, string $statusProperty): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (property_exists($row, $slugProperty) && property_exists($row, $localeProperty) && property_exists($row, $statusProperty)) {
                $map[$this->rowKey($row->{$slugProperty}, $row->{$localeProperty})] = $row->{$statusProperty};
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     */
    private function unverifiedLayer(string $layer, array $reasons, array $evidence, string $source): CareerCanonicalEligibilityLayerStatus
    {
        return new CareerCanonicalEligibilityLayerStatus(
            layer: $layer,
            status: CareerCanonicalEligibilityStatus::UNVERIFIED,
            reasons: array_values(array_unique($reasons)),
            evidence: $evidence,
            source: $source,
        );
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $locales
     * @return array{items: list<array<string, mixed>>}
     */
    private function seoGeoArtifact(array $planRows, array $locales): array
    {
        $items = [];
        foreach ($planRows as $row) {
            if ($row->canonicalSlug === null) {
                continue;
            }
            foreach ($locales as $locale) {
                $items[] = [
                    'slug' => $row->canonicalSlug,
                    'locale' => $locale,
                    'canonical_path' => '/'.$locale.'/career/jobs/'.$row->canonicalSlug,
                    'robots_indexable' => true,
                    'sitemap_eligible' => $this->boolField($row->raw, ['ready_for_sitemap', 'Ready_For_Sitemap']),
                    'llms_eligible' => $this->boolField($row->raw, ['ready_for_llms', 'Ready_For_LLMS']),
                    'llms_full_eligible' => $this->boolField($row->raw, ['ready_for_llms_full', 'Ready_For_LLMS_Full']),
                    'structured_data_ready' => $this->hasStructuredData($row, $locale),
                    'dataset_eligible' => true,
                    'search_eligible' => true,
                    'citation_metadata_ready' => $this->hasSeoMetadata($row, $locale),
                ];
            }
        }

        return ['items' => $items];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function boolField(array $row, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value)) {
                return $value === 1;
            }
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['1', 'true', 'yes', 'ready', 'eligible', 'live'], true)) {
                    return true;
                }
                if (in_array($normalized, ['0', 'false', 'no', 'missing', 'not_ready'], true)) {
                    return false;
                }
            }
        }

        return null;
    }

    private function hasStructuredData(CareerPublicResolutionPlanRow $row, string $locale): bool
    {
        $key = $locale === 'zh' ? 'CN_Occupation_Schema_JSON' : 'EN_Occupation_Schema_JSON';

        return $this->nonEmptyString($row->raw[$key] ?? null);
    }

    private function hasSeoMetadata(CareerPublicResolutionPlanRow $row, string $locale): bool
    {
        if ($locale === 'zh') {
            return $this->nonEmptyString($row->raw['CN_SEO_Title'] ?? null)
                && $this->nonEmptyString($row->raw['CN_SEO_Description'] ?? null);
        }

        return $this->nonEmptyString($row->raw['EN_SEO_Title'] ?? null)
            && $this->nonEmptyString($row->raw['EN_SEO_Description'] ?? null);
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    /**
     * @return array{exception: class-string<Throwable>, message: string}
     */
    private function exceptionEvidence(Throwable $exception): array
    {
        $message = $exception->getMessage();
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500).'...';
        }

        return [
            'exception' => $exception::class,
            'message' => $message,
        ];
    }

    /**
     * @return array{0: array<string, mixed>|list<mixed>|null, 1: CareerCanonicalEligibilitySidecar|null}
     */
    private function jsonArtifact(string $path, string $sidecarId, string $option): array
    {
        if (! is_file($path)) {
            return [
                null,
                $this->contextSidecar(
                    sidecarId: $sidecarId,
                    title: 'Audit artifact path was not found.',
                    evidence: [['option' => $option, 'path' => $path]]
                ),
            ];
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            return [
                null,
                $this->contextSidecar(
                    sidecarId: $sidecarId,
                    title: 'Audit artifact JSON could not be parsed.',
                    evidence: [['option' => $option, 'path' => $path, 'json_error' => json_last_error_msg()]]
                ),
            ];
        }

        return [$payload, null];
    }

    private function contextSidecar(
        string $sidecarId,
        string $title,
        array $evidence,
        string $scopeRelation = CareerCanonicalEligibilitySidecar::SCOPE_RELATION_EXTERNAL,
        bool $mayContinueTrain = true,
    ): CareerCanonicalEligibilitySidecar {
        return new CareerCanonicalEligibilitySidecar(
            sidecarId: $sidecarId,
            title: $title,
            ownerRepo: CareerCanonicalEligibilitySidecar::OWNER_REPO_FAP_API,
            scopeRelation: $scopeRelation,
            introducedByCurrentPr: false,
            affectedSlugs: [],
            affectedLocales: [],
            evidence: $evidence,
            severity: CareerCanonicalEligibilitySeverity::HIGH,
            nextGoal: 'RUN-1 rerun real 2786 canonical eligibility audit',
            mayContinueTrain: $mayContinueTrain,
        );
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @return list<string>
     */
    private function slugsFromPlanRows(array $planRows): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (CareerPublicResolutionPlanRow $row): ?string => $row->canonicalSlug,
            $planRows
        ))));
    }

    /**
     * @return list<CareerPublicResolutionPlanRow>
     */
    private function planRowsForScope(string $scope): array
    {
        if ($scope !== CareerCanonicalEligibilityScope::SLUGS) {
            return [];
        }

        return array_map(
            static fn (string $slug): CareerPublicResolutionPlanRow => new CareerPublicResolutionPlanRow(
                rowNumber: null,
                canonicalSlug: $slug,
                publicResolutionState: 'explicit_slug',
                canonicalPublicType: null,
                rolloutState: null,
                projectionState: null,
                indexStateHint: null,
                titleEn: null,
                titleZh: null,
                sourceCode: null,
                family: null,
                batchId: null,
                locales: [],
                raw: ['slug' => $slug, 'status' => 'explicit_slug'],
            ),
            $this->slugsForScope($scope)
        );
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return list<CareerCanonicalEligibilityAuditRow>
     */
    private function surfaceApiArtifact(array $slugs, array $locales): array
    {
        $items = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'api_canonical_path' => '/'.$locale.'/career/jobs/'.$slug,
                    'api_indexable' => true,
                ];
            }
        }

        return ['items' => $items];
    }

    private function rowKey(string $slug, string $locale): string
    {
        return strtolower($slug).'|'.strtolower($locale);
    }

    private function scopeOption(): string
    {
        $scope = $this->stringOption('scope') ?? CareerCanonicalEligibilityScope::ALL;
        CareerCanonicalEligibilityScope::assertValid($scope);

        return $scope;
    }

    /**
     * @return list<string>
     */
    private function slugsForScope(string $scope): array
    {
        if ($scope !== CareerCanonicalEligibilityScope::SLUGS) {
            return [];
        }

        return $this->csvOption('slugs', required: true);
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name, ?string $default = null, bool $required = false): array
    {
        $value = $this->stringOption($name) ?? $default;
        if ($value === null || trim($value) === '') {
            if ($required) {
                throw new \InvalidArgumentException('--'.$name.' is required.');
            }

            return [];
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            $normalized = strtolower(trim($item));
            if ($normalized !== '') {
                $items[] = $normalized;
            }
        }

        return array_values(array_unique($items));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, int>  $left
     * @param  array<string, int>  $right
     * @return array<string, int>
     */
    private function mergeReasons(array $left, array $right): array
    {
        foreach ($right as $reason => $count) {
            $left[$reason] = ($left[$reason] ?? 0) + $count;
        }
        ksort($left);

        return $left;
    }
}
