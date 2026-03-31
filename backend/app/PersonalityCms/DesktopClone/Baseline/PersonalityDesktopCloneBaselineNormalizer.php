<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone\Baseline;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use RuntimeException;

final class PersonalityDesktopCloneBaselineNormalizer
{
    public function __construct(
        private readonly PersonalityDesktopCloneP0ModuleHydrator $p0Hydrator,
    ) {}

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
                $normalized = $this->p0Hydrator->hydrate(
                    $normalized,
                    $this->resolveBaselineRootFromDocumentPath($file),
                );
                $this->assertP0ModulesComplete($normalized, $file, $index);

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
        $this->assertFullCodeCoverage($records, $normalizedTypes);

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

    private function resolveBaselineRootFromDocumentPath(string $file): string
    {
        $resolvedFile = realpath($file);
        if ($resolvedFile === false) {
            throw new RuntimeException(sprintf(
                'Unable to resolve desktop clone baseline file path: %s',
                $file,
            ));
        }

        $personalityCloneDir = dirname($resolvedFile);
        $baselineRoot = dirname($personalityCloneDir);
        $resolvedRoot = realpath($baselineRoot);

        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new RuntimeException(sprintf(
                'Unable to resolve desktop clone baseline root from %s.',
                $file,
            ));
        }

        return $resolvedRoot;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertP0ModulesComplete(array $row, string $file, int $index): void
    {
        $context = sprintf('%s variants[%d] (%s)', $file, $index, (string) ($row['full_code'] ?? 'unknown'));
        $content = is_array($row['content_json'] ?? null) ? $row['content_json'] : [];

        $lettersIntro = $this->requiredArray($content, ['letters_intro'], $context);
        $this->requiredString($lettersIntro, ['headline'], $context);
        $letters = $this->requiredArray($lettersIntro, ['letters'], $context);
        if ($letters === []) {
            throw new RuntimeException(sprintf('%s is missing letters_intro.letters entries.', $context));
        }

        foreach ($letters as $letterIndex => $letter) {
            if (! is_array($letter)) {
                throw new RuntimeException(sprintf('%s has invalid letters_intro.letters[%d].', $context, $letterIndex));
            }

            $this->requiredString($letter, ['letter'], $context);
            $this->requiredString($letter, ['title'], $context);
            $this->requiredString($letter, ['description'], $context);
        }

        $overview = $this->requiredArray($content, ['overview'], $context);
        $this->requiredString($overview, ['title'], $context);
        $paragraphs = $this->requiredArray($overview, ['paragraphs'], $context);
        if ($paragraphs === []) {
            throw new RuntimeException(sprintf('%s is missing overview.paragraphs entries.', $context));
        }

        foreach ($paragraphs as $paragraphIndex => $paragraph) {
            if (! is_string($paragraph) || trim($paragraph) === '') {
                throw new RuntimeException(sprintf('%s has invalid overview.paragraphs[%d].', $context, $paragraphIndex));
            }
        }

        $chapters = $this->requiredArray($content, ['chapters'], $context);
        foreach (['career', 'growth', 'relationships'] as $chapterKey) {
            $chapter = $this->requiredArray($chapters, [$chapterKey], $context);

            foreach (['strengths', 'weaknesses'] as $module) {
                $payload = $this->requiredArray($chapter, [$module], $context);
                $this->requiredString($payload, ['title'], $context);
                $items = $this->requiredArray($payload, ['items'], $context);

                if ($items === []) {
                    throw new RuntimeException(sprintf(
                        '%s is missing chapters.%s.%s.items entries.',
                        $context,
                        $chapterKey,
                        $module,
                    ));
                }

                foreach ($items as $itemIndex => $item) {
                    if (! is_array($item)) {
                        throw new RuntimeException(sprintf(
                            '%s has invalid chapters.%s.%s.items[%d].',
                            $context,
                            $chapterKey,
                            $module,
                            $itemIndex,
                        ));
                    }

                    $this->requiredString($item, ['title'], $context);
                    $this->requiredString($item, ['description'], $context);
                }
            }
        }

        $career = $this->requiredArray($chapters, ['career'], $context);
        $matchedJobs = $this->requiredArray($career, ['matched_jobs'], $context);
        $this->requiredString($matchedJobs, ['title'], $context);
        $fitBucket = $this->requiredString($matchedJobs, ['fit_bucket'], $context);
        if (! in_array($fitBucket, ['primary', 'secondary'], true)) {
            throw new RuntimeException(sprintf(
                '%s has invalid chapters.career.matched_jobs.fit_bucket=%s.',
                $context,
                $fitBucket,
            ));
        }
        $this->requiredString($matchedJobs, ['summary'], $context);
        $this->requiredString($matchedJobs, ['fit_reason'], $context);
        $jobExamples = $this->requiredArray($matchedJobs, ['job_examples'], $context);
        if ($jobExamples === []) {
            throw new RuntimeException(sprintf('%s is missing chapters.career.matched_jobs.job_examples entries.', $context));
        }

        foreach ($jobExamples as $jobExampleIndex => $jobExample) {
            if (! is_string($jobExample) || trim($jobExample) === '') {
                throw new RuntimeException(sprintf(
                    '%s has invalid chapters.career.matched_jobs.job_examples[%d].',
                    $context,
                    $jobExampleIndex,
                ));
            }
        }

        $matchedGuides = $this->requiredArray($career, ['matched_guides'], $context);
        $this->requiredString($matchedGuides, ['title'], $context);
        $this->requiredString($matchedGuides, ['summary'], $context);
        $this->requiredString($matchedGuides, ['fit_reason'], $context);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>  $normalizedTypes
     */
    private function assertFullCodeCoverage(array $records, array $normalizedTypes): void
    {
        if ($normalizedTypes !== []) {
            return;
        }

        $expected = [];
        foreach (PersonalityProfile::TYPE_CODES as $baseCode) {
            foreach (['A', 'T'] as $variantCode) {
                $fullCode = strtoupper((string) $baseCode).'-'.$variantCode;
                $expected[$fullCode] = $fullCode;
            }
        }

        $actualByLocale = [];
        foreach ($records as $record) {
            $locale = trim((string) ($record['locale'] ?? ''));
            $fullCode = strtoupper(trim((string) ($record['full_code'] ?? '')));

            if ($locale === '' || $fullCode === '') {
                continue;
            }

            $actualByLocale[$locale][$fullCode] = $fullCode;
        }

        foreach ($actualByLocale as $locale => $actual) {
            $missing = array_values(array_diff(array_values($expected), array_values($actual)));

            if ($missing !== []) {
                throw new RuntimeException(sprintf(
                    'Desktop clone baseline locale %s is missing full_code entries: %s',
                    $locale,
                    implode(',', $missing),
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $path
     * @return array<string, mixed>|array<int, mixed>
     */
    private function requiredArray(array $payload, array $path, string $context): array
    {
        $cursor = $payload;
        $renderedPath = implode('.', $path);

        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor) || ! is_array($cursor[$segment])) {
                throw new RuntimeException(sprintf('%s is missing required array %s.', $context, $renderedPath));
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
     * @param  array<int, string>  $path
     */
    private function requiredString(array $payload, array $path, string $context): string
    {
        $cursor = $payload;
        $renderedPath = implode('.', $path);

        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                throw new RuntimeException(sprintf('%s is missing required string %s.', $context, $renderedPath));
            }

            $cursor = $cursor[$segment];
        }

        if (! is_string($cursor) || trim($cursor) === '') {
            throw new RuntimeException(sprintf('%s has invalid required string %s.', $context, $renderedPath));
        }

        return trim($cursor);
    }
}
