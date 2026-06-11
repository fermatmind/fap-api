<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use InvalidArgumentException;

final class MbtiPersonalityVariantSeoMetadataService
{
    /**
     * @return list<string>
     */
    public function supportedRuntimeTypeCodes(): array
    {
        $codes = [];

        foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
            $codes[] = $typeCode.'-A';
            $codes[] = $typeCode.'-T';
        }

        sort($codes);

        return $codes;
    }

    /**
     * @return array{
     *   seo_title:string,
     *   seo_description:string,
     *   og_title:string,
     *   og_description:string,
     *   twitter_title:string,
     *   twitter_description:string
     * }
     */
    public function build(string $runtimeTypeCode, string $locale, ?string $typeName = null): array
    {
        $runtimeTypeCode = strtoupper(trim($runtimeTypeCode));
        $locale = $this->normalizeLocale($locale);
        $typeName = $this->normalizeTypeName($typeName);

        if (! in_array($runtimeTypeCode, $this->supportedRuntimeTypeCodes(), true)) {
            throw new InvalidArgumentException('Unsupported MBTI runtime type code for personality SEO metadata.');
        }

        $typeLabel = $this->typeLabel($runtimeTypeCode, $typeName, $locale);

        if ($locale === 'zh-CN') {
            $title = $typeLabel.'人格：特点、爱情、职业与适合工作';
            $description = '了解 '.$typeLabel.'人格的核心特点、优势盲点、爱情关系、职业倾向与适合工作，并通过 MBTI 测试确认自己的类型。';
        } else {
            $title = $typeLabel.' Personality: Traits, Careers & Relationships';
            $description = 'Explore the '.$typeLabel.' personality type, including traits, strengths, blind spots, relationships, career fit, and how to confirm your type with an MBTI test.';
        }

        return [
            'seo_title' => $title,
            'seo_description' => $description,
            'og_title' => $title,
            'og_description' => $description,
            'twitter_title' => $title,
            'twitter_description' => $description,
        ];
    }

    private function typeLabel(string $runtimeTypeCode, ?string $typeName, string $locale): string
    {
        if ($typeName === null) {
            return $runtimeTypeCode;
        }

        return $locale === 'zh-CN'
            ? $runtimeTypeCode.' '.$typeName
            : $runtimeTypeCode.' '.$typeName;
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = trim($locale);

        if ($normalized === 'zh') {
            return 'zh-CN';
        }

        if (in_array($normalized, PersonalityProfile::SUPPORTED_LOCALES, true)) {
            return $normalized;
        }

        throw new InvalidArgumentException('Unsupported locale for MBTI personality SEO metadata.');
    }

    private function normalizeTypeName(?string $typeName): ?string
    {
        $normalized = trim((string) $typeName);

        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/[-－](?:A|T)$/i', '', $normalized) ?: $normalized;

        return trim($normalized) !== '' ? trim($normalized) : null;
    }
}
