<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Ledger;

use App\Services\SeoIntel\GscDataQualityGate;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Carbon\CarbonImmutable;
use Throwable;

final class SeoLedgerEvidenceAdapter
{
    public const SCHEMA_VERSION = 'seo.change_ledger.evidence.v1';

    private const REQUIRED_SOURCES = [
        'runtime',
        'url_truth',
        'page_family',
        'cms_revision',
        'deploy',
        'gsc',
    ];

    private const FORBIDDEN_KEYS = [
        'attempt',
        'attempt_id',
        'result',
        'result_id',
        'report',
        'report_id',
        'order',
        'order_id',
        'payment',
        'payment_id',
        'token',
        'user_id',
        'anon_id',
        'ip',
        'user_agent',
        'request_id',
        'raw_query',
        'raw_url',
        'canonical_url',
        'raw_response',
        'internal_topology',
    ];

    public function __construct(
        private readonly GscDataQualityGate $gscQualityGate = new GscDataQualityGate,
        private readonly PageFamilyPolicyRegistry $pageFamilyRegistry = new PageFamilyPolicyRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $sources
     * @return array<string, mixed>
     */
    public function adapt(array $sources, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $holdReasons = $this->privacyHoldReasons($sources);

        foreach (self::REQUIRED_SOURCES as $source) {
            if (! is_array($sources[$source] ?? null)) {
                $holdReasons[] = 'source_unavailable:'.$source;
            }
        }

        $runtime = $this->source($sources, 'runtime');
        $urlTruth = $this->source($sources, 'url_truth');
        $pageFamily = $this->source($sources, 'page_family');
        $cmsRevision = $this->source($sources, 'cms_revision');
        $deploy = $this->source($sources, 'deploy');
        $gsc = $this->source($sources, 'gsc');

        foreach (['runtime' => $runtime, 'url_truth' => $urlTruth, 'page_family' => $pageFamily, 'cms_revision' => $cmsRevision, 'deploy' => $deploy] as $name => $source) {
            if (! $this->isFresh($source, $now)) {
                $holdReasons[] = 'evidence_stale_or_undated:'.$name;
            }
        }

        $family = $this->pageFamily($pageFamily);
        if ($family === null) {
            $holdReasons[] = 'page_family_unavailable_or_private';
        }

        $gscRows = is_array($gsc['rows'] ?? null) ? array_values(array_filter($gsc['rows'], 'is_array')) : [];
        $gscQuality = $this->gscQualityGate->evaluate($gscRows, $now);
        if (($gscQuality['status'] ?? null) !== 'pass') {
            $holdReasons[] = 'gsc_aggregate_quality_hold';
        }

        if (($runtime['state'] ?? null) !== 'production_proven') {
            $holdReasons[] = 'runtime_not_production_proven';
        }
        if (($deploy['status'] ?? null) !== 'success') {
            $holdReasons[] = 'deploy_not_successful';
        }

        $cohortDigest = $this->digest($urlTruth['cohort_digest'] ?? null);
        $publicCount = $this->nonNegativeInteger($urlTruth['public_count'] ?? null);
        if ($cohortDigest === null || $publicCount === null) {
            $holdReasons[] = 'url_truth_cohort_unavailable';
        }

        $authorityRevision = $this->revision($pageFamily['authority_revision'] ?? null);
        $cmsRevisionValue = $this->revision($cmsRevision['revision'] ?? null);
        $deploySha = $this->commitSha($deploy['sha'] ?? null);
        if ($authorityRevision === null || $cmsRevisionValue === null || $deploySha === null) {
            $holdReasons[] = 'revision_evidence_unavailable';
        }

        $holdReasons = array_values(array_unique($holdReasons));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $holdReasons === [] ? 'verified' : 'MEASUREMENT_HOLD',
            'hold_reasons' => $holdReasons,
            'evidence' => [
                'runtime' => [
                    'state' => $this->safeEnum($runtime['state'] ?? null),
                    'contract_projection_hash' => $this->digest($runtime['contract_projection_hash'] ?? null),
                    'observed_at' => $this->observedAt($runtime),
                ],
                'url_truth' => [
                    'cohort_digest' => $cohortDigest,
                    'public_count' => $publicCount,
                    'revision_digest' => $this->revisionDigest($urlTruth['revision'] ?? null),
                    'observed_at' => $this->observedAt($urlTruth),
                ],
                'page_family' => $family,
                'cms_revision' => [
                    'revision_digest' => $this->revisionDigest($cmsRevisionValue),
                    'observed_at' => $this->observedAt($cmsRevision),
                ],
                'deploy' => [
                    'sha' => $deploySha,
                    'status' => $this->safeEnum($deploy['status'] ?? null),
                    'observed_at' => $this->observedAt($deploy),
                ],
                'gsc_aggregate' => [
                    'state' => ($gscQuality['status'] ?? null) === 'pass' ? 'verified' : 'blocked',
                    'row_count' => count($gscRows),
                    'clicks' => array_sum(array_map(static fn (array $row): int => max(0, (int) ($row['clicks'] ?? 0)), $gscRows)),
                    'impressions' => array_sum(array_map(static fn (array $row): int => max(0, (int) ($row['impressions'] ?? 0)), $gscRows)),
                    'freshness' => $gscQuality['freshness'] ?? null,
                    'quality_reasons' => $gscQuality['reasons'] ?? [],
                ],
            ],
            'boundaries' => [
                'read_only' => true,
                'aggregate_evidence_only' => true,
                'private_product_evidence_allowed' => false,
                'raw_query_allowed' => false,
                'raw_url_allowed' => false,
                'raw_payload_allowed' => false,
                'search_submission_allowed' => false,
                'write_authorization_granted' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $sources */
    private function source(array $sources, string $key): array
    {
        return is_array($sources[$key] ?? null) ? $sources[$key] : [];
    }

    /** @param array<string,mixed> $source */
    private function isFresh(array $source, CarbonImmutable $now): bool
    {
        $maxAgeHours = filter_var($source['max_age_hours'] ?? null, FILTER_VALIDATE_INT);
        $observedAt = $this->parseTime($source['observed_at'] ?? null);
        if ($observedAt === null || $maxAgeHours === false || $maxAgeHours < 1 || $observedAt->greaterThan($now)) {
            return false;
        }

        return $observedAt->diffInHours($now, false) <= $maxAgeHours;
    }

    /** @param array<string,mixed> $source */
    private function observedAt(array $source): ?string
    {
        return $this->parseTime($source['observed_at'] ?? null)?->utc()->toIso8601String();
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $source */
    private function pageFamily(array $source): ?array
    {
        $familyId = $this->safeEnum($source['id'] ?? null);
        $locale = $this->safeEnum($source['locale'] ?? null);
        $families = $this->pageFamilyRegistry->families();
        $family = $familyId === null ? null : ($families[$familyId] ?? null);
        if (! is_array($family) || ($family['public_family'] ?? false) !== true || ! in_array($locale, ['zh-CN', 'en'], true)) {
            return null;
        }

        return [
            'id' => $familyId,
            'locale' => $locale,
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'business_priority' => $family['business_priority'],
            'authority_revision_digest' => $this->revisionDigest($source['authority_revision'] ?? null),
            'observed_at' => $this->observedAt($source),
        ];
    }

    /** @param array<string,mixed> $value */
    private function privacyHoldReasons(array $value, string $prefix = ''): array
    {
        $reasons = [];
        foreach ($value as $key => $item) {
            $normalizedKey = mb_strtolower((string) $key, 'UTF-8');
            if (in_array($normalizedKey, self::FORBIDDEN_KEYS, true)) {
                $reasons[] = 'private_or_raw_evidence';
            }
            if (is_array($item)) {
                $reasons = [...$reasons, ...$this->privacyHoldReasons($item, $prefix === '' ? $normalizedKey : $prefix.'.'.$normalizedKey)];
            }
        }

        return $reasons;
    }

    private function revision(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= 160 && ! str_contains($value, "\n") ? $value : null;
    }

    private function revisionDigest(mixed $value): ?string
    {
        $revision = $this->revision($value);

        return $revision === null ? null : hash('sha256', 'seo-ledger-evidence|'.$revision);
    }

    private function digest(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : null;
    }

    private function commitSha(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/', $value) === 1 ? $value : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);

        return $number !== false && $number >= 0 ? $number : null;
    }

    private function safeEnum(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
