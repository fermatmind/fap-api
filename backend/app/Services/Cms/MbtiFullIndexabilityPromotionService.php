<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MbtiFullIndexabilityPromotionService
{
    public const CONTRACT = 'mbti.full.indexability-promotion.v1';

    private const SOURCE_SHA = '840288581ce02e26afdd40dde1e25cf995fe334791b0a306929a13c76247a78d';

    private const SCOPE = 'full_chinese_mbti_repair_batch_only';

    public function __construct(
        private readonly MbtiFullCmsPromotionService $publicContentPromotion,
        private readonly PersonalityPublicReadModelCache $readModelCache,
    ) {}

    /** @param array<string,mixed> $package @return array<string,mixed> */
    public function plan(array $package): array
    {
        $contentPlan = $this->publicContentPlan($package);
        $errors = [];
        if (($contentPlan['ok'] ?? false) !== true) {
            $errors[] = $this->issue('public_content', 'public_content_preflight_failed', 'The exact CMS-40 public content preflight must pass before indexability release.');
        }

        $contentRows = collect((array) ($contentPlan['rows'] ?? []))->keyBy('approval_record_id');
        $records = $this->records($package, $errors);
        $profileRuntimeCodes = collect($records)
            ->where('entity_kind', 'profile')
            ->mapWithKeys(static fn (array $record): array => [strtoupper((string) data_get($record, 'import_payload.identity.runtime_type_code')) => true])
            ->all();
        $rows = [];

        foreach ($records as $position => $record) {
            $field = 'repair_records.'.$position;
            $contentRow = $contentRows->get((string) ($record['approval_record_id'] ?? ''));
            try {
                $authorityRow = ($record['entity_kind'] ?? null) === 'profile'
                    ? $this->profileRow($record, $field)
                    : $this->comparisonRow($record, $field, $profileRuntimeCodes);
                $contentReady = is_array($contentRow)
                    && (($contentRow['live_matches_draft'] ?? false) === true
                        || (($authorityRow['already_promoted'] ?? false) === true
                            && $this->releasedPublicContentMatches($authorityRow, $contentRow)));
                if (! $contentReady) {
                    $errors[] = $this->issue($field, 'public_content_not_promoted', 'The exact CMS-40 public content is not live for this record.');

                    continue;
                }
                $rows[] = $authorityRow;
            } catch (RuntimeException $exception) {
                $errors[] = $this->issue($field, 'prestate_mismatch', $exception->getMessage());
            }
        }

        $manifest = $this->promotionManifest($rows);
        $promotionSha = $this->hashCanonicalJson($manifest);
        $authorization = $this->authorizationPayload($promotionSha, $rows);

        return [
            'ok' => $errors === [],
            'status' => $errors === [] ? 'pass' : 'fail',
            'mode' => 'dry_run',
            'contract' => self::CONTRACT,
            'source_package_sha256' => self::SOURCE_SHA,
            'import_scope_mode' => self::SCOPE,
            'record_count' => count($rows),
            'profile_row_count' => count(array_filter($rows, static fn (array $row): bool => $row['entity_kind'] === 'profile')),
            'at_comparison_row_count' => count(array_filter($rows, static fn (array $row): bool => $row['entity_kind'] === 'at_comparison')),
            'already_promoted_count' => count(array_filter($rows, static fn (array $row): bool => $row['already_promoted'] === true)),
            'promotion_package_sha256' => $promotionSha,
            'authorization_payload_sha256' => $this->hashCanonicalJson($authorization),
            'authorization_payload' => $authorization,
            'exact_urls' => array_values(array_map(static fn (array $row): string => $row['url'], $rows)),
            'rows' => $rows,
            'errors' => $errors,
            'writes_committed' => false,
            'production_promotion_executed' => false,
            'gsc_executed' => false,
            'url_inspection_executed' => false,
        ];
    }

    /** @param array<string,mixed> $package @return array<string,mixed> */
    public function promote(array $package): array
    {
        $preflight = $this->plan($package);
        if (($preflight['ok'] ?? false) !== true) {
            throw new RuntimeException('Indexability promotion preflight failed; no records were changed.');
        }

        $result = DB::transaction(function () use ($package, $preflight): array {
            $this->lockRows((array) $preflight['rows']);
            $lockedPlan = $this->plan($package);
            if (($lockedPlan['ok'] ?? false) !== true
                || ! hash_equals((string) $preflight['promotion_package_sha256'], (string) $lockedPlan['promotion_package_sha256'])
                || ! hash_equals((string) $preflight['authorization_payload_sha256'], (string) $lockedPlan['authorization_payload_sha256'])) {
                throw new RuntimeException('Indexability authority changed while acquiring locks; no records were changed.');
            }

            foreach ((array) $lockedPlan['rows'] as $row) {
                if (($row['already_promoted'] ?? false) === true) {
                    continue;
                }
                $this->apply($row);
            }

            $after = $this->plan($package);
            if (($after['ok'] ?? false) !== true || (int) ($after['already_promoted_count'] ?? 0) !== 43) {
                throw new RuntimeException('Indexability promotion post-write verification failed; the transaction was rolled back.');
            }

            return $after;
        });

        $invalidated = 0;
        foreach ((array) $result['rows'] as $row) {
            if (($row['entity_kind'] ?? null) !== 'profile') {
                continue;
            }
            if ($this->readModelCache->forgetType((string) $row['slug'], 'zh-CN', 0, PersonalityProfile::SCALE_CODE_MBTI)) {
                $invalidated++;
            }
        }

        $result['mode'] = 'write';
        $result['writes_committed'] = true;
        $result['production_promotion_executed'] = true;
        $result['read_model_cache_invalidated_count'] = $invalidated;

        return $result;
    }

    /** @param array<string,mixed> $package @return array<string,mixed> */
    private function publicContentPlan(array $package): array
    {
        return $this->publicContentPromotion->plan($package, [
            'expected_source_package_sha256' => self::SOURCE_SHA,
            'expected_import_scope_mode' => self::SCOPE,
            'expected_record_count' => 43,
        ]);
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $errors @return list<array<string,mixed>> */
    private function records(array $package, array &$errors): array
    {
        $records = is_array($package['repair_records'] ?? null) ? array_values($package['repair_records']) : [];
        if (count($records) !== 43) {
            $errors[] = $this->issue('repair_records', 'record_count_mismatch', 'The complete exact 43-record repair batch is required.');

            return [];
        }
        $profileCount = 0;
        $comparisonCount = 0;
        $slugs = [];
        foreach ($records as $position => $record) {
            if (! is_array($record)) {
                $errors[] = $this->issue('repair_records.'.$position, 'record_invalid', 'Every repair record must be an object.');

                continue;
            }
            $kind = (string) ($record['entity_kind'] ?? '');
            $slug = trim((string) ($record['slug'] ?? ''));
            $profileCount += $kind === 'profile' ? 1 : 0;
            $comparisonCount += $kind === 'at_comparison' ? 1 : 0;
            if (! in_array($kind, ['profile', 'at_comparison'], true)
                || ($record['locale'] ?? null) !== 'zh-CN'
                || $slug === ''
                || isset($slugs[$slug])) {
                $errors[] = $this->issue('repair_records.'.$position, 'record_identity_invalid', 'Every record must be a unique zh-CN Profile or A/T comparison.');
            }
            $slugs[$slug] = true;
        }
        if ($profileCount !== 28 || $comparisonCount !== 15 || count($slugs) !== 43) {
            $errors[] = $this->issue('repair_records', 'cohort_shape_mismatch', 'The cohort must contain exactly 28 Profiles and 15 A/T comparisons.');
        }

        return array_values(array_filter($records, 'is_array'));
    }

    /** @param array<string,mixed> $record */
    private function profileRow(array $record, string $field): array
    {
        $runtime = strtoupper((string) data_get($record, 'import_payload.identity.runtime_type_code'));
        if ($runtime === '' || strtolower($runtime) !== (string) ($record['slug'] ?? '')) {
            throw new RuntimeException($field.' Profile runtime identity does not match the approved slug.');
        }
        [$profile, $variant, $seo] = $this->profileRows($runtime);
        $robots = strtolower(trim((string) $seo->robots));
        $alreadyPromoted = (bool) $profile->is_indexable && $robots !== '' && ! str_contains($robots, 'noindex');
        if (! $alreadyPromoted && ($robots === '' || ! str_contains($robots, 'noindex'))) {
            throw new RuntimeException($field.' Profile robots are neither the held nor released state.');
        }

        return [
            'approval_record_id' => (string) $record['approval_record_id'],
            'entity_kind' => 'profile',
            'slug' => (string) $record['slug'],
            'url' => (string) $record['target_url'],
            'profile_id' => (int) $profile->id,
            'variant_id' => (int) $variant->id,
            'seo_id' => (int) $seo->id,
            'already_promoted' => $alreadyPromoted,
            'current_robots' => (string) $seo->robots,
            'next_robots' => 'index,follow',
        ];
    }

    /** @param array<string,mixed> $record @param array<string,bool> $profileRuntimeCodes */
    private function comparisonRow(array $record, string $field, array $profileRuntimeCodes): array
    {
        $base = strtoupper((string) data_get($record, 'import_payload.identity.base_type_code'));
        $expectedSlug = strtolower($base).'-a-vs-'.strtolower($base).'-t';
        if ($base === '' || $expectedSlug !== (string) ($record['slug'] ?? '')) {
            throw new RuntimeException($field.' A/T comparison identity does not match the approved slug.');
        }
        $profile = $this->publishedProfile($base);
        $section = PersonalityProfileSection::query()->withoutGlobalScopes()
            ->where('profile_id', $profile->id)
            ->where('section_key', 'mbti64_comparison_a_vs_t')
            ->where('is_enabled', true)
            ->first();
        if (! $section instanceof PersonalityProfileSection) {
            throw new RuntimeException($field.' A/T comparison authority section is missing.');
        }
        $payload = is_array($section->payload_json) ? $section->payload_json : [];
        $held = ($payload['indexability_held'] ?? false) === true;
        $rootRobots = strtolower(trim((string) ($payload['robots'] ?? '')));
        $nestedRobots = strtolower(trim((string) data_get($payload, 'seo.robots', data_get($payload, 'content.seo.robots', ''))));
        $alreadyPromoted = ! $held
            && $rootRobots !== ''
            && ! str_contains($rootRobots, 'noindex')
            && ($nestedRobots === '' || ! str_contains($nestedRobots, 'noindex'));
        if (! $alreadyPromoted && ($rootRobots === '' || ! str_contains($rootRobots, 'noindex'))) {
            throw new RuntimeException($field.' A/T comparison robots are neither the exact held nor released state.');
        }
        foreach ([$base.'-A', $base.'-T'] as $runtime) {
            if (isset($profileRuntimeCodes[$runtime])) {
                continue;
            }
            [, , $seo] = $this->profileRows($runtime, false);
            $robots = strtolower(trim((string) $seo?->robots));
            if ($seo instanceof PersonalityProfileVariantSeoMeta && $robots !== '' && ! str_contains($robots, 'noindex')) {
                continue;
            }
            $variant = PersonalityProfileVariant::query()->withoutGlobalScopes()->where('runtime_type_code', $runtime)->first();
            if (! $variant instanceof PersonalityProfileVariant || ! (bool) $profile->is_indexable) {
                throw new RuntimeException($field.' A/T comparison depends on a variant outside the release cohort that is not indexable.');
            }
        }

        return [
            'approval_record_id' => (string) $record['approval_record_id'],
            'entity_kind' => 'at_comparison',
            'slug' => (string) $record['slug'],
            'url' => (string) $record['target_url'],
            'profile_id' => (int) $profile->id,
            'section_id' => (int) $section->id,
            'already_promoted' => $alreadyPromoted,
            'current_robots' => (string) ($payload['robots'] ?? ''),
            'next_robots' => 'index,follow',
        ];
    }

    /** @return array{PersonalityProfile,PersonalityProfileVariant,?PersonalityProfileVariantSeoMeta} */
    private function profileRows(string $runtime, bool $requireSeo = true): array
    {
        $variant = PersonalityProfileVariant::query()->withoutGlobalScopes()
            ->where('runtime_type_code', $runtime)
            ->where('is_published', true)
            ->first();
        if (! $variant instanceof PersonalityProfileVariant) {
            throw new RuntimeException('Published Profile variant '.$runtime.' was not found.');
        }
        $profile = PersonalityProfile::query()->withoutGlobalScopes()->whereKey($variant->personality_profile_id)->first();
        $seo = PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()
            ->where('personality_profile_variant_id', $variant->id)
            ->first();
        if (! $profile instanceof PersonalityProfile || ($requireSeo && ! $seo instanceof PersonalityProfileVariantSeoMeta)) {
            throw new RuntimeException('Profile authority rows for '.$runtime.' are incomplete.');
        }

        return [$profile, $variant, $seo];
    }

    private function publishedProfile(string $base): PersonalityProfile
    {
        $profile = PersonalityProfile::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', 'zh-CN')
            ->where('canonical_type_code', $base)
            ->where('status', 'published')
            ->where('is_public', true)
            ->first();
        if (! $profile instanceof PersonalityProfile) {
            throw new RuntimeException('Published A/T comparison Profile '.$base.' was not found.');
        }

        return $profile;
    }

    /** @param list<array<string,mixed>> $rows */
    private function lockRows(array $rows): void
    {
        $profileIds = collect($rows)->pluck('profile_id')->filter()->unique()->sort()->values()->all();
        $variantIds = collect($rows)->pluck('variant_id')->filter()->unique()->sort()->values()->all();
        $seoIds = collect($rows)->pluck('seo_id')->filter()->unique()->sort()->values()->all();
        $sectionIds = collect($rows)->pluck('section_id')->filter()->unique()->sort()->values()->all();
        PersonalityProfile::query()->withoutGlobalScopes()->whereIn('id', $profileIds)->orderBy('id')->lockForUpdate()->get();
        PersonalityProfileVariant::query()->withoutGlobalScopes()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
        PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->whereIn('id', $seoIds)->orderBy('id')->lockForUpdate()->get();
        PersonalityProfileSection::query()->withoutGlobalScopes()->whereIn('id', $sectionIds)->orderBy('id')->lockForUpdate()->get();
    }

    /** @param array<string,mixed> $row */
    private function apply(array $row): void
    {
        if ($row['entity_kind'] === 'profile') {
            PersonalityProfile::query()->withoutGlobalScopes()->whereKey($row['profile_id'])->update(['is_indexable' => true]);
            PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->whereKey($row['seo_id'])->update(['robots' => 'index,follow']);

            return;
        }
        $section = PersonalityProfileSection::query()->withoutGlobalScopes()->whereKey($row['section_id'])->firstOrFail();
        $payload = is_array($section->payload_json) ? $section->payload_json : [];
        $payload['robots'] = 'index,follow';
        $payload['indexability_held'] = false;
        if (is_array($payload['seo'] ?? null)) {
            data_set($payload, 'seo.robots', 'index,follow');
        }
        if (is_array(data_get($payload, 'content.seo'))) {
            data_set($payload, 'content.seo.robots', 'index,follow');
        }
        $section->forceFill(['payload_json' => $payload])->save();
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $contentRow */
    private function releasedPublicContentMatches(array $row, array $contentRow): bool
    {
        $promotion = is_array($contentRow['promotion_preview'] ?? null) ? $contentRow['promotion_preview'] : [];
        if (($row['entity_kind'] ?? null) === 'profile') {
            $seo = PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->whereKey($row['seo_id'])->first();
            $expectedSeo = is_array($promotion['seo'] ?? null) ? $promotion['seo'] : [];
            $expectedSeo['robots'] = 'index,follow';
            if (! $seo instanceof PersonalityProfileVariantSeoMeta || ! $this->modelSubsetMatches($seo, $expectedSeo)) {
                return false;
            }
            foreach ((array) ($promotion['sections'] ?? []) as $expected) {
                if (! is_array($expected)) {
                    continue;
                }
                $section = PersonalityProfileVariantSection::query()->withoutGlobalScopes()
                    ->where('personality_profile_variant_id', $row['variant_id'])
                    ->where('section_key', (string) ($expected['section_key'] ?? ''))
                    ->first();
                if (! $section instanceof PersonalityProfileVariantSection || ! $this->modelSubsetMatches($section, $expected)) {
                    return false;
                }
            }

            return true;
        }

        $section = PersonalityProfileSection::query()->withoutGlobalScopes()->whereKey($row['section_id'])->first();
        $expected = is_array($promotion['comparison_section'] ?? null) ? $promotion['comparison_section'] : [];
        $payload = is_array($expected['payload_json'] ?? null) ? $expected['payload_json'] : [];
        $payload['robots'] = 'index,follow';
        $payload['indexability_held'] = false;
        if (is_array($payload['seo'] ?? null)) {
            data_set($payload, 'seo.robots', 'index,follow');
        }
        if (is_array(data_get($payload, 'content.seo'))) {
            data_set($payload, 'content.seo.robots', 'index,follow');
        }
        $expected['payload_json'] = $payload;

        return $section instanceof PersonalityProfileSection && $this->modelSubsetMatches($section, $expected);
    }

    /** @param array<string,mixed> $expected */
    private function modelSubsetMatches(Model $model, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            $actual = $model->getAttribute($key);
            if (is_array($value)) {
                if ($this->canonicalize(is_array($actual) ? $actual : []) !== $this->canonicalize($value)) {
                    return false;
                }
            } elseif ($actual !== $value && (string) $actual !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function promotionManifest(array $rows): array
    {
        return [
            'artifact' => 'MBTI-FULL-43-INDEXABILITY-PROMOTION-PACKAGE-V1',
            'source_package_sha256' => self::SOURCE_SHA,
            'import_scope_mode' => self::SCOPE,
            'record_count' => 43,
            'records' => array_map(static fn (array $row): array => [
                'approval_record_id' => $row['approval_record_id'],
                'entity_kind' => $row['entity_kind'],
                'slug' => $row['slug'],
                'url' => $row['url'],
                'next_robots' => $row['next_robots'],
            ], $rows),
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function authorizationPayload(string $promotionSha, array $rows): array
    {
        return [
            'artifact' => 'MBTI-FULL-43-INDEXABILITY-PROMOTION-AUTHORIZATION-V1',
            'promotion_package_sha256' => $promotionSha,
            'source_package_sha256' => self::SOURCE_SHA,
            'import_scope_mode' => self::SCOPE,
            'record_count' => 43,
            'exact_urls' => array_values(array_map(static fn (array $row): string => $row['url'], $rows)),
            'mutations' => [
                'indexability' => true,
                'sitemap' => true,
                'llms' => true,
                'gsc' => false,
                'url_inspection' => false,
                'search_submission' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $value */
    private function hashCanonicalJson(array $value): string
    {
        return hash('sha256', (string) json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @return array<string,string> */
    private function issue(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
