<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class WarmSitemapSourceCacheCommand extends Command
{
    protected $signature = 'seo:warm-sitemap-source-cache
        {--json : Emit JSON output}';

    protected $description = 'Warm the SEO sitemap-source fresh and stale caches outside the HTTP request path.';

    public function handle(): int
    {
        $start = microtime(true);

        try {
            $controller = app(SitemapSourceController::class);
            $generator = app(\App\Services\SEO\SitemapGenerator::class);
            $projection = app(\App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup::class);

            $lock = Cache::lock(SitemapSourceController::CACHE_KEY_LOCK, SitemapSourceController::LOCK_TTL_SECONDS);
            if (! $lock->get()) {
                return $this->emitResult('locked', 0, round(microtime(true) - $start, 3));
            }

            try {
                $payload = $controller->buildPayload($generator, $projection);
                if ((int) ($payload['count'] ?? 0) < 1) {
                    throw new \RuntimeException('Generated sitemap-source payload was empty.');
                }

                $controller->storeCache($payload);

                return $this->emitResult('warmed', (int) ($payload['count'] ?? 0), round(microtime(true) - $start, 3));
            } finally {
                $lock->release();
            }
        } catch (\Throwable $throwable) {
            $elapsed = round(microtime(true) - $start, 3);
            $stale = Cache::get(SitemapSourceController::CACHE_KEY_STALE);
            if (is_array($stale)) {
                return $this->emitResult('stale_retained', (int) ($stale['count'] ?? 0), $elapsed, $throwable->getMessage());
            }

            $controller = app(SitemapSourceController::class);
            $payload = $controller->fallbackPayload();
            $controller->storeCache($payload);

            return $this->emitResult('fallback_warmed', (int) ($payload['count'] ?? 0), $elapsed, $throwable->getMessage());
        }
    }

    private function emitResult(string $status, int $count, float $elapsed, ?string $error = null): int
    {
        if ((bool) $this->option('json')) {
            $payload = [
                'status' => $status,
                'count' => $count,
                'elapsed_seconds' => $elapsed,
            ];
            if ($error !== null && $error !== '') {
                $payload['error'] = $error;
            }

            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $suffix = $error === null || $error === '' ? '' : " error=\"{$error}\"";
        $this->line("status={$status} count={$count} elapsed={$elapsed}s{$suffix}");

        return self::SUCCESS;
    }
}
