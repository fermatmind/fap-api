<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\ScienceContentPageDraftImportService;
use Illuminate\Console\Command;
use Throwable;

final class ScienceContentPageImportDraftsCommand extends Command
{
    protected $signature = 'content-pages:science-import-drafts
        {--package= : Path to the Science ContentPage CMS draft package directory}
        {--dry-run : Validate and plan without writing to the database}
        {--execute : Import missing Science ContentPage rows as non-public drafts}
        {--approval-phrase= : Required exact phrase when --execute is used}
        {--json : Emit machine-readable JSON only}';

    protected $description = 'Controlled Science ContentPage draft import command; defaults to no-write dry-run.';

    public function handle(ScienceContentPageDraftImportService $service): int
    {
        try {
            $execute = (bool) $this->option('execute');
            $dryRun = (bool) $this->option('dry-run');
            if ($execute && $dryRun) {
                return $this->finish([
                    'ok' => false,
                    'command' => ScienceContentPageDraftImportService::COMMAND,
                    'mode' => 'invalid_options',
                    'dry_run' => true,
                    'writes_committed' => false,
                    'errors' => [[
                        'field' => 'options',
                        'code' => 'execute_dry_run_conflict',
                        'message' => '--execute and --dry-run cannot be combined.',
                    ]],
                ]);
            }

            $package = trim((string) $this->option('package'));
            if ($package === '') {
                throw new \RuntimeException('--package is required.');
            }

            $payload = $execute
                ? $service->execute($package, (string) $this->option('approval-phrase'))
                : $service->dryRun($package);

            return $this->finish($payload);
        } catch (Throwable $throwable) {
            return $this->finish([
                'ok' => false,
                'command' => ScienceContentPageDraftImportService::COMMAND,
                'mode' => 'runtime_error',
                'dry_run' => true,
                'writes_committed' => false,
                'errors' => [[
                    'field' => 'command',
                    'code' => 'runtime_error',
                    'message' => $throwable->getMessage(),
                ]],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
        }

        $this->line('ok='.(($payload['ok'] ?? false) ? '1' : '0'));
        $this->line('mode='.(string) ($payload['mode'] ?? 'Unknown'));
        $this->line('dry_run='.(($payload['dry_run'] ?? true) ? '1' : '0'));
        $this->line('writes_committed='.(($payload['writes_committed'] ?? false) ? '1' : '0'));
        $this->line('pages_seen='.(string) ($payload['pages_seen'] ?? 0));
        $this->line('planned_create_count='.(string) ($payload['planned_create_count'] ?? 0));
        $this->line('skipped_existing_count='.(string) ($payload['skipped_existing_count'] ?? 0));
        $this->line('authority_revision_only_count='.(string) ($payload['authority_revision_only_count'] ?? 0));
        $this->line('blocked_count='.(string) ($payload['blocked_count'] ?? 0));
        $this->line('created_count='.(string) ($payload['created_count'] ?? 0));
        $this->line('publish_allowed='.(($payload['publish_allowed'] ?? false) ? '1' : '0'));
        $this->line('discoverability_allowed='.(($payload['discoverability_allowed'] ?? false) ? '1' : '0'));

        foreach (($payload['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $this->line(sprintf(
                    'error field=%s code=%s message=%s',
                    (string) ($error['field'] ?? 'Unknown'),
                    (string) ($error['code'] ?? 'Unknown'),
                    (string) ($error['message'] ?? 'Unknown'),
                ));
            }
        }

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
