<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class SeoIssueSanitizer
{
    public function __construct(
        private readonly SeoIssueQueueContract $contract = new SeoIssueQueueContract,
    ) {}

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    public function sanitize(array $issue): array
    {
        $issueType = $this->stringOrDefault($issue['issue_type'] ?? null, 'metadata_drift');
        if (! $this->contract->isIssueTypeAllowed($issueType)) {
            $issueType = 'metadata_drift';
        }

        $canonicalUrl = $this->publicUrlOrNull($issue['canonical_url'] ?? null);
        $evidence = $this->sanitizeMetadata($issue['evidence'] ?? $issue['metadata_json'] ?? []);

        $safe = [
            'issue_uid' => $this->stringOrDefault($issue['issue_uid'] ?? null, $this->issueUid($issueType, $issue, $canonicalUrl)),
            'issue_type' => $issueType,
            'severity' => $this->contract->normalizeSeverity($issue['severity'] ?? 'info'),
            'source_system' => $this->stringOrDefault($issue['source_system'] ?? null, 'seo_intel'),
            'source_engine' => $this->stringOrNull($issue['source_engine'] ?? null),
            'canonical_url_hash' => $this->stringOrNull($issue['canonical_url_hash'] ?? null) ?: ($canonicalUrl === null ? null : hash('sha256', $canonicalUrl)),
            'canonical_url' => $canonicalUrl,
            'locale' => $this->stringOrNull($issue['locale'] ?? null),
            'page_entity_type' => $this->stringOrNull($issue['page_entity_type'] ?? null),
            'entity_id_or_slug' => $this->stringOrNull($issue['entity_id_or_slug'] ?? null),
            'cluster' => $this->stringOrNull($issue['cluster'] ?? null),
            'status' => $this->contract->normalizeLifecycle($issue['status'] ?? 'open'),
            'lifecycle_state' => $this->contract->normalizeLifecycle($issue['lifecycle_state'] ?? $issue['status'] ?? 'open'),
            'detected_at' => $this->stringOrNull($issue['detected_at'] ?? null),
            'acknowledged_at' => $this->stringOrNull($issue['acknowledged_at'] ?? null),
            'resolved_at' => $this->stringOrNull($issue['resolved_at'] ?? null),
            'ignored_at' => $this->stringOrNull($issue['ignored_at'] ?? null),
            'summary' => $this->safeText($issue['summary'] ?? null, 512),
            'recommendation' => $this->safeText($issue['recommendation'] ?? null, 512),
            'evidence_hash' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
            'metadata_json' => $evidence + [
                'cms_mutation_allowed' => false,
                'auto_publish_allowed' => false,
                'auto_pseo_allowed' => false,
                'raw_pii_stored' => false,
            ],
        ];

        return $this->sanitizeMetadata($safe);
    }

    /**
     * @param  array<string, mixed>|mixed  $value
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $safe = [];

        foreach ($value as $key => $item) {
            $key = (string) $key;

            if ($this->isForbiddenKey($key)) {
                continue;
            }

            $safe[$key] = match (true) {
                is_array($item) => $this->sanitizeMetadata($item),
                is_string($item) => $this->maskSensitiveText($item),
                is_bool($item), is_int($item), is_float($item), $item === null => $item,
                default => $this->maskSensitiveText((string) $item),
            };
        }

        return $safe;
    }

    private function isForbiddenKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach ($this->contract->forbiddenColumns() as $forbidden) {
            if ($normalized === $forbidden || str_contains($normalized, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private function publicUrlOrNull(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        $path = $this->maskSensitivePath((string) ($parts['path'] ?? '/'));

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$path;
    }

    private function safeText(mixed $value, int $limit): ?string
    {
        $text = $this->stringOrNull($value);

        if ($text === null) {
            return null;
        }

        return substr($this->maskSensitiveText($text), 0, $limit);
    }

    private function maskSensitiveText(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\b(?:order|attempt|payment|provider)[-_ ]?[A-Z0-9]{6,}\b/i', '[redacted]', $text) ?? $text;
        $text = preg_replace('#/(attempts?|results?|orders?|payments?|shares?|reports?)/[A-Za-z0-9_-]{6,}#i', '/$1/:redacted', $text) ?? $text;
        $text = preg_replace('/\b[A-F0-9]{16,}\b/i', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\b(?:token|secret|api[_-]?key)=?[^,\s]+/i', '[redacted]', $text) ?? $text;

        return $text;
    }

    private function maskSensitivePath(string $path): string
    {
        $segments = explode('/', $path);
        $privateParents = ['attempt', 'attempts', 'result', 'results', 'order', 'orders', 'payment', 'payments', 'share', 'shares', 'report', 'reports'];

        foreach ($segments as $index => $segment) {
            $previous = strtolower($segments[$index - 1] ?? '');
            if (
                in_array($previous, $privateParents, true)
                && preg_match('/^[A-Za-z0-9_-]{6,}$/', $segment) === 1
            ) {
                $segments[$index] = ':redacted';

                continue;
            }

            if (preg_match('/^[A-F0-9]{16,}$/i', $segment) === 1) {
                $segments[$index] = ':redacted';
            }
        }

        return implode('/', $segments);
    }

    private function issueUid(string $issueType, array $issue, ?string $canonicalUrl): string
    {
        $seed = implode('|', [
            $issueType,
            (string) ($issue['source_system'] ?? 'seo_intel'),
            (string) ($issue['source_engine'] ?? ''),
            $canonicalUrl ?? '',
            (string) ($issue['locale'] ?? ''),
            (string) ($issue['page_entity_type'] ?? ''),
            (string) ($issue['entity_id_or_slug'] ?? ''),
        ]);

        return 'seo_issue_'.substr(hash('sha256', $seed), 0, 48);
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return $this->stringOrNull($value) ?? $default;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
