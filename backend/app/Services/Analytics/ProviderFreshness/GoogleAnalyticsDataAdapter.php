<?php

declare(strict_types=1);

namespace App\Services\Analytics\ProviderFreshness;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

final class GoogleAnalyticsDataAdapter
{
    public const PROVIDER = 'ga4';

    public function __construct(private readonly ProviderHttpClient $http) {}

    /** @return array<string,mixed> */
    public function fetch(CarbonImmutable $day): array
    {
        if (! $this->configured()) {
            return $this->failure('unconfigured', false);
        }

        try {
            $credential = $this->serviceAccount();
            if ($credential === null) {
                return $this->failure('unconfigured', false);
            }

            $token = $this->accessToken($credential);
            $endpoint = sprintf(
                (string) config('analytics.provider_freshness.ga4.report_endpoint'),
                rawurlencode(trim((string) config('analytics.provider_freshness.ga4.property_id'))),
            );
            $date = $day->toDateString();
            $response = $this->http->send(fn () => Http::acceptJson()
                ->withToken($token)
                ->timeout($this->timeout())
                ->post($endpoint, [
                    'dateRanges' => [['startDate' => $date, 'endDate' => $date]],
                    'dimensions' => [['name' => 'eventName']],
                    'metrics' => [['name' => 'eventCount'], ['name' => 'activeUsers']],
                    'dimensionFilter' => [
                        'filter' => [
                            'fieldName' => 'eventName',
                            'inListFilter' => [
                                'values' => ['page_view', 'view_landing'],
                                'caseSensitive' => true,
                            ],
                        ],
                    ],
                    'metricAggregations' => ['TOTAL'],
                    'keepEmptyRows' => true,
                ]));

            $payload = $response->json();
            if (! is_array($payload)) {
                return $this->failure('malformed_payload', true);
            }

            $totals = $payload['totals'][0]['metricValues'] ?? null;
            if (! is_array($totals) || ! $this->numericValue($totals[0]['value'] ?? null) || ! $this->numericValue($totals[1]['value'] ?? null)) {
                return $this->failure('malformed_payload', true);
            }

            $byEvent = ['page_view' => 0, 'view_landing' => 0];
            foreach (($payload['rows'] ?? []) as $row) {
                if (! is_array($row)) {
                    return $this->failure('malformed_payload', true);
                }

                $event = $row['dimensionValues'][0]['value'] ?? null;
                $count = $row['metricValues'][0]['value'] ?? null;
                if (! is_string($event) || ! array_key_exists($event, $byEvent) || ! $this->numericValue($count)) {
                    return $this->failure('malformed_payload', true);
                }

                $byEvent[$event] = (int) $count;
            }

            return [
                'provider' => self::PROVIDER,
                'outcome' => 'success',
                'request_attempted' => true,
                'diagnostic_code' => null,
                'data_through' => $date,
                'metrics' => [
                    'event_count' => (int) $totals[0]['value'],
                    'active_users' => (int) $totals[1]['value'],
                    'page_view' => $byEvent['page_view'],
                    'view_landing' => $byEvent['view_landing'],
                ],
            ];
        } catch (ProviderRequestException $exception) {
            return $this->failure($exception->diagnosticCode, true);
        } catch (Throwable) {
            return $this->failure('adapter_failed', true);
        }
    }

    /** @param array<string,mixed> $credential */
    private function accessToken(array $credential): string
    {
        $tokenEndpoint = (string) config('analytics.provider_freshness.ga4.token_endpoint');
        $jwt = $this->serviceAccountJwt($credential, $tokenEndpoint);
        $response = $this->http->send(fn () => Http::asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->post($tokenEndpoint, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]));
        $token = $response->json('access_token');

        if (! is_string($token) || trim($token) === '') {
            throw new ProviderRequestException('malformed_auth_payload');
        }

        return trim($token);
    }

    /** @param array<string,mixed> $credential */
    private function serviceAccountJwt(array $credential, string $audience): string
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $credential['client_email'],
            'scope' => (string) config('analytics.provider_freshness.ga4.readonly_scope'),
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $signature = '';

        if (! openssl_sign($unsigned, $signature, (string) $credential['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new ProviderRequestException('invalid_credentials');
        }

        return $unsigned.'.'.$this->base64UrlEncode($signature);
    }

    /** @return array<string,mixed>|null */
    private function serviceAccount(): ?array
    {
        $raw = trim((string) config('analytics.provider_freshness.ga4.service_account_json'));
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['client_email', 'private_key'] as $key) {
            if (! is_string($decoded[$key] ?? null) || trim((string) $decoded[$key]) === '') {
                return null;
            }
        }

        return $decoded;
    }

    private function configured(): bool
    {
        return (bool) config('analytics.provider_freshness.enabled')
            && (bool) config('analytics.provider_freshness.ga4.enabled')
            && preg_match('/^\d+$/', trim((string) config('analytics.provider_freshness.ga4.property_id'))) === 1
            && $this->serviceAccount() !== null;
    }

    /** @return array<string,mixed> */
    private function failure(string $code, bool $attempted): array
    {
        return [
            'provider' => self::PROVIDER,
            'outcome' => $code === 'unconfigured' ? 'unconfigured' : 'failure',
            'request_attempted' => $attempted,
            'diagnostic_code' => $code,
            'data_through' => null,
            'metrics' => [],
        ];
    }

    private function timeout(): int
    {
        return min(10, max(1, (int) config('analytics.provider_freshness.timeout_seconds', 8)));
    }

    private function numericValue(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
