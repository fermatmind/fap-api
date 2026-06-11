<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Services\Cms\MbtiPersonalityVariantSectionStructureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PersonalityEnsureMbtiVariantSectionStructure extends Command
{
    protected $signature = 'personality:ensure-mbti-variant-section-structure
        {--locale=* : Locale to ensure, defaults to en and zh-CN}
        {--type=* : Canonical MBTI type code to ensure, defaults to all 16 types}
        {--dry-run : Preview changes without writing}
        {--json : Emit a machine-readable JSON summary}
        {--assert-complete : Fail when the selected locale/type scope is missing any A/T variant}';

    protected $description = 'Ensure MBTI personality variant pages have the canonical section structure without publishing articles, changing sitemap, or submitting search surfaces.';

    public function handle(MbtiPersonalityVariantSectionStructureService $structureService): int
    {
        $locales = $this->selectedLocales();
        $types = $this->selectedTypes();
        $dryRun = (bool) $this->option('dry-run');
        $requiredSectionKeys = $structureService->requiredSectionKeys();
        $summary = [
            'dry_run' => $dryRun,
            'locales' => $locales,
            'types' => $types,
            'required_section_keys' => $requiredSectionKeys,
            'expected_variants' => count($locales) * count($types) * 2,
            'variants_scanned' => 0,
            'expected_required_sections' => count($locales) * count($types) * 2 * count($requiredSectionKeys),
            'existing_required_sections' => 0,
            'section_changes' => 0,
            'writes_committed' => 0,
            'preserved_existing_sections' => 0,
            'missing_variants' => [],
            'sample_changes' => [],
        ];

        $seen = [];
        $profiles = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->whereIn('locale', $locales)
            ->whereIn('type_code', $types)
            ->with(['variants.sections'])
            ->orderBy('locale')
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
                $seen[(string) $profile->locale.'|'.$runtimeTypeCode] = true;
                $summary['variants_scanned']++;

                $existingSections = $variant->sections
                    ->filter(static fn (mixed $section): bool => $section instanceof PersonalityProfileVariantSection)
                    ->keyBy(static fn (PersonalityProfileVariantSection $section): string => (string) $section->section_key);

                foreach ($requiredSectionKeys as $sectionKey) {
                    /** @var PersonalityProfileVariantSection|null $existing */
                    $existing = $existingSections->get($sectionKey);
                    if ($existing instanceof PersonalityProfileVariantSection && $this->sectionHasContent($existing)) {
                        $summary['existing_required_sections']++;
                        $summary['preserved_existing_sections']++;
                        continue;
                    }

                    if ($existing instanceof PersonalityProfileVariantSection) {
                        $summary['existing_required_sections']++;
                    }

                    $desired = $structureService->build(
                        $runtimeTypeCode,
                        (string) $profile->locale,
                        $this->variantOrProfileTypeName($variant, $profile),
                        $sectionKey,
                    );

                    if ($existing instanceof PersonalityProfileVariantSection && ! $this->sectionNeedsRefresh($existing, $desired)) {
                        continue;
                    }

                    $summary['section_changes']++;
                    if (count($summary['sample_changes']) < 10) {
                        $summary['sample_changes'][] = [
                            'locale' => (string) $profile->locale,
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

        foreach ($locales as $locale) {
            foreach ($types as $type) {
                foreach (['A', 'T'] as $variant) {
                    $runtimeTypeCode = $type.'-'.$variant;
                    if (! isset($seen[$locale.'|'.$runtimeTypeCode])) {
                        $summary['missing_variants'][] = [
                            'locale' => $locale,
                            'runtime_type_code' => $runtimeTypeCode,
                        ];
                    }
                }
            }
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
    private function selectedLocales(): array
    {
        $input = array_map('strval', (array) $this->option('locale'));
        $locales = $input === [] ? PersonalityProfile::SUPPORTED_LOCALES : array_map(static function (string $locale): string {
            $normalized = trim($locale);

            return $normalized === 'zh' ? 'zh-CN' : $normalized;
        }, $input);

        $locales = array_values(array_unique($locales));
        sort($locales);

        foreach ($locales as $locale) {
            if (! in_array($locale, PersonalityProfile::SUPPORTED_LOCALES, true)) {
                $this->fail('Unsupported locale: '.$locale);
            }
        }

        return $locales;
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

    private function sectionHasContent(PersonalityProfileVariantSection $section): bool
    {
        if (trim((string) $section->body_md) !== '' || trim((string) $section->body_html) !== '') {
            return true;
        }

        return is_array($section->payload_json) && $section->payload_json !== [];
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
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('dry_run='.($summary['dry_run'] ? 'true' : 'false'));
        $this->line('expected_variants='.$summary['expected_variants']);
        $this->line('variants_scanned='.$summary['variants_scanned']);
        $this->line('expected_required_sections='.$summary['expected_required_sections']);
        $this->line('existing_required_sections='.$summary['existing_required_sections']);
        $this->line('section_changes='.$summary['section_changes']);
        $this->line('writes_committed='.$summary['writes_committed']);
        $this->line('preserved_existing_sections='.$summary['preserved_existing_sections']);
        $this->line('missing_variants='.count((array) $summary['missing_variants']));
    }
}
