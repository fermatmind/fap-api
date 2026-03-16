<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use App\Contracts\MbtiPublicResultAuthoritySource;
use App\Contracts\MbtiPublicResultPayloadBuilder;
use App\Support\Mbti\MbtiCanonicalPublicResultSchema;
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

        $payload = MbtiCanonicalPublicResultSchema::scaffoldPayload($identity, $source->sourceKey());
        $profile = $this->arrayOrEmpty($authority['profile'] ?? null);
        $payload['profile']['hero_summary'] = $this->nullableText($profile['hero_summary'] ?? null);

        foreach ($this->arrayOrEmpty($authority['sections'] ?? null) as $sectionKey => $sectionData) {
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

            $payload['sections'][(string) $sectionKey] = $this->mergeSectionEntry(
                $payload['sections'][(string) $sectionKey],
                $sectionData,
            );
        }

        foreach ($this->arrayOrEmpty($authority['premium_teaser'] ?? null) as $sectionKey => $sectionData) {
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

            $payload['premium_teaser'][(string) $sectionKey] = $this->mergePremiumTeaserEntry(
                $payload['premium_teaser'][(string) $sectionKey],
                $sectionData,
            );
        }

        foreach ($this->arrayOrEmpty($authority['seo_meta'] ?? null) as $key => $value) {
            if (! array_key_exists((string) $key, $payload['seo_meta'])) {
                continue;
            }

            $payload['seo_meta'][(string) $key] = $this->nullableText($value);
        }

        $payload['_meta']['authority_meta'] = $this->arrayOrEmpty($authority['meta'] ?? null);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function mergeSectionEntry(array $template, array $source): array
    {
        $this->assertRenderVariantMatches((string) $template['section_key'], $template, $source);

        $template['title'] = $this->nullableText($source['title'] ?? $template['title'] ?? null);
        $template['body'] = $this->nullableText($source['body'] ?? $template['body'] ?? null);

        if (array_key_exists('payload', $source)) {
            $template['payload'] = is_array($source['payload']) ? $source['payload'] : null;
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function mergePremiumTeaserEntry(array $template, array $source): array
    {
        $this->assertRenderVariantMatches((string) $template['section_key'], $template, $source);

        $template['title'] = $this->nullableText($source['title'] ?? $template['title'] ?? null);
        $template['teaser'] = $this->nullableText($source['teaser'] ?? $template['teaser'] ?? null);

        if (array_key_exists('payload', $source)) {
            $template['payload'] = is_array($source['payload']) ? $source['payload'] : null;
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $source
     */
    private function assertRenderVariantMatches(string $sectionKey, array $template, array $source): void
    {
        $runtimeVariant = trim((string) ($source['render_variant'] ?? ''));
        if ($runtimeVariant === '') {
            return;
        }

        $expectedVariant = (string) ($template['render_variant'] ?? '');
        if ($runtimeVariant !== $expectedVariant) {
            throw new InvalidArgumentException(sprintf(
                'MBTI canonical section [%s] requires render_variant [%s], got [%s].',
                $sectionKey,
                $expectedVariant,
                $runtimeVariant,
            ));
        }
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
