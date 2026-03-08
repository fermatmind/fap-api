<?php

declare(strict_types=1);

namespace App\TopicsCms\Baseline;

use RuntimeException;

final class TopicBaselineReader
{
    public function resolveSourceDir(?string $sourceDir = null): string
    {
        $candidate = trim((string) $sourceDir);

        if ($candidate === '') {
            $candidate = base_path('../content_baselines/topics');
        } elseif (! str_starts_with($candidate, DIRECTORY_SEPARATOR)) {
            $candidate = base_path($candidate);
        }

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException(sprintf(
                'Topic baseline source directory not found: %s',
                $candidate,
            ));
        }

        return $resolved;
    }

    /**
     * @param  array<int, string>  $selectedLocales
     * @param  array<int, string>  $selectedTopics
     * @return array<int, array{file: string, payload: array<string, mixed>}>
     */
    public function read(string $sourceDir, array $selectedLocales = [], array $selectedTopics = []): array
    {
        $locales = $this->normalizeSelectedLocales($selectedLocales);
        $topics = $this->normalizeSelectedTopics($selectedTopics);
        $files = glob(rtrim($sourceDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.json') ?: [];

        if ($files === []) {
            throw new RuntimeException(sprintf(
                'No topic baseline files found in %s.',
                $sourceDir,
            ));
        }

        sort($files);

        $documents = [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (! preg_match('/^(?<topic>[a-z0-9-]+)\.(?<locale>en|zh-CN|zh)\.json$/', $basename, $matches)) {
                continue;
            }

            $topicCode = trim((string) ($matches['topic'] ?? ''));
            $locale = $matches['locale'] === 'zh' ? 'zh-CN' : (string) $matches['locale'];

            if ($topics !== [] && ! in_array($topicCode, $topics, true)) {
                continue;
            }

            if ($locales !== [] && ! in_array($locale, $locales, true)) {
                continue;
            }

            $raw = file_get_contents($file);

            if (! is_string($raw) || trim($raw) === '') {
                throw new RuntimeException(sprintf(
                    'Topic baseline file is empty: %s',
                    $file,
                ));
            }

            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                throw new RuntimeException(sprintf(
                    'Topic baseline file is not valid JSON: %s',
                    $file,
                ));
            }

            $documents[] = [
                'file' => $file,
                'payload' => $decoded,
            ];
        }

        if ($documents === []) {
            throw new RuntimeException(sprintf(
                'No matching topic baseline files found in %s.',
                $sourceDir,
            ));
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

            if (! in_array($candidate, ['en', 'zh-CN'], true)) {
                throw new RuntimeException(sprintf(
                    'Unsupported locale selection: %s',
                    $candidate,
                ));
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<int, string>  $selectedTopics
     * @return array<int, string>
     */
    private function normalizeSelectedTopics(array $selectedTopics): array
    {
        $normalized = [];

        foreach ($selectedTopics as $topic) {
            $candidate = strtolower(trim((string) $topic));

            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }
}
