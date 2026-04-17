<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Console\Command;

final class CareerWarmPublicAuthorityCache extends Command
{
    protected $signature = 'career:warm-public-authority-cache
        {--json : Emit JSON output}';

    protected $description = 'Warm public Career dataset and launch-governance authority response caches outside the HTTP request path.';

    public function handle(PublicCareerAuthorityResponseCache $cache): int
    {
        try {
            $summary = $cache->warm();

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode([
                    'status' => 'warmed',
                    'entries' => $summary,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=warmed');
            foreach ($summary as $name => $entry) {
                $this->line(sprintf(
                    '%s cache_key=%s member_count=%d',
                    $name,
                    (string) ($entry['cache_key'] ?? ''),
                    (int) ($entry['member_count'] ?? 0),
                ));
            }

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
