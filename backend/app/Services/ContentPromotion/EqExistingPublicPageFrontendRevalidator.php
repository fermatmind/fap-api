<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;
use Illuminate\Support\Facades\Http;

/** Only the six existing EQ public paths; no discoverability or broadcast. */
final class EqExistingPublicPageFrontendRevalidator
{
    public const PATHS = [
        '/zh/tests/eq-test-emotional-intelligence-assessment',
        '/en/tests/eq-test-emotional-intelligence-assessment',
        '/zh/articles/eq-test-tool-guide',
        '/en/articles/eq-test-tool-guide',
        '/zh/career/guides/iq-eq-balance-at-work',
        '/en/career/guides/iq-eq-balance-at-work',
    ];

    public function assertConfigured(): void
    {
        $this->configuration();
    }

    public function revalidate(): void
    {
        [$endpoint, $secret] = $this->configuration();
        $body = json_encode([
            'event' => 'content_release_revalidate',
            'source' => 'eq_existing_public_exact_package',
            'content' => ['type' => 'eq_existing_public_pages'],
            'cache_signal' => ['paths' => self::PATHS],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $response = Http::acceptJson()->timeout(15)->connectTimeout(5)
            ->withoutRedirecting()
            ->withHeaders([
                'X-FM-Content-Release-Timestamp' => $timestamp,
                'X-FM-Content-Release-Nonce' => $nonce,
                'X-FM-Content-Release-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret),
            ])->withBody($body, 'application/json')->post($endpoint);
        $receipt = $response->json();
        if (! $response->successful() || ! is_array($receipt)
            || ($receipt['ok'] ?? null) !== true
            || ($receipt['revalidated_paths'] ?? null) !== self::PATHS
            || ($receipt['rejected_paths'] ?? null) !== []
            || ($receipt['invalidated_tags'] ?? null) !== []) {
            throw new DomainException('eq_existing_frontend_revalidation_failed');
        }
    }

    private function configuration(): array
    {
        $endpoint = trim((string) config('ops.content_release_observability.hmac_revalidation_url', ''));
        $secret = trim((string) config('ops.content_release_observability.hmac_revalidation_secret', ''));
        $url = parse_url($endpoint);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || ($url['path'] ?? '') !== '/api/content-release/revalidate'
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || $secret === '') {
            throw new DomainException('eq_existing_frontend_revalidation_not_configured');
        }

        return [$endpoint, $secret];
    }
}
