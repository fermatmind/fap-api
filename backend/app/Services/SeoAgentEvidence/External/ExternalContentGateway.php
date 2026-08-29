<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\External;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoQueryHmac;
use DOMDocument;
use DOMXPath;
use Throwable;

final class ExternalContentGateway
{
    public function __construct(
        private readonly ExternalDnsResolver $dns,
        private readonly ExternalContentTransport $transport,
        private readonly ExternalInjectionScanner $injection,
        private readonly SeoPrivateDataScanner $privateScanner,
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoQueryHmac $queryHmac,
    ) {}

    /** @return array<string, mixed> */
    public function fetch(string $sourceId, string $url): array
    {
        if (! (bool) config('seo_agent_evidence.external_fetch_enabled', false)
            || (bool) config('seo_agent_evidence.agent_external_egress', false)) {
            return $this->hold('EXTERNAL_FETCH_DISABLED');
        }
        $policies = (array) config('seo_agent_evidence.allowed_sources', []);
        $policy = $policies[$sourceId] ?? null;
        if (! is_array($policy) || ! $this->sourcePolicyApproved($sourceId, $policy)) {
            return $this->hold('SOURCE_POLICY_HELD');
        }

        try {
            return $this->fetchValidated($sourceId, $url, $policy, 0, []);
        } catch (Throwable) {
            return $this->hold('EXTERNAL_GATEWAY_HELD');
        }
    }

    /** @param array<string, mixed> $policy @param list<string> $visited @return array<string, mixed> */
    private function fetchValidated(string $sourceId, string $url, array $policy, int $redirects, array $visited): array
    {
        $parts = $this->validateUrl($url, $policy);
        $host = (string) $parts['host'];
        $requestUrl = 'https://'.$host.((string) ($parts['path'] ?? '/'));
        if (is_string($parts['query'] ?? null) && $parts['query'] !== '') {
            $requestUrl .= '?'.$parts['query'];
        }
        $approved = $this->approvedAddresses($host);
        if (in_array($requestUrl, $visited, true)) {
            return $this->hold('REDIRECT_LOOP');
        }
        $visited[] = $requestUrl;

        $robotsUrl = 'https://'.$host.'/robots.txt';
        $robots = $this->transport->request('GET', $robotsUrl, $approved[0], 3, 8, 65536);
        if (! in_array($robots['connected_ip'], $approved, true) || $robots['status'] !== 200
            || ! $this->robotsAllows($robots['body'], (string) ($parts['path'] ?? '/'))) {
            return $this->hold('ROBOTS_HELD');
        }

        $maxBytes = min(1048576, max(1, (int) ($policy['max_content_bytes'] ?? 524288)));
        $response = $this->transport->request('GET', $requestUrl, $approved[0], min(3, (int) ($policy['connect_timeout_seconds'] ?? 3)), min(8, (int) ($policy['request_timeout_seconds'] ?? 8)), $maxBytes);
        if (! in_array($response['connected_ip'], $approved, true)) {
            return $this->hold('DNS_REBINDING_BLOCKED');
        }
        $location = $response['headers']['location'] ?? $response['headers']['Location'] ?? null;
        if ($response['status'] >= 300 && $response['status'] < 400 && is_string($location)) {
            $allowedRedirects = min(2, max(0, (int) ($policy['redirect_policy'] ?? 0)));
            if ($redirects >= $allowedRedirects) {
                return $this->hold('REDIRECT_BLOCKED');
            }

            return $this->fetchValidated($sourceId, $location, $policy, $redirects + 1, $visited);
        }
        if ($response['status'] !== 200 || strlen($response['body']) > $maxBytes) {
            return $this->hold('CONTENT_RESPONSE_HELD');
        }
        $contentLength = $response['headers']['content-length'] ?? $response['headers']['Content-Length'] ?? null;
        if ($contentLength !== null && ((int) $contentLength > $maxBytes || (int) $contentLength !== strlen($response['body']))) {
            return $this->hold('CONTENT_LENGTH_HELD');
        }
        $contentType = strtolower(trim(explode(';', (string) ($response['headers']['content-type'] ?? $response['headers']['Content-Type'] ?? ''))[0]));
        if (! in_array($contentType, (array) ($policy['allowed_content_types'] ?? []), true)) {
            return $this->hold('CONTENT_TYPE_HELD');
        }
        $contentEncoding = strtolower(trim((string) ($response['headers']['content-encoding'] ?? $response['headers']['Content-Encoding'] ?? 'identity')));
        if (! in_array($contentEncoding, ['identity', 'gzip', 'br'], true)) {
            return $this->hold('CONTENT_ENCODING_HELD');
        }
        $injection = $this->injection->scan($response['body']);
        if ($injection['result'] !== 'pass') {
            return $this->hold('INJECTION_BLOCKED', 'blocked');
        }
        [$facts, $snippets] = $this->extract($contentType, $response['body'], (int) ($policy['max_snippet_chars'] ?? 280));
        if ($this->privateScanner->scan([$facts, $snippets])['private_data_present']) {
            return $this->hold('PRIVATE_DATA_BLOCKED');
        }
        $sanitizedUrl = 'https://'.$host.((string) ($parts['path'] ?? '/'));
        $sourceIdentity = $sanitizedUrl;
        if (is_string($parts['query'] ?? null) && $parts['query'] !== '') {
            $queryIdentity = $this->queryHmac->identify((string) $parts['query']);
            if (($queryIdentity['status'] ?? null) !== 'available') {
                return $this->hold('QUERY_HMAC_UNAVAILABLE');
            }
            $sourceIdentity .= '|query-hmac:'.$queryIdentity['query_hmac'].'|'.$queryIdentity['query_hmac_key_version'];
        }

        return [
            'source_id' => $sourceId,
            'sanitized_source_url' => $sanitizedUrl,
            'source_url_hash_or_hmac' => $this->hasher->hash($sourceIdentity),
            'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'source_content_hash' => hash('sha256', $response['body']),
            'content_type' => $contentType,
            'structured_facts' => $facts,
            'bounded_snippets' => array_slice($snippets, 0, 3),
            'robots_decision' => 'allowed',
            'terms_decision' => 'approved',
            'license_class' => $policy['license_class'],
            'redaction_summary' => ['private_values' => 0, 'removed_nodes' => true],
            'injection_scan_result' => 'pass',
            'retention_class' => $policy['retention_class'],
            'egress_decision' => 'allowed_by_gateway',
        ];
    }

