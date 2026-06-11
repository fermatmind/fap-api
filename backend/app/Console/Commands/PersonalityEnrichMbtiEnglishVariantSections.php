<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Services\Cms\MbtiEnglishVariantSectionEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PersonalityEnrichMbtiEnglishVariantSections extends Command
{
    protected $signature = 'personality:enrich-mbti-english-variant-sections
        {--type=* : Canonical MBTI type code to enrich, defaults to all 16 types}
        {--write : Commit CMS section updates; default is dry-run/no-write}
        {--dry-run : Explicitly preview changes without writing}
        {--json : Emit a machine-readable JSON summary}
        {--assert-complete : Fail when the selected type scope is missing any A/T variant}';

    protected $description = 'Enrich English MBTI A/T personality variant sections without changing frontend, publication state, sitemap, or search submission.';

    public function handle(MbtiEnglishVariantSectionEnrichmentService $enrichmentService): int
    {
        $write = (bool) $this->option('write');
        $dryRun = ! $write || (bool) $this->option('dry-run');
        if ($write && (bool) $this->option('dry-run')) {
            $this->error('Use either --write or --dry-run, not both.');

            return self::FAILURE;
        }

        $types = $this->selectedTypes();
        $sectionKeys = $enrichmentService->sectionKeys();
        $summary = [
            'task_id' => 'PERSONALITY-EN-CONTENT-00',
            'dry_run' => $dryRun,
            'writes_committed' => 0,
            'locale' => 'en',
            'types' => $types,
            'expected_variants' => count($types) * 2,
            'variants_scanned' => 0,
            'expected_sections' => count($types) * 2 * count($sectionKeys),
            'sections_scanned' => 0,
            'section_changes' => 0,
            'a_t_differentiated_sections' => [],
            'missing_variants' => [],
            'sample_changes' => [],
            'guardrails' => [
                'frontend_changed' => false,
                'publication_state_changed' => false,
                'sitemap_changed' => false,
                'search_submission' => false,
            ],
        ];

        $seen = [];
        $desiredByRuntime = [];
        $profiles = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', 'en')
            ->whereIn('type_code', $types)
            ->with(['variants.sections'])
            ->orderBy('type_code')
            ->get();

        foreach ($profiles as $profile) {
            foreach ($profile->variants as $variant) {
                if (! $variant instanceof PersonalityProfileVariant) {
                    continue;
                }

                if (! in_array((string) $variant->variant_code, ['A', 'T'], true)) {
                    continue;
                }

                $runtimeTypeCode = (string) $variant->runtime_type_code;
                $seen[$runtimeTypeCode] = true;
                $summary['variants_scanned']++;

                $existingSections = $variant->sections
                    ->filter(static fn (mixed $section): bool => $section instanceof PersonalityProfileVariantSection)
                    ->keyBy(static fn (PersonalityProfileVariantSection $section): string => (string) $section->section_key);

                foreach ($sectionKeys as $sectionKey) {
                    $desired = $enrichmentService->build(
                        $runtimeTypeCode,
                        $this->variantOrProfileTypeName($variant, $profile),
                        $sectionKey,
                    );
                    $desiredByRuntime[$runtimeTypeCode][$sectionKey] = $desired;
                    $summary['sections_scanned']++;

                    /** @var PersonalityProfileVariantSection|null $existing */
                    $existing = $existingSections->get($sectionKey);
                    if ($existing instanceof PersonalityProfileVariantSection && ! $this->sectionNeedsRefresh($existing, $desired)) {
                        continue;
                    }

                    $summary['section_changes']++;
                    if (count($summary['sample_changes']) < 12) {
                        $summary['sample_changes'][] = [
                            'runtime_type_code' => $runtimeTypeCode,
                            'section_key' => $sectionKey,
                        ];
                    }

                    if ($dryRun) {
                        continue;
                    }

                    DB::transaction(function () use ($variant, $sectionKey, $desired): void {
                        PersonalityProfileVariantSection::query()->updateOrCreate(
                            [
                                'personality_profile_variant_id' => (int) $variant->id,
                                'section_key' => $sectionKey,
                            ],
                            $desired,
                        );
                    });
                    $summary['writes_committed']++;
                }
            }
        }

        foreach ($types as $type) {
            foreach (['A', 'T'] as $variantCode) {
                $runtimeTypeCode = $type.'-'.$variantCode;
                if (! isset($seen[$runtimeTypeCode])) {
                    $summary['missing_variants'][] = [
                        'locale' => 'en',
                        'runtime_type_code' => $runtimeTypeCode,
                    ];
                }
            }

            $summary['a_t_differentiated_sections'][$type] = $this->differentiatedSections(
                $desiredByRuntime[$type.'-A'] ?? [],
                $desiredByRuntime[$type.'-T'] ?? [],
            );
        }

        $this->emitSummary($summary);

        if ((bool) $this->option('assert-complete') && $summary['missing_variants'] !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function selectedTypes(): array
    {
        $input = array_map('strval', (array) $this->option('type'));
        $types = $input === [] ? PersonalityProfile::BASE_TYPE_CODES : array_map(
            static fn (string $type): string => strtoupper(trim($type)),
            $input,
        );

        $types = array_values(array_unique($types));
        sort($types);

        foreach ($types as $type) {
            if (! in_array($type, PersonalityProfile::BASE_TYPE_CODES, true)) {
                $this->fail('Unsupported MBTI type code: '.$type);
            }
        }

        return $types;
    }

    /**
     * @param  array<string,mixed>  $desired
     */
    private function sectionNeedsRefresh(PersonalityProfileVariantSection $section, array $desired): bool
    {
        foreach (['render_variant', 'body_md', 'body_html', 'sort_order', 'is_enabled'] as $field) {
            if ($section->{$field} !== $desired[$field]) {
                return true;
            }
        }

        return $section->payload_json !== $desired['payload_json'];
    }

    private function variantOrProfileTypeName(PersonalityProfileVariant $variant, PersonalityProfile $profile): ?string
    {
        $variantTypeName = trim((string) ($variant->type_name ?? ''));
        if ($variantTypeName !== '') {
            return $variantTypeName;
        }

        $profileTypeName = trim((string) ($profile->type_name ?? ''));

        return $profileTypeName !== '' ? $profileTypeName : null;
    }

    /**
     * @param  array<string,array<string,mixed>>  $assertive
     * @param  array<string,array<string,mixed>>  $turbulent
     * @return list<string>
     */
    private function differentiatedSections(array $assertive, array $turbulent): array
    {
        $differentiated = [];
        foreach ($assertive as $sectionKey => $assertiveSection) {
            if (! isset($turbulent[$sectionKey])) {
                continue;
            }

            if ($this->comparableSectionFingerprint($assertiveSection) !== $this->comparableSectionFingerprint($turbulent[$sectionKey])) {
                $differentiated[] = $sectionKey;
            }
        }

        return $differentiated;
    }

    /**
     * @param  array<string,mixed>  $section
     */
    private function comparableSectionFingerprint(array $section): string
    {
        return json_encode([
            'body_md' => $section['body_md'] ?? null,
            'payload_json' => $section['payload_json'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('task_id='.$summary['task_id']);
        $this->line('dry_run='.($summary['dry_run'] ? 'true' : 'false'));
        $this->line('locale='.$summary['locale']);
        $this->line('expected_variants='.$summary['expected_variants']);
        $this->line('variants_scanned='.$summary['variants_scanned']);
        $this->line('expected_sections='.$summary['expected_sections']);
        $this->line('sections_scanned='.$summary['sections_scanned']);
        $this->line('section_changes='.$summary['section_changes']);
        $this->line('writes_committed='.$summary['writes_committed']);
        $this->line('missing_variants='.count((array) $summary['missing_variants']));
    }
}
