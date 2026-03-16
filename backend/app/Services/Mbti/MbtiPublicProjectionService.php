<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\Result;
use App\Services\Mbti\Adapters\MbtiPersonalityProfileAuthoritySourceAdapter;
use App\Services\Mbti\Adapters\MbtiReportAuthoritySourceAdapter;
use App\Support\Mbti\MbtiPublicTypeIdentity;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MbtiPublicProjectionService
{
    public function __construct(
        private readonly MbtiCanonicalPublicResultPayloadBuilder $payloadBuilder,
        private readonly MbtiPublicSummaryV1Builder $summaryBuilder,
        private readonly MbtiPersonalityProfileAuthoritySourceAdapter $profileAuthorityAdapter,
    ) {}

    /**
     * @param  array<string, mixed>  $reportPayload
     * @return array<string, mixed>
     */
    public function buildForReportEnvelope(Result $result, array $reportPayload, string $locale, int $orgId = 0): array
    {
        $summary = $this->resolveReportSummary($result, $reportPayload, $locale);
        $identity = $this->resolveRuntimeIdentity($summary);

        $authority = $this->baseRuntimeAuthorityFromSummary($summary, $locale);

        if ($identity instanceof MbtiPublicTypeIdentity) {
            $fallbackAuthority = (new MbtiReportAuthoritySourceAdapter(
                $this->arrayOrEmpty($reportPayload['report'] ?? null),
                'report.v0_3.public_fallback'
            ))->read($identity);

            $authority = $this->mergeReportFallbackAuthority($authority, $fallbackAuthority);
            $authority = $this->overlayRuntimeCmsAuthority(
                $authority,
                $identity->baseTypeCode,
                $identity->typeCode,
                $locale,
                $orgId
            );
        }

        return $this->finalizeProjection(
            $this->payloadBuilder->buildProjection($authority),
            $authority,
            $summary
        );
    }

    /**
     * @param  array<string, mixed>  $sharePayload
     * @param  array<string, mixed>|null  $reportPayload
     * @param  array<string, mixed>|null  $resultPayload
     * @return array<string, mixed>
     */
    public function buildForSharePayload(
        array $sharePayload,
        string $locale,
        int $orgId = 0,
        ?array $reportPayload = null,
        ?array $resultPayload = null
    ): array {
        $summary = is_array($sharePayload['mbti_public_summary_v1'] ?? null)
            ? $sharePayload['mbti_public_summary_v1']
            : $this->summaryBuilder->buildFromSharePayload($sharePayload, $reportPayload, $locale);

        $identity = $this->resolveRuntimeIdentity($summary);
        $authority = $this->baseRuntimeAuthorityFromSummary($summary, $locale);
        $authority['legacy_dimensions'] = is_array($sharePayload['dimensions'] ?? null)
            ? array_values($sharePayload['dimensions'])
            : [];

        if ($identity instanceof MbtiPublicTypeIdentity) {
            if (is_array($reportPayload) && $reportPayload !== []) {
                $fallbackAuthority = (new MbtiReportAuthoritySourceAdapter(
                    $reportPayload,
                    'share.v0_3.public_fallback'
                ))->read($identity);

                $authority = $this->mergeReportFallbackAuthority($authority, $fallbackAuthority);
            }

            $authority = $this->mergeShareSnapshotFallbackAuthority($authority, $sharePayload, $resultPayload);
            $authority = $this->overlayRuntimeCmsAuthority(
                $authority,
                $identity->baseTypeCode,
                $identity->typeCode,
                $locale,
                $orgId
            );
        }

        return $this->finalizeProjection(
            $this->payloadBuilder->buildProjection($authority),
            $authority,
            $summary
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForPersonalityProfile(PersonalityProfile $profile): array
    {
        $profile->loadMissing([
            'sections' => static function (HasMany $query): void {
                $query->where('is_enabled', true)
                    ->orderBy('sort_order')
                    ->orderBy('id');
            },
            'seoMeta',
        ]);

        $authority = $this->profileAuthorityAdapter->fromBaseProfile($profile);
        $authority['runtime_type_code'] = null;
        $authority['display_type'] = strtoupper(trim((string) ($profile->canonical_type_code ?: $profile->type_code)));
        $authority['variant_code'] = null;
        $authority['dimensions'] = [];
        $authority['offer_set'] = [];
        $authority['_meta'] = array_merge(
            is_array($authority['_meta'] ?? null) ? $authority['_meta'] : [],
            [
                'authority_source' => 'personality_cms_v2',
                'route_mode' => 'base',
                'public_route_type' => '16-type',
                'schema_version' => (string) ($profile->schema_version ?: PersonalityProfile::SCHEMA_VERSION_V2),
            ],
        );

        return $this->finalizeProjection(
            $this->payloadBuilder->buildProjection($authority),
            $authority
        );
    }

    public function buildCanonicalUrl(?string $slug, string $locale): ?string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $normalizedSlug = trim((string) $slug);

        if ($baseUrl === '' || $normalizedSlug === '') {
            return null;
        }

        return $baseUrl
            .'/'.$this->mapBackendLocaleToFrontendSegment($locale)
            .'/personality/'
            .rawurlencode($normalizedSlug);
    }

    public function mapBackendLocaleToFrontendSegment(string $locale): string
    {
        return $this->normalizeLocale($locale) === 'zh-CN' ? 'zh' : 'en';
    }

    /**
     * @param  array<string, mixed>  $reportPayload
     * @return array<string, mixed>
     */
    private function resolveReportSummary(Result $result, array $reportPayload, string $locale): array
    {
        if (is_array($reportPayload['mbti_public_summary_v1'] ?? null)) {
            return $reportPayload['mbti_public_summary_v1'];
        }

        return $this->summaryBuilder->buildFromReportEnvelope($result, $reportPayload, $locale);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function baseRuntimeAuthorityFromSummary(array $summary, string $locale): array
    {
        $runtimeTypeCode = $this->nullableText($summary['runtime_type_code'] ?? null);
        $canonicalTypeCode = strtoupper(trim((string) ($summary['canonical_type_16'] ?? '')));

        return [
            'runtime_type_code' => $runtimeTypeCode,
            'canonical_type_code' => $canonicalTypeCode !== '' ? $canonicalTypeCode : null,
            'display_type' => $this->nullableText($summary['display_type'] ?? null),
            'variant_code' => $this->nullableText($summary['variant'] ?? null),
            'profile' => [
                'type_name' => $this->nullableText(data_get($summary, 'profile.type_name')),
                'nickname' => $this->nullableText(data_get($summary, 'profile.nickname')),
                'rarity' => $this->nullableText(data_get($summary, 'profile.rarity')),
                'keywords' => $this->stringList(data_get($summary, 'profile.keywords')),
                'hero_summary' => $this->firstNonEmpty(
                    $this->nullableText(data_get($summary, 'profile.summary')),
                    $this->nullableText(data_get($summary, 'summary_card.share_text'))
                ),
            ],
            'summary_card' => [
                'title' => $this->nullableText(data_get($summary, 'summary_card.title')),
                'subtitle' => $this->nullableText(data_get($summary, 'summary_card.subtitle')),
                'summary' => $this->firstNonEmpty(
                    $this->nullableText(data_get($summary, 'summary_card.share_text')),
                    $this->nullableText(data_get($summary, 'profile.summary'))
                ),
                'tagline' => $this->nullableText(data_get($summary, 'profile.nickname')),
                'public_tags' => $this->stringList(data_get($summary, 'summary_card.tags')),
            ],
            'dimensions' => $this->projectionDimensionsFromSummary($summary, $locale),
            'sections' => [],
            'seo' => [],
            'offer_set' => is_array($summary['offer_set'] ?? null) ? $summary['offer_set'] : [],
            '_meta' => [
                'authority_source' => 'runtime+report_fallback',
                'route_mode' => 'runtime',
                'public_route_type' => '16-type',
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>  $fallbackAuthority
     * @return array<string, mixed>
     */
    private function mergeReportFallbackAuthority(array $authority, array $fallbackAuthority): array
    {
        $heroSummary = $this->nullableText(data_get($fallbackAuthority, 'profile.hero_summary'));
        if ($heroSummary !== null && $this->nullableText(data_get($authority, 'profile.hero_summary')) === null) {
            $authority['profile']['hero_summary'] = $heroSummary;
        }

        $sections = is_array($authority['sections'] ?? null) ? $authority['sections'] : [];

        foreach ($this->canonicalSectionMapFromAuthoritySource($fallbackAuthority, 'report_fallback') as $sectionKey => $section) {
            if (! isset($sections[$sectionKey])) {
                $sections[$sectionKey] = $section;

                continue;
            }

            $sections[$sectionKey] = array_merge($section, array_filter([
                'title' => $this->firstNonEmpty(
                    $this->nullableText($sections[$sectionKey]['title'] ?? null),
                    $this->nullableText($section['title'] ?? null)
                ),
                'body_md' => $this->firstNonEmpty(
                    $this->nullableText($sections[$sectionKey]['body_md'] ?? null),
                    $this->nullableText($section['body_md'] ?? null)
                ),
                'payload' => is_array($sections[$sectionKey]['payload'] ?? null)
                    ? $sections[$sectionKey]['payload']
                    : (is_array($section['payload'] ?? null) ? $section['payload'] : null),
                'source' => $this->nullableText($sections[$sectionKey]['source'] ?? null) ?? 'report_fallback',
            ], static fn (mixed $value): bool => $value !== null));
        }

        $authority['sections'] = $sections;

        return $authority;
    }

    /**
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>  $sharePayload
     * @param  array<string, mixed>|null  $resultPayload
     * @return array<string, mixed>
     */
    private function mergeShareSnapshotFallbackAuthority(array $authority, array $sharePayload, ?array $resultPayload): array
    {
        $summary = $this->firstNonEmpty(
            $this->nullableText($sharePayload['summary'] ?? null),
            $this->nullableText($resultPayload['summary'] ?? null),
            $this->nullableText($resultPayload['short_summary'] ?? null)
        );

        if ($summary !== null) {
            if ($this->nullableText(data_get($authority, 'profile.hero_summary')) === null) {
                $authority['profile']['hero_summary'] = $summary;
            }

            $sections = is_array($authority['sections'] ?? null) ? $authority['sections'] : [];
            if (! isset($sections['overview'])) {
                $sections['overview'] = [
                    'key' => 'overview',
                    'title' => $this->nullableText($sharePayload['title'] ?? null),
                    'render' => 'rich_text',
                    'body_md' => $summary,
                    'payload' => null,
                    'is_enabled' => true,
                    'source' => 'report_fallback',
                ];
            }

            $authority['sections'] = $sections;
        }

        return $authority;
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return array<string, mixed>
     */
    private function overlayRuntimeCmsAuthority(
        array $authority,
        string $canonicalTypeCode,
        string $runtimeTypeCode,
        string $locale,
        int $orgId
    ): array {
        $profile = $this->resolvePublicProfile($canonicalTypeCode, $orgId, $locale);
        if (! $profile instanceof PersonalityProfile) {
            return $authority;
        }

        $cmsAuthority = $this->profileAuthorityAdapter->fromBaseProfile($profile);
        $variant = $this->resolvePublishedVariant($profile, $runtimeTypeCode);
        if ($variant instanceof PersonalityProfileVariant) {
            $cmsAuthority = $this->profileAuthorityAdapter->overlayVariant($cmsAuthority, $variant);
        }

        $merged = $this->mergeCmsAuthority($authority, $cmsAuthority);
        $merged['_meta']['authority_source'] = 'runtime+report_fallback+personality_cms_v2';

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>  $cmsAuthority
     * @return array<string, mixed>
     */
    private function mergeCmsAuthority(array $authority, array $cmsAuthority): array
    {
        $authority['canonical_type_code'] = $this->firstNonEmpty(
            $this->nullableText($cmsAuthority['canonical_type_code'] ?? null),
            $this->nullableText($authority['canonical_type_code'] ?? null)
        );
        $authority['slug'] = $this->firstNonEmpty(
            $this->nullableText($cmsAuthority['slug'] ?? null),
            $this->nullableText($authority['slug'] ?? null)
        );
        $authority['locale'] = $this->firstNonEmpty(
            $this->nullableText($cmsAuthority['locale'] ?? null),
            $this->nullableText($authority['locale'] ?? null)
        );
        $authority['is_indexable'] = (bool) ($cmsAuthority['is_indexable'] ?? ($authority['is_indexable'] ?? true));

        foreach (['type_name', 'nickname', 'rarity', 'hero_summary'] as $key) {
            $value = $this->nullableText(data_get($cmsAuthority, 'profile.'.$key));
            if ($value !== null) {
                $authority['profile'][$key] = $value;
            }
        }

        $keywords = $this->stringList(data_get($cmsAuthority, 'profile.keywords'));
        if ($keywords !== []) {
            $authority['profile']['keywords'] = $keywords;
        }

        foreach (['title', 'subtitle', 'summary'] as $key) {
            $value = $this->nullableText(data_get($cmsAuthority, 'summary_card.'.$key));
            if ($value !== null) {
                $authority['summary_card'][$key] = $value;
            }
        }

        $sections = is_array($authority['sections'] ?? null) ? $authority['sections'] : [];
        foreach ((array) ($cmsAuthority['sections'] ?? []) as $sectionKey => $section) {
            if (is_array($section)) {
                $sections[(string) $sectionKey] = $section;
            }
        }
        $authority['sections'] = $sections;

        foreach ((array) ($cmsAuthority['seo'] ?? []) as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($key === 'jsonld' && is_array($value) && $value === []) {
                continue;
            }

            $authority['seo'][$key] = $value;
        }

        $authority['_meta'] = array_merge(
            is_array($authority['_meta'] ?? null) ? $authority['_meta'] : [],
            is_array($cmsAuthority['_meta'] ?? null) ? $cmsAuthority['_meta'] : [],
            ['schema_version' => (string) data_get($cmsAuthority, '_meta.schema_version', PersonalityProfile::SCHEMA_VERSION_V2)],
        );

        return $authority;
    }

    /**
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>
     */
    private function finalizeProjection(array $projection, array $authority, ?array $summary = null): array
    {
        if ($summary !== null) {
            $projection['dimensions'] = $this->projectionDimensionsFromSummary($summary, (string) ($authority['locale'] ?? 'en'));
            $projection['dimensions'] = $this->mergeLegacyDimensions(
                $projection['dimensions'],
                is_array($authority['legacy_dimensions'] ?? null) ? $authority['legacy_dimensions'] : []
            );
            $projection['offer_set'] = is_array($summary['offer_set'] ?? null) ? $summary['offer_set'] : [];
        }

        $projection['summary_card']['tagline'] = $this->firstNonEmpty(
            $this->nullableText($projection['summary_card']['tagline'] ?? null),
            $this->nullableText($projection['profile']['nickname'] ?? null)
        );
        $projection['summary_card']['public_tags'] = $this->stringList(
            ($projection['summary_card']['public_tags'] ?? []) !== []
                ? $projection['summary_card']['public_tags']
                : ($projection['profile']['keywords'] ?? [])
        );

        $canonicalUrl = $this->buildCanonicalUrl(
            $this->nullableText($authority['slug'] ?? null),
            (string) ($authority['locale'] ?? 'en')
        );

        $projection['seo']['title'] = $this->firstNonEmpty(
            $this->nullableText($projection['seo']['title'] ?? null),
            $this->nullableText($projection['summary_card']['title'] ?? null),
            $this->nullableText($projection['display_type'] ?? null)
        );
        $projection['seo']['description'] = $this->firstNonEmpty(
            $this->nullableText($projection['seo']['description'] ?? null),
            $this->nullableText($projection['summary_card']['summary'] ?? null),
            $this->nullableText($projection['summary_card']['subtitle'] ?? null),
            $this->nullableText($projection['profile']['hero_summary'] ?? null)
        );
        $projection['seo']['canonical_url'] = $canonicalUrl;
        $projection['seo']['og_title'] = $this->firstNonEmpty(
            $this->nullableText($projection['seo']['og_title'] ?? null),
            $this->nullableText($projection['seo']['title'] ?? null)
        );
        $projection['seo']['og_description'] = $this->firstNonEmpty(
            $this->nullableText($projection['seo']['og_description'] ?? null),
            $this->nullableText($projection['seo']['description'] ?? null)
        );
        $projection['seo']['twitter_title'] = $this->firstNonEmpty(
            $this->nullableText($projection['seo']['twitter_title'] ?? null),
            $this->nullableText($projection['seo']['og_title'] ?? null),
            $this->nullableText($projection['seo']['title'] ?? null)
        );
        $projection['seo']['twitter_description'] = $this->firstNonEmpty(
            $this->nullableText($projection['seo']['twitter_description'] ?? null),
            $this->nullableText($projection['seo']['og_description'] ?? null),
            $this->nullableText($projection['seo']['description'] ?? null)
        );
        $projection['seo']['robots'] = $this->firstNonEmpty(
            $this->nullableText($projection['seo']['robots'] ?? null),
            ($authority['is_indexable'] ?? true) ? 'index,follow' : 'noindex,follow'
        );
        $projection['seo']['jsonld'] = is_array($projection['seo']['jsonld'] ?? null)
            ? $projection['seo']['jsonld']
            : [];

        return $projection;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function resolveRuntimeIdentity(array $summary): ?MbtiPublicTypeIdentity
    {
        $runtimeTypeCode = $this->nullableText($summary['runtime_type_code'] ?? null);

        return MbtiPublicTypeIdentity::tryFromTypeCode($runtimeTypeCode);
    }

    private function resolvePublicProfile(string $typeCode, int $orgId, string $locale): ?PersonalityProfile
    {
        $normalizedTypeCode = strtoupper(trim($typeCode));
        if ($normalizedTypeCode === '') {
            return null;
        }

        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', max(0, $orgId))
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->whereIn('type_code', PersonalityProfile::TYPE_CODES)
            ->where('type_code', $normalizedTypeCode)
            ->forLocale($this->normalizeLocale($locale))
            ->publishedPublic()
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with([
                'sections' => static function (HasMany $query): void {
                    $query->where('is_enabled', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
                'seoMeta',
            ])
            ->first();
    }

    private function resolvePublishedVariant(PersonalityProfile $profile, string $runtimeTypeCode): ?PersonalityProfileVariant
    {
        $normalized = strtoupper(trim($runtimeTypeCode));
        if ($normalized === '') {
            return null;
        }

        return $profile->variants()
            ->where('runtime_type_code', $normalized)
            ->where('is_published', true)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with([
                'sections' => static function (HasMany $query): void {
                    $query->orderBy('sort_order')
                        ->orderBy('id');
                },
                'seoMeta',
            ])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return array<string, array<string, mixed>>
     */
    private function canonicalSectionMapFromAuthoritySource(array $authority, string $source): array
    {
        $sections = [];

        foreach ((array) ($authority['sections'] ?? []) as $sectionKey => $sectionData) {
            if (! is_array($sectionData)) {
                continue;
            }

            $sectionKey = trim((string) $sectionKey);
            if ($sectionKey === '') {
                continue;
            }

            $sections[$sectionKey] = [
                'key' => $sectionKey,
                'title' => $this->nullableText($sectionData['title'] ?? null),
                'render' => $this->nullableText($sectionData['render'] ?? $sectionData['render_variant'] ?? null),
                'body_md' => $this->firstNonEmpty(
                    $this->nullableText($sectionData['body_md'] ?? null),
                    $this->nullableText($sectionData['body'] ?? null)
                ),
                'payload' => is_array($sectionData['payload'] ?? null) ? $sectionData['payload'] : null,
                'is_enabled' => true,
                'source' => $source,
            ];
        }

        foreach ((array) ($authority['premium_teaser'] ?? []) as $sectionKey => $sectionData) {
            if (! is_array($sectionData)) {
                continue;
            }

            $sectionKey = trim((string) $sectionKey);
            if ($sectionKey === '') {
                continue;
            }

            $sections[$sectionKey] = [
                'key' => $sectionKey,
                'title' => $this->nullableText($sectionData['title'] ?? null),
                'render' => $this->nullableText($sectionData['render'] ?? $sectionData['render_variant'] ?? null),
                'body_md' => $this->nullableText($sectionData['teaser'] ?? null),
                'payload' => is_array($sectionData['payload'] ?? null) ? $sectionData['payload'] : null,
                'is_enabled' => true,
                'source' => $source,
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array<string, mixed>>
     */
    private function projectionDimensionsFromSummary(array $summary, string $locale): array
    {
        $dimensions = [];

        foreach ((array) ($summary['dimensions'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $axisId = strtoupper(trim((string) ($item['id'] ?? $item['code'] ?? '')));
            if (! in_array($axisId, ['EI', 'SN', 'TF', 'JP', 'AT'], true)) {
                continue;
            }

            $dimensions[$axisId] = [
                'id' => $axisId,
                'code' => $axisId,
                'name' => $this->nullableText($item['name'] ?? $item['label'] ?? null) ?? $axisId,
                'label' => $this->nullableText($item['label'] ?? $item['name'] ?? null) ?? $axisId,
                'axis_left' => $this->nullableText($item['axis_left'] ?? null),
                'axis_right' => $this->nullableText($item['axis_right'] ?? null),
                'summary' => $this->nullableText($item['summary'] ?? null),
                'description' => $this->nullableText($item['description'] ?? null),
                'score_pct' => $this->normalizePercent($item['score_pct'] ?? $item['value_pct'] ?? $item['pct'] ?? null),
                'source' => 'runtime',
                'side' => null,
                'side_label' => null,
                'pct' => null,
                'state' => $this->nullableText($item['state'] ?? null),
            ];
        }

        $ordered = [];
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axisId) {
            if (! isset($dimensions[$axisId])) {
                continue;
            }

            if ($dimensions[$axisId]['axis_left'] === null || $dimensions[$axisId]['axis_right'] === null) {
                [$left, $right] = $this->defaultAxisLabels($axisId, $locale);
                $dimensions[$axisId]['axis_left'] = $dimensions[$axisId]['axis_left'] ?? $left;
                $dimensions[$axisId]['axis_right'] = $dimensions[$axisId]['axis_right'] ?? $right;
            }

            [$leftCode, $rightCode] = $this->axisLetters($axisId);
            $scorePct = $dimensions[$axisId]['score_pct'];
            if (is_int($scorePct)) {
                $dimensions[$axisId]['side'] = $scorePct >= 50 ? $leftCode : $rightCode;
                $dimensions[$axisId]['side_label'] = $scorePct >= 50
                    ? $dimensions[$axisId]['axis_left']
                    : $dimensions[$axisId]['axis_right'];
                $dimensions[$axisId]['pct'] = $scorePct >= 50 ? $scorePct : 100 - $scorePct;
            }

            $ordered[] = $dimensions[$axisId];
        }

        return $ordered;
    }

    /**
     * @param  list<array<string, mixed>>  $dimensions
     * @param  list<mixed>  $legacyDimensions
     * @return list<array<string, mixed>>
     */
    private function mergeLegacyDimensions(array $dimensions, array $legacyDimensions): array
    {
        $legacyMap = [];

        foreach ($legacyDimensions as $item) {
            if (! is_array($item)) {
                continue;
            }

            $axisId = strtoupper(trim((string) ($item['id'] ?? $item['code'] ?? '')));
            if ($axisId === '') {
                continue;
            }

            $legacyMap[$axisId] = $item;
        }

        foreach ($dimensions as $index => $dimension) {
            $axisId = strtoupper(trim((string) ($dimension['id'] ?? '')));
            if ($axisId === '' || ! isset($legacyMap[$axisId])) {
                continue;
            }

            $legacy = $legacyMap[$axisId];
            $dimensions[$index]['code'] = $axisId;
            $dimensions[$index]['label'] = $this->nullableText($legacy['label'] ?? null)
                ?? $dimensions[$index]['label'];
            $dimensions[$index]['side'] = $this->nullableText($legacy['side'] ?? null)
                ?? $dimensions[$index]['side'];
            $dimensions[$index]['side_label'] = $this->nullableText($legacy['side_label'] ?? null)
                ?? $dimensions[$index]['side_label'];
            $dimensions[$index]['pct'] = $this->normalizePercent($legacy['pct'] ?? null)
                ?? $dimensions[$index]['pct'];
            $dimensions[$index]['state'] = $this->nullableText($legacy['state'] ?? null)
                ?? $dimensions[$index]['state'];
        }

        return $dimensions;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function defaultAxisLabels(string $axisId, string $locale): array
    {
        $isZh = $this->normalizeLocale($locale) === 'zh-CN';

        return match ($axisId) {
            'EI' => [$isZh ? '外倾' : 'Extraversion', $isZh ? '内倾' : 'Introversion'],
            'SN' => [$isZh ? '实感' : 'Sensing', $isZh ? '直觉' : 'Intuition'],
            'TF' => [$isZh ? '思考' : 'Thinking', $isZh ? '情感' : 'Feeling'],
            'JP' => [$isZh ? '判断' : 'Judging', $isZh ? '感知' : 'Perceiving'],
            default => [$isZh ? '果断' : 'Assertive', $isZh ? '敏感' : 'Turbulent'],
        };
    }

    /**
     * @return array{0:string,1:string}
     */
    private function axisLetters(string $axisId): array
    {
        return match ($axisId) {
            'EI' => ['E', 'I'],
            'SN' => ['S', 'N'],
            'TF' => ['T', 'F'],
            'JP' => ['J', 'P'],
            default => ['A', 'T'],
        };
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function normalizePercent(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = $this->nullableText($item);
            if ($normalized === null) {
                continue;
            }

            $items[$normalized] = true;
        }

        return array_keys($items);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
