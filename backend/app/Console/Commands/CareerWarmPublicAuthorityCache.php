<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Console\Command;

final class CareerWarmPublicAuthorityCache extends Command
{
    protected $signature = 'career:warm-public-authority-cache
        {--job-detail-slugs= : Comma-separated career job slugs for per-locale detail cache warm}
        {--job-detail-locales=zh-CN : Comma-separated public locales for detail cache warm}
        {--forget-job-detail : Forget targeted job detail caches before warming them}
        {--job-detail-only : Warm only targeted job detail caches}
        {--json : Emit JSON output}';

    protected $description = 'Warm public Career dataset and launch-governance authority response caches outside the HTTP request path.';

    public function handle(PublicCareerAuthorityResponseCache $cache): int
    {
        try {
            $jobDetailSlugs = $this->csvOption('job-detail-slugs');
            $jobDetailLocales = $this->csvOption('job-detail-locales');
            $jobDetailOnly = (bool) $this->option('job-detail-only');
            if ($jobDetailOnly && $jobDetailSlugs === []) {
                $this->error('--job-detail-only requires --job-detail-slugs.');

                return self::FAILURE;
            }

            $reporter = function (string $phase, string $state): void {
                if (! (bool) $this->option('json')) {
                    $this->line(sprintf('career_warm_phase=%s state=%s', $phase, $state));
                }
            };
            $summary = $jobDetailOnly ? [] : $cache->warm($reporter);
            if ($jobDetailSlugs !== []) {
                $summary = array_merge(
                    $summary,
                    $cache->warmJobDetailPayloads(
                        $jobDetailSlugs,
                        $jobDetailLocales === [] ? ['zh-CN'] : $jobDetailLocales,
                        (bool) $this->option('forget-job-detail'),
                        $reporter,
                    ),
                );
            }

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

    /**
     * @return list<string>
     */
    private function csvOption(string $name): array
    {
        $raw = trim((string) $this->option($name));
        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw),
        ), static fn (string $value): bool => $value !== '')));
    }
}
