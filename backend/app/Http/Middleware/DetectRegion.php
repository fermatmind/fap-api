<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RegionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DetectRegion
{
    public function __construct(private RegionContext $regionContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $regions = config('regions.regions', []);
        $defaultRegion = $this->resolveDefaultRegion($regions);

        $region = strtoupper(trim((string) $request->header('X-Region', '')));
        if ($region === '' || !is_array($regions) || !array_key_exists($region, $regions)) {
            $region = $defaultRegion;
        }
        if (!is_array($regions) || !array_key_exists($region, $regions)) {
            $region = 'CN_MAINLAND';
        }

        $regionConfig = is_array($regions) ? ($regions[$region] ?? []) : [];

        $locale = $this->resolveLocale($request, $regionConfig);
        $currency = trim((string) ($regionConfig['currency'] ?? ''));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $this->regionContext->set($region, $locale, $currency);
        app()->instance(RegionContext::class, $this->regionContext);

        $request->attributes->set('region', $region);
        $request->attributes->set('locale', $locale);
        $request->attributes->set('currency', $currency);

        return $next($request);
    }

    private function resolveDefaultRegion(array $regions): string
    {
        $default = (string) (config('regions.default_region') ?? config('content_packs.default_region', 'CN_MAINLAND'));
        $default = strtoupper(trim($default));
        if ($default !== '' && array_key_exists($default, $regions)) {
            return $default;
        }

        return 'CN_MAINLAND';
    }

    private function resolveLocale(Request $request, array $regionConfig): string
    {
        $fromLocaleHeader = $this->parseLocaleHeader((string) $request->header('X-FAP-Locale', ''));
        if ($fromLocaleHeader !== '') {
            return $fromLocaleHeader;
        }

        $fromHeader = $this->parseAcceptLanguage((string) $request->header('Accept-Language', ''));
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        $fallback = trim((string) ($regionConfig['default_locale'] ?? ''));
        if ($fallback === '') {
            $fallback = (string) config('content_packs.default_locale', 'zh-CN');
        }

        return $fallback;
    }

    private function parseLocaleHeader(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $normalized = $this->normalizeLocale($value);
        if ($normalized === '') {
            return '';
        }

        // Normalize short zh/en to deterministic downstream values.
        return match (strtolower($normalized)) {
            'zh' => 'zh-CN',
            'en' => 'en',
            default => $normalized,
        };
    }

    private function parseAcceptLanguage(string $header): string
    {
        $header = trim($header);
        if ($header === '') {
            return '';
        }

        $parts = explode(',', $header);
        $first = trim((string) ($parts[0] ?? ''));
        if ($first === '') {
            return '';
        }

        $first = trim((string) (explode(';', $first)[0] ?? ''));
        if ($first === '') {
            return '';
        }

        return $this->normalizeLocale($first);
    }

    private function normalizeLocale(string $raw): string
    {
        $raw = trim(str_replace('_', '-', $raw));
        if ($raw === '') {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9-]+$/', $raw)) {
            return '';
        }

        $parts = array_values(array_filter(explode('-', $raw), fn ($p) => $p !== ''));
        if ($parts === []) {
            return '';
        }

        $lang = strtolower($parts[0]);
        $region = $parts[1] ?? '';
        $rest = array_slice($parts, 2);

        $locale = $lang;
        if ($region !== '') {
            $locale .= '-' . strtoupper($region);
        }
        if ($rest !== []) {
            $locale .= '-' . implode('-', $rest);
        }

        return $locale;
    }
}
