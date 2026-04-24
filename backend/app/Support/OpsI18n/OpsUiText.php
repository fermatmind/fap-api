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

        $html = (string) preg_replace_callback(
            '/\b(?P<name>title|description|eyebrow|label|placeholder|empty-title|empty-description|empty-eyebrow|aria-label)="(?P<value>[^"]*[A-Za-z][^"]*)"/u',
            static function (array $matches): string {
                return $matches['name'].'="'.e(self::translateText($matches['value'])).'"';
            },
            $html,
        );

        return (string) preg_replace_callback(
            '/(?P<open><(?P<tag>h[1-6]|label|button|option|th|a|p|span|li)\b[^>]*>)(?P<text>[^<>]*[A-Za-z][^<>]*)(?P<close><\/\g{tag}>)/u',
            static function (array $matches): string {
                return $matches['open'].self::translateTextPreservingWhitespace($matches['text']).$matches['close'];
            },
            $html,
        );
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

    private static function translateText(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::translate(self::normalize($decoded));
    }

    private static function translateTextPreservingWhitespace(string $text): string
    {
        preg_match('/^\s*/u', $text, $leading);
        preg_match('/\s*$/u', $text, $trailing);

        $translated = self::translateText($text);

        return ($leading[0] ?? '').e($translated).($trailing[0] ?? '');
    }
}
