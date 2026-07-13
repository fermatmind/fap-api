<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Career\CareerRuntimeSloService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class RecordCareerRuntimeSlo
{
    public function __construct(private readonly CareerRuntimeSloService $slo) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->path() !== 'api/v0.5/career/directory') {
            return $next($request);
        }

        $started = hrtime(true);
        try {
            $response = $next($request);
            $this->recordSafely($response->getStatusCode(), (hrtime(true) - $started) / 1_000_000, [
                'locale' => (string) $request->query('locale', ''),
            ]);

            return $response;
        } catch (\Throwable $throwable) {
            $this->recordSafely(500, (hrtime(true) - $started) / 1_000_000, [
                'exception' => $throwable::class,
            ]);
            throw $throwable;
        }
    }

    /** @param array<string, mixed> $context */
    private function recordSafely(int $status, float $durationMs, array $context): void
    {
        try {
            $this->slo->record('career_directory_api', $status, $durationMs, $context);
        } catch (\Throwable $throwable) {
            Log::debug('CAREER_RUNTIME_SLO_RECORD_FAILED', [
                'exception' => $throwable::class,
            ]);
        }
    }
}
