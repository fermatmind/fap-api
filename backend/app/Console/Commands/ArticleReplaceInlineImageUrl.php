<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\ArticleInlineImageUrlReplacer;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ArticleReplaceInlineImageUrl extends Command
{
    protected $signature = 'articles:replace-inline-image-url
        {--article-ids= : Comma-separated article IDs to lock and update}
        {--translation-group-id= : Expected translation_group_id lock}
        {--old-url= : Exact inline image URL or URL fragment to replace}
        {--new-url= : Exact inline image URL or URL fragment to write}
        {--dry-run : Validate and plan without writing DB rows}
        {--execute : Apply the inline image URL replacement; omitted by default for dry-run safety}
        {--json : Emit a JSON summary}
        {--no-publish : Required execute-mode hold: do not publish}
        {--no-schema : Required execute-mode hold: do not modify schema gates}
        {--no-hreflang : Required execute-mode hold: do not modify hreflang gates}
        {--no-search : Required execute-mode hold: do not submit search channels}
        {--no-sitemap-llms-change : Required execute-mode hold: do not modify sitemap/llms eligibility}';

    protected $description = 'Safely replace one inline Markdown image URL in locked published CMS articles.';

    public function handle(ArticleInlineImageUrlReplacer $replacer): int
    {
        try {
            $summary = $replacer->run($this->optionsPayload());
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
        return [
            'article_ids' => (string) $this->option('article-ids'),
            'translation_group_id' => (string) $this->option('translation-group-id'),
            'old_url' => (string) $this->option('old-url'),
            'new_url' => (string) $this->option('new-url'),
            'dry_run' => (bool) $this->option('dry-run'),
            'execute' => (bool) $this->option('execute'),
            'json' => (bool) $this->option('json'),
            'no_publish' => (bool) $this->option('no-publish'),
            'no_schema' => (bool) $this->option('no-schema'),
            'no_hreflang' => (bool) $this->option('no-hreflang'),
            'no_search' => (bool) $this->option('no-search'),
            'no_sitemap_llms_change' => (bool) $this->option('no-sitemap-llms-change'),
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
        $this->line('translation_group_id='.(string) ($summary['translation_group_id'] ?? ''));
        $this->line('article_ids='.implode(',', array_map('strval', (array) ($summary['article_ids'] ?? []))));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $this->line('validation_error='.$this->issueLine($error));
            }
        }
    }

    /**
     * @param  array<string,mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        return sprintf(
            '%s:%s:%s',
            (string) ($issue['field'] ?? 'unknown'),
            (string) ($issue['code'] ?? 'unknown'),
            (string) ($issue['message'] ?? '')
        );
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
            'article_ids' => [],
            'translation_group_id' => (string) $this->option('translation-group-id'),
            'old_url' => (string) $this->option('old-url'),
            'new_url' => (string) $this->option('new-url'),
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
