<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Ops\Support\ContentReleaseFollowUp;
use App\Models\Article;
use App\Services\Cms\ContentReleasePathPlanner;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

final class ContentReleaseRevalidate extends Command
{
    protected $signature = 'content-release:revalidate
        {--type=article : Content type to revalidate}
        {--article-id= : Article id when --type=article}
        {--source=manual_revalidate : Safe audit/source label}
        {--dry-run : Plan paths without posting to configured revalidation endpoints}
        {--execute : Dispatch configured frontend revalidation endpoints}
        {--json : Emit safe machine-readable JSON}';

    protected $description = 'Safely plan or dispatch content-release revalidation without exposing revalidation tokens.';

    public function handle(ContentReleasePathPlanner $pathPlanner): int
    {
        $summary = $this->summary($pathPlanner);
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(ContentReleasePathPlanner $pathPlanner): array
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $type = trim((string) $this->option('type'));
        $articleId = (int) $this->option('article-id');
        $issues = [];

        if ((bool) $this->option('dry-run') && $execute) {
            $issues[] = 'execute_dry_run_conflict';
        }

        if ($type !== 'article') {
            $issues[] = 'unsupported_type';
        }

        if ($articleId <= 0) {
            $issues[] = 'article_id_required';
        }

        $article = null;
        if ($type === 'article' && $articleId > 0) {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes()])
                ->find($articleId);

            if (! $article instanceof Article) {
                $issues[] = 'article_not_found';
            }
        }

        $paths = $article instanceof Article ? $pathPlanner->paths('article', $article) : [];
        $endpoints = $this->cacheInvalidationUrls();
        $tokenPresent = $this->cacheInvalidationSecretPresent();

        if ($execute && $endpoints === []) {
            $issues[] = 'cache_invalidation_urls_missing';
        }

        if ($execute && ! $tokenPresent) {
            $issues[] = 'cache_invalidation_secret_missing';
        }

        $ok = $issues === [];
        $action = $execute ? 'revalidation_dispatched' : 'would_revalidate_content_release_paths';

        if (! $ok) {
            $action = 'will_skip';
        } elseif ($execute && $article instanceof Article) {
            ContentReleaseFollowUp::dispatch(
                'article',
                $article,
                $this->safeSource(),
                Request::create('/ops/content-release/revalidate-command', 'POST')
            );
        }

        return [
            'runtime' => 'content_release_revalidate',
            'status' => $ok ? 'success' : 'blocked',
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'type' => $type,
            'article_id' => $articleId > 0 ? $articleId : null,
            'paths' => $paths,
            'endpoint_count' => count($endpoints),
            'token_present' => $tokenPresent,
            'token_output' => false,
            'external_search_submission_attempted' => false,
            'search_submission_attempted' => false,
            'live_submission_attempted' => false,
            'secrets_read_from_environment_by_operator' => false,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @return list<string>
     */
    private function cacheInvalidationUrls(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('ops.content_release_observability.cache_invalidation_urls', [])
        )));
    }

    private function cacheInvalidationSecretPresent(): bool
    {
        return trim((string) config('ops.content_release_observability.cache_invalidation_secret', '')) !== '';
    }

    private function safeSource(): string
    {
        $source = preg_replace('/[^A-Za-z0-9:_@.-]/', '_', trim((string) $this->option('source'))) ?: 'manual_revalidate';

        return substr($source, 0, 128);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach (['status', 'dry_run', 'action', 'type', 'article_id', 'endpoint_count', 'token_present', 'token_output'] as $key) {
            $value = $summary[$key] ?? null;
            $this->line($key.'='.$this->stringValue($value));
        }
        $this->line('paths='.$this->stringValue($summary['paths'] ?? []));
        $this->line('issues='.$this->stringValue($summary['issues'] ?? []));
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
