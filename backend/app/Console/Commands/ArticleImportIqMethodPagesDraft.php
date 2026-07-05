<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\IqMethodPages\IqMethodPagesDraftImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ArticleImportIqMethodPagesDraft extends Command
{
    protected $signature = 'articles:import-iq-method-pages-draft
        {--package= : Path to fap-web root, generated/iq-method-pages-zh-cn-v0.2, or its cms-dry-run directory}
        {--dry-run : Validate and plan without writing to the database}
        {--json : Emit a JSON summary}';

    protected $description = 'Validate and import the zh-CN IQ method pages CMS dry-run package as draft-only CMS records.';

    public function handle(IqMethodPagesDraftImporter $importer): int
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
     * @return array<string,mixed>
     */
    private function optionsPayload(): array
    {
        return [
            'package' => (string) $this->option('package'),
            'dry_run' => (bool) $this->option('dry-run'),
            'json' => (bool) $this->option('json'),
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
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));

        foreach ((array) ($summary['articles'] ?? []) as $article) {
            if (! is_array($article)) {
                continue;
            }

            $this->line(sprintf(
                'article=%s:%s:%s:article_id=%s:working_revision_id=%s',
                (string) ($article['locale'] ?? ''),
                (string) ($article['slug'] ?? ''),
                (string) ($article['action'] ?? ''),
                (string) ($article['article_id'] ?? ''),
                (string) ($article['working_revision_id'] ?? '')
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
