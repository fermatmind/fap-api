<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

/**
 * Enforces the public reader projection contract for CareerJob API responses.
 *
 * W8-03: Internal identifiers (DB UUIDs, lineage traces, compile refs,
 * metadata fingerprints) must never leak into public reader-facing payloads.
 *
 * W8-04: Locale-specific fields must be projected according to the active
 * request locale. A zh-CN field on an English page is a leakage.
 */
final class CareerJobPublicAllowlist
{
    /** @var list<string> Internal keys that must be redacted from provenance_meta. */
    public const REDACTED_PROVENANCE_KEYS = [
        'truth_metric_id',
        'trust_manifest_id',
        'index_state_id',
        'compile_run_id',
        'import_run_id',
        'source_trace_id',
        'cms_job_id',
        'display_asset_id',
        'source_docx',
        'runtime_publish_projection',
    ];

    /** @var list<string> Keys in compile_refs / source_refs that must be redacted. */
    public const REDACTED_COMPILE_REF_KEYS = [
        'cms_job_id',
        'source_trace_id',
        'display_asset_id',
        'source_docx',
        'runtime_publish_projection',
    ];

    /** @var list<string> Title keys that are ONLY valid for zh-CN locale. */
    private const ZH_ONLY_TITLE_KEYS = [
        'canonical_zh',
        'search_h1_zh',
        'short_title_zh',
    ];

    /** @var list<string> Title keys that are ONLY valid for en locale. */
    private const EN_ONLY_TITLE_KEYS = [
        'canonical_en',
        'short_title_en',
    ];

    /**
     * Redact internal IDs from provenance_meta, keeping only safe version/surface fields.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function sanitizeProvenanceMeta(array $meta): array
    {
        foreach (self::REDACTED_PROVENANCE_KEYS as $key) {
            unset($meta[$key]);
        }

        return $meta;
    }

    /**
     * Redact internal IDs from compile_refs entries.
     *
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array<string, mixed>>
     */
    public static function sanitizeCompileRefs(array $refs): array
    {
        return array_map(static function (array $entry): array {
            foreach (self::REDACTED_COMPILE_REF_KEYS as $key) {
                unset($entry[$key]);
            }

            return $entry;
        }, $refs);
    }

    /**
     * Remove locale-inappropriate title keys from the titles array.
     *
     * @param  array<string, string|null>  $titles
     * @return array<string, string|null>
     */
    public static function filterTitlesForLocale(array $titles, string $locale): array
    {
        $isEn = in_array(mb_strtolower(trim($locale)), ['en', 'en-us'], true);
        $isZh = in_array(mb_strtolower(trim($locale)), ['zh', 'zh-cn', 'zh_cn'], true);

        if ($isEn) {
            // Remove all zh-only fields (canonical_zh, search_h1_zh, short_title_zh)
            foreach (self::ZH_ONLY_TITLE_KEYS as $key) {
                unset($titles[$key]);
            }
        }

        if ($isZh) {
            // Remove empty or invalid zh fields; keep en for cross-reference
            foreach (self::ZH_ONLY_TITLE_KEYS as $key) {
                $value = $titles[$key] ?? null;
                // If the zh field is null, empty, or does not contain CJK, drop it
                if ($value === null || trim((string) $value) === '' ||
                    preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', (string) $value) !== 1) {
                    unset($titles[$key]);
                }
            }
        }

        return $titles;
    }

    /**
     * Determine if a zh-CN field value is valid (contains actual CJK content).
     */
    public static function isValidZhContent(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        return preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $value) === 1;
    }
}
