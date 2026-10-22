<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SEO\SitemapSourceCacheHandoff;
use Illuminate\Console\Command;

final class CarrySitemapSourceCacheCommand extends Command
{
    protected $signature = 'seo:carry-sitemap-source-cache {--previous-backend= : Active backend root}';

    protected $description = 'Carry a compatible fresh sitemap into the inactive release without regenerating it.';

    public function handle(SitemapSourceCacheHandoff $handoff): int
    {
        try {
            $result = $handoff->carry((string) $this->option('previous-backend'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Never print paths, configuration, Redis details or payloads.
            $reason = preg_match('/\ASITEMAP_HANDOFF_[A-Z_]+\z/', $error->getMessage()) === 1
                ? $error->getMessage() : 'SITEMAP_HANDOFF_FAILED';
            $this->line(json_encode(['status' => 'rebuild_required', 'reason' => $reason], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
