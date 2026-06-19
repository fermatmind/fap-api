<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Support;

use App\Models\Article;
use App\Services\Cms\ArticleReleaseCloseoutService;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Throwable;

final class ArticleSeoReleaseStatus
{
    public static function render(?Article $record): Htmlable
    {
        if (! $record instanceof Article || ! $record->getKey()) {
            return new HtmlString('');
        }

        $payload = self::closeoutPayload($record);

        return new HtmlString((string) view('filament.ops.articles.partials.seo-release-status', [
            'decision' => (string) ($payload['decision'] ?? ArticleReleaseCloseoutService::BLOCKED_OPERATOR_INPUT),
            'ok' => (bool) ($payload['ok'] ?? false),
            'canonicalUrl' => $payload['canonical_url'] ?? null,
            'command' => self::commandFor($record),
            'checks' => self::checks($payload),
            'issues' => (array) ($payload['issues'] ?? []),
        ])->render());
    }

    /**
     * @return array<string,mixed>
     */
    private static function closeoutPayload(Article $record): array
    {
        try {
            return app(ArticleReleaseCloseoutService::class)->inspect(
                articleId: (int) $record->getKey(),
                expectedSlug: (string) $record->slug,
            );
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'decision' => ArticleReleaseCloseoutService::BLOCKED_OPERATOR_INPUT,
                'canonical_url' => null,
                'checks' => [],
                'issues' => [[
                    'field' => 'article_release_closeout',
                    'code' => 'release_closeout_unavailable',
                    'message' => 'Article release closeout service could not read all required status sources.',
                    'context' => [
                        'exception' => $exception::class,
                    ],
                ]],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array{key:string,label:string,state:string,summary:string,issues:int}>
     */
    private static function checks(array $payload): array
    {
        $checks = (array) ($payload['checks'] ?? []);

        return [
            self::check('article', 'Content', $checks),
            self::check('seo_meta', 'Title, meta, canonical, robots', $checks),
            self::check('media', 'Cover, OG image, body visuals', $checks),
            self::check('taxonomy', 'Category and tags', $checks),
            self::check('discoverability', 'Sitemap, llms, llms-full eligibility', $checks),
            self::check('url_truth', 'URL Truth', $checks),
            self::check('search_channel', 'IndexNow and Baidu queue', $checks),
            self::check('schema_hreflang', 'Schema and hreflang gates', $checks),
            self::check('public_html_smoke', 'Public HTML smoke', $checks, nullableOkState: 'manual'),
            self::check('gsc_manual', 'GSC manual request', $checks, nullableOkState: 'manual'),
            self::check('observation', 'D1 / D7 / D14 observation', $checks, nullableOkState: 'manual'),
        ];
    }

    /**
     * @param  array<string,mixed>  $checks
     * @return array{key:string,label:string,state:string,summary:string,issues:int}
     */
    private static function check(string $key, string $label, array $checks, string $nullableOkState = 'hold'): array
    {
        $check = (array) ($checks[$key] ?? []);
        $ok = $check['ok'] ?? false;
        $issues = (array) ($check['issues'] ?? []);
        $state = match ($ok) {
            true => 'success',
            null => $nullableOkState,
            default => 'danger',
        };

        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'summary' => self::summary($key, $check),
            'issues' => count($issues),
        ];
    }

    /**
     * @param  array<string,mixed>  $check
     */
    private static function summary(string $key, array $check): string
    {
        return match ($key) {
            'article' => sprintf(
                '%s / public=%s / indexable=%s',
                (string) ($check['status'] ?? 'unknown'),
                self::yesNo($check['is_public'] ?? null),
                self::yesNo($check['is_indexable'] ?? null),
            ),
            'seo_meta' => sprintf(
                'canonical=%s / robots=%s',
                (string) ($check['canonical_path'] ?? 'unknown'),
                (string) ($check['robots'] ?? 'unknown'),
            ),
            'media' => sprintf('body visuals=%d', count((array) ($check['body_visual_urls'] ?? []))),
            'taxonomy' => (string) data_get($check, 'category.name', 'category missing'),
            'discoverability' => sprintf(
                'sitemap=%s / llms=%s / full=%s',
                self::yesNo($check['sitemap_eligible'] ?? null),
                self::yesNo($check['llms_eligible'] ?? null),
                self::yesNo($check['llms_full_source_eligible'] ?? null),
            ),
            'url_truth' => self::yesNo($check['present'] ?? null),
            'search_channel' => self::searchChannelSummary((array) ($check['channels'] ?? [])),
            'schema_hreflang' => sprintf(
                'Article=%s / Breadcrumb=%s / FAQ=%s',
                self::yesNo($check['article_schema_enabled'] ?? null),
                self::yesNo($check['breadcrumb_schema_enabled'] ?? null),
                self::yesNo($check['faq_schema_enabled'] ?? null),
            ),
            'public_html_smoke', 'gsc_manual', 'observation' => (string) ($check['state'] ?? 'not recorded'),
            default => 'not checked',
        };
    }

    /**
     * @param  array<string,array<string,mixed>>  $channels
     */
    private static function searchChannelSummary(array $channels): string
    {
        $parts = [];
        foreach (['indexnow', 'baidu_push'] as $channel) {
            $row = (array) ($channels[$channel] ?? []);
            $parts[] = $channel.'#'.(string) ($row['queue_item_id'] ?? 'missing').':'.(string) ($row['execution_state'] ?? 'missing');
        }

        return implode(' / ', $parts);
    }

    private static function yesNo(mixed $value): string
    {
        return match ($value) {
            true => 'yes',
            false => 'no',
            default => 'unknown',
        };
    }

    private static function commandFor(Article $record): string
    {
        return sprintf(
            'php artisan articles:release-closeout --article-id=%d --expected-slug=%s --json --no-ansi',
            (int) $record->getKey(),
            escapeshellarg((string) $record->slug),
        );
    }
}
