<?php

declare(strict_types=1);

namespace App\Services\SEO;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class BigFivePublicIntegrityGate
{
    public const REVIEWED_301_ALIASES = [
        '/zh/personality/big-five/emotional-stability' => '/zh/personality/big-five/neuroticism-low',
        '/zh/personality/big-five/high-agreeableness' => '/zh/personality/big-five/agreeableness-high',
        '/zh/personality/big-five/high-conscientiousness' => '/zh/personality/big-five/conscientiousness-high',
        '/zh/personality/big-five/high-extraversion' => '/zh/personality/big-five/extraversion-high',
        '/zh/personality/big-five/high-neuroticism' => '/zh/personality/big-five/neuroticism-high',
        '/zh/personality/big-five/high-openness' => '/zh/personality/big-five/openness-high',
        '/zh/personality/big-five/low-agreeableness' => '/zh/personality/big-five/agreeableness-low',
        '/zh/personality/big-five/low-conscientiousness' => '/zh/personality/big-five/conscientiousness-low',
        '/zh/personality/big-five/low-extraversion' => '/zh/personality/big-five/extraversion-low',
        '/zh/personality/big-five/low-openness' => '/zh/personality/big-five/openness-low',
    ];

    private const PRIVATE_PATH_PATTERN =
        '~^/(?:(?:en|zh)/)?(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:/|$)'
        .'|^/api(?:/v\d+(?:\.\d+)?)?/(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:/|$)~i';

