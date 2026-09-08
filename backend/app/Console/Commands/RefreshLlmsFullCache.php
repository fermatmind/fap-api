<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\CacheLifecycleAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class RefreshLlmsFullCache extends Command
{
    protected $signature = 'seo:refresh-llms-full-cache';

    protected $description = 'Refresh the full frontend SEO artifact through existing signed revalidation.';

    public function handle(CacheLifecycleAlerts $alerts): int
    {
        try {
            $endpoint = trim((string) config('ops.content_release_observability.hmac_revalidation_url'));
            $secret = (string) config('ops.content_release_observability.hmac_revalidation_secret');
            $url = parse_url($endpoint);
            if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
                || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || strlen($secret) < 24) {
                throw new \RuntimeException('Internal refresh configuration unavailable.');
            }
            $authorityUrls = app(\App\Services\SEO\SitemapGenerator::class)->generateSitemapUrls();
            if ($authorityUrls === []) {
                throw new \RuntimeException('Empty public authority inventory.');
            }
            $fingerprint = app(WarmSitemapSourceCacheCommand::class)->buildFingerprint($authorityUrls);
            $identity = ['sitemap' => $fingerprint['fingerprint_sha256']];
            foreach (['career', 'personality_public'] as $family) {
                $manifest = base_path('content_assets/'.$family.'/current/manifest.json');
                $digest = is_file($manifest) ? hash_file('sha256', $manifest) : false;
                if (! is_string($digest)) {
                    throw new \RuntimeException('Current public content identity unavailable.');
                }
                $identity[$family] = $digest;
            }
            $body = json_encode(['operation' => 'refresh_llms_full',
                'source_fingerprint' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(24));
            $response = Http::connectTimeout(5)->timeout(325)->withoutRedirecting()
                ->withHeaders([
                    'X-FM-Content-Release-Timestamp' => $timestamp,
                    'X-FM-Content-Release-Nonce' => $nonce,
                    'X-FM-Content-Release-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret),
                ])->withBody($body, 'application/json')->post($endpoint);
            $ok = $response->successful() && $response->json('ok') === true
                && $response->json('operation') === 'refresh_llms_full'
                && $response->json('status') === 'complete' && (int) $response->json('bytes') > 0;
        } catch (\Throwable) {
            $ok = false;
        }
        $alerts->observe('llms_refresh', $ok);
        $this->line(json_encode(['status' => $ok ? 'complete' : 'failed'], JSON_THROW_ON_ERROR));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
