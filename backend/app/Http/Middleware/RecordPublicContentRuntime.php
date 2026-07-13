<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Ops\PublicContentRuntimeMetricsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

final class RecordPublicContentRuntime
{
    public function __construct(
        private readonly PublicContentRuntimeMetricsService $metrics,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $scope = $this->scope($request);
        if ($scope === null) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        try {
            $response = $next($request);
            $this->deferRecord(
                $scope,
                $response->getStatusCode(),
                (hrtime(true) - $startedAt) / 1_000_000,
            );

            return $response;
        } catch (\Throwable $throwable) {
            $this->deferRecord(
                $scope,
                500,
                (hrtime(true) - $startedAt) / 1_000_000,
            );

            throw $throwable;
        }
    }

    /** @return array{family:string,priority:string,locale:string}|null */
    private function scope(Request $request): ?array
    {
        if (! $this->isAnonymousPublicGet($request)) {
            return null;
        }

        $route = $request->route();
        if (! is_object($route) || ! method_exists($route, 'uri')) {
            return null;
        }
        $resolved = $this->metrics->resolveRoute(
            (string) $route->uri(),
            is_string($request->route('framework')) ? $request->route('framework') : null,
        );
        if ($resolved === null) {
            return null;
        }

        $locale = $request->query('locale');
        if (! is_string($locale) || trim($locale) === '') {
            $locale = $request->header('X-FAP-Locale');
        }

        return [
            ...$resolved,
            'locale' => $this->metrics->canonicalLocale(is_string($locale) ? $locale : null),
        ];
    }

    private function isAnonymousPublicGet(Request $request): bool
    {
        if (! $request->isMethod('GET') || trim((string) $request->header('Authorization', '')) !== '') {
            return false;
        }

        foreach (['X-FM-Token', 'X-Org-Id', 'X-FM-Org-Id', 'X-FAP-Admin-Token', 'X-Admin-Token'] as $header) {
            if (trim((string) $request->header($header, '')) !== '') {
                return false;
            }
        }

        foreach (['fm_token', 'ops_org_id', (string) config('session.cookie')] as $cookie) {
            if ($cookie !== '' && $request->cookies->has($cookie)) {
                return false;
            }
        }

        if (! $request->query->has('org_id')) {
            return true;
        }

        $orgId = $request->query('org_id');

        return (is_string($orgId) || is_int($orgId)) && (string) $orgId === '0';
    }

    /** @param array{family:string,priority:string,locale:string} $scope */
    private function deferRecord(array $scope, int $statusCode, float $durationMs): void
    {
        defer(function () use ($scope, $statusCode, $durationMs): void {
            try {
                $this->metrics->record(
                    $scope['family'],
                    $scope['priority'],
                    $scope['locale'],
                    $statusCode,
                    $durationMs,
                    in_array($statusCode, [408, 504], true),
                );
            } catch (\Throwable) {
                // Public delivery is authoritative; telemetry is always fail-open.
                return;
            }
        })->always();
    }
}
