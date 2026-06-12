<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\SeoContentPackage\SeoContentPackageDraftImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ArticleImportSeoContentPackageDraft extends Command
{
    protected $signature = 'articles:import-seo-content-package-draft
        {--package= : Path to a GPT-5.5 Pro Mode C SEO content package directory}
        {--translation-group-id= : Expected translation_group_id}
        {--locales=zh-CN,en : Comma-separated locale list}
        {--dry-run : Validate and plan without writing to the database}
        {--json : Emit a JSON summary}
        {--draft-only : Explicitly require draft-only writes}
        {--no-publish : Explicitly forbid publish}
        {--no-index : Explicitly forbid indexability}
        {--no-sitemap : Explicitly forbid sitemap eligibility}
        {--no-llms : Explicitly forbid llms eligibility}
        {--schema-hold : Explicitly hold schema output}
        {--hreflang-hold : Explicitly hold hreflang output}
        {--expected-zh-slug= : Expected zh-CN article slug}
        {--expected-en-slug= : Expected en article slug}';

    protected $description = 'Validate and import a Mode C bilingual SEO content package as CMS draft-only articles.';

    public function handle(SeoContentPackageDraftImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $summary = $dryRun
                ? $importer->planFromDirectory($this->optionsPayload())
                : $importer->importFromDirectory($this->optionsPayload());
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsPayload(): array
    {
        $locales = array_values(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', (string) $this->option('locales'))
        ), static fn (string $locale): bool => $locale !== ''));

        return [
            'package' => (string) $this->option('package'),
            'translation_group_id' => (string) $this->option('translation-group-id'),
            'locales' => $locales,
            'dry_run' => (bool) $this->option('dry-run'),
            'json' => (bool) $this->option('json'),
            'draft_only' => (bool) $this->option('draft-only'),
            'no_publish' => (bool) $this->option('no-publish'),
            'no_index' => (bool) $this->option('no-index'),
            'no_sitemap' => (bool) $this->option('no-sitemap'),
            'no_llms' => (bool) $this->option('no-llms'),
            'schema_hold' => (bool) $this->option('schema-hold'),
            'hreflang_hold' => (bool) $this->option('hreflang-hold'),
            'expected_slugs' => [
                'zh-CN' => (string) $this->option('expected-zh-slug'),
                'en' => (string) $this->option('expected-en-slug'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
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
        $this->line('translation_group_id='.(string) ($summary['translation_group_id'] ?? ''));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));

        foreach ((array) ($summary['articles'] ?? []) as $article) {
            if (! is_array($article)) {
                continue;
            }

            $this->line(sprintf(
                'article=%s:%s:%s:article_id=%s:working_revision_id=%s:preview=%s',
                (string) ($article['locale'] ?? ''),
                (string) ($article['slug'] ?? ''),
                (string) ($article['action'] ?? ''),
                (string) ($article['article_id'] ?? ''),
                (string) ($article['working_revision_id'] ?? ''),
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
     * @return array<string, mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'ok' => false,
            'dry_run' => (bool) $this->option('dry-run'),
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
     * @param  array<string, mixed>  $issue
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
