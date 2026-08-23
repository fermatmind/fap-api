<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use Illuminate\Support\Collection;

final class SeoIssuePriorityScorer
{
    private const SEVERITY_WEIGHT = [
        'info' => 1,
        'low' => 2,
        'medium' => 3,
        'high' => 4,
        'critical' => 5,
    ];

    /**
     * @param  array<string,mixed>  $cluster
     * @param  Collection<int,object>  $members
     * @param  array<string,array{clicks:int,impressions:int}>  $gscByUrlHash
     * @return array<string,mixed>
     */
    public function score(array $cluster, Collection $members, array $gscByUrlHash, bool $gscQualityPassed): array
    {
        $severity = (string) ($cluster['severity'] ?? 'info');
        $severityWeight = self::SEVERITY_WEIGHT[$severity] ?? 1;
        $affectedUrls = max(1, (int) ($cluster['affected_url_count'] ?? 0));
        $severityUrlPoints = $severityWeight * $affectedUrls;
        $gsc = $this->gscImpact($members, $gscByUrlHash, $gscQualityPassed);
        $impact = $severityUrlPoints + $gsc['points'];
        $confidence = $this->confidence($members, $gscQualityPassed);
        $effort = $this->effort($members, $cluster);
        $score = round(($impact * $confidence['value']) / $effort['value'], 2);

        return [
            'formula' => 'impact_x_confidence_div_effort',
            'ranking_eligible' => $confidence['value'] > 0,
            'score' => $score,
            'impact' => [
                'total' => $impact,
                'severity' => [
                    'level' => $severity,
                    'weight' => $severityWeight,
                    'affected_urls' => $affectedUrls,
                    'points' => $severityUrlPoints,
                ],
                'gsc' => $gsc,
            ],
            'confidence' => $confidence,
            'effort' => $effort,
            'sort_reason' => sprintf(
                'impact_%d_x_confidence_%s_div_effort_%d',
                $impact,
                $this->decimal($confidence['value']),
                $effort['value'],
            ),
        ];
    }

    /**
     * @param  Collection<int,object>  $members
     * @param  array<string,array{clicks:int,impressions:int}>  $gscByUrlHash
     * @return array{included:bool,quality_gate:string,clicks:int,impressions:int,points:int,basis:string}
     */
    private function gscImpact(Collection $members, array $gscByUrlHash, bool $gscQualityPassed): array
    {
        if (! $gscQualityPassed) {
            return [
                'included' => false,
                'quality_gate' => 'not_passed',
                'clicks' => 0,
                'impressions' => 0,
                'points' => 0,
                'basis' => 'cms_technical_only_no_eligible_gsc',
            ];
        }

        $hashes = $members
            ->pluck('canonical_url_hash')
            ->filter(fn (mixed $hash): bool => is_string($hash) && $hash !== '')
            ->unique();
        $clicks = 0;
        $impressions = 0;
        foreach ($hashes as $hash) {
            $clicks += (int) data_get($gscByUrlHash, $hash.'.clicks', 0);
            $impressions += (int) data_get($gscByUrlHash, $hash.'.impressions', 0);
        }

        return [
            'included' => $clicks > 0 || $impressions > 0,
            'quality_gate' => 'passed',
            'clicks' => $clicks,
            'impressions' => $impressions,
            'points' => $clicks + $impressions,
            'basis' => 'observed_clicks_plus_impressions_no_loss_estimate',
        ];
    }

    /** @param Collection<int,object> $members @return array{value:float,basis:string} */
    private function confidence(Collection $members, bool $gscQualityPassed): array
    {
        $sourceSystems = $members->pluck('source_system')
            ->map(static fn (mixed $source): string => strtolower(trim((string) $source)));

        if ($sourceSystems->contains(fn (string $source): bool => str_contains($source, 'gsc'))) {
            return $gscQualityPassed
                ? ['value' => 0.9, 'basis' => 'gsc_quality_gate_passed']
                : ['value' => 0.0, 'basis' => 'gsc_quality_gate_blocked'];
        }

        $templateInferred = $members->contains(function (object $row): bool {
            $metadata = $this->metadata($row);

            return empty($metadata['root_cause']) || empty($metadata['template']) || empty($metadata['field']);
        });

        return $templateInferred
            ? ['value' => 0.75, 'basis' => 'template_inference']
            : ['value' => 1.0, 'basis' => 'deterministic_cms_or_technical_rule'];
    }

    /** @param Collection<int,object> $members @param array<string,mixed> $cluster @return array{value:int,basis:string} */
    private function effort(Collection $members, array $cluster): array
    {
        $metadata = $members->map(fn (object $row): array => $this->metadata($row));

        if ($metadata->contains(fn (array $item): bool => (bool) ($item['external_dependency'] ?? false))) {
            return ['value' => 5, 'basis' => 'external_dependency'];
        }

        if ($metadata->contains(fn (array $item): bool => (bool) ($item['engineering_fix'] ?? false))) {
            return ['value' => 4, 'basis' => 'engineering_fix'];
        }

        if ($metadata->contains(fn (array $item): bool => (bool) ($item['autofixable'] ?? false))) {
            return ['value' => 1, 'basis' => 'batch_automatic_fix'];
        }

        if (($cluster['template'] ?? 'unknown') !== 'unknown') {
            return ['value' => 3, 'basis' => 'template_fix'];
        }

        return ['value' => 2, 'basis' => 'single_manual_fix'];
    }

    /** @return array<string,mixed> */
    private function metadata(object $row): array
    {
        $value = $row->metadata_json ?? null;
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
