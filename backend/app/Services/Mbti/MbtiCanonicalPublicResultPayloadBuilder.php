<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use App\Contracts\MbtiPublicResultAuthoritySource;
use App\Contracts\MbtiPublicResultPayloadBuilder;
use App\Support\Mbti\MbtiAxisStrengthBand;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use App\Support\Mbti\MbtiPublicTypeIdentity;
use InvalidArgumentException;
use RuntimeException;

final class MbtiCanonicalPublicResultPayloadBuilder implements MbtiPublicResultPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(MbtiPublicTypeIdentity $identity, MbtiPublicResultAuthoritySource $source): array
    {
        $authority = $source->read($identity);
        $resolvedTypeCode = strtoupper(trim((string) ($authority['resolved_type_code'] ?? '')));

        if ($resolvedTypeCode === '') {
            throw new InvalidArgumentException('MBTI canonical public payload builder requires a resolved_type_code from the authority source.');
        }

        $resolvedIdentity = MbtiPublicTypeIdentity::fromTypeCode($resolvedTypeCode);
        if (! $resolvedIdentity->equals($identity)) {
            throw new RuntimeException(sprintf(
                'MBTI canonical public payload builder cannot rewrite runtime type identity from [%s] to [%s].',
                $identity->typeCode,
                $resolvedIdentity->typeCode,
            ));
        }

        return $this->buildProjection($this->projectionAuthorityFromLegacySource(
            $identity,
            $source->sourceKey(),
            $authority
        ));
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return array<string, mixed>
     */
    public function buildProjection(array $authority): array
    {
        $routeMode = $this->resolveRouteMode($authority);
        $canonicalTypeCode = $this->resolveCanonicalTypeCode($authority);

        if ($canonicalTypeCode === null) {
            throw new InvalidArgumentException('MBTI public projection requires canonical_type_code.');
        }

        $runtimeTypeCode = $routeMode === 'runtime'
            ? $this->nullableUpperText($authority['runtime_type_code'] ?? $authority['display_type'] ?? null)
            : $this->nullableUpperText($authority['runtime_type_code'] ?? null);
        $displayType = $this->resolveDisplayType($authority, $routeMode, $canonicalTypeCode, $runtimeTypeCode);
        $variantCode = $routeMode === 'runtime'
            ? $this->resolveVariantCode($authority, $runtimeTypeCode, $displayType)
            : null;
        $sections = $this->normalizeSections($authority['sections'] ?? null);
        $dimensions = $this->normalizeDimensions($authority['dimensions'] ?? null, $sections);

        return [
            'runtime_type_code' => $runtimeTypeCode,
            'canonical_type_code' => $canonicalTypeCode,
            'display_type' => $displayType,
            'variant_code' => $variantCode,
            'profile' => $this->normalizeProfile($authority['profile'] ?? null),
            'summary_card' => $this->normalizeSummaryCard($authority['summary_card'] ?? null),
            'dimensions' => $dimensions,
            'sections' => $sections,
            'seo' => $this->normalizeSeo($authority['seo'] ?? null),
            'offer_set' => is_array($authority['offer_set'] ?? null) ? $authority['offer_set'] : [],
            '_meta' => $this->normalizeMeta(
                $authority['_meta'] ?? $authority['meta'] ?? null,
                $routeMode
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return array<string, mixed>
     */
    private function projectionAuthorityFromLegacySource(
        MbtiPublicTypeIdentity $identity,
        string $sourceKey,
        array $authority
    ): array {
        $sections = [];

        foreach ((array) ($authority['sections'] ?? []) as $sectionKey => $sectionData) {
            if (! is_array($sectionData)) {
                throw new InvalidArgumentException(sprintf(
                    'MBTI canonical section payload for [%s] must be an array.',
                    $sectionKey,
                ));
            }

            $definition = MbtiCanonicalSectionRegistry::definition((string) $sectionKey);
            if (($definition['bucket'] ?? null) === MbtiCanonicalSectionRegistry::BUCKET_PREMIUM_TEASER) {
                throw new InvalidArgumentException(sprintf(
                    'MBTI premium teaser block [%s] must be supplied through the premium_teaser bucket.',
                    $sectionKey,
                ));
            }

            $sections[(string) $sectionKey] = [
                'key' => (string) $sectionKey,
                'title' => $sectionData['title'] ?? null,
                'render' => $sectionData['render_variant'] ?? $definition['render_variant'],
                'body_md' => $sectionData['body'] ?? null,
                'payload' => is_array($sectionData['payload'] ?? null) ? $sectionData['payload'] : null,
                'is_enabled' => true,
                'source' => $sourceKey,
            ];
        }

        foreach ((array) ($authority['premium_teaser'] ?? []) as $sectionKey => $sectionData) {
            if (! is_array($sectionData)) {
                throw new InvalidArgumentException(sprintf(
                    'MBTI premium teaser payload for [%s] must be an array.',
                    $sectionKey,
                ));
            }

            $definition = MbtiCanonicalSectionRegistry::definition((string) $sectionKey);
            if (($definition['bucket'] ?? null) !== MbtiCanonicalSectionRegistry::BUCKET_PREMIUM_TEASER) {
                throw new InvalidArgumentException(sprintf(
                    'MBTI canonical section [%s] is not a premium teaser key.',
                    $sectionKey,
                ));
            }

            $sections[(string) $sectionKey] = [
                'key' => (string) $sectionKey,
                'title' => $sectionData['title'] ?? null,
                'render' => $sectionData['render_variant'] ?? $definition['render_variant'],
                'body_md' => $sectionData['teaser'] ?? null,
                'payload' => is_array($sectionData['payload'] ?? null) ? $sectionData['payload'] : null,
                'is_enabled' => true,
                'source' => $sourceKey,
            ];
        }

        $summary = $this->nullableText(data_get($authority, 'sections.trait_overview.payload.summary'));

        return [
            'runtime_type_code' => $identity->typeCode,
            'canonical_type_code' => $identity->baseTypeCode,
            'display_type' => $identity->typeCode,
            'variant_code' => $identity->variant,
            'profile' => [
                'hero_summary' => $this->nullableText(data_get($authority, 'profile.hero_summary')),
            ],
            'summary_card' => [
                'summary' => $summary,
            ],
            'dimensions' => is_array(data_get($authority, 'sections.trait_overview.payload.dimensions'))
                ? data_get($authority, 'sections.trait_overview.payload.dimensions')
                : [],
            'sections' => $sections,
            'seo' => [
                'title' => $this->nullableText($authority['seo_meta']['seo_title'] ?? null),
                'description' => $this->nullableText($authority['seo_meta']['seo_description'] ?? null),
                'canonical_url' => $this->nullableText($authority['seo_meta']['canonical_url'] ?? null),
                'og_title' => $this->nullableText($authority['seo_meta']['og_title'] ?? null),
                'og_description' => $this->nullableText($authority['seo_meta']['og_description'] ?? null),
                'og_image_url' => $this->nullableText($authority['seo_meta']['og_image_url'] ?? null),
                'twitter_title' => $this->nullableText($authority['seo_meta']['twitter_title'] ?? null),
                'twitter_description' => $this->nullableText($authority['seo_meta']['twitter_description'] ?? null),
                'twitter_image_url' => $this->nullableText($authority['seo_meta']['twitter_image_url'] ?? null),
                'robots' => $this->nullableText($authority['seo_meta']['robots'] ?? null),
                'jsonld' => [],
            ],
            'offer_set' => [],
            '_meta' => [
                'authority_source' => $sourceKey,
                'route_mode' => 'runtime',
                'public_route_type' => '16-type',
                'schema_version' => 'v2',
                'authority_meta' => is_array($authority['meta'] ?? null) ? $authority['meta'] : [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return list<array<string, mixed>>
     */
    private function normalizeSections(mixed $authority): array
    {
        if (! is_array($authority)) {
            return [];
        }

        $sections = [];

        foreach ($authority as $sectionKey => $sectionData) {
            if (! is_array($sectionData)) {
                continue;
            }

            $key = trim((string) ($sectionData['key'] ?? $sectionKey));
            if ($key === '') {
                continue;
            }

            $definition = MbtiCanonicalSectionRegistry::definition($key);
            $render = $this->nullableText($sectionData['render'] ?? $sectionData['render_variant'] ?? null)
                ?? (string) $definition['render_variant'];

            if ($render !== (string) $definition['render_variant']) {
                throw new InvalidArgumentException(sprintf(
                    'MBTI canonical section [%s] requires render_variant [%s], got [%s].',
                    $key,
                    (string) $definition['render_variant'],
                    $render,
                ));
            }

            if (($sectionData['is_enabled'] ?? true) === false) {
                continue;
            }

            $sections[$key] = [
                'key' => $key,
                'title' => $this->nullableText($sectionData['title'] ?? null) ?? (string) $definition['title'],
                'render' => $render,
                'body_md' => $this->firstNonEmpty(
                    $this->nullableText($sectionData['body_md'] ?? null),
                    $this->nullableText($sectionData['body'] ?? null)
                ),
                'payload' => is_array($sectionData['payload'] ?? null) ? $sectionData['payload'] : [],
                'is_enabled' => true,
                'source' => $this->nullableText($sectionData['source'] ?? null) ?? 'unknown',
                '_sort_order' => (int) ($definition['sort_order'] ?? 0),
            ];
        }

        uasort($sections, static function (array $left, array $right): int {
            return ($left['_sort_order'] ?? 0) <=> ($right['_sort_order'] ?? 0);
        });

        return array_values(array_map(static function (array $section): array {
            unset($section['_sort_order']);

            return $section;
        }, $sections));
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function normalizeDimensions(mixed $dimensions, array $sections): array
    {
        $sourceItems = is_array($dimensions) && $dimensions !== []
            ? $dimensions
            : $this->traitOverviewDimensionsFromSections($sections);

        if (! is_array($sourceItems)) {
            return [];
        }

        $normalized = [];

        foreach ($sourceItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $axisId = $this->normalizeAxisId($item);
            if ($axisId === null) {
                continue;
            }

            $normalized[$axisId] = [
                'id' => $axisId,
                'code' => $axisId,
                'name' => $this->nullableText($item['name'] ?? $item['label'] ?? $item['axis_label'] ?? null) ?? $axisId,
                'label' => $this->nullableText($item['label'] ?? $item['name'] ?? $item['axis_label'] ?? null) ?? $axisId,
                'axis_left' => $this->nullableText($item['axis_left'] ?? null),
                'axis_right' => $this->nullableText($item['axis_right'] ?? null),
                'summary' => $this->nullableText($item['summary'] ?? null),
                'description' => $this->nullableText($item['description'] ?? $item['text'] ?? null),
                'score_pct' => $this->normalizePercent($item['score_pct'] ?? $item['value_pct'] ?? $item['percent'] ?? $item['pct'] ?? null),
                'source' => $this->nullableText($item['source'] ?? null)
                    ?? ($this->normalizePercent($item['score_pct'] ?? $item['value_pct'] ?? $item['percent'] ?? $item['pct'] ?? null) !== null ? 'runtime' : 'authored'),
                'side' => null,
                'side_label' => null,
                'pct' => null,
                'state' => $this->nullableText($item['state'] ?? null),
            ];
        }

        $ordered = [];
        foreach (MbtiCanonicalSectionRegistry::traitOverviewAxisTargets() as $axisId) {
            if (isset($normalized[$axisId])) {
                [$leftCode, $rightCode] = $this->axisLetters($axisId);
                $scorePct = $normalized[$axisId]['score_pct'];
                if (is_int($scorePct)) {
                    $normalized[$axisId]['side'] = $scorePct >= 50 ? $leftCode : $rightCode;
                    $normalized[$axisId]['side_label'] = $scorePct >= 50
                        ? $normalized[$axisId]['axis_left']
                        : $normalized[$axisId]['axis_right'];
                    $normalized[$axisId]['pct'] = $scorePct >= 50 ? $scorePct : 100 - $scorePct;
                }

                $ordered[] = $this->enrichCanonicalDimension($normalized[$axisId]);
            }
        }

        return $ordered;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function traitOverviewDimensionsFromSections(array $sections): array
    {
        foreach ($sections as $section) {
            if (($section['key'] ?? null) !== 'trait_overview') {
                continue;
            }

            $payload = $section['payload'] ?? null;
            if (is_array($payload['dimensions'] ?? null)) {
                return array_values($payload['dimensions']);
            }

            return [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeProfile(mixed $profile): array
    {
        $payload = is_array($profile) ? $profile : [];

        return [
            'type_name' => $this->nullableText($payload['type_name'] ?? null),
            'nickname' => $this->nullableText($payload['nickname'] ?? null),
            'rarity' => $this->nullableText($payload['rarity'] ?? null),
            'keywords' => $this->stringList($payload['keywords'] ?? null),
            'hero_summary' => $this->nullableText($payload['hero_summary'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSummaryCard(mixed $summaryCard): array
    {
        $payload = is_array($summaryCard) ? $summaryCard : [];

        return [
            'title' => $this->nullableText($payload['title'] ?? null),
            'subtitle' => $this->nullableText($payload['subtitle'] ?? null),
            'summary' => $this->nullableText($payload['summary'] ?? null),
            'tagline' => $this->nullableText($payload['tagline'] ?? null),
            'public_tags' => $this->stringList($payload['public_tags'] ?? $payload['tags'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSeo(mixed $seo): array
    {
        $payload = is_array($seo) ? $seo : [];

        return [
            'title' => $this->nullableText($payload['title'] ?? $payload['seo_title'] ?? null),
            'description' => $this->nullableText($payload['description'] ?? $payload['seo_description'] ?? null),
            'og_title' => $this->nullableText($payload['og_title'] ?? null),
            'og_description' => $this->nullableText($payload['og_description'] ?? null),
            'og_image_url' => $this->nullableText($payload['og_image_url'] ?? null),
            'twitter_title' => $this->nullableText($payload['twitter_title'] ?? null),
            'twitter_description' => $this->nullableText($payload['twitter_description'] ?? null),
            'twitter_image_url' => $this->nullableText($payload['twitter_image_url'] ?? null),
            'canonical_url' => $this->nullableText($payload['canonical_url'] ?? null),
            'robots' => $this->nullableText($payload['robots'] ?? null),
            'jsonld' => is_array($payload['jsonld'] ?? null) ? $payload['jsonld'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMeta(mixed $meta, string $routeMode): array
    {
        $payload = is_array($meta) ? $meta : [];
        $normalized = [
            'authority_source' => $this->nullableText($payload['authority_source'] ?? null) ?? 'unknown',
            'route_mode' => $this->nullableText($payload['route_mode'] ?? null) ?? $routeMode,
            'public_route_type' => $this->nullableText($payload['public_route_type'] ?? null) ?? '16-type',
            'schema_version' => $this->nullableText($payload['schema_version'] ?? null) ?? 'v2',
        ];

        if (is_array($payload['authority_meta'] ?? null)) {
            $normalized['authority_meta'] = $payload['authority_meta'];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $authority
     */
    private function resolveRouteMode(array $authority): string
    {
        $normalized = strtolower(trim((string) (($authority['_meta']['route_mode'] ?? $authority['route_mode'] ?? ''))));
        if (in_array($normalized, ['runtime', 'base'], true)) {
            return $normalized;
        }

        return $this->nullableText($authority['runtime_type_code'] ?? null) !== null ? 'runtime' : 'base';
    }

    /**
     * @param  array<string, mixed>  $authority
     */
    private function resolveCanonicalTypeCode(array $authority): ?string
    {
        $canonicalTypeCode = $this->nullableUpperText($authority['canonical_type_code'] ?? null);
        if ($canonicalTypeCode !== null) {
            return $canonicalTypeCode;
        }

        $runtimeTypeCode = $this->nullableUpperText($authority['runtime_type_code'] ?? $authority['display_type'] ?? null);
        if ($runtimeTypeCode !== null && preg_match('/^(?<base>[EI][SN][TF][JP])-(?<variant>[AT])$/', $runtimeTypeCode, $matches) === 1) {
            return (string) $matches['base'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $authority
     */
    private function resolveDisplayType(array $authority, string $routeMode, string $canonicalTypeCode, ?string $runtimeTypeCode): string
    {
        $displayType = $this->nullableUpperText($authority['display_type'] ?? null);
        if ($displayType !== null) {
            return $displayType;
        }

        if ($routeMode === 'runtime' && $runtimeTypeCode !== null) {
            return $runtimeTypeCode;
        }

        return $canonicalTypeCode;
    }

    /**
     * @param  array<string, mixed>  $authority
     */
    private function resolveVariantCode(array $authority, ?string $runtimeTypeCode, string $displayType): ?string
    {
        $variantCode = $this->nullableUpperText($authority['variant_code'] ?? null);
        if ($variantCode !== null) {
            return $variantCode;
        }

        $candidate = $runtimeTypeCode ?? $displayType;
        if ($candidate !== null && preg_match('/^(?<base>[EI][SN][TF][JP])-(?<variant>[AT])$/', $candidate, $matches) === 1) {
            return (string) $matches['variant'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function normalizeAxisId(array $item): ?string
    {
        $candidate = strtoupper(trim((string) ($item['id'] ?? $item['normalized_axis_code'] ?? $item['axis_code'] ?? $item['code'] ?? '')));
        if ($candidate === '') {
            return null;
        }

        return MbtiCanonicalSectionRegistry::normalizeTraitAxisCode($candidate);
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

    private function nullableUpperText(mixed $value): ?string
    {
        $normalized = $this->nullableText($value);

        return $normalized === null ? null : strtoupper($normalized);
    }

    private function normalizePercent(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
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

    /**
     * @param  array<string, mixed>  $dimension
     * @return array<string, mixed>
     */
    private function enrichCanonicalDimension(array $dimension): array
    {
        $axisId = strtoupper(trim((string) ($dimension['id'] ?? $dimension['code'] ?? '')));
        if ($axisId === '') {
            return $dimension;
        }

        [$leftCode, $rightCode] = $this->axisLetters($axisId);
        $axisTitle = $this->nullableText($dimension['label'] ?? $dimension['name'] ?? null);
        if ($axisTitle === null || strtoupper($axisTitle) === $axisId) {
            $axisTitle = $this->defaultAxisTitle($axisId);
        }
        [$defaultLeftPole, $defaultRightPole] = $this->defaultAxisPoles($axisId);
        $leftPole = $this->nullableText($dimension['axis_left'] ?? null) ?? $defaultLeftPole;
        $rightPole = $this->nullableText($dimension['axis_right'] ?? null) ?? $defaultRightPole;
        $rawFirstPolePct = $this->normalizePercent($dimension['score_pct'] ?? null);
        $dominantPole = $this->nullableText($dimension['side'] ?? null);
        $dominantPct = $this->normalizePercent($dimension['pct'] ?? null);
        $state = $this->nullableText($dimension['state'] ?? null);
        $dominantLabel = $this->nullableText($dimension['side_label'] ?? null);
        if ($dominantLabel === null && $dominantPole !== null) {
            $dominantLabel = strtoupper($dominantPole) === $leftCode ? $leftPole : $rightPole;
        }

        return array_merge($dimension, [
            'axis_code' => $axisId,
            'axis_title' => $axisTitle,
            'left_pole' => $leftPole,
            'right_pole' => $rightPole,
            'left_code' => $leftCode,
            'right_code' => $rightCode,
            'raw_first_pole_pct' => $rawFirstPolePct,
            'dominant_pole' => $dominantPole,
            'dominant_label' => $dominantLabel,
            'dominant_pct' => $dominantPct,
            'opposite_pct' => is_int($dominantPct) ? max(0, min(100, 100 - $dominantPct)) : null,
            'strength_band' => MbtiAxisStrengthBand::fromDominantPercent($dominantPct, $state),
        ]);
    }

    private function defaultAxisTitle(string $axisId): string
    {
        return match ($axisId) {
            'EI' => 'Energy',
            'SN' => 'Information',
            'TF' => 'Decision',
            'JP' => 'Lifestyle',
            default => 'Identity',
        };
    }

    /**
     * @return array{0:string,1:string}
     */
    private function defaultAxisPoles(string $axisId): array
    {
        return match ($axisId) {
            'EI' => ['Extraversion', 'Introversion'],
            'SN' => ['Sensing', 'Intuition'],
            'TF' => ['Thinking', 'Feeling'],
            'JP' => ['Judging', 'Perceiving'],
            default => ['Assertive', 'Turbulent'],
        };
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

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
