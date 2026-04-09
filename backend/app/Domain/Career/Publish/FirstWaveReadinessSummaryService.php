<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\FirstWaveReadinessSummary;

final class FirstWaveReadinessSummaryService
{
    public const SUMMARY_VERSION = 'career.release.first_wave_readiness.v1';

    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
        private readonly FirstWavePublishReadyValidator $validator,
    ) {}

    public function build(
        ?string $blockedRegistryPath = null,
        ?string $authorityOverridePath = null,
    ): FirstWaveReadinessSummary {
        $manifest = $this->manifestReader->read();
        $titlesBySlug = [];

        foreach ($manifest['occupations'] as $occupation) {
            if (! is_array($occupation)) {
                continue;
            }

            $titlesBySlug[(string) ($occupation['canonical_slug'] ?? '')] = (string) ($occupation['canonical_title_en'] ?? '');
        }

        $report = $this->validator->validate(
            externalIssuesBySlug: [],
            blockedRegistryPath: $blockedRegistryPath,
            authorityOverridePath: $authorityOverridePath,
        );

        $counts = [
            'total' => 0,
            'publish_ready' => 0,
            'blocked_override_eligible' => 0,
            'blocked_not_safely_remediable' => 0,
            'blocked_total' => 0,
            'partial_raw' => 0,
        ];
        $occupations = [];

        foreach ((array) ($report['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalizedStatus = $this->normalizeStatus($row);
            $canonicalSlug = (string) ($row['canonical_slug'] ?? '');

            $counts['total']++;
            if (array_key_exists($normalizedStatus, $counts)) {
                $counts[$normalizedStatus]++;
            }
            if (str_starts_with($normalizedStatus, 'blocked_')) {
                $counts['blocked_total']++;
            }

            $occupations[] = [
                'occupation_uuid' => (string) ($row['occupation_uuid'] ?? ''),
                'canonical_slug' => $canonicalSlug,
                'canonical_title_en' => $titlesBySlug[$canonicalSlug] ?? '',
                'status' => $normalizedStatus,
                'blocker_type' => $row['blocker_type'] ?? null,
                'remediation_class' => $row['remediation_class'] ?? null,
                'authority_override_supplied' => (bool) ($row['authority_override_supplied'] ?? false),
                'review_required' => (bool) ($row['review_required'] ?? false),
                'crosswalk_mode' => $row['crosswalk_mode'] ?? null,
                'reviewer_status' => $row['reviewer_status'] ?? null,
                'index_state' => $row['index_state'] ?? null,
                'index_eligible' => (bool) ($row['index_eligible'] ?? false),
                'reason_codes' => $this->reasonCodesFor($row, $normalizedStatus),
            ];
        }

        return new FirstWaveReadinessSummary(
            waveName: (string) ($report['wave_name'] ?? $manifest['wave_name']),
            summaryVersion: self::SUMMARY_VERSION,
            counts: $counts,
            occupations: $occupations,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function normalizeStatus(array $row): string
    {
        $rawStatus = (string) ($row['status'] ?? '');
        if ($rawStatus === 'publish_ready') {
            return 'publish_ready';
        }

        $governanceStatus = (string) ($row['blocked_governance_status'] ?? '');
        if (in_array($governanceStatus, ['blocked_override_eligible', 'blocked_not_safely_remediable'], true)) {
            return $governanceStatus;
        }

        if ($rawStatus === 'partial') {
            return 'partial_raw';
        }

        return 'blocked_not_safely_remediable';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function reasonCodesFor(array $row, string $normalizedStatus): array
    {
        $reasonCodes = [];

        if ($normalizedStatus === 'publish_ready') {
            return ['publish_ready'];
        }

        if ($normalizedStatus === 'partial_raw') {
            $reasonCodes[] = 'partial_raw';
            foreach ((array) ($row['missing_requirements'] ?? []) as $requirement) {
                if (is_string($requirement) && $requirement !== '') {
                    $reasonCodes[] = $requirement;
                }
            }
        }

        foreach ([
            $row['blocker_type'] ?? null,
            $row['remediation_class'] ?? null,
        ] as $code) {
            if (is_string($code) && $code !== '') {
                $reasonCodes[] = $code;
            }
        }

        if (($row['authority_override_supplied'] ?? false) === true) {
            $reasonCodes[] = 'authority_override_supplied';
        } elseif ($normalizedStatus === 'blocked_override_eligible') {
            $reasonCodes[] = 'authority_override_not_supplied';
        }

        $reasonCodes = array_values(array_unique(array_filter(
            $reasonCodes,
            static fn (mixed $code): bool => is_string($code) && $code !== ''
        )));

        return $reasonCodes;
    }
}
