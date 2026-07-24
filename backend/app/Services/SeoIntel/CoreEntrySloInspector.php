<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class CoreEntrySloInspector
{
    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, list<string>>  $headers
     * @return array<string, mixed>
     */
    public function inspect(
        array $target,
        string $publicBaseUrl,
        int $statusCode,
        array $headers,
        string $body,
        ?float $ttfbMs,
        string $ttfbSource,
    ): array {
        $incidents = [];
        $delivery = $this->deliveryMode($headers, $body, $target);
        $httpStatus = $this->httpStatus($statusCode);

        if ($httpStatus !== 'pass') {
            $incidents[] = $httpStatus;
        } else {
            $ssrVisible = $this->containsAny($body, (array) $target['ssr_markers'])
                && $this->hasVisibleH1($body);
            if (! $ssrVisible || $delivery['mode'] === 'minimal_shell') {
                $incidents[] = 'thin_shell';
            }

            $canonicalStatus = $this->canonicalStatus(
                $body,
                $publicBaseUrl,
                (string) $target['path']
            );
            if ($canonicalStatus !== 'pass') {
                $incidents[] = 'canonical_drift';
            }

            $robotsStatus = $this->robotsStatus($headers, $body);
            if ($robotsStatus !== 'pass') {
                $incidents[] = 'robots_drift';
            }

            $hreflangStatus = $this->hreflangStatus(
                $body,
                $publicBaseUrl,
                (string) $target['alternate_path'],
                (string) $target['alternate_hreflang']
            );
            if ($hreflangStatus !== 'pass') {
                $incidents[] = 'hreflang_drift';
            }

            $ctaStatus = $this->primaryCtaStatus(
                $body,
                $publicBaseUrl,
                (array) $target['primary_cta_markers']
            );
            if ($ctaStatus !== 'pass') {
                $incidents[] = 'primary_cta_missing';
            }
        }

        if ($ttfbMs === null) {
            $incidents[] = 'ttfb_unavailable';
        } elseif ($ttfbMs > (int) $target['ttfb_budget_ms']) {
            $incidents[] = 'ttfb_breach';
        }

        $incidents = array_values(array_unique($incidents));
        $deliveryMode = $delivery['mode'];
        $cmsApiState = match ($deliveryMode) {
            'fresh' => 'healthy',
            'last_known_good' => 'degraded',
            'minimal_shell' => 'unavailable',
            default => 'unknown',
        };

        return [
            'target_id' => (string) $target['id'],
            'tier' => (string) $target['tier'],
            'page_family' => (string) $target['page_family'],
            'locale' => (string) $target['locale'],
            'safe_path' => (string) $target['path'],
            'safe_path_sha256' => (string) $target['path_sha256'],
            'status' => $incidents === [] ? 'healthy' : 'incident',
            'severity' => $this->severity((string) $target['tier'], $incidents),
            'incident_categories' => $incidents,
            'http' => [
                'status_code' => $statusCode,
                'status' => $httpStatus,
            ],
            'ttfb' => [
                'milliseconds' => $ttfbMs === null ? null : round($ttfbMs, 2),
                'budget_milliseconds' => (int) $target['ttfb_budget_ms'],
                'source' => $ttfbSource,
                'status' => $ttfbMs === null
                    ? 'unavailable'
                    : ($ttfbMs <= (int) $target['ttfb_budget_ms'] ? 'pass' : 'breach'),
            ],
            'ssr_visible_content' => [
                'status' => $httpStatus === 'pass' && $this->containsAny($body, (array) $target['ssr_markers']) && $this->hasVisibleH1($body)
                    ? 'pass'
                    : 'missing_or_thin',
            ],
            'canonical' => [
                'status' => $httpStatus === 'pass'
                    ? $this->canonicalStatus($body, $publicBaseUrl, (string) $target['path'])
                    : 'not_evaluated',
                'expected_path_sha256' => (string) $target['path_sha256'],
            ],
            'robots' => [
                'status' => $httpStatus === 'pass' ? $this->robotsStatus($headers, $body) : 'not_evaluated',
            ],
            'hreflang' => [
                'status' => $httpStatus === 'pass'
                    ? $this->hreflangStatus(
                        $body,
                        $publicBaseUrl,
                        (string) $target['alternate_path'],
                        (string) $target['alternate_hreflang']
                    )
                    : 'not_evaluated',
                'expected_locale' => (string) $target['alternate_hreflang'],
                'expected_path_sha256' => hash('sha256', (string) $target['alternate_path']),
            ],
            'primary_cta' => [
                'status' => $httpStatus === 'pass'
                    ? $this->primaryCtaStatus(
                        $body,
                        $publicBaseUrl,
                        (array) $target['primary_cta_markers']
                    )
                    : 'not_evaluated',
            ],
            'dependency_state' => [
                'authority_dependency' => (string) $target['authority_dependency'],
                'upstream' => $statusCode >= 500 ? 'unavailable' : $cmsApiState,
                'cms_api' => $cmsApiState,
                'delivery_mode' => $deliveryMode,
                'evidence_source' => $delivery['source'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public function transportFailure(array $target): array
    {
        return [
            'target_id' => (string) $target['id'],
            'tier' => (string) $target['tier'],
            'page_family' => (string) $target['page_family'],
            'locale' => (string) $target['locale'],
            'safe_path' => (string) $target['path'],
            'safe_path_sha256' => (string) $target['path_sha256'],
            'status' => 'incident',
            'severity' => $this->severity((string) $target['tier'], ['transport_error']),
            'incident_categories' => ['transport_error', 'ttfb_unavailable'],
            'http' => [
                'status_code' => null,
                'status' => 'transport_error',
            ],
            'ttfb' => [
                'milliseconds' => null,
                'budget_milliseconds' => (int) $target['ttfb_budget_ms'],
                'source' => 'unavailable',
                'status' => 'unavailable',
            ],
            'ssr_visible_content' => ['status' => 'not_evaluated'],
            'canonical' => [
                'status' => 'not_evaluated',
                'expected_path_sha256' => (string) $target['path_sha256'],
            ],
            'robots' => ['status' => 'not_evaluated'],
            'hreflang' => [
                'status' => 'not_evaluated',
                'expected_locale' => (string) $target['alternate_hreflang'],
                'expected_path_sha256' => hash('sha256', (string) $target['alternate_path']),
            ],
            'primary_cta' => ['status' => 'not_evaluated'],
            'dependency_state' => [
                'authority_dependency' => (string) $target['authority_dependency'],
                'upstream' => 'unavailable',
                'cms_api' => 'unknown',
                'delivery_mode' => 'unknown',
                'evidence_source' => 'transport_error',
            ],
        ];
    }

    private function httpStatus(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 500 => 'http_5xx',
            $statusCode >= 400 => 'http_4xx',
            $statusCode >= 300 => 'http_redirect',
            $statusCode === 200 => 'pass',
            default => 'http_unexpected',
        };
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @param  array<string, mixed>  $target
     * @return array{mode:string, source:string}
     */
    private function deliveryMode(array $headers, string $body, array $target): array
    {
        $headerState = strtolower($this->header($headers, 'x-fermatmind-content-state'));
        if (in_array($headerState, ['fresh', 'last-known-good', 'last_known_good', 'minimal-shell', 'minimal_shell'], true)) {
            return [
                'mode' => match ($headerState) {
                    'last-known-good', 'last_known_good' => 'last_known_good',
                    'minimal-shell', 'minimal_shell' => 'minimal_shell',
                    default => 'fresh',
                },
                'source' => 'response_state_header',
            ];
        }

        if ($this->containsAny($body, (array) $target['minimal_shell_markers'])) {
            return ['mode' => 'minimal_shell', 'source' => 'html_minimal_shell_marker'];
        }

        if ($this->containsAny($body, (array) $target['last_known_good_markers'])) {
            return ['mode' => 'last_known_good', 'source' => 'html_last_known_good_marker'];
        }

        if ($this->containsAny($body, (array) $target['ssr_markers']) && $this->hasVisibleH1($body)) {
            return ['mode' => 'fresh', 'source' => 'inferred_visible_content'];
        }

        return ['mode' => 'unknown', 'source' => 'no_state_evidence'];
    }

    private function canonicalStatus(string $body, string $publicBaseUrl, string $expectedPath): string
    {
        $href = $this->linkHref($body, 'canonical');
        if ($href === null) {
            return 'missing';
        }

        return $this->urlMatches($href, $publicBaseUrl, $expectedPath) ? 'pass' : 'drift';
    }

    private function robotsStatus(array $headers, string $body): string
    {
        $meta = $this->metaContent($body, 'robots');
        $header = $this->header($headers, 'x-robots-tag');
        $directives = strtolower(trim($meta.' '.$header));

        if ($meta === '') {
            return 'missing';
        }
        if (str_contains($directives, 'noindex') || str_contains($directives, 'nofollow')) {
            return 'drift';
        }

        return str_contains(strtolower($meta), 'index') && str_contains(strtolower($meta), 'follow')
            ? 'pass'
            : 'drift';
    }

    private function hreflangStatus(
        string $body,
        string $publicBaseUrl,
        string $expectedPath,
        string $expectedLocale,
    ): string {
        foreach ($this->linkTags($body) as $attributes) {
            $rel = strtolower((string) ($attributes['rel'] ?? ''));
            $hreflang = (string) ($attributes['hreflang'] ?? '');
            $href = (string) ($attributes['href'] ?? '');

            if (
                str_contains($rel, 'alternate')
                && strcasecmp($hreflang, $expectedLocale) === 0
                && $this->urlMatches($href, $publicBaseUrl, $expectedPath)
            ) {
                return 'pass';
            }
        }

        return 'missing_or_drift';
    }

    private function linkHref(string $body, string $expectedRel): ?string
    {
        foreach ($this->linkTags($body) as $attributes) {
            $rel = strtolower((string) ($attributes['rel'] ?? ''));
            if (str_contains($rel, strtolower($expectedRel)) && isset($attributes['href'])) {
                return (string) $attributes['href'];
            }
        }

        return null;
    }

    /**
     * @return list<array<string, string>>
     */
    private function linkTags(string $body): array
    {
        if (preg_match_all('/<link\b[^>]*>/i', $body, $matches) !== 1 && ($matches[0] ?? []) === []) {
            return [];
        }

        return array_map(fn (string $tag): array => $this->attributes($tag), $matches[0]);
    }

    private function metaContent(string $body, string $expectedName): string
    {
        if (preg_match_all('/<meta\b[^>]*>/i', $body, $matches) !== 1 && ($matches[0] ?? []) === []) {
            return '';
        }

        foreach ($matches[0] as $tag) {
            $attributes = $this->attributes($tag);
            if (strcasecmp((string) ($attributes['name'] ?? ''), $expectedName) === 0) {
                return (string) ($attributes['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function attributes(string $tag): array
    {
        preg_match_all('/([A-Za-z:-]+)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER);
        $attributes = [];

        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5);
        }

        return $attributes;
    }

    private function urlMatches(string $candidate, string $publicBaseUrl, string $expectedPath): bool
    {
        $candidateParts = parse_url($candidate);
        $baseParts = parse_url($publicBaseUrl);

        if (! is_array($candidateParts) || ! is_array($baseParts)) {
            return false;
        }

        $candidateHost = strtolower((string) ($candidateParts['host'] ?? $baseParts['host'] ?? ''));
        $baseHost = strtolower((string) ($baseParts['host'] ?? ''));
        $candidateScheme = strtolower((string) ($candidateParts['scheme'] ?? $baseParts['scheme'] ?? ''));
        $path = preg_replace('#/+#', '/', '/'.ltrim((string) ($candidateParts['path'] ?? ''), '/'));

        return $candidateScheme === 'https'
            && $candidateHost === $baseHost
            && $path === $expectedPath
            && ! isset($candidateParts['query'])
            && ! isset($candidateParts['fragment']);
    }

    /**
     * CTA markers describe an href/action path; an omitted closing quote marks a
     * public path prefix. Query parameters on the rendered URL are allowed.
     *
     * @param  list<string>  $markers
     */
    private function primaryCtaStatus(string $body, string $publicBaseUrl, array $markers): string
    {
        if (preg_match_all('/<(?:a|form)\b[^>]*>/i', $body, $matches) === false) {
            return 'missing';
        }

        foreach ($markers as $marker) {
            if (preg_match('/^(href|action)="([^"]+)"?$/', $marker, $expected) !== 1) {
                continue;
            }

            $attribute = $expected[1];
            $expectedPath = $expected[2];
            $isPrefix = ! str_ends_with($marker, '"');

            foreach ($matches[0] ?? [] as $tag) {
                $candidate = $this->attributes($tag)[$attribute] ?? null;
                if (
                    is_string($candidate)
                    && $this->primaryCtaUrlMatches($candidate, $publicBaseUrl, $expectedPath, $isPrefix)
                ) {
                    return 'pass';
                }
            }
        }

        return 'missing';
    }

    private function primaryCtaUrlMatches(
        string $candidate,
        string $publicBaseUrl,
        string $expectedPath,
        bool $isPrefix,
    ): bool {
        $candidateParts = parse_url($candidate);
        $baseParts = parse_url($publicBaseUrl);

        if (
            ! is_array($candidateParts)
            || ! is_array($baseParts)
            || isset($candidateParts['fragment'])
        ) {
            return false;
        }

        $candidateHost = strtolower((string) ($candidateParts['host'] ?? $baseParts['host'] ?? ''));
        $baseHost = strtolower((string) ($baseParts['host'] ?? ''));
        $candidateScheme = strtolower((string) ($candidateParts['scheme'] ?? $baseParts['scheme'] ?? ''));
        $path = preg_replace('#/+#', '/', '/'.ltrim((string) ($candidateParts['path'] ?? ''), '/'));
        $pathMatches = $isPrefix
            ? str_starts_with($path, $expectedPath)
            : $path === $expectedPath;

        return $candidateScheme === 'https'
            && $candidateHost === $baseHost
            && $pathMatches;
    }

    /**
     * @param  list<string>  $markers
     */
    private function containsAny(string $body, array $markers): bool
    {
        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function hasVisibleH1(string $body): bool
    {
        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $body, $matches) !== 1) {
            return false;
        }

        return trim(html_entity_decode(strip_tags((string) $matches[1]), ENT_QUOTES | ENT_HTML5)) !== '';
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp((string) $key, $name) === 0) {
                return trim(implode(',', (array) $values));
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $incidents
     */
    private function severity(string $tier, array $incidents): string
    {
        if ($incidents === []) {
            return 'none';
        }

        return match ($tier) {
            'L1' => 'critical',
            'L2' => 'high',
            default => 'warning',
        };
    }
}
