<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Drift;

final class HtmlSnapshotParser
{
    /**
     * @return array{
     *     status_code: int,
     *     canonical: string|null,
     *     title: string|null,
     *     description: string|null,
     *     robots: string|null,
     *     jsonld_count: int,
     *     jsonld_types: list<string>,
     *     hreflang: list<array{hreflang: string, href_hash: string}>
     * }
     */
    public function parse(string $html, int $statusCode = 200): array
    {
        return [
            'status_code' => $statusCode,
            'canonical' => $this->firstMatch('/<link\b[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html)
                ?? $this->firstMatch('/<link\b[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\'][^>]*>/i', $html),
            'title' => $this->firstMatch('/<title[^>]*>(.*?)<\/title>/is', $html),
            'description' => $this->metaContent($html, 'description'),
            'robots' => $this->metaContent($html, 'robots'),
            'jsonld_count' => count($this->jsonLdPayloads($html)),
            'jsonld_types' => $this->jsonLdTypes($html),
            'hreflang' => $this->hreflangAlternates($html),
        ];
    }

    private function metaContent(string $html, string $name): ?string
    {
        return $this->firstMatch('/<meta\b[^>]*name=["\']'.preg_quote($name, '/').'["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i', $html)
            ?? $this->firstMatch('/<meta\b[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']'.preg_quote($name, '/').'["\'][^>]*>/i', $html);
    }

    private function firstMatch(string $pattern, string $subject): ?string
    {
        if (preg_match($pattern, $subject, $matches) !== 1) {
            return null;
        }

        $value = trim(html_entity_decode(strip_tags((string) $matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function jsonLdPayloads(string $html): array
    {
        if (preg_match_all('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches) < 1) {
            return [];
        }

        return array_values(array_map(
            static fn (string $payload): string => trim($payload),
            $matches[1] ?? []
        ));
    }

    /**
     * @return list<string>
     */
    private function jsonLdTypes(string $html): array
    {
        $types = [];

        foreach ($this->jsonLdPayloads($html) as $payload) {
            $decoded = json_decode($payload, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->extractJsonLdTypes($decoded) as $type) {
                $types[] = $type;
            }
        }

        $types = array_values(array_unique($types));
        sort($types);

        return $types;
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function extractJsonLdTypes(array $payload): array
    {
        $types = [];

        if (isset($payload['@type'])) {
            $rawType = $payload['@type'];
            foreach (is_array($rawType) ? $rawType : [$rawType] as $type) {
                if (is_string($type) && $type !== '') {
                    $types[] = $type;
                }
            }
        }

        if (isset($payload['@graph']) && is_array($payload['@graph'])) {
            foreach ($payload['@graph'] as $node) {
                if (is_array($node)) {
                    array_push($types, ...$this->extractJsonLdTypes($node));
                }
            }
        }

        return $types;
    }

    /**
     * @return list<array{hreflang: string, href_hash: string}>
     */
    private function hreflangAlternates(string $html): array
    {
        if (preg_match_all('/<link\b(?=[^>]*rel=["\']alternate["\'])(?=[^>]*hreflang=["\']([^"\']+)["\'])(?=[^>]*href=["\']([^"\']+)["\'])[^>]*>/i', $html, $matches) < 1) {
            return [];
        }

        $alternates = [];

        foreach (($matches[1] ?? []) as $index => $hreflang) {
            $href = (string) ($matches[2][$index] ?? '');

            if ($href === '') {
                continue;
            }

            $alternates[] = [
                'hreflang' => strtolower((string) $hreflang),
                'href_hash' => hash('sha256', $href),
            ];
        }

        return $alternates;
    }
}
