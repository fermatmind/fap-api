<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\ArticleWeeklySeoObservationExportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ArticleWeeklySeoObservationExport extends Command
{
    protected $signature = 'articles:weekly-seo-observation-export
        {--article-ids= : Optional comma-separated article ids; omitted means recent published public indexable articles}
        {--expected-slugs= : Optional comma-separated slug locks matching --article-ids order}
        {--from= : Inclusive start date (Y-m-d); defaults to --days window}
        {--to= : Inclusive end date (Y-m-d); defaults to today}
        {--days=14 : Days back when --from is omitted}
        {--locale= : Optional exact locale filter, for example zh-CN or en-US}
        {--limit=25 : Max recent articles when --article-ids is omitted}
        {--json : Emit JSON}';

    protected $description = 'Read-only weekly SEO observation export for article release closeout, GSC, and site conversion metrics.';

    public function handle(ArticleWeeklySeoObservationExportService $exporter): int
    {
        $articleIds = $this->parseIdList((string) $this->option('article-ids'));
        $expectedSlugs = $this->parseStringList((string) $this->option('expected-slugs'));

        if ($expectedSlugs !== [] && $articleIds === []) {
            $this->error('--expected-slugs requires --article-ids.');

            return self::FAILURE;
        }

        if ($expectedSlugs !== [] && count($expectedSlugs) !== count($articleIds)) {
            $this->error('--expected-slugs count must match --article-ids count.');

            return self::FAILURE;
        }

        $to = $this->parseDate((string) $this->option('to'), now()->toDateString());
        $from = $this->parseFromDate((string) $this->option('from'), $to, (int) $this->option('days'));
        if ($from->greaterThan($to)) {
            $this->error('The --from date must be on or before --to.');

            return self::FAILURE;
        }

        $slugLocks = [];
        foreach ($articleIds as $index => $articleId) {
            if (isset($expectedSlugs[$index])) {
                $slugLocks[$articleId] = $expectedSlugs[$index];
            }
        }

        $payload = $exporter->export(
            articleIds: $articleIds,
            expectedSlugsById: $slugLocks,
            from: $from,
            to: $to,
            locale: trim((string) $this->option('locale')),
            limit: (int) $this->option('limit'),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $summary = (array) ($payload['summary'] ?? []);
            $this->info('weekly SEO observation export complete');
            $this->line('article_count='.(string) ($summary['article_count'] ?? 0));
            $this->line('gsc_clicks='.(string) ($summary['gsc_clicks'] ?? 0));
            $this->line('gsc_impressions='.(string) ($summary['gsc_impressions'] ?? 0));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function parseIdList(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): int => max(0, (int) trim($item)),
            explode(',', $value)
        ), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<string>
     */
    private function parseStringList(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        ), static fn (string $item): bool => $item !== ''));
    }

    private function parseFromDate(string $value, CarbonImmutable $to, int $days): CarbonImmutable
    {
        if (trim($value) !== '') {
            return $this->parseDate($value, $to->toDateString());
        }

        return $to->subDays(max(1, $days) - 1)->startOfDay();
    }

    private function parseDate(string $value, string $fallback): CarbonImmutable
    {
        $candidate = trim($value) !== '' ? trim($value) : $fallback;

        return CarbonImmutable::createFromFormat('Y-m-d', $candidate)?->startOfDay()
            ?? CarbonImmutable::parse($candidate)->startOfDay();
    }
}
