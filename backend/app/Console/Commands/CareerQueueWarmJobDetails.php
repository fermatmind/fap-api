<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Career\WarmCareerJobDetailProjection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class CareerQueueWarmJobDetails extends Command
{
    protected $signature = 'career:queue-warm-job-details
        {--manifest= : JSON file containing items[].slug or slugs[]}
        {--slugs= : Comma-separated changed slugs}
        {--locales=en,zh-CN : Comma-separated locales}
        {--batch-size=250 : Maximum slugs dispatched per invocation}
        {--resume-key=default : Durable cursor namespace}
        {--reset : Reset the cursor before dispatching}
        {--json : Emit JSON output}';

    protected $description = 'Queue resumable, per-slug Career detail projection warm jobs without serially rebuilding all details.';

    public function handle(): int
    {
        $slugs = $this->slugs();
        if ($slugs === []) {
            $this->error('--manifest or --slugs must provide at least one slug.');

            return self::FAILURE;
        }

        $locales = array_values(array_intersect($this->csv((string) $this->option('locales')), ['en', 'zh-CN']));
        if ($locales === []) {
            $this->error('--locales must include en or zh-CN.');

            return self::FAILURE;
        }

        $resumeKey = 'career:detail-warm-queue:v1:'.preg_replace('/[^a-z0-9._-]+/i', '-', trim((string) $this->option('resume-key')));
        if ((bool) $this->option('reset')) {
            Cache::forget($resumeKey);
        }
        $cursor = min(count($slugs), max(0, (int) Cache::get($resumeKey, 0)));
        $batchSize = min(1000, max(1, (int) $this->option('batch-size')));
        $batch = array_slice($slugs, $cursor, $batchSize);

        foreach ($batch as $slug) {
            foreach ($locales as $locale) {
                WarmCareerJobDetailProjection::dispatch($slug, $locale);
            }
        }

        $nextCursor = $cursor + count($batch);
        Cache::forever($resumeKey, $nextCursor);
        $report = [
            'status' => $nextCursor >= count($slugs) ? 'queued_complete' : 'queued_partial',
            'resume_key' => $resumeKey,
            'slug_count' => count($slugs),
            'locale_count' => count($locales),
            'queued_jobs' => count($batch) * count($locales),
            'cursor_before' => $cursor,
            'cursor_after' => $nextCursor,
            'remaining_slugs' => count($slugs) - $nextCursor,
        ];

        $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function slugs(): array
    {
        $slugs = $this->csv((string) $this->option('slugs'));
        $manifest = trim((string) $this->option('manifest'));
        if ($manifest !== '') {
            if (! is_file($manifest)) {
                throw new \RuntimeException('Career detail warm manifest not found.');
            }
            $payload = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            foreach ((array) ($payload['slugs'] ?? []) as $slug) {
                $slugs[] = (string) $slug;
            }
            foreach ((array) ($payload['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $slugs[] = (string) ($item['slug'] ?? data_get($item, 'identity.canonical_slug', ''));
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            $slugs,
        ))));
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value),
        ))));
    }
}
