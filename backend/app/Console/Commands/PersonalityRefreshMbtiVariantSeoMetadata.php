<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Services\Cms\MbtiPersonalityVariantSeoMetadataService;
use App\Services\Cms\MbtiSeoFieldOverrideRevisionService;
use App\Services\Cms\PersonalityPublicReadModelCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PersonalityRefreshMbtiVariantSeoMetadata extends Command
{
    protected $signature = 'personality:refresh-mbti-variant-seo-metadata
        {--locale=* : Locale to refresh, defaults to en and zh-CN}
        {--type=* : Canonical MBTI type code to refresh, defaults to all 16 types}
        {--dry-run : Preview changes without writing}
        {--json : Emit a machine-readable JSON summary}
        {--assert-complete : Fail when the selected locale/type scope is missing any A/T variant}';

    protected $description = 'Refresh MBTI personality variant SEO metadata without changing profile content, sections, publication state, canonical, or robots.';

    public function handle(
        MbtiPersonalityVariantSeoMetadataService $metadataService,
        MbtiSeoFieldOverrideRevisionService $overrideService,
        PersonalityPublicReadModelCache $readModelCache,
    ): int {
        $locales = $this->selectedLocales();
        $types = $this->selectedTypes();
        $dryRun = (bool) $this->option('dry-run');
        $assertComplete = (bool) $this->option('assert-complete');
        $summary = [
            'dry_run' => $dryRun,
            'locales' => $locales,
            'types' => $types,
            'expected_variants' => count($locales) * count($types) * 2,
            'profiles_scanned' => 0,
            'variants_scanned' => 0,
            'metadata_changes' => 0,
            'writes_committed' => 0,
            'protected_override_count' => 0,
            'protected_fields' => [],
            'cache_invalidations' => 0,
            'missing_variants' => [],
            'sample_changes' => [],
        ];

        $seen = [];
        $plans = [];
        $profiles = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->whereIn('locale', $locales)
            ->whereIn('type_code', $types)
            ->with(['variants.seoMeta'])
            ->orderBy('locale')
            ->orderBy('type_code')
            ->get();

        $summary['profiles_scanned'] = $profiles->count();

        foreach ($profiles as $profile) {
            foreach ($profile->variants as $variant) {
                if (! $variant instanceof PersonalityProfileVariant
                    || ! in_array((string) $variant->variant_code, ['A', 'T'], true)) {
                    continue;
                }

                $runtimeTypeCode = (string) $variant->runtime_type_code;
                $seen[(string) $profile->locale.'|'.$runtimeTypeCode] = true;
                $summary['variants_scanned']++;

                $seoMeta = $variant->seoMeta;
                $override = $seoMeta instanceof PersonalityProfileVariantSeoMeta
                    ? $overrideService->resolve($profile, $variant, $seoMeta)
                    : $this->emptyOverride();
                if ($override['protected_fields'] !== []) {
                    $summary['protected_override_count']++;
                    $summary['protected_fields'] = array_values(array_unique([
                        ...$summary['protected_fields'],
                        ...$override['protected_fields'],
                    ]));
                }

                $desired = $metadataService->build(
                    $runtimeTypeCode,
                    (string) $profile->locale,
                    $this->profileTypeName($profile),
                );
                foreach ($override['protected_fields'] as $protectedField) {
                    unset($desired[$protectedField]);
                }

                $needsRefresh = $this->metadataNeedsRefresh($seoMeta, $desired);
                if ($needsRefresh) {
                    $summary['metadata_changes']++;
                    if (count($summary['sample_changes']) < 8) {
                        $summary['sample_changes'][] = [
                            'locale' => (string) $profile->locale,
                            'runtime_type_code' => $runtimeTypeCode,
                            'seo_title' => $desired['seo_title'] ?? (string) $seoMeta?->seo_title,
                        ];
                    }
                }

                $plans[] = [
                    'profile_id' => (int) $profile->id,
                    'variant_id' => (int) $variant->id,
                    'locale' => (string) $profile->locale,
                    'runtime_type_code' => $runtimeTypeCode,
                    'profile_type_name' => $this->profileTypeName($profile),
                    'desired' => $desired,
                    'protected_fields' => $override['protected_fields'],
                    'marker_snapshot_sha256' => $override['marker_snapshot_sha256'],
                    'current' => $this->currentMetadata($seoMeta),
                    'needs_refresh' => $needsRefresh,
                ];
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

        sort($summary['protected_fields']);
        if ($assertComplete && $summary['missing_variants'] !== []) {
            $this->emitSummary($summary);

            return self::FAILURE;
        }

        if (! $dryRun) {
            DB::transaction(function () use (
                $plans,
                $metadataService,
                $overrideService,
                $readModelCache,
                &$summary,
            ): void {
                foreach ($plans as $plan) {
                    $profile = PersonalityProfile::query()
                        ->withoutGlobalScopes()
                        ->whereKey($plan['profile_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $variant = PersonalityProfileVariant::query()
                        ->withoutGlobalScopes()
                        ->whereKey($plan['variant_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $seoMeta = PersonalityProfileVariantSeoMeta::query()
                        ->withoutGlobalScopes()
                        ->where('personality_profile_variant_id', (int) $variant->id)
                        ->lockForUpdate()
                        ->first();

                    $override = $seoMeta instanceof PersonalityProfileVariantSeoMeta
                        ? $overrideService->resolve($profile, $variant, $seoMeta, lock: true)
                        : $this->emptyOverride();
                    $desired = $metadataService->build(
                        (string) $variant->runtime_type_code,
                        (string) $profile->locale,
                        $this->profileTypeName($profile),
                    );
                    foreach ($override['protected_fields'] as $protectedField) {
                        unset($desired[$protectedField]);
                    }

                    if ($plan['protected_fields'] !== $override['protected_fields']
                        || $plan['marker_snapshot_sha256'] !== $override['marker_snapshot_sha256']
                        || $plan['current'] !== $this->currentMetadata($seoMeta)
                        || $plan['desired'] !== $desired) {
                        throw new RuntimeException('MBTI variant SEO metadata authority drifted after planning; no writes were committed.');
                    }

                    if (! $plan['needs_refresh']) {
                        continue;
                    }

                    PersonalityProfileVariantSeoMeta::query()->updateOrCreate(
                        ['personality_profile_variant_id' => (int) $variant->id],
                        $desired,
                    );
                    if (! $readModelCache->forgetType(
                        (string) $variant->runtime_type_code,
                        (string) $profile->locale,
                        0,
                        PersonalityProfile::SCALE_CODE_MBTI,
                    )) {
                        throw new RuntimeException('MBTI personality public read-model cache invalidation failed; database writes were rolled back.');
                    }

                    $summary['writes_committed']++;
                    $summary['cache_invalidations']++;
                }
            }, 3);
        }

        $this->emitSummary($summary);

        return self::SUCCESS;
    }

    /** @return array{status:string,protected_fields:list<string>,marker_revision_id:null,marker_snapshot_sha256:null} */
    private function emptyOverride(): array
    {
        return [
            'status' => 'none',
            'protected_fields' => [],
            'marker_revision_id' => null,
            'marker_snapshot_sha256' => null,
        ];
    }

    /** @return list<string> */
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

    /** @return list<string> */
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

    /** @param array<string,string> $desired */
    private function metadataNeedsRefresh(?PersonalityProfileVariantSeoMeta $current, array $desired): bool
    {
        if (! $current instanceof PersonalityProfileVariantSeoMeta) {
            return true;
        }

        foreach ($desired as $field => $value) {
            if ((string) ($current->{$field} ?? '') !== $value) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed>|null */
    private function currentMetadata(?PersonalityProfileVariantSeoMeta $seoMeta): ?array
    {
        return $seoMeta?->only([
            'personality_profile_variant_id', 'seo_title', 'seo_description', 'canonical_url',
            'og_title', 'og_description', 'og_image_url', 'twitter_title', 'twitter_description',
            'twitter_image_url', 'robots', 'jsonld_overrides_json',
        ]);
    }

    private function profileTypeName(PersonalityProfile $profile): ?string
    {
        $typeName = trim((string) ($profile->type_name ?? ''));

        return $typeName !== '' ? $typeName : null;
    }

    /** @param array<string,mixed> $summary */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('dry_run='.($summary['dry_run'] ? 'true' : 'false'));
        $this->line('expected_variants='.$summary['expected_variants']);
        $this->line('profiles_scanned='.$summary['profiles_scanned']);
        $this->line('variants_scanned='.$summary['variants_scanned']);
        $this->line('metadata_changes='.$summary['metadata_changes']);
        $this->line('writes_committed='.$summary['writes_committed']);
        $this->line('protected_override_count='.$summary['protected_override_count']);
        $this->line('protected_fields='.json_encode($summary['protected_fields'], JSON_UNESCAPED_SLASHES));
        $this->line('cache_invalidations='.$summary['cache_invalidations']);
        $this->line('missing_variants='.count((array) $summary['missing_variants']));
    }
}