    /** @param array<string, mixed> $policy */
    private function sourcePolicyApproved(string $sourceId, array $policy): bool
    {
        $required = ['source_id', 'allowed_hosts', 'allowed_protocols', 'allowed_ports', 'redirect_policy', 'robots_required', 'minimum_request_interval', 'max_concurrency', 'max_content_bytes', 'allowed_content_types', 'connect_timeout_seconds', 'request_timeout_seconds', 'terms_status', 'terms_reviewed_at', 'login_required', 'technical_restriction_state', 'license_class', 'allowed_saved_fields', 'max_snippet_chars', 'retention_class', 'data_usage_purpose'];

        return array_diff($required, array_keys($policy)) === []
            && $policy['source_id'] === $sourceId
            && $policy['allowed_protocols'] === ['https']
            && $policy['allowed_ports'] === [443]
            && $policy['robots_required'] === true
            && (int) $policy['minimum_request_interval'] >= 1
            && (int) $policy['max_concurrency'] === 1
            && $policy['terms_status'] === 'approved'
            && $policy['login_required'] === false
            && $policy['technical_restriction_state'] === 'permitted'
            && in_array($policy['license_class'], ['first_party', 'licensed', 'public_fact_permitted'], true);
    }

    /** @param array<string, mixed> $policy @return array<string, mixed> */
    private function validateUrl(string $url, array $policy): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || (int) ($parts['port'] ?? 443) !== 443) {
            throw new \RuntimeException('URL_BLOCKED');
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match('/(?:^|\.)(?:localhost|local|internal|onion)$/', $host) === 1) {
            throw new \RuntimeException('HOST_BLOCKED');
        }
        $allowed = array_map(static fn (mixed $item): string => strtolower((string) $item), (array) $policy['allowed_hosts']);
        $matches = array_filter($allowed, static fn (string $allowedHost): bool => $host === $allowedHost || str_ends_with($host, '.'.$allowedHost));
        if ($matches === []) {
            throw new \RuntimeException('HOST_NOT_ALLOWED');
        }
        unset($parts['fragment']);

        return $parts;
    }

    /** @return list<string> */
    private function approvedAddresses(string $host): array
    {
        $addresses = array_values(array_unique($this->dns->resolveAll($host)));
        if ($addresses === []) {
            throw new \RuntimeException('DNS_EMPTY');
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new \RuntimeException('DNS_PRIVATE');
            }
        }

        return $addresses;
    }

    private function isPublicAddress(string $address): bool
    {
        if (str_starts_with(strtolower($address), '::ffff:')) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($address);
            $cgnat = ip2long('100.64.0.0');
            if ($long !== false && $cgnat !== false && (($long & 0xFFC00000) === ($cgnat & 0xFFC00000))) {
                return false;
            }
        }

        return true;
    }

    private function robotsAllows(string $robots, string $path): bool
    {
        $active = false;
        $seenUserAgent = false;
        foreach (preg_split('/\R/', strtolower($robots)) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (str_starts_with($line, 'user-agent:')) {
                $seenUserAgent = true;
                $active = trim(substr($line, 11)) === '*';
            } elseif ($active && str_starts_with($line, 'disallow:')) {
                $blocked = trim(substr($line, 9));
                if ($blocked !== '' && str_starts_with(strtolower($path), $blocked)) {
                    return false;
                }
            }
        }

        return $seenUserAgent;
    }

    /** @return array{0:array<string, scalar|null>,1:list<string>} */
    private function extract(string $contentType, string $body, int $snippetMax): array
    {
        $snippetMax = min(280, max(1, $snippetMax));
        if ($contentType === 'application/json') {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                return [[], []];
            }
            $reserved = ['tool_call', 'function_call', 'tool_allowlist', 'egress_allowlist', 'authority_ceiling', 'write_permissions', 'execution_allowed', 'policy_hash', 'prompt_hash'];
            $facts = [];
            foreach ($decoded as $key => $value) {
                if (in_array((string) $key, $reserved, true)) {
                    throw new \RuntimeException('RESERVED_METADATA');
                }
                if (is_scalar($value) || $value === null) {
                    $facts[(string) $key] = is_string($value) ? mb_substr($value, 0, $snippetMax) : $value;
                }
            }

            return [array_slice($facts, 0, 20, true), []];
        }
        $text = $body;
        if ($contentType === 'text/html') {
            $dom = new DOMDocument;
            @$dom->loadHTML($body, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query('//script|//style|//iframe|//form|//input|//comment()|//*[@hidden]|//*[contains(translate(@style,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"display:none")]|//meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="refresh"]') ?: [] as $node) {
                $node->parentNode?->removeChild($node);
            }
            $text = (string) $dom->textContent;
        }
        $text = preg_replace('/\s+/u', ' ', trim(strip_tags($text))) ?: '';
        $sentences = preg_split('/(?<=[.!?。！？])\s+/u', $text) ?: [];
        $snippets = array_values(array_filter(array_map(static fn (string $item): string => mb_substr(trim($item), 0, $snippetMax), $sentences)));

        return [[], array_slice($snippets, 0, 3)];
    }

    /** @return array<string, mixed> */
    private function hold(string $code, string $injection = 'not_scanned'): array
    {
        return [
            'status' => 'held',
            'safe_error_code' => $code,
            'injection_scan_result' => $injection,
            'egress_decision' => 'held',
            'context_eligible' => false,
        ];
    }
}
