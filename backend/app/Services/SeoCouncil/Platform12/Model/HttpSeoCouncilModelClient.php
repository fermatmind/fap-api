<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Model;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

final class HttpSeoCouncilModelClient implements SeoCouncilModelClient
{
    public function complete(SeoCouncilModelRequest $request): SeoCouncilModelResponse
    {
        $endpoint = trim((string) config('seo_council.model_http.endpoint', ''));
        $secret = trim((string) config('seo_council.model_http.secret', ''));
        if ($endpoint === '' || $secret === '' || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw new SeoCouncilModelFailure('MODEL_PROVIDER_NOT_CONFIGURED');
        }

        $maximumAttempts = min(2, max(1, $request->maxModelCalls));
        for ($attempt = 1; $attempt <= $maximumAttempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->withToken($secret)
                    ->connectTimeout($this->connectTimeoutSeconds($request))
                    ->timeout($this->timeoutSeconds($request))
                    ->post($endpoint, $request->providerPayload());
            } catch (ConnectionException) {
                if ($attempt < $maximumAttempts) {
                    continue;
                }

                throw new SeoCouncilModelFailure('MODEL_TRANSPORT_RETRY_EXHAUSTED', $attempt);
            }

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt < $maximumAttempts) {
                    continue;
                }

                throw new SeoCouncilModelFailure('MODEL_HTTP_RETRY_EXHAUSTED', $attempt);
            }
            if (! $response->successful()) {
                throw new SeoCouncilModelFailure('MODEL_PROVIDER_REJECTED', $attempt);
            }

            return $this->decode($response, $request, $attempt);
        }

        throw new SeoCouncilModelFailure('MODEL_TRANSPORT_RETRY_EXHAUSTED', $maximumAttempts);
    }

    private function connectTimeoutSeconds(SeoCouncilModelRequest $request): int
    {
        $configured = max(1, (int) config('seo_council.model_http.connect_timeout_seconds', 3));

        return min($configured, $this->timeoutSeconds($request));
    }

    private function timeoutSeconds(SeoCouncilModelRequest $request): int
    {
        $deadline = max(1, (int) ceil($request->deadlineMilliseconds / 1000));
        $configured = max(1, (int) config('seo_council.model_http.timeout_seconds', 15));

        return min($configured, $deadline);
    }

    private function decode(Response $response, SeoCouncilModelRequest $request, int $attempt): SeoCouncilModelResponse
    {
        $body = $response->body();
        if (strlen($body) > $request->maxResponseBytes) {
            throw new SeoCouncilModelFailure('MODEL_RESPONSE_BUDGET_EXHAUSTED', $attempt);
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SeoCouncilModelFailure('MODEL_RESPONSE_MALFORMED_JSON', $attempt);
        }
        if (! is_array($decoded)
            || ! $this->exactKeys($decoded, ['output', 'usage'])
            || ! is_array($decoded['output'])
            || ! is_array($decoded['usage'])) {
            throw new SeoCouncilModelFailure('MODEL_RESPONSE_SCHEMA_INVALID', $attempt);
        }

        return new SeoCouncilModelResponse($decoded['output'], $decoded['usage'], $attempt);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
