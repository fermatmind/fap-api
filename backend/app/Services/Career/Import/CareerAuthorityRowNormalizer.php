<?php

declare(strict_types=1);

namespace App\Services\Career\Import;

use App\Domain\Career\Import\ImportScopeMode;
use Illuminate\Support\Str;

final class CareerAuthorityRowNormalizer
{
    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function normalize(array $row, array $manifest = []): array
    {
        $slug = $this->stringValue($row, 'Slug');
        $canonicalTitleEn = $this->stringValue($row, 'Occupation Title');
        $category = $this->stringValue($row, 'Category');
        $manifestOccupation = $this->manifestOccupation($manifest, $slug);
        $manifestDefaults = is_array($manifest['defaults'] ?? null) ? $manifest['defaults'] : [];
        $familySlug = (string) ($manifestOccupation['family_slug']
            ?? $this->slugValue($category));
        $familyTitleEn = (string) ($manifestOccupation['family_title_en']
            ?? Str::of(str_replace('-', ' ', $category))->title()->toString());

        $mappingMode = (string) ($manifestOccupation['mapping_mode']
            ?? $row['Mapping Mode']
            ?? $manifestDefaults['mapping_mode']
            ?? ImportScopeMode::EXACT);

        $truthMarket = (string) ($manifestOccupation['truth_market']
            ?? $row['Truth Market']
            ?? $manifestDefaults['truth_market']
            ?? 'US');
        $displayMarket = (string) ($manifestOccupation['display_market']
            ?? $row['Display Market']
            ?? $manifestDefaults['display_market']
            ?? $truthMarket);

        $canonicalTitleZh = $this->nullableString(
            $manifestOccupation['canonical_title_zh']
                ?? $row['Canonical Title ZH']
                ?? null
        );

        return [
            'row_number' => (int) ($row['_row_number'] ?? 0),
            'occupation_uuid' => $this->nullableString($manifestOccupation['occupation_uuid'] ?? null),
            'canonical_title_en' => $canonicalTitleEn,
            'canonical_title_zh' => $canonicalTitleZh,
            'canonical_slug' => $slug !== '' ? $slug : Str::slug($canonicalTitleEn),
            'family_uuid' => $this->nullableString($manifestOccupation['family_uuid'] ?? null),
            'family_slug' => $familySlug,
            'family_title_en' => $familyTitleEn,
            'family_title_zh' => $this->nullableString($manifestOccupation['family_title_zh'] ?? null),
            'mapping_mode' => $mappingMode,
            'truth_market' => $truthMarket,
            'display_market' => $displayMarket,
            'crosswalk_mode' => $mappingMode,
            'canonical_path' => '/career/jobs/'.($slug !== '' ? $slug : Str::slug($canonicalTitleEn)),
            'crosswalk_source_system' => 'us_soc',
            'crosswalk_source_code' => $this->stringValue($row, 'SOC Code'),
            'crosswalk_source_title' => $canonicalTitleEn,
            'ai_exposure' => $this->nullableFloat($row['AI Exposure (0-10)'] ?? null),
            'median_pay_usd_annual' => $this->nullableInt($row['Median Pay Annual (USD)'] ?? null),
            'median_pay_hourly_usd' => $this->nullableFloat($row['Median Pay Hourly (USD)'] ?? null),
            'jobs_2024' => $this->nullableInt($row['Jobs 2024'] ?? null),
            'projected_jobs_2034' => $this->nullableInt($row['Projected Jobs 2034'] ?? null),
            'employment_change' => $this->nullableInt($row['Employment Change'] ?? null),
            'outlook_pct_2024_2034' => $this->nullableFloat($row['Outlook %'] ?? null),
            'outlook_description' => $this->nullableString($row['Outlook Description'] ?? null),
            'entry_education' => $this->nullableString($row['Entry Education'] ?? null),
            'work_experience' => $this->nullableString($row['Work Experience'] ?? null),
            'on_the_job_training' => $this->nullableString($row['Training'] ?? null),
            'ai_rationale' => $this->nullableString($row['AI Rationale'] ?? null),
            'bls_url' => $this->nullableString($row['BLS URL'] ?? null),
            'source_url' => $this->nullableString($row['AI Exposure Source'] ?? null),
            'structural_stability' => $this->nullableFloat($manifestOccupation['structural_stability'] ?? null),
            'task_prototype_signature' => $manifestOccupation['task_prototype_signature'] ?? null,
            'market_semantics_gap' => $this->nullableFloat($manifestOccupation['market_semantics_gap'] ?? null),
            'regulatory_divergence' => $this->nullableFloat($manifestOccupation['regulatory_divergence'] ?? null),
            'toolchain_divergence' => $this->nullableFloat($manifestOccupation['toolchain_divergence'] ?? null),
            'skill_gap_threshold' => $this->nullableFloat($manifestOccupation['skill_gap_threshold'] ?? null),
            'trust_inheritance_scope' => $manifestOccupation['trust_inheritance_scope']
                ?? ['allow_task_truth' => true, 'allow_pay_direct_inheritance' => false],
            'skill_graph' => is_array($manifestOccupation['skill_graph'] ?? null) ? $manifestOccupation['skill_graph'] : null,
            'source_title' => $canonicalTitleEn.' source trace',
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function manifestOccupation(array $manifest, string $slug): array
    {
        $occupations = is_array($manifest['occupations'] ?? null) ? $manifest['occupations'] : [];
        $occupation = $occupations[$slug] ?? null;

        if (! is_array($occupation)) {
            foreach ($occupations as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                if ((string) ($candidate['canonical_slug'] ?? '') === $slug) {
                    $occupation = $candidate;
                    break;
                }
            }
        }

        return is_array($occupation) ? $occupation : [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringValue(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    private function slugValue(string $value): string
    {
        return Str::slug($value);
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) round((float) $value);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (float) $value;
    }
}
