<?php

declare(strict_types=1);

namespace App\Support\OpsI18n;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

final class OpsUiText
{
    /**
     * UI abbreviations and machine identifiers intentionally remain English in every Ops locale.
     *
     * @var array<int, string>
     */
    public const ALLOWLIST = [
        'API',
        'CSV',
        'ID',
        'JSON',
        'MBTI',
        'PDF',
        'RIASEC',
        'SEO',
        'SKU',
        'TTL',
        'URL',
        'Webhook',
    ];

    public static function translate(string $text): string
    {
        if (app()->getLocale() !== 'zh_CN') {
            return $text;
        }

        $translations = self::translations();

        if (array_key_exists($text, $translations)) {
            return (string) $translations[$text];
        }

        $normalized = self::normalize($text);

        return array_key_exists($normalized, $translations)
            ? (string) $translations[$normalized]
            : $text;
    }

    public static function localizeHtml(string $html): string
    {
        if (app()->getLocale() !== 'zh_CN' || $html === '') {
            return $html;
        }

        $translations = self::translations();
        if ($translations === []) {
            return $html;
        }

        uksort($translations, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return strtr($html, $translations);
    }

    /**
     * @return array<string, string>
     */
    public static function translations(): array
    {
        $translations = Lang::get('ops_ui');

        return is_array($translations) ? $translations : [];
    }

    public static function isAllowedToken(string $text): bool
    {
        $candidate = trim($text);
        if ($candidate === '') {
            return true;
        }

        if (in_array($candidate, self::ALLOWLIST, true)) {
            return true;
        }

        if (Str::contains($candidate, ['$', '::', '=>', '{{', '}}', '__(', '@', '\\'])) {
            return true;
        }

        if (preg_match('/^[a-z0-9_ .:\\-\\/]+$/', $candidate) === 1) {
            return true;
        }

        return false;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
