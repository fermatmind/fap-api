<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\CompetitorAlternativesSourceLedgerValidator;
use Illuminate\Console\Command;
use Throwable;

final class CompetitorAlternativesSourceLedgerAuditCommand extends Command
{
    protected $signature = 'competitor-alternatives:source-ledger-audit
        {--source=docs/seo/import-packages/competitor-alternatives-source-ledger/competitor_alternatives_source_ledger.v1.json : Source package path relative to backend base path}
        {--strict : Return non-zero when source-ledger validation fails}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only competitor alternatives source-ledger validator.';

    public function handle(CompetitorAlternativesSourceLedgerValidator $validator): int
    {
        try {
            $source = ltrim((string) $this->option('source'), '/');
            $path = base_path($source);

            if (! is_file($path)) {
                $this->emit([
                    'task' => 'FA30-API-10',
                    'status' => 'fail',
                    'ok' => false,
                    'source_package' => $source,
                    'issues' => ['source_package_missing'],
                    'issue_count' => 1,
                    'boundary' => $this->boundary(),
                ]);

                return self::FAILURE;
            }

            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $result = $validator->validate(is_array($payload) ? $payload : []);
            $summary = [
                'task' => 'FA30-API-10',
                'runtime' => 'competitor_alternatives_source_ledger_audit',
                'status' => $result['status'],
                'ok' => $result['ok'],
                'source_package' => $source,
                'entry_count' => data_get($result, 'summary.entry_count', 0),
                'indexable_entry_count' => data_get($result, 'summary.indexable_entry_count', 0),
                'legal_approved_count' => data_get($result, 'summary.legal_approved_count', 0),
                'issue_count' => $result['issue_count'],
                'issues' => $result['issues'],
                'boundary' => $this->boundary(),
            ];

            $this->emit($summary);

            return ((bool) $result['ok'] || ! (bool) $this->option('strict')) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->emit([
                'task' => 'FA30-API-10',
                'status' => 'fail',
                'ok' => false,
                'issues' => ['exception:'.$throwable->getMessage()],
                'issue_count' => 1,
                'boundary' => $this->boundary(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('status='.(string) ($payload['status'] ?? 'fail'));
        $this->line('ok='.(($payload['ok'] ?? false) ? '1' : '0'));
        $this->line('source_package='.(string) ($payload['source_package'] ?? ''));
        $this->line('entry_count='.(string) ($payload['entry_count'] ?? 0));
        $this->line('indexable_entry_count='.(string) ($payload['indexable_entry_count'] ?? 0));
        $this->line('legal_approved_count='.(string) ($payload['legal_approved_count'] ?? 0));
        $this->line('issue_count='.(string) ($payload['issue_count'] ?? 0));

        foreach ((array) ($payload['issues'] ?? []) as $issue) {
            $this->line('issue='.(string) $issue);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function boundary(): array
    {
        return [
            'scrape_performed' => false,
            'external_write_performed' => false,
            'db_write_performed' => false,
            'cms_write_performed' => false,
            'public_api_route_created' => false,
            'frontend_changed' => false,
            'seo_runtime_changed' => false,
            'search_submission_performed' => false,
            'production_deploy_performed' => false,
        ];
    }
}
