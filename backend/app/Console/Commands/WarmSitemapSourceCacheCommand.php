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

            $payload = $controller->buildPayload($generator, $projection);

            $count = (int) ($payload['count'] ?? 0);
            $elapsed = round(microtime(true) - $start, 3);

            Cache::put(SitemapSourceController::CACHE_KEY_FRESH, $payload, SitemapSourceController::FRESH_TTL_SECONDS);
            Cache::put(SitemapSourceController::CACHE_KEY_STALE, $payload, SitemapSourceController::STALE_TTL_SECONDS);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode([
                    'status' => 'warmed',
                    'count' => $count,
                    'elapsed_seconds' => $elapsed,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->line("status=warmed count={$count} elapsed={$elapsed}s");

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $elapsed = round(microtime(true) - $start, 3);
            $message = $throwable->getMessage();

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode([
                    'status' => 'failed',
                    'error' => $message,
                    'elapsed_seconds' => $elapsed,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error("{$message} (elapsed={$elapsed}s)");
            }

            return self::FAILURE;
        }
    }
}