    private const PRIVATE_QUERY_PATTERN =
        '/(?:^|&)(?:token|session|user_id|attempt_id|result_id|report_id|order_no|payment_id)=/i';

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    public function validate(array $package, string $baseUrl): array
    {
        $base = $this->normalizeBaseUrl($baseUrl);
        $targets = $this->targets($package);
        $results = [];
        $errors = [];

        foreach ($targets as $target) {
            $result = $this->resolveTarget($target, $base);
            $results[] = $result;
            if (($result['ok'] ?? false) !== true) {
                $errors[] = [
                    'target' => $target,
                    'code' => (string) ($result['code'] ?? 'target_validation_failed'),
                    'message' => (string) ($result['message'] ?? 'Target validation failed.'),
                ];
            }
        }

        return [
            'artifact' => 'BIG5-AUTHORITY-V2-INTEGRITY-GATE-02',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'base_url' => $base,
            'target_count' => count($targets),
            'canonical_200_count' => count(array_filter(
                $results,
                static fn (array $result): bool => ($result['resolution'] ?? null) === 'canonical_200'
            )),
            'reviewed_301_alias_count' => count(array_filter(
                $results,
                static fn (array $result): bool => ($result['resolution'] ?? null) === 'reviewed_301_alias'
            )),
            'results' => $results,
            'errors' => $errors,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'indexability_mutation_attempted' => false,
            'search_submission_attempted' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @return list<string>
     */
    private function targets(array $package): array
    {
        $assets = is_array($package['assets'] ?? null) ? array_values($package['assets']) : [];
        $targets = [];

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            foreach (array_values(is_array($asset['internal_links'] ?? null) ? $asset['internal_links'] : []) as $link) {
                if (is_string($link)) {
                    $targets[] = trim($link);

                    continue;
                }

                if (is_array($link)) {
                    $targets[] = trim((string) ($link['href'] ?? $link['url'] ?? ''));
                }
            }

            foreach (array_values(is_array($asset['sections'] ?? null) ? $asset['sections'] : []) as $section) {
                if (! is_array($section)) {
                    continue;
                }

                $body = (string) ($section['body_md'] ?? $section['body'] ?? '');
                preg_match_all('~(?:https://fermatmind\.com)?/(?:en|zh)/[^\s\]\[()<>"\']+~iu', $body, $matches);
                foreach ($matches[0] ?? [] as $match) {
                    $targets[] = rtrim((string) $match, '.,;:!?，。；：！？');
                }
            }
        }

        $targets = array_values(array_unique(array_filter(
            $targets,
            static fn (string $target): bool => $target !== ''
        )));
        sort($targets);

        return $targets;
    }

    /** @return array<string,mixed> */
    private function resolveTarget(string $target, string $baseUrl): array
    {
        $current = $this->normalizeTargetUrl($target, $baseUrl);
        if ($current === null) {
            return $this->failure($target, 'undeclared_external_or_invalid_target', 'Target must be an HTTPS URL on the declared FermatMind authority or a root-relative path.');
        }

        $original = $current;
        $visited = [];
        $usedReviewedAlias = false;

        for ($hop = 0; $hop <= 5; $hop++) {
            $path = $this->urlPath($current);
            if ($this->isPrivate($current)) {
                return $this->failure($target, 'private_target_rejected', 'Public internal links must not target private result, order, payment, account, or user paths.');
            }

            if (isset($visited[$current])) {
                return $this->failure($target, 'redirect_loop', 'Internal-link redirect chain contains a loop.');
            }
            $visited[$current] = true;

            try {
                $response = Http::accept('text/html')
                    ->timeout(10)
                    ->withoutRedirecting()
                    ->get($current);
            } catch (ConnectionException $exception) {
                return $this->failure($target, 'target_unreachable', $exception->getMessage());
            }

            if ($response->status() === 200) {
                $canonical = $this->canonicalUrl($response, $baseUrl);
                if ($canonical === null || $this->urlPath($canonical) !== $path) {
                    return $this->failure($target, 'canonical_mismatch', 'A 200 target must declare a same-authority canonical matching its final path.');
                }

                return [
                    'target' => $target,
                    'ok' => true,
                    'status' => 200,
                    'final_url' => $current,
                    'canonical' => $canonical,
                    'redirect_hops' => $hop,
                    'resolution' => $usedReviewedAlias ? 'reviewed_301_alias' : 'canonical_200',
                ];
            }

            if ($response->status() !== 301) {
                return $this->failure($target, 'unexpected_http_status', 'Internal-link target returned HTTP '.$response->status().'; only canonical 200 or reviewed 301 aliases are allowed.');
            }

            $expectedPath = self::REVIEWED_301_ALIASES[$path] ?? null;
            $location = trim((string) $response->header('Location'));
            $next = $this->normalizeTargetUrl($location, $baseUrl);
            if ($next !== null && isset($visited[$next])) {
                return $this->failure($target, 'redirect_loop', 'Internal-link redirect chain contains a loop.');
            }

            if ($expectedPath === null || $next === null || $this->urlPath($next) !== $expectedPath) {
                return $this->failure($target, 'unreviewed_301_alias', 'HTTP 301 is allowed only for the exact reviewed Big Five legacy alias map.');
            }

            $usedReviewedAlias = true;
            $current = $next;
        }

        return $this->failure($original, 'redirect_hop_limit', 'Internal-link redirect chain exceeded five hops.');
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $normalized = rtrim(trim($baseUrl), '/');
        $parts = parse_url($normalized);
        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'fermatmind.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            throw new InvalidArgumentException('Big Five integrity gate base URL must be https://fermatmind.com.');
        }

        return 'https://fermatmind.com';
    }

    private function normalizeTargetUrl(string $target, string $baseUrl): ?string
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }

        if (str_starts_with($target, '/')) {
            $target = $baseUrl.$target;
        }

        $parts = parse_url($target);
        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'fermatmind.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $baseUrl.($path !== '' ? $path : '/').$query;
    }

    private function isPrivate(string $url): bool
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        return preg_match(self::PRIVATE_PATH_PATTERN, $path) === 1
            || preg_match(self::PRIVATE_QUERY_PATTERN, $query) === 1;
    }

    private function canonicalUrl(Response $response, string $baseUrl): ?string
    {
        $body = $response->body();
        preg_match_all('/<link\b[^>]*>/iu', $body, $links);
        foreach ($links[0] ?? [] as $link) {
            if (preg_match('/\brel=["\'][^"\']*\bcanonical\b[^"\']*["\']/iu', $link) !== 1) {
                continue;
            }

            if (preg_match('/\bhref=["\']([^"\']+)["\']/iu', $link, $href) !== 1) {
                continue;
            }

            return $this->normalizeTargetUrl((string) $href[1], $baseUrl);
        }

        return null;
    }

    private function urlPath(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    }

    /** @return array<string,mixed> */
    private function failure(string $target, string $code, string $message): array
    {
        return [
            'target' => $target,
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'resolution' => 'rejected',
        ];
    }
}
