<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayerStatus;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use App\Domain\Career\Audit\CareerCanonicalEligibilityScope;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerPublicResolutionPlanResolver;
use App\Domain\Career\Audit\CareerSurfaceReadinessAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerAuditCanonicalEligibility extends Command
{
    protected $signature = 'career:audit-canonical-eligibility
        {--scope=all : Audit scope: all, batch, or slugs}
        {--slugs= : Comma-separated canonical slugs when scope=slugs}
        {--locales= : Comma-separated locales, defaults to en,zh}
        {--public-resolution-plan= : Optional public-resolution planner JSON artifact}
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
        $slugs = $this->slugsForScope($scope);
        $issues = [];

        if ($scope !== CareerCanonicalEligibilityScope::SLUGS) {
            $planPath = $this->stringOption('public-resolution-plan');
            if ($planPath === null) {
                $issues['public_resolution_plan_missing'] = 1;
            } else {
                $planResult = CareerPublicResolutionPlanResolver::fromPath($planPath);
                if ($planResult->issues !== []) {
                    $issues = $planResult->byReason();
                }
                $slugs = array_values(array_filter(array_map(
                    static fn ($row): ?string => $row->canonicalSlug,
                    $planResult->rows()
                )));
            }
        }

        $rows = $this->rows($slugs, $locales);
        if ((bool) $this->option('include-live-html') && $this->stringOption('base-url') === null) {
            $rows = $this->rowsWithLiveHtmlContext($slugs, $locales, $rows);
        }

        $byReason = $this->mergeReasons($issues, CareerCanonicalEligibilityReport::byReasonFromRows($rows));
        $blockedCount = count(array_filter(
            $rows,
            static fn (CareerCanonicalEligibilityAuditRow $row): bool => $row->overallStatus !== CareerCanonicalEligibilityStatus::PASS
        ));
        $report = new CareerCanonicalEligibilityReport(
            status: $byReason === [] && $blockedCount === 0 ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            scope: $scope,
            expectedOccupations: count(array_unique($slugs)),
            auditedOccupations: count(array_unique($slugs)),
            eligibleCount: max(0, count(array_unique($slugs)) - $blockedCount),
            blockedCount: $blockedCount,
            byReason: $byReason,
            rows: $rows,
            sidecars: [],
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
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return list<CareerCanonicalEligibilityAuditRow>
     */
    private function rows(array $slugs, array $locales): array
    {
        $rows = [];
        foreach (array_values(array_unique($slugs)) as $slug) {
            foreach ($locales as $locale) {
                $rows[] = $this->unverifiedRow($slug, $locale);
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  list<CareerCanonicalEligibilityAuditRow>  $existingRows
     * @return list<CareerCanonicalEligibilityAuditRow>
     */
    private function rowsWithLiveHtmlContext(array $slugs, array $locales, array $existingRows): array
    {
        $surfaceResult = (new CareerSurfaceReadinessAuditor)->audit(
            planRows: $slugs,
            locales: $locales,
            apiArtifact: $this->surfaceApiArtifact($slugs, $locales),
            includeLiveHtml: true,
            baseUrl: $this->stringOption('base-url'),
            liveHtmlByKey: [],
        );
        $surfaceByKey = [];
        foreach ($surfaceResult->rows as $row) {
            $surfaceByKey[$row->canonicalSlug.'|'.$row->locale] = $row->surfaceStatus;
        }

        return array_map(function (CareerCanonicalEligibilityAuditRow $row) use ($surfaceByKey): CareerCanonicalEligibilityAuditRow {
            $surfaceStatus = $surfaceByKey[$row->slug.'|'.$row->locale] ?? $row->surfaceStatus;
            $reasons = array_values(array_unique([...$row->reasons, ...$surfaceStatus->reasons]));

            return new CareerCanonicalEligibilityAuditRow(
                slug: $row->slug,
                locale: $row->locale,
                sourceScope: $row->sourceScope,
                entityStatus: $row->entityStatus,
                baselineStatus: $row->baselineStatus,
                indexStatus: $row->indexStatus,
                runtimeStatus: $row->runtimeStatus,
                seoGeoStatus: $row->seoGeoStatus,
                surfaceStatus: $surfaceStatus,
                safetyStatus: $row->safetyStatus,
                overallStatus: CareerCanonicalEligibilityStatus::BLOCKED,
                severity: CareerCanonicalEligibilitySeverity::MEDIUM,
                reasons: $reasons,
                evidence: $row->evidence,
                sidecars: [],
            );
        }, $existingRows);
    }

    private function unverifiedRow(string $slug, string $locale): CareerCanonicalEligibilityAuditRow
    {
        $unverified = static fn (string $layer): CareerCanonicalEligibilityLayerStatus => new CareerCanonicalEligibilityLayerStatus(
            layer: $layer,
            status: CareerCanonicalEligibilityStatus::UNVERIFIED,
            reasons: ['validator_context_missing'],
            evidence: [['command_context' => 'integration_only']],
            source: 'career_audit_command',
        );
        $safety = new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::SAFETY,
            status: CareerCanonicalEligibilityStatus::PASS,
            reasons: [],
            evidence: [['read_only' => true, 'writes_database' => false]],
            source: 'career_audit_command',
        );

        return new CareerCanonicalEligibilityAuditRow(
            slug: $slug,
            locale: $locale,
            sourceScope: CareerCanonicalEligibilityScope::SLUGS,
            entityStatus: $unverified(CareerCanonicalEligibilityLayer::ENTITY),
            baselineStatus: $unverified(CareerCanonicalEligibilityLayer::BASELINE),
            indexStatus: $unverified(CareerCanonicalEligibilityLayer::INDEX),
            runtimeStatus: $unverified(CareerCanonicalEligibilityLayer::RUNTIME),
            seoGeoStatus: $unverified(CareerCanonicalEligibilityLayer::SEO_GEO),
            surfaceStatus: $unverified(CareerCanonicalEligibilityLayer::SURFACE),
            safetyStatus: $safety,
            overallStatus: CareerCanonicalEligibilityStatus::BLOCKED,
            severity: CareerCanonicalEligibilitySeverity::MEDIUM,
            reasons: ['validator_context_missing'],
            evidence: [['command_context' => 'integration_only']],
            sidecars: [],
        );
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{items: list<array<string, mixed>>}
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
