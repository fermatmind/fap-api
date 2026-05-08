<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\IndexStateValue;

final class CareerRuntimePublishProjectionService
{
    public const PROJECTION_KIND = 'career_runtime_publish_projection';

    public const PROJECTION_VERSION = 'career.runtime_publish_projection.v1';

    public const STATE_BLOCKED = 'blocked';

    public const STATE_PUBLISHED_CANDIDATE = 'published_candidate';

    public const STATE_PUBLISHED = 'published';

    public const STATE_QUARANTINED = 'quarantined';

    /**
     * @var list<string>
     */
    public const LOCALES = ['en', 'zh'];

    /**
     * @return array<string, mixed>
     */
    public function buildFromLedgerArray(array $ledger): array
    {
        $sourceRows = $this->sourceRows($ledger);
        $items = [];

        foreach ($sourceRows as $row) {
            $slug = $this->slugForRow($row);
            if ($slug === null) {
                continue;
            }

            foreach (self::LOCALES as $locale) {
                $items[] = $this->projectRow($row, $slug, $locale)->toArray();
            }
        }

        return [
            'projection_kind' => self::PROJECTION_KIND,
            'projection_version' => self::PROJECTION_VERSION,
            'source_authority' => 'CareerFullReleaseLedger',
            'ledger_kind' => $ledger['ledger_kind'] ?? null,
            'ledger_version' => $ledger['ledger_version'] ?? null,
            'scope' => $ledger['scope'] ?? data_get($ledger, 'public_resolution.scope'),
            'counts' => $this->counts($items),
            'items' => $items,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceRows(array $ledger): array
    {
        $publicResolutionRows = data_get($ledger, 'public_resolution.rows');
        if (is_array($publicResolutionRows) && $publicResolutionRows !== []) {
            return array_values(array_filter($publicResolutionRows, static fn (mixed $row): bool => is_array($row)));
        }

        $members = $ledger['members'] ?? [];

        return is_array($members)
            ? array_values(array_filter($members, static fn (mixed $row): bool => is_array($row)))
            : [];
    }

    /**
     * @return array<string, int>
     */
    private function counts(array $items): array
    {
        $counts = [
            'projection_rows' => count($items),
            'canonical_published' => 0,
            'dataset_visible' => 0,
            'search_visible' => 0,
            'detail_route_enabled' => 0,
            'sitemap_live' => 0,
            'llms_live' => 0,
            'llms_full_live' => 0,
            'blocked' => 0,
            'published_candidate' => 0,
            'published' => 0,
            'quarantined' => 0,
        ];

        foreach ($items as $item) {
            $state = (string) ($item['runtime_publish_state'] ?? '');
            if (array_key_exists($state, $counts)) {
                $counts[$state]++;
            }
            if (($item['public_resolution_type'] ?? null) === CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB
                && $state === self::STATE_PUBLISHED) {
                $counts['canonical_published']++;
            }
            foreach (['dataset_visible', 'search_visible', 'sitemap_live', 'llms_live', 'llms_full_live'] as $field) {
                if ((bool) ($item[$field] ?? false)) {
                    $counts[$field]++;
                }
            }
            if (($item['detail_route_enabled'] ?? false) === true) {
                $counts['detail_route_enabled']++;
            }
        }

        return $counts;
    }

    private function projectRow(array $row, string $slug, string $locale): CareerRuntimePublishProjectionDTO
    {
        $type = $this->publicResolutionType($row);
        $indexability = strtolower(trim((string) ($row['indexability'] ?? $row['public_index_state'] ?? '')));
        $publicEligible = (bool) ($row['public_eligible'] ?? $this->derivedPublicEligible($row));
        $hardBlocked = $slug === 'software-developers';
        $blockers = [];

        if ($hardBlocked) {
            $blockers[] = 'software_developers_manual_hold';
        }
        if (! in_array($type, CareerPublicResolutionTypeMatrix::allowedTypes(), true)) {
            $blockers[] = 'unknown_public_resolution_type';
        }

        $robotsIndexable = $publicEligible
            && ! $hardBlocked
            && in_array($indexability, ['indexable', IndexStateValue::INDEXABLE, 'index'], true);

        $canonicalSelf = $type === CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB && ! $hardBlocked;
        $detailRouteEnabled = false;
        $datasetVisible = false;
        $searchVisible = false;
        $sitemapLive = false;
        $llmsLive = false;
        $llmsFullLive = false;

        if ($type === CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB && $publicEligible && ! $hardBlocked) {
            $detailRouteEnabled = true;
            $datasetVisible = true;
            $searchVisible = true;
        } elseif ($type === CareerPublicResolutionTypeMatrix::PUBLIC_ALIAS_REDIRECT && $publicEligible && ! $hardBlocked) {
            $detailRouteEnabled = 'redirect_only';
        }

        $releaseGatePass = $type === CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB
            && $detailRouteEnabled === true
            && $canonicalSelf
            && $robotsIndexable;

        if ($releaseGatePass) {
            $sitemapLive = true;
            $llmsLive = true;
            $llmsFullLive = true;
        }

        $runtimePublishState = $this->runtimePublishState(
            type: $type,
            publicEligible: $publicEligible,
            releaseGatePass: $releaseGatePass,
            hardBlocked: $hardBlocked,
            blockers: $blockers,
        );

        if ($runtimePublishState !== self::STATE_PUBLISHED) {
            if ($detailRouteEnabled === true) {
                $detailRouteEnabled = false;
            }
            $datasetVisible = false;
            $searchVisible = false;
            $sitemapLive = false;
            $llmsLive = false;
            $llmsFullLive = false;
        }

        return new CareerRuntimePublishProjectionDTO(
            slug: $slug,
            locale: $locale,
            publicResolutionType: $type,
            runtimePublishState: $runtimePublishState,
            detailRouteEnabled: $detailRouteEnabled,
            datasetVisible: $datasetVisible && $runtimePublishState !== self::STATE_QUARANTINED,
            searchVisible: $searchVisible && $runtimePublishState !== self::STATE_QUARANTINED,
            sitemapLive: $sitemapLive,
            llmsLive: $llmsLive,
            llmsFullLive: $llmsFullLive,
            canonicalUrl: $canonicalSelf ? 'https://fermatmind.com/'.$locale.'/career/jobs/'.$slug : null,
            canonicalSelf: $canonicalSelf,
            robotsIndexable: $robotsIndexable,
            releaseGatePass: $releaseGatePass,
            blockers: array_values(array_unique($blockers)),
        );
    }

    /**
     * @param  list<string>  $blockers
     */
    private function runtimePublishState(
        string $type,
        bool $publicEligible,
        bool $releaseGatePass,
        bool $hardBlocked,
        array $blockers,
    ): string {
        if ($hardBlocked || in_array('unknown_public_resolution_type', $blockers, true)) {
            return self::STATE_QUARANTINED;
        }

        if (! $publicEligible || in_array($type, [
            CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
            CareerPublicResolutionTypeMatrix::BLOCKED_UNTIL_GOVERNANCE_APPROVAL,
        ], true)) {
            return self::STATE_BLOCKED;
        }

        return $releaseGatePass ? self::STATE_PUBLISHED : self::STATE_PUBLISHED_CANDIDATE;
    }

    private function publicResolutionType(array $row): string
    {
        $type = trim((string) ($row['public_resolution_type'] ?? ''));
        if ($type !== '') {
            return $type;
        }

        $releaseCohort = trim((string) ($row['release_cohort'] ?? ''));
        if (in_array($releaseCohort, ['public_detail_indexable', 'public_detail_conservative'], true)) {
            return CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB;
        }

        return CareerPublicResolutionTypeMatrix::BLOCKED_UNTIL_GOVERNANCE_APPROVAL;
    }

    private function derivedPublicEligible(array $row): bool
    {
        $releaseCohort = trim((string) ($row['release_cohort'] ?? ''));

        return in_array($releaseCohort, ['public_detail_indexable', 'public_detail_conservative'], true);
    }

    private function slugForRow(array $row): ?string
    {
        $slug = trim((string) ($row['source_slug'] ?? $row['canonical_slug'] ?? $row['slug'] ?? ''));

        return $slug === '' ? null : strtolower($slug);
    }
}
