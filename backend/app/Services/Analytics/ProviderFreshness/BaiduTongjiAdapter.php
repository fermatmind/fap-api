<?php

declare(strict_types=1);

namespace App\Services\Analytics\ProviderFreshness;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

final class BaiduTongjiAdapter
{
    public const PROVIDER = 'baidu';

    public function __construct(private readonly ProviderHttpClient $http) {}

    /** @return array<string,mixed> */
    public function fetch(CarbonImmutable $day): array
    {
        if (! $this->configured()) {
            return $this->failure('unconfigured', false);
        }

        try {
            $token = $this->accessToken();
            $date = $day->format('Ymd');
            $response = $this->http->send(fn () => Http::acceptJson()
                ->timeout($this->timeout())
                ->get((string) config('analytics.provider_freshness.baidu.report_endpoint'), [
                    'access_token' => $token,
                    'site_id' => trim((string) config('analytics.provider_freshness.baidu.site_id')),
                    'method' => 'trend/time/a',
                    'start_date' => $date,
                    'end_date' => $date,
                    'metrics' => 'pv_count,visitor_count',
                    'gran' => 'day',
                    'max_results' => 0,
                ]));

            $result = $this->resultFromPayload($response->json());
            $fields = $result['fields'] ?? null;
            $sum = $result['sum'][0] ?? null;
            if (! is_array($fields) || ! is_array($sum) || count($fields) !== count($sum)) {
                return $this->failure('malformed_payload', true);
            }

            $totals = array_combine(array_map('strval', $fields), $sum);
            if (! is_array($totals) || ! $this->numericValue($totals['pv_count'] ?? null) || ! $this->numericValue($totals['visitor_count'] ?? null)) {
                return $this->failure('malformed_payload', true);
            }

            return [
                'provider' => self::PROVIDER,
                'outcome' => 'success',
                'request_attempted' => true,
                'diagnostic_code' => null,
                'data_through' => $day->toDateString(),
                'metrics' => [
                    'page_views' => (int) $totals['pv_count'],
                    'visitors' => (int) $totals['visitor_count'],
                ],
            ];
        } catch (ProviderRequestException $exception) {
            return $this->failure($exception->diagnosticCode, true);
        } catch (Throwable) {
            return $this->failure('adapter_failed', true);
        }
    }

    private function accessToken(): string
    {
        $accessToken = trim((string) config('analytics.provider_freshness.baidu.access_token'));
        if ($accessToken !== '') {
            return $accessToken;
        }

        $response = $this->http->send(fn () => Http::acceptJson()
            ->timeout($this->timeout())
            ->get((string) config('analytics.provider_freshness.baidu.token_endpoint'), [
                'grant_type' => 'refresh_token',
                'refresh_token' => trim((string) config('analytics.provider_freshness.baidu.refresh_token')),
                'client_id' => trim((string) config('analytics.provider_freshness.baidu.client_id')),
                'client_secret' => trim((string) config('analytics.provider_freshness.baidu.client_secret')),
            ]));
        $token = $response->json('access_token');

        if (! is_string($token) || trim($token) === '') {
            throw new ProviderRequestException('malformed_auth_payload');
        }

        return trim($token);
    }

    /** @return array<string,mixed> */
    private function resultFromPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (is_array($payload['result'] ?? null)) {
            return $payload['result'];
        }

        $enveloped = $payload['body']['data'][0]['result'] ?? null;

        return is_array($enveloped) ? $enveloped : [];
    }

    private function configured(): bool
    {
        if (! (bool) config('analytics.provider_freshness.enabled')
            || ! (bool) config('analytics.provider_freshness.baidu.enabled')
            || preg_match('/^\d+$/', trim((string) config('analytics.provider_freshness.baidu.site_id'))) !== 1) {
            return false;
        }

        if (trim((string) config('analytics.provider_freshness.baidu.access_token')) !== '') {
            return true;
        }

        foreach (['refresh_token', 'client_id', 'client_secret'] as $key) {
            if (trim((string) config('analytics.provider_freshness.baidu.'.$key)) === '') {
                return false;
            }
        }

        return true;
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
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }
}
