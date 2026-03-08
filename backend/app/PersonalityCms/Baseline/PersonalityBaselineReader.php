<?php

declare(strict_types=1);

namespace App\PersonalityCms\Baseline;

use RuntimeException;

final class PersonalityBaselineReader
{
    public function resolveSourceDir(?string $sourceDir = null): string
    {
        $candidate = trim((string) $sourceDir);

        if ($candidate === '') {
            $candidate = base_path('../content_baselines/personality');
        } elseif (! str_starts_with($candidate, DIRECTORY_SEPARATOR)) {
            $candidate = base_path($candidate);
        }

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException(sprintf(
                'Personality baseline source directory not found: %s',
                $candidate,
            ));
        }

        return $resolved;
    }

    /**
     * @param  array<int, string>  $selectedLocales
     * @return array<int, array{file: string, payload: array<string, mixed>}>
     */
    public function read(string $sourceDir, array $selectedLocales = []): array
    {
        $locales = $this->normalizeSelectedLocales($selectedLocales);
        $files = $locales === []
            ? glob(rtrim($sourceDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'mbti.*.json') ?: []
            : array_map(
                static fn (string $locale): string => rtrim($sourceDir, DIRECTORY_SEPARATOR)
                    .DIRECTORY_SEPARATOR
                    .sprintf('mbti.%s.json', $locale),
                $locales,
            );

        if ($files === []) {
            throw new RuntimeException(sprintf(
                'No personality baseline files found in %s.',
                $sourceDir,
            ));
        }

        sort($files);

        $documents = [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                throw new RuntimeException(sprintf(
                    'Personality baseline file missing: %s',
                    $file,
                ));
            }

            $raw = file_get_contents($file);

            if (! is_string($raw) || trim($raw) === '') {
                throw new RuntimeException(sprintf(
                    'Personality baseline file is empty: %s',
                    $file,
                ));
            }

            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                throw new RuntimeException(sprintf(
                    'Personality baseline file is not valid JSON: %s',
                    $file,
                ));
            }

            $documents[] = [
                'file' => $file,
                'payload' => $decoded,
            ];
        }

        return $documents;
    }

    /**
     * @param  array<int, string>  $selectedLocales
     * @return array<int, string>
     */
    private function normalizeSelectedLocales(array $selectedLocales): array
    {
        $normalized = [];

        foreach ($selectedLocales as $locale) {
            $candidate = trim((string) $locale);

            if ($candidate === '') {
                continue;
            }

            $candidate = $candidate === 'zh' ? 'zh-CN' : $candidate;
            $normalized[] = $candidate;
        }

        $normalized = array_values(array_unique($normalized));

        foreach ($normalized as $locale) {
            if (! in_array($locale, ['en', 'zh-CN'], true)) {
                throw new RuntimeException(sprintf(
                    'Unsupported locale selection: %s',
                    $locale,
                ));
            }
        }

        return $normalized;
    }
}
