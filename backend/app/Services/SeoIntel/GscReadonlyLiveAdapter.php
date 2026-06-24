<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Support\Facades\Http;
use Throwable;

final class GscReadonlyLiveAdapter
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function preflight(array $options = []): array
    {
        $credential = $this->credentialState();
        $property = $this->propertyUrl();
        $issues = [];

        if (! (bool) config('seo_intel.gsc_enabled', false)) {
            $issues[] = 'gsc_enabled_false';
        }

        if (! (bool) config('seo_intel.gsc_live_api_enabled', false)) {
            $issues[] = 'gsc_live_api_disabled';
        }

        if ($property === null) {
            $issues[] = 'gsc_property_url_missing';
        }

        if (! $this->externalApiCallsAllowed($options)) {
            $issues[] = 'external_api_gate_disabled';
        }

        if (! (bool) $credential['credential_valid']) {
            $issues = [...$issues, ...$credential['issues']];
        }

        $issues = array_values(array_unique(array_map('strval', $issues)));

        return [
            'status' => $issues === [] ? 'ready' : 'blocked',
            'read_only' => true,
            'adapter' => 'google_search_console_searchanalytics_readonly',
            'data_origin_if_executed' => 'live_gsc_api',
            'property_configured' => $property !== null,
            'property_hash' => $property === null ? null : hash('sha256', $property),
            'auth_mode' => $credential['auth_mode'],
            'credential_source' => $credential['credential_source'],
            'credential_valid' => $credential['credential_valid'],
            'credential_checks' => $credential['credential_checks'],
            'gsc_enabled' => (bool) config('seo_intel.gsc_enabled', false),
            'gsc_live_api_enabled' => (bool) config('seo_intel.gsc_live_api_enabled', false),
            'external_api_calls_allowed' => $this->externalApiCallsAllowed($options),
            'live_read_allowed' => $issues === [],
            'writes_attempted' => false,
            'writes_committed' => false,
            'cms_write_allowed' => false,
            'search_channel_enqueue_allowed' => false,
            'search_provider_submission_allowed' => false,
            'indexing_request_allowed' => false,
            'scheduler_enabled' => false,
            'queue_worker_enabled' => false,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function fetchSearchAnalyticsRows(array $request, array $options = []): array
    {
        $preflight = $this->preflight($options);
        if (($preflight['status'] ?? 'blocked') !== 'ready') {
            return [
                'status' => 'blocked',
                'rows' => [],
                'external_calls_attempted' => false,
                'writes_attempted' => false,
                'issues' => $preflight['issues'] ?? ['gsc_live_readiness_blocked'],
                'preflight' => $preflight,
            ];
        }

        if (! (bool) ($options['execute_live_read'] ?? false)) {
            return [
                'status' => 'blocked',
                'rows' => [],
                'external_calls_attempted' => false,
                'writes_attempted' => false,
                'issues' => ['live_read_not_explicitly_requested'],
                'preflight' => $preflight,
            ];
        }

        $token = $this->accessToken();
        if ($token === '') {
            return [
                'status' => 'blocked',
                'rows' => [],
                'external_calls_attempted' => $this->authMode() === 'service_account',
                'writes_attempted' => false,
                'issues' => ['gsc_access_token_resolution_failed'],
                'preflight' => $preflight,
            ];
        }

        $endpoint = sprintf(
            (string) config('seo_intel.gsc_readonly_adapter.search_analytics_endpoint'),
            rawurlencode((string) $this->propertyUrl())
        );

        $payload = $this->safeSearchAnalyticsPayload($request);
        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(max(1, (int) config('seo_intel.gsc_readonly_adapter.timeout_seconds', 10)))
            ->post($endpoint, $payload);

        if (! $response->successful()) {
            return [
                'status' => 'blocked',
                'rows' => [],
                'external_calls_attempted' => true,
                'writes_attempted' => false,
                'http_status' => $response->status(),
                'issues' => ['gsc_searchanalytics_request_failed'],
                'preflight' => $preflight,
            ];
        }

        $rows = $this->rowsFromResponse($response->json());

        return [
            'status' => 'success',
            'rows' => $rows,
            'rows_seen' => count($rows),
            'external_calls_attempted' => true,
            'writes_attempted' => false,
            'http_status' => $response->status(),
            'preflight' => $preflight,
        ];
    }

    /**
     * @return array{
     *     auth_mode:string,
     *     credential_source:string,
     *     credential_valid:bool,
     *     credential_checks:array<string,bool>,
     *     issues:list<string>
     * }
     */
    private function credentialState(): array
    {
        $mode = $this->authMode();
        $checks = [
            'access_token_present' => $this->accessTokenConfigured(),
            'service_account_json_present' => $this->serviceAccountJson() !== null,
            'service_account_client_email_present' => false,
            'service_account_private_key_present' => false,
            'token_uri_present' => false,
        ];
        $issues = [];
        $source = 'none';

        if ($mode === 'access_token') {
            $source = 'access_token_env';
            if (! $checks['access_token_present']) {
                $issues[] = 'gsc_access_token_missing';
            }

            return [
                'auth_mode' => $mode,
                'credential_source' => $source,
                'credential_valid' => $issues === [],
                'credential_checks' => $checks,
                'issues' => $issues,
            ];
        }

        if ($mode === 'service_account') {
            $decoded = $this->serviceAccountJson();
            $source = $this->serviceAccountJsonSource();

            if ($decoded === null) {
                $issues[] = 'gsc_service_account_json_missing_or_invalid';
            } else {
                $checks['service_account_client_email_present'] = $this->nonEmptyString($decoded['client_email'] ?? null);
                $checks['service_account_private_key_present'] = $this->nonEmptyString($decoded['private_key'] ?? null);
                $checks['token_uri_present'] = $this->nonEmptyString($decoded['token_uri'] ?? null)
                    || $this->nonEmptyString(config('seo_intel.gsc_readonly_adapter.token_uri'));

                foreach ([
                    'service_account_client_email_present' => 'gsc_service_account_client_email_missing',
                    'service_account_private_key_present' => 'gsc_service_account_private_key_missing',
                    'token_uri_present' => 'gsc_token_uri_missing',
                ] as $check => $issue) {
                    if (! $checks[$check]) {
                        $issues[] = $issue;
                    }
                }
            }

            return [
                'auth_mode' => $mode,
                'credential_source' => $source,
                'credential_valid' => $issues === [],
                'credential_checks' => $checks,
                'issues' => $issues,
            ];
        }

        return [
            'auth_mode' => $mode,
            'credential_source' => $source,
            'credential_valid' => false,
            'credential_checks' => $checks,
            'issues' => ['gsc_auth_mode_disabled_or_unsupported'],
        ];
    }

    private function accessToken(): string
    {
        if ($this->authMode() === 'access_token') {
            return trim((string) config('seo_intel.gsc_readonly_adapter.access_token', ''));
        }

        $serviceAccount = $this->serviceAccountJson();
        if ($serviceAccount === null) {
            return '';
        }

        $tokenUri = (string) ($serviceAccount['token_uri'] ?? config('seo_intel.gsc_readonly_adapter.token_uri'));
        $jwt = $this->serviceAccountJwt($serviceAccount, $tokenUri);
        $response = Http::asForm()
            ->timeout(max(1, (int) config('seo_intel.gsc_readonly_adapter.timeout_seconds', 10)))
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if (! $response->successful()) {
            return '';
        }

        return trim((string) ($response->json('access_token') ?? ''));
    }

    /**
     * @param  array<string, mixed>  $serviceAccount
     */
    private function serviceAccountJwt(array $serviceAccount, string $tokenUri): string
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => (string) $serviceAccount['client_email'],
            'scope' => (string) config('seo_intel.gsc_readonly_adapter.scope'),
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $signature = '';

        openssl_sign($unsigned, $signature, (string) $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);

        return $unsigned.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serviceAccountJson(): ?array
    {
        $raw = trim((string) config('seo_intel.gsc_readonly_adapter.service_account_json', ''));
        $path = trim((string) config('seo_intel.gsc_readonly_adapter.service_account_json_path', ''));

        if ($raw === '' && $path !== '' && is_file($path)) {
            $raw = (string) file_get_contents($path);
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            $raw = is_string($decoded) ? $decoded : '';
        }

        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function serviceAccountJsonSource(): string
    {
        if (trim((string) config('seo_intel.gsc_readonly_adapter.service_account_json', '')) !== '') {
            return 'service_account_json_env';
        }

        if (trim((string) config('seo_intel.gsc_readonly_adapter.service_account_json_path', '')) !== '') {
            return 'service_account_json_path';
        }

        return 'none';
    }

    private function authMode(): string
    {
        return mb_strtolower(trim((string) config('seo_intel.gsc_readonly_adapter.auth_mode', 'disabled')), 'UTF-8');
    }

    private function propertyUrl(): ?string
    {
        $property = trim((string) config('seo_intel.gsc_property_url', ''));

        return $property === '' ? null : $property;
    }

    private function accessTokenConfigured(): bool
    {
        return trim((string) config('seo_intel.gsc_readonly_adapter.access_token', '')) !== '';
    }

    private function externalApiCallsAllowed(array $options): bool
    {
        if (array_key_exists('allow_external_api_calls', $options)) {
            return (bool) config('seo_intel.allow_external_api_calls', false)
                && (bool) $options['allow_external_api_calls'];
        }

        return (bool) config('seo_intel.allow_external_api_calls', false);
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function safeSearchAnalyticsPayload(array $request): array
    {
        $limit = max(1, min(
            (int) ($request['rowLimit'] ?? config('seo_intel.gsc_readonly_adapter.default_limit', 250)),
            (int) config('seo_intel.gsc_readonly_adapter.max_limit', 250)
        ));

        $payload = [
            'startDate' => substr((string) ($request['startDate'] ?? now()->subDays(30)->toDateString()), 0, 10),
            'endDate' => substr((string) ($request['endDate'] ?? now()->subDays(3)->toDateString()), 0, 10),
            'dimensions' => array_values(array_intersect(
                is_array($request['dimensions'] ?? null) ? $request['dimensions'] : ['query', 'page'],
                ['query', 'page', 'country', 'device', 'searchAppearance']
            )),
            'type' => 'web',
            'rowLimit' => $limit,
        ];

        $filterGroups = $this->safeDimensionFilterGroups($request['dimensionFilterGroups'] ?? null);
        if ($filterGroups !== []) {
            $payload['dimensionFilterGroups'] = $filterGroups;
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function safeDimensionFilterGroups(mixed $groups): array
    {
        if (! is_array($groups)) {
            return [];
        }

        $safeGroups = [];
        foreach ($groups as $group) {
            if (! is_array($group) || ! is_array($group['filters'] ?? null)) {
                continue;
            }

            $filters = [];
            foreach ($group['filters'] as $filter) {
                if (! is_array($filter)) {
                    continue;
                }

                $dimension = (string) ($filter['dimension'] ?? '');
                $operator = (string) ($filter['operator'] ?? '');
                $expression = trim((string) ($filter['expression'] ?? ''));
                if (! in_array($dimension, ['page', 'query', 'country', 'device', 'searchAppearance'], true)) {
                    continue;
                }
                if (! in_array($operator, ['equals', 'contains', 'notContains', 'includingRegex', 'excludingRegex'], true)) {
                    continue;
                }
                if ($expression === '') {
                    continue;
                }

                $filters[] = [
                    'dimension' => $dimension,
                    'operator' => $operator,
                    'expression' => mb_substr($expression, 0, 2048, 'UTF-8'),
                ];
            }

            if ($filters !== []) {
                $safeGroups[] = ['filters' => $filters];
            }
        }

        return $safeGroups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFromResponse(mixed $payload): array
    {
        $rows = is_array($payload) && is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        return array_values(array_map(static function (array $row): array {
            $keys = is_array($row['keys'] ?? null) ? array_values($row['keys']) : [];

            return [
                'query' => isset($keys[0]) ? (string) $keys[0] : null,
                'page' => isset($keys[1]) ? (string) $keys[1] : null,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'position' => isset($row['position']) ? (float) $row['position'] : null,
                'data_origin' => 'live_gsc_api',
                'row_source' => 'live_gsc_api',
            ];
        }, $rows));
    }
}
