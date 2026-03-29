<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_4;

use App\Http\Controllers\Controller;
use App\Services\Experiments\ExperimentAssigner;
use App\Services\Payments\PaymentRouter;
use App\Support\RegionContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BootController extends Controller
{
    public function __construct(
        private RegionContext $regionContext,
        private PaymentRouter $paymentRouter,
        private ExperimentAssigner $experimentAssigner,
    ) {}

    /**
     * GET /api/v0.4/boot
     */
    public function show(Request $request): Response
    {
        $region = $this->regionContext->region();
        $locale = $this->regionContext->locale();
        $currency = $this->regionContext->currency();
        $this->primeOrgContextForPublicBoot($request);
        $anonId = $this->resolveAnonId($request);
        $bootExperiments = $anonId !== null
            ? $this->experimentAssigner->assignActive(0, $anonId, null)
            : [];

        $regionsConfig = config('regions.regions', []);
        $regionConfig = is_array($regionsConfig) ? ($regionsConfig[$region] ?? []) : [];

        $cdnBaseUrl = $this->resolveCdnBaseUrl($region);
        $paymentMethods = $this->paymentRouter->methodsForRegion($region);

        $payload = [
            'ok' => true,
            'region' => $region,
            'locale' => $locale,
            'currency' => $currency,
            'cdn' => [
                'assets_base_url' => $cdnBaseUrl,
            ],
            'payment_methods' => $paymentMethods,
            'compliance' => [
                'pipl' => (bool) ($regionConfig['compliance_flags']['pipl'] ?? false),
                'gdpr' => (bool) ($regionConfig['compliance_flags']['gdpr'] ?? false),
                'legal_urls' => is_array($regionConfig['legal_urls'] ?? null) ? $regionConfig['legal_urls'] : [],
            ],
            'experiments' => [
                'experiments_json' => $bootExperiments,
                'boot_experiments' => $bootExperiments,
            ],
            'feature_flags_version' => (string) \App\Support\RuntimeConfig::value('FAP_FEATURE_FLAGS_VERSION', 'v0.4'),
            'policy_versions' => is_array($regionConfig['policy_versions'] ?? null) ? $regionConfig['policy_versions'] : [],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($body)) {
            $body = '{"ok":false}';
        }

        $etag = '"'.sha1($body).'"';
        $headers = [
            'Cache-Control' => 'public, max-age=300',
            'Vary' => 'X-Region, Accept-Language, X-FAP-Locale, X-Anon-Id',
            'ETag' => $etag,
            'Content-Type' => 'application/json',
        ];

        $ifNoneMatch = (string) $request->header('If-None-Match', '');
        if ($this->etagMatches($ifNoneMatch, $etag)) {
            return response('', 304)->withHeaders($headers);
        }

        return response($body, 200)->withHeaders($headers);
    }

    private function resolveCdnBaseUrl(string $region): string
    {
        $map = config('cdn_map.map', []);
        if (is_array($map) && isset($map[$region]) && is_array($map[$region])) {
            $base = trim((string) ($map[$region]['assets_base_url'] ?? ''));
            if ($base !== '') {
                return $base;
            }
        }

        $appUrl = trim((string) config('app.url', ''));
        if ($appUrl === '') {
            $appUrl = trim((string) \App\Support\RuntimeConfig::value('APP_URL', ''));
        }
        if ($appUrl === '') {
            $appUrl = 'http://localhost';
        }

        return rtrim($appUrl, '/').'/storage/content_assets';
    }

    private function etagMatches(string $header, string $etag): bool
    {
        $header = trim($header);
        if ($header === '') {
            return false;
        }

        if ($header === '*' || $header === $etag) {
            return true;
        }

        $parts = array_map('trim', explode(',', $header));
        foreach ($parts as $part) {
            if ($part === $etag) {
                return true;
            }
        }

        return false;
    }

    private function resolveAnonId(Request $request): ?string
    {
        $candidates = [
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            $request->attributes->get('client_anon_id'),
            $request->query('anon_id'),
            $request->header('X-Anon-Id'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }
            $value = trim((string) $candidate);
            if ($value === '' || strlen($value) > 128) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function primeOrgContextForPublicBoot(Request $request): void
    {
        $request->attributes->set('org_context_resolved', true);
        $request->attributes->set('org_id', 0);
        $request->attributes->set('fm_org_id', 0);
    }
}
