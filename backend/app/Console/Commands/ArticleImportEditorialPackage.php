<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\EditorialPackage\EditorialPackageDraftImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ArticleImportEditorialPackage extends Command
{
    protected $signature = 'articles:import-editorial-package
        {--file= : Path to a CMS-ready editorial package JSON file}
        {--locale= : Override the package locale}
        {--dry-run : Validate and plan without writing to the database}
        {--allow-claim-warnings : Allow claim linter warnings, but import only as draft}
        {--json : Emit a JSON summary}';

    protected $description = 'Validate a CMS-ready editorial package and import it as a non-public CMS article draft.';

    public function handle(EditorialPackageDraftImporter $importer): int
    {
        $file = (string) $this->option('file');
        $locale = $this->option('locale');
        $localeOverride = is_string($locale) && trim($locale) !== '' ? trim($locale) : null;
        $dryRun = (bool) $this->option('dry-run');
        $allowClaimWarnings = (bool) $this->option('allow-claim-warnings');

        try {
            $summary = $dryRun
                ? $importer->planFromFile($file, $localeOverride, $allowClaimWarnings)
                : $importer->importFromFile($file, $localeOverride, $allowClaimWarnings);
        } catch (RuntimeException $exception) {
            $summary = [
                'ok' => false,
                'action' => 'will_skip',
                'errors' => [[
                    'field' => 'file',
                    'code' => 'runtime_error',
                    'message' => $exception->getMessage(),
                ]],
                'warnings' => [],
                'claim_matches' => [],
            ];
        } catch (Throwable $exception) {
            $summary = [
                'ok' => false,
                'action' => 'will_skip',
                'errors' => [[
                    'field' => 'command',
                    'code' => 'unexpected_error',
                    'message' => $exception->getMessage(),
                ]],
                'warnings' => [],
                'claim_matches' => [],
            ];
        }

        $this->emitSummary($summary, $dryRun);

        return ($summary['errors'] ?? []) === [] && ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function emitSummary(array $summary, bool $dryRun): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $package = is_array($summary['package'] ?? null) ? $summary['package'] : [];
        $action = (string) ($summary['action'] ?? 'will_skip');

        $this->line('dry_run='.($dryRun ? '1' : '0'));
        $this->line('action='.$action);
        $this->line('will_create='.($action === 'will_create' ? '1' : '0'));
        $this->line('will_update='.($action === 'will_update' ? '1' : '0'));
        $this->line('will_skip='.($action === 'will_skip' ? '1' : '0'));
        $this->line('content_track='.(string) ($package['content_track'] ?? ''));
        $this->line('decision_domains='.implode(',', $this->stringList($package['decision_domains'] ?? [])));
        $this->line('target_tests='.implode(',', $this->stringList($package['target_tests'] ?? [])));
        $this->line('target_topics='.implode(',', $this->stringList($package['target_topics'] ?? [])));
        $this->line('references_count='.(string) ($summary['references_count'] ?? 0));
        $this->line('body_hash='.(string) ($summary['body_hash'] ?? ''));
        $this->line('answer_surface_hash='.(string) ($summary['answer_surface_hash'] ?? ''));
        $this->line('existing_article_id='.(string) ($summary['existing_article_id'] ?? ''));
        $this->line('would_write='.(($summary['would_write'] ?? false) ? '1' : '0'));
        if (isset($summary['article_id'])) {
            $this->line('article_id='.(string) $summary['article_id']);
        }
        if (isset($summary['working_revision_id'])) {
            $this->line('working_revision_id='.(string) $summary['working_revision_id']);
        }
        if (isset($summary['working_revision_status'])) {
            $this->line('working_revision_status='.(string) $summary['working_revision_status']);
        }
        $this->line('published_revision_id='.(string) ($summary['published_revision_id'] ?? ''));
        $this->line('errors_count='.(string) count($summary['errors'] ?? []));
        $this->line('warnings_count='.(string) count($summary['warnings'] ?? []));
        $this->line('claim_matches_count='.(string) count($summary['claim_matches'] ?? []));

        foreach (($summary['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $this->line('validation_error='.$this->issueLine($error));
            }
        }
        foreach (($summary['warnings'] ?? []) as $warning) {
            if (is_array($warning)) {
                $prefix = ($warning['code'] ?? '') === 'claim_boundary_forbidden_phrase'
                    ? 'claim_warning='
                    : 'validation_warning=';
                $this->line($prefix.$this->issueLine($warning));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        $parts = [
            (string) ($issue['field'] ?? ''),
            (string) ($issue['code'] ?? ''),
            (string) ($issue['message'] ?? ''),
        ];

        if (isset($issue['phrase'])) {
            $parts[] = 'phrase='.(string) $issue['phrase'];
        }
        if (isset($issue['suggested_replacement'])) {
            $parts[] = 'suggested_replacement='.(string) $issue['suggested_replacement'];
        }

        return implode(':', $parts);
    }
}
