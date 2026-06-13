<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\SeoContentPackage\SeoContentPackageExistingArticleUpdater;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ArticleUpdateExistingSeoContentPackage extends Command
{
    protected $signature = 'articles:update-existing-seo-content-package
        {--package= : Path to a GPT-5.5 Pro Mode C existing-article update package directory}
        {--article-id= : Exact existing article id to update}
        {--translation-group-id= : Expected translation_group_id}
        {--locale=zh-CN : Expected locale}
        {--expected-slug= : Locked existing article slug}
        {--expected-canonical= : Locked existing canonical route}
        {--dry-run : Validate and plan without writing to the database}
        {--execute : Apply the working revision update}
        {--json : Emit a JSON summary}
        {--slug-lock : Explicitly preserve the current slug}
        {--canonical-lock : Explicitly preserve the current canonical}
        {--schema-hold : Explicitly hold schema output}
        {--hreflang-hold : Explicitly hold hreflang output}
        {--search-hold : Explicitly hold search submission}
        {--no-revalidation : Explicitly forbid revalidation}
        {--no-sitemap : Explicitly forbid sitemap changes}
        {--no-llms : Explicitly forbid llms changes}';

    protected $description = 'Validate and update an existing published article working revision from a controlled SEO content package.';

    public function handle(SeoContentPackageExistingArticleUpdater $updater): int
    {
        $execute = (bool) $this->option('execute');

        try {
            $summary = $execute
                ? $updater->updateWorkingRevisionFromDirectory($this->optionsPayload())
                : $updater->planFromDirectory($this->optionsPayload());
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function optionsPayload(): array
    {
        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run') || ! $execute;

        return [
            'package' => (string) $this->option('package'),
            'article_id' => (int) $this->option('article-id'),
            'translation_group_id' => (string) $this->option('translation-group-id'),
            'locale' => (string) $this->option('locale'),
            'expected_slug' => (string) $this->option('expected-slug'),
            'expected_canonical' => (string) $this->option('expected-canonical'),
            'dry_run' => $dryRun,
            'execute' => $execute,
            'json' => (bool) $this->option('json'),
            'slug_lock' => (bool) $this->option('slug-lock'),
            'canonical_lock' => (bool) $this->option('canonical-lock'),
            'schema_hold' => (bool) $this->option('schema-hold'),
            'hreflang_hold' => (bool) $this->option('hreflang-hold'),
            'search_hold' => (bool) $this->option('search-hold'),
            'no_revalidation' => (bool) $this->option('no-revalidation'),
            'no_sitemap' => (bool) $this->option('no-sitemap'),
            'no_llms' => (bool) $this->option('no-llms'),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('action='.(string) ($summary['action'] ?? 'will_skip'));
        $this->line('would_write='.(($summary['would_write'] ?? false) ? '1' : '0'));
        $this->line('article_id='.(string) ($summary['article_id'] ?? ''));
        $this->line('translation_group_id='.(string) ($summary['translation_group_id'] ?? ''));
        $this->line('slug_lock='.(string) ($summary['slug_lock'] ?? ''));
        $this->line('canonical_lock='.(string) ($summary['canonical_lock'] ?? ''));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));

        foreach ((array) ($summary['articles'] ?? []) as $article) {
            if (! is_array($article)) {
                continue;
            }

            $this->line(sprintf(
                'article=%s:%s:%s:article_id=%s:working_revision_id=%s:published_revision_id=%s:preview=%s',
                (string) ($article['locale'] ?? ''),
                (string) ($article['slug'] ?? ''),
                (string) ($article['action'] ?? ''),
                (string) ($article['article_id'] ?? ''),
                (string) ($article['working_revision_id'] ?? ''),
                (string) ($article['published_revision_id'] ?? ''),
                (string) ($article['preview_url_candidate'] ?? '')
            ));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $this->line('validation_error='.$this->issueLine($error));
            }
        }
        foreach ((array) ($summary['warnings'] ?? []) as $warning) {
            if (is_array($warning)) {
                $this->line('validation_warning='.$this->issueLine($warning));
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'ok' => false,
            'dry_run' => ! (bool) $this->option('execute'),
            'action' => 'will_skip',
            'would_write' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
            'articles' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        return implode(':', [
            (string) ($issue['field'] ?? 'unknown'),
            (string) ($issue['code'] ?? 'unknown'),
            (string) ($issue['message'] ?? ''),
        ]);
    }
}
