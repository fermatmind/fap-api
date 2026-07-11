<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MbtiContent15IndexabilityPromotionService
{
    public const CONTRACT = 'mbti.content15.indexability-promotion.v1';

    /** @return array<string,mixed> */
    public function plan(array $package): array
    {
        $records = $this->validatePackage($package);
        $plans = array_map(fn (array $record): array => $this->inspect($record), $records);
        $errors = array_values(array_filter($plans, static fn (array $plan): bool => ($plan['ok'] ?? false) !== true));

        return [
            'ok' => $errors === [],
            'mode' => 'dry_run',
            'contract' => self::CONTRACT,
            'record_count' => count($plans),
            'records' => $plans,
            'promotion_package_sha256' => $this->packageSha($package),
            'authorization_payload_sha256' => $this->authorizationSha($package),
            'authorization_payload' => $this->authorizationPayload($package),
            'production_promotion_executed' => false,
            'search_submission_executed' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function promote(array $package): array
    {
        $preflight = $this->plan($package);
        if (($preflight['ok'] ?? false) !== true) {
            throw new RuntimeException('Promotion preflight failed; no records were changed.');
        }

        DB::transaction(function () use ($package): void {
            foreach ($this->validatePackage($package) as $record) {
                $this->apply($record);
            }
        });

        $result = $this->plan($package);
        $result['mode'] = 'write';
        $result['production_promotion_executed'] = true;

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function validatePackage(array $package): array
    {
        if (($package['contract'] ?? null) !== self::CONTRACT) {
            throw new RuntimeException('Promotion package contract mismatch.');
        }
        $records = $package['records'] ?? null;
        if (! is_array($records) || count($records) !== 9) {
            throw new RuntimeException('Promotion package must contain exactly 9 records.');
        }
        $slugs = array_map(static fn (mixed $record): string => is_array($record) ? (string) ($record['slug'] ?? '') : '', $records);
        if (count(array_unique($slugs)) !== 9 || in_array('', $slugs, true)) {
            throw new RuntimeException('Promotion package slugs must be non-empty and unique.');
        }
        foreach ($records as $record) {
            if (! is_array($record) || ($record['locale'] ?? null) !== 'zh-CN') {
                throw new RuntimeException('Every promotion record must be a zh-CN object.');
            }
            if (! in_array($record['kind'] ?? null, ['profile', 'at_comparison', 'cross_type_comparison'], true)) {
                throw new RuntimeException('Unsupported promotion record kind.');
            }
        }

        return array_values($records);
    }

    /** @return array<string,mixed> */
    private function inspect(array $record): array
    {
        try {
            $state = match ($record['kind']) {
                'profile' => $this->inspectProfile($record),
                'at_comparison' => $this->inspectAtComparison($record),
                'cross_type_comparison' => $this->inspectCrossType($record),
            };

            return ['ok' => true, 'slug' => $record['slug'], 'kind' => $record['kind'], 'state' => $state];
        } catch (RuntimeException $exception) {
            return ['ok' => false, 'slug' => $record['slug'], 'kind' => $record['kind'], 'error' => $exception->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function inspectProfile(array $record): array
    {
        [$profile, $variant, $seo] = $this->profileRows($record);
        $robots = strtolower((string) $seo->robots);
        $alreadyPromoted = (bool) $profile->is_indexable && ! str_contains($robots, 'noindex');
        if (! $alreadyPromoted && (! (bool) $profile->is_indexable || ! str_contains($robots, 'noindex'))) {
            throw new RuntimeException('Profile expected pre-state does not match indexable profile + noindex variant SEO.');
        }

        return ['already_promoted' => $alreadyPromoted, 'profile_id' => $profile->id, 'variant_id' => $variant->id, 'robots' => $seo->robots];
    }

    /** @return array<string,mixed> */
    private function inspectAtComparison(array $record): array
    {
        $section = $this->atSection($record);
        $payload = (array) $section->payload_json;
        $held = (bool) ($payload['indexability_held'] ?? false);
        $robots = strtolower((string) data_get($payload, 'content.seo.robots', data_get($payload, 'seo.robots', '')));
        $alreadyPromoted = ! $held && $robots !== '' && ! str_contains($robots, 'noindex');
        if (! $alreadyPromoted && (! $held || ($robots !== '' && ! str_contains($robots, 'noindex')))) {
            throw new RuntimeException('A/T comparison expected pre-state does not match the held indexability gate.');
        }

        return ['already_promoted' => $alreadyPromoted, 'section_id' => $section->id, 'indexability_held' => $held, 'robots' => $robots];
    }

    /** @return array<string,mixed> */
    private function inspectCrossType(array $record): array
    {
        $row = $this->crossTypeRow($record);
        $alreadyPromoted = (bool) $row->is_indexable && (bool) $row->sitemap_eligible && (bool) $row->llms_eligible;
        if (! $alreadyPromoted && ((bool) $row->is_indexable || (bool) $row->sitemap_eligible || (bool) $row->llms_eligible || (bool) $row->search_submission_eligible)) {
            throw new RuntimeException('Cross-type comparison expected pre-state is not fully held.');
        }

        return ['already_promoted' => $alreadyPromoted, 'authority_id' => $row->id, 'indexability_status' => $row->indexability_status];
    }

    private function apply(array $record): void
    {
        if ($record['kind'] === 'profile') {
            [$profile, , $seo] = $this->profileRows($record, true);
            $profile->forceFill(['is_indexable' => true])->save();
            $seo->forceFill(['robots' => 'index,follow'])->save();

            return;
        }
        if ($record['kind'] === 'at_comparison') {
            $section = $this->atSection($record, true);
            $payload = (array) $section->payload_json;
            $payload['indexability_held'] = false;
            if (is_array(data_get($payload, 'content.seo'))) {
                data_set($payload, 'content.seo.robots', 'index,follow');
            } else {
                data_set($payload, 'seo.robots', 'index,follow');
            }
            $section->forceFill(['payload_json' => $payload])->save();

            return;
        }

        $this->crossTypeRow($record, true)->forceFill([
            'indexability_status' => 'released_by_mbti_index_24b',
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'search_submission_eligible' => false,
        ])->save();
    }

    /** @return array{PersonalityProfile,PersonalityProfileVariant,PersonalityProfileVariantSeoMeta} */
    private function profileRows(array $record, bool $lock = false): array
    {
        $runtime = strtoupper((string) $record['runtime_type_code']);
        $variantQuery = PersonalityProfileVariant::query()->withoutGlobalScopes()
            ->where('runtime_type_code', $runtime)
            ->whereHas('profile', static fn ($query) => $query->withoutGlobalScopes()->where('locale', 'zh-CN'));
        $variant = ($lock ? $variantQuery->lockForUpdate() : $variantQuery)->first();
        if (! $variant instanceof PersonalityProfileVariant) {
            throw new RuntimeException('Profile variant not found.');
        }
        $profileQuery = PersonalityProfile::query()->withoutGlobalScopes()->whereKey($variant->personality_profile_id)->where('locale', 'zh-CN');
        $profile = ($lock ? $profileQuery->lockForUpdate() : $profileQuery)->first();
        $seoQuery = PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->where('personality_profile_variant_id', $variant->id);
        $seo = ($lock ? $seoQuery->lockForUpdate() : $seoQuery)->first();
        if (! $profile instanceof PersonalityProfile || ! $seo instanceof PersonalityProfileVariantSeoMeta) {
            throw new RuntimeException('Profile authority rows are incomplete.');
        }

        return [$profile, $variant, $seo];
    }

    private function atSection(array $record, bool $lock = false): PersonalityProfileSection
    {
        $base = strtoupper((string) $record['base_type_code']);
        $profile = PersonalityProfile::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->where('canonical_type_code', $base)->first();
        if (! $profile instanceof PersonalityProfile) {
            throw new RuntimeException('A/T comparison profile not found.');
        }
        $query = PersonalityProfileSection::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->where('section_key', 'mbti64_comparison_a_vs_t')->where('is_enabled', true);
        $section = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $section instanceof PersonalityProfileSection) {
            throw new RuntimeException('A/T comparison authority section not found.');
        }

        return $section;
    }

    private function crossTypeRow(array $record, bool $lock = false): MbtiCrossTypeComparisonAuthority
    {
        $query = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->where('slug', $record['slug']);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row instanceof MbtiCrossTypeComparisonAuthority) {
            throw new RuntimeException('Cross-type comparison authority row not found.');
        }

        return $row;
    }

    private function packageSha(array $package): string
    {
        return hash('sha256', (string) json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    private function authorizationPayload(array $package): array
    {
        return [
            'contract' => 'mbti.content15.indexability-promotion.authorization.v1',
            'promotion_package_sha256' => $this->packageSha($package),
            'import_scope_mode' => 'top_blocker_batch_only',
            'record_count' => 9,
            'urls' => array_values(array_map(static fn (array $record): string => (string) $record['url'], $package['records'])),
        ];
    }

    private function authorizationSha(array $package): string
    {
        return hash('sha256', (string) json_encode($this->authorizationPayload($package), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
