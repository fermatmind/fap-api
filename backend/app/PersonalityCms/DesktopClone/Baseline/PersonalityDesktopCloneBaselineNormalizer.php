<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone\Baseline;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use RuntimeException;

final class PersonalityDesktopCloneBaselineNormalizer
{
    /**
     * @param  array<int, array{file: string, payload: array<string, mixed>}>  $documents
     * @param  array<int, string>  $selectedTypes
     * @return array<int, array<string, mixed>>
     */
    public function normalizeDocuments(array $documents, array $selectedTypes = []): array
    {
        $normalizedTypes = $this->normalizeSelectedTypes($selectedTypes);
        $recordsByLocaleCode = [];

        foreach ($documents as $document) {
            $file = (string) ($document['file'] ?? 'unknown');
            $payload = is_array($document['payload'] ?? null) ? $document['payload'] : [];
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $scaleCode = strtoupper(trim((string) ($meta['scale_code'] ?? PersonalityProfile::SCALE_CODE_MBTI)));
            $locale = $this->normalizeLocale($meta['locale'] ?? null, $file);
            $templateKey = trim((string) ($meta['template_key'] ?? PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1));
            $schemaVersion = trim((string) ($meta['schema_version'] ?? 'v1'));

            if ($scaleCode !== PersonalityProfile::SCALE_CODE_MBTI) {
                throw new RuntimeException(sprintf(
                    'Desktop clone baseline file %s has unsupported scale_code=%s.',
                    $file,
                    $scaleCode,
                ));
            }

            if ($templateKey === '') {
                throw new RuntimeException(sprintf(
                    'Desktop clone baseline file %s is missing template_key.',
                    $file,
                ));
            }

            if ($templateKey !== PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1) {
                throw new RuntimeException(sprintf(
                    'Desktop clone baseline file %s has unsupported template_key=%s.',
                    $file,
                    $templateKey,
                ));
            }

            $rows = $payload['variants'] ?? null;

            if (! is_array($rows)) {
                throw new RuntimeException(sprintf(
                    'Desktop clone baseline file %s must contain a variants array.',
                    $file,
                ));
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException(sprintf(
                        'Desktop clone baseline file %s contains a non-object variant row at index %d.',
                        $file,
                        $index,
                    ));
                }

                $normalized = $this->normalizeVariant($row, [
                    'file' => $file,
                    'index' => $index,
                    'locale' => $locale,
                    'template_key' => $templateKey,
                    'schema_version' => $schemaVersion,
                ]);

                $fullCode = (string) $normalized['full_code'];
                if ($normalizedTypes !== [] && ! in_array($fullCode, $normalizedTypes, true)) {
                    continue;
                }

                $key = $locale.'|'.$fullCode;

                if (isset($recordsByLocaleCode[$key])) {
                    throw new RuntimeException(sprintf(
                        'Duplicate full_code %s for locale %s in desktop clone baseline file %s.',
                        $fullCode,
                        $locale,
                        $file,
                    ));
                }

                $recordsByLocaleCode[$key] = $normalized;
            }
        }

        $records = array_values($recordsByLocaleCode);
        usort($records, static fn (array $left, array $right): int => [$left['locale'], $left['full_code']] <=> [$right['locale'], $right['full_code']]);

        return $records;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{file:string,index:int,locale:string,template_key:string,schema_version:string}  $context
     * @return array<string, mixed>
     */
    private function normalizeVariant(array $row, array $context): array
    {
        $file = $context['file'];
        $index = $context['index'];
        $fullCode = strtoupper(trim((string) ($row['full_code'] ?? '')));

        if (preg_match('/^(?<base>[EI][SN][TF][JP])-(?<variant>[AT])$/', $fullCode, $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Desktop clone baseline file %s has invalid full_code at variants[%d].',
                $file,
                $index,
            ));
        }

        $contentJson = $row['content_json'] ?? null;
        if (! is_array($contentJson)) {
            throw new RuntimeException(sprintf(
                'Desktop clone baseline file %s has invalid content_json at variants[%d].',
                $file,
                $index,
            ));
        }

        $assetSlotsJson = $row['asset_slots_json'] ?? null;
        if (! is_array($assetSlotsJson)) {
            throw new RuntimeException(sprintf(
                'Desktop clone baseline file %s has invalid asset_slots_json at variants[%d].',
                $file,
                $index,
            ));
        }

        $metaJson = $row['meta_json'] ?? null;
        if ($metaJson !== null && ! is_array($metaJson)) {
            throw new RuntimeException(sprintf(
                'Desktop clone baseline file %s has invalid meta_json at variants[%d].',
                $file,
                $index,
            ));
        }

        return [
            'locale' => $context['locale'],
            'template_key' => $context['template_key'],
            'schema_version' => $context['schema_version'] !== '' ? $context['schema_version'] : 'v1',
            'full_code' => $fullCode,
            'base_code' => (string) $matches['base'],
            'content_json' => $contentJson,
            'asset_slots_json' => PersonalityDesktopCloneAssetSlotSupport::normalizeAssetSlots(array_values($assetSlotsJson)),
            'meta_json' => $metaJson,
        ];
    }

    private function normalizeLocale(mixed $locale, string $file): string
    {
        $normalized = trim((string) $locale);

        if ($normalized === 'zh') {
            $normalized = 'zh-CN';
        }

        if (! in_array($normalized, PersonalityProfile::SUPPORTED_LOCALES, true)) {
            throw new RuntimeException(sprintf(
                'Desktop clone baseline file %s has unsupported locale=%s.',
                $file,
                $normalized,
            ));
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $selectedTypes
     * @return array<int, string>
     */
    private function normalizeSelectedTypes(array $selectedTypes): array
    {
        $normalized = [];

        foreach ($selectedTypes as $type) {
            $candidate = strtoupper(trim((string) $type));

            if ($candidate === '') {
                continue;
            }

            if (preg_match('/^(?<base>[EI][SN][TF][JP])-(?<variant>[AT])$/', $candidate) !== 1) {
                throw new RuntimeException(sprintf(
                    'Unsupported type selection: %s',
                    $candidate,
                ));
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }
}
