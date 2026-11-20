<?php

declare(strict_types=1);

namespace App\Services\Content;

use RuntimeException;

/** Persisted machine translations; not a human-review or publication attestation. */
final class RiasecLocalizedSourceContract
{
    public static function contract(array $legacy): array
    {
        $contract = $legacy;
        foreach ($legacy as $source => $rule) {
            if (! str_contains($source, '.zh-CN.')) {
                continue;
            }
            foreach (['en', 'zh-TW'] as $locale) {
                $target = self::target($source, $locale);
                $contract[$target] ??= [...$rule, 'role' => $locale.' machine translation: '.$rule['role']];
            }
        }
        ksort($contract, SORT_STRING);

        return $contract;
    }

    public static function target(string $source, string $locale): string
    {
        return str_replace(['.zh-CN.r3.', '.zh-CN.'], '.'.$locale.'.', $source);
    }

    public static function coverage(array $assets): array
    {
        $coverage = [];
        foreach (RiasecPrivateResultCompileService::SOURCE_CONTRACT as $source => $rule) {
            if (! str_contains($source, '.zh-CN.')) {
                continue;
            }
            $sourceDigest = self::digest($assets[$source]);
            foreach (['en', 'zh-TW'] as $locale) {
                $target = self::target($source, $locale);
                if (! isset($assets[$target])) {
                    throw new RuntimeException('RIASEC_LOCALIZED_SOURCE_MISSING:'.$target);
                }
                $coverage[$locale][$source] = $target;
                // Existing English adapters have their own stricter, versioned field contracts.
                if ($locale === 'en' && isset(RiasecPrivateResultCompileService::SOURCE_CONTRACT[$target])) {
                    continue;
                }
                $original = str_ends_with($source, '.jsonl') ? $assets[$source] : [$assets[$source]];
                $translated = str_ends_with($target, '.jsonl') ? $assets[$target] : [$assets[$target]];
                if (count($original) !== count($translated)) {
                    throw new RuntimeException('RIASEC_LOCALIZED_ROW_COUNT_INVALID:'.$target);
                }
                foreach ($original as $index => $row) {
                    $candidate = $translated[$index];
                    $binding = $candidate['translation_source'] ?? [];
                    if (($binding['path'] ?? null) !== $source || ($binding['locale'] ?? null) !== 'zh-CN'
                        || ($binding['canonical_sha256'] ?? null) !== $sourceDigest
                        || ($binding['human_review_completed'] ?? null) !== false
                        || ($binding['status'] ?? null) !== 'machine_translated') {
                        throw new RuntimeException('RIASEC_LOCALIZED_SOURCE_BINDING_INVALID:'.$target);
                    }
                    unset($candidate['translation_source']);
                    if (! array_key_exists('locale', $row)) {
                        if (($candidate['locale'] ?? null) !== $locale) {
                            throw new RuntimeException('RIASEC_LOCALIZED_LOCALE_INVALID:'.$target);
                        }
                        unset($candidate['locale']);
                    }
                    self::assertTree($row, $candidate, $locale, $target);
                }
            }
        }

        return $coverage;
    }

    public static function machineField(string $key): bool
    {
        return preg_match('/(?:^|_)(?:id|ids|key|keys|code|codes|hash|sha256|version)$/', $key) === 1
            || in_array($key, ['state', 'asset_status', 'source_status', 'user_stage', 'audience'], true);
    }

    private static function assertTree(mixed $source, mixed $target, string $locale, string $path, string $key = ''): void
    {
        if ($key === 'locale' && $source === 'zh-CN') {
            if ($target !== $locale) {
                throw new RuntimeException('RIASEC_LOCALIZED_LOCALE_INVALID:'.$path);
            }

            return;
        }
        if ($key === 'review_status') {
            if ($target !== 'machine_translated') {
                throw new RuntimeException('RIASEC_LOCALIZED_REVIEW_INVALID:'.$path);
            }

            return;
        }
        if (self::machineField($key)) {
            if ($source !== $target) {
                throw new RuntimeException('RIASEC_LOCALIZED_IDENTITY_CHANGED:'.$path);
            }

            return;
        }
        if (is_array($source)) {
            if (! is_array($target) || array_keys($source) !== array_keys($target)) {
                throw new RuntimeException('RIASEC_LOCALIZED_STRUCTURE_CHANGED:'.$path);
            }
            foreach ($source as $child => $value) {
                self::assertTree($value, $target[$child], $locale, $path.'.'.$child, (string) $child);
            }

            return;
        }
        if (! is_string($source) || preg_match('/[\x{3400}-\x{9fff}]/u', $source) !== 1) {
            if ($source !== $target) {
                throw new RuntimeException('RIASEC_LOCALIZED_LITERAL_CHANGED:'.$path);
            }

            return;
        }
        if (! is_string($target) || trim($target) === '' || ($locale === 'en' && preg_match('/[\x{3400}-\x{9fff}]/u', $target) === 1)) {
            throw new RuntimeException('RIASEC_LOCALIZED_TRANSLATION_MISSING:'.$path);
        }
        $pattern = '~https?://[^\s<>）)]+|`[^`]*`|\{\{[^}]*\}\}|\$?\{[^}]*\}|%\d*\$?[sdif]|\d+(?:[.,:/-]\d+)*~u';
        preg_match_all($pattern, $source, $before);
        preg_match_all($pattern, $target, $after);
        sort($before[0]);
        sort($after[0]);
        if ($before[0] !== $after[0]) {
            throw new RuntimeException('RIASEC_LOCALIZED_TOKENS_CHANGED:'.$path);
        }
    }

    private static function digest(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return is_string($item) ? str_replace(["\r\n", "\r"], "\n", $item) : $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return array_map($normalize, $item);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
