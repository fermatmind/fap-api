<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ArticleSeoObservationPlan extends Command
{
    protected $signature = 'articles:seo-observation-plan
        {--article-ids= : Required comma-separated published article ids}
        {--package-id= : Required Mode C package id}
        {--output-dir= : Required artifact output directory}
        {--evidence-json= : Optional approved observation/export evidence JSON}
        {--target-queries= : Optional comma-separated target queries}
        {--primary-cta= : Optional primary CTA href}
        {--content-id= : Optional CTA content_id}
        {--json : Emit JSON}';

    protected $description = 'Generate artifact-only D1/D7/D14 SEO observation due records and recommendations.';

    public function handle(): int
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('article-ids')))));
        $packageId = trim((string) $this->option('package-id'));
        $outputDir = trim((string) $this->option('output-dir'));
        if ($ids === [] || $packageId === '' || $outputDir === '' || str_contains($outputDir, "\0")) {
            return $this->finish(['ok' => false, 'status' => 'blocked_invalid_arguments']);
        }

        $articles = Article::query()->withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get();
        if ($articles->count() !== count(array_unique($ids))) {
            return $this->finish(['ok' => false, 'status' => 'blocked_article_identity']);
        }

        $evidence = $this->evidence();
        $queries = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('target-queries')))));
        $rows = [];
        foreach ($articles as $article) {
            $publishedAt = CarbonImmutable::parse($article->published_at ?? $article->created_at);
            $canonical = 'https://fermatmind.com/'.($article->locale === 'zh-CN' ? 'zh' : 'en').'/articles/'.$article->slug;
            $windows = [];
            foreach ([1, 7, 14] as $day) {
                $key = 'd'.$day;
                $metrics = data_get($evidence, 'articles.'.(string) $article->id.'.'.$key);
                $windows[$key] = [
                    'due_at' => $publishedAt->addDays($day)->toIso8601String(),
                    'state' => now()->greaterThanOrEqualTo($publishedAt->addDays($day)) ? 'due' : 'pending_window',
                    'metrics' => is_array($metrics) ? $metrics : null,
                    'recommendation' => $this->recommendation(is_array($metrics) ? $metrics : null),
                ];
            }
            $seo = $article->seoMeta()->withoutGlobalScopes()->first();
            $rows[] = [
                'article_id' => (int) $article->id, 'locale' => (string) $article->locale, 'slug' => (string) $article->slug,
                'canonical_url' => $canonical, 'translation_group_id' => (string) $article->translation_group_id,
                'published_at' => $publishedAt->toIso8601String(), 'target_queries' => $queries,
                'primary_cta' => ['href' => (string) $this->option('primary-cta'), 'content_id' => (string) $this->option('content-id')],
                'expected_events' => ['article_to_test_click', 'start_test'],
                'baseline' => ['title' => (string) $article->title, 'meta_description' => (string) ($seo?->meta_description ?? ''), 'internal_links' => $this->links((string) $article->content_md), 'faq' => data_get($seo?->schema_json, 'answer_surface_v1.faq_items', [])],
                'windows' => $windows,
            ];
        }

        $payload = [
            'schema_version' => 'article-seo-observation-plan.v1', 'ok' => true, 'status' => 'planned', 'read_only' => true,
            'package_id' => $packageId, 'generated_at' => now()->toIso8601String(), 'articles' => $rows,
            'evidence_source' => $this->evidenceRef(),
            'negative_guarantees' => ['no_cms_write', 'no_title_meta_change', 'no_faq_change', 'no_internal_link_change', 'no_search_action'],
        ];
        $dir = str_starts_with($outputDir, '/') ? $outputDir : base_path($outputDir);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/article-seo-observation-plan.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/article-seo-observation-plan.md', $this->markdown($payload));
        $payload['artifacts'] = ['json' => $dir.'/article-seo-observation-plan.json', 'markdown' => $dir.'/article-seo-observation-plan.md'];

        return $this->finish($payload);
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        $path = trim((string) $this->option('evidence-json'));
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,string|null> */
    private function evidenceRef(): array
    {
        $path = trim((string) $this->option('evidence-json'));

        return ['path' => $path !== '' ? $path : null, 'sha256' => $path !== '' && is_file($path) ? hash_file('sha256', $path) : null];
    }

    /** @param array<string,mixed>|null $metrics */
    private function recommendation(?array $metrics): string
    {
        if ($metrics === null || ! array_key_exists('impressions', $metrics) || ! array_key_exists('clicks', $metrics)) {
            return 'HOLD_INSUFFICIENT_DATA';
        }
        if (($metrics['near_duplicate'] ?? false) === true) {
            return 'STOP_NEAR_DUPLICATE_EXPANSION';
        }
        if ((int) $metrics['impressions'] >= 50 && (float) ($metrics['ctr'] ?? 0) < 0.01) {
            return 'TITLE_META_REVIEW';
        }
        if ((int) $metrics['impressions'] < 10) {
            return 'INTERNAL_LINK_REVIEW';
        }
        if ((int) ($metrics['cta_click'] ?? 0) === 0 && (int) $metrics['clicks'] > 0) {
            return 'CTA_REVIEW';
        }
        if (($metrics['query_gap'] ?? false) === true) {
            return 'QUERY_EXPANSION_REVIEW';
        }
        if (($metrics['faq_gap'] ?? false) === true) {
            return 'FAQ_REVIEW';
        }

        return 'KEEP';
    }

    /** @return list<string> */
    private function links(string $markdown): array
    {
        preg_match_all('/\]\((https:\/\/fermatmind\.com\/[^)]+|\/(?:zh|en)\/[^)]+)\)/', $markdown, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** @param array<string,mixed> $payload */
    private function markdown(array $payload): string
    {
        $lines = ['# Article SEO Observation Plan', '', 'Package: `'.$payload['package_id'].'`', ''];
        foreach ($payload['articles'] as $article) {
            $lines[] = '## '.$article['locale'].' '.$article['slug'];
            foreach ($article['windows'] as $window => $record) {
                $lines[] = '- '.strtoupper($window).': `'.$record['recommendation'].'` (due '.$record['due_at'].')';
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line((string) ($payload['status'] ?? 'blocked'));
        }

        return ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
