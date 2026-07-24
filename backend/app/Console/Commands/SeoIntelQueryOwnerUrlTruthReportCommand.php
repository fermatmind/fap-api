<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\QueryOwnerUrlTruthReadModel;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class SeoIntelQueryOwnerUrlTruthReportCommand extends Command
{
    protected $signature = 'seo-intel:query-owner-url-truth-report
        {--family= : Optional backend query-family key}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read the backend-authoritative query-owner URL Truth contract and fail closed on conflicts.';

    public function handle(QueryOwnerUrlTruthReadModel $readModel): int
    {
        try {
            $report = $readModel->report($this->familyKey());
        } catch (Throwable) {
            $report = [
                'schema_version' => QueryOwnerUrlTruthReadModel::SCHEMA_VERSION,
                'task' => QueryOwnerUrlTruthReadModel::TASK,
                'ok' => false,
                'status' => 'blocked',
                'issues' => ['query_owner_read_failed'],
                'read_only' => true,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $this->line('status='.(string) ($report['status'] ?? 'blocked'));
            $this->line('family_count='.(string) ($report['family_count'] ?? 0));
            $this->line('conflict_count='.(string) ($report['conflict_count'] ?? 0));
            $this->line('hold_count='.(string) ($report['hold_count'] ?? 0));
            $this->line('private_binding_exclusion_count='.(string) (
                $report['private_binding_exclusion_count'] ?? 0
            ));
        }

        return ($report['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function familyKey(): ?string
    {
        $value = trim((string) $this->option('family'));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $value) !== 1) {
            throw new InvalidArgumentException('query_family_key_invalid');
        }

        return $value;
    }
}
