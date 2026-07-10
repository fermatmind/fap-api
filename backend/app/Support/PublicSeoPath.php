<?php

declare(strict_types=1);

namespace App\Support;

final class PublicSeoPath
{
    /** @var array<string, true> */
    private const PRIVATE_ROOTS = [
        'account' => true,
        'admin' => true,
        'api' => true,
        'checkout' => true,
        'history' => true,
        'login' => true,
        'me' => true,
        'ops' => true,
        'order' => true,
        'orders' => true,
        'pay' => true,
        'payment' => true,
        'payments' => true,
        'report' => true,
        'reports' => true,
        'result' => true,
        'results' => true,
        'share' => true,
        'shares' => true,
        'take' => true,
    ];

    /** @var array<string, true> */
    private const LOCALE_SEGMENTS = [
        'en' => true,
        'zh' => true,
        'zh-cn' => true,
        'zh-tw' => true,
    ];

    public static function normalizePath(?string $value): ?string
    {
        $path = trim((string) $value);
        if ($path === ''
            || str_contains($path, '?')
            || str_contains($path, '#')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || preg_match('/%(?:2e|2f|5c)/i', $path) === 1
            || preg_match('#^https?://#i', $path) === 1) {
            return null;
        }

        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $segments = array_values(array_filter(
            explode('/', strtolower($path)),
            static fn (string $segment): bool => $segment !== ''
        ));

        if (array_intersect($segments, ['.', '..']) !== []) {
            return null;
        }

        $rootIndex = isset($segments[0]) && isset(self::LOCALE_SEGMENTS[$segments[0]]) ? 1 : 0;
        $root = $segments[$rootIndex] ?? '';
        if ($root !== '' && isset(self::PRIVATE_ROOTS[$root])) {
            return null;
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    public static function fromCanonicalUrl(?string $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! self::isOwnedHost($parts['host'])) {
            return null;
        }

        return self::normalizePath((string) ($parts['path'] ?? '/'));
    }

    private static function isOwnedHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $configuredHost = parse_url(
            (string) config('seo_intel.public_canonical_host', CanonicalFrontendUrl::APEX_URL),
            PHP_URL_HOST
        );
        $configuredHost = is_string($configuredHost) ? strtolower(rtrim($configuredHost, '.')) : '';
        $allowedHosts = array_filter([$configuredHost]);

        if (in_array($configuredHost, ['fermatmind.com', 'www.fermatmind.com'], true)) {
            $allowedHosts[] = 'fermatmind.com';
            $allowedHosts[] = 'www.fermatmind.com';
        }

        return in_array($host, array_values(array_unique($allowedHosts)), true);
    }
}
