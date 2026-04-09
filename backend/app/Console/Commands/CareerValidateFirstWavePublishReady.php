<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\FirstWavePublishReadyValidator;
use App\Services\Career\Import\FirstWaveAuthorityMaterializationService;
use Illuminate\Console\Command;

final class CareerValidateFirstWavePublishReady extends Command
{
    protected $signature = 'career:validate-first-wave-publish-ready
        {--source= : Optional dataset source path for first-wave materialization}
        {--blocked-registry= : Optional blocked registry path for first-wave governance}
        {--authority-overrides= : Optional authority override path for first-wave source-code overrides}
        {--json : Emit JSON output}
        {--materialize-missing : Materialize manifest-scoped rows from the provided source}
        {--compile-missing : Compile materialized first-wave rows after import}
        {--repair-safe-partials : Repair safely remediable manifest-scoped identity drift before rematerialization}';

    protected $description = 'Validate first-wave Career occupations as publish-ready, partial, or blocked, with optional first-wave-scoped materialization and compile.';

    public function handle(
        FirstWaveAuthorityMaterializationService $materializationService,
        FirstWavePublishReadyValidator $validator,
    ): int {
        $materialize = (bool) $this->option('materialize-missing');
        $compile = (bool) $this->option('compile-missing');
        $repairSafePartials = (bool) $this->option('repair-safe-partials');
        $source = trim((string) $this->option('source'));
        $blockedRegistryPath = trim((string) $this->option('blocked-registry'));
        $authorityOverridePath = trim((string) $this->option('authority-overrides'));

        if ($compile) {
            $materialize = true;
        }

        if ($materialize && $source === '') {
            $this->error('--source is required when using --materialize-missing or --compile-missing.');

            return self::FAILURE;
        }

        $materialization = [
            'import_run_id' => null,
            'compile_run_id' => null,
            'imported_slugs' => [],
            'issues_by_slug' => [],
        ];

        if ($materialize) {
            $materialization = $materializationService->materialize(
                $source,
                $compile,
                $repairSafePartials,
                $authorityOverridePath !== '' ? $authorityOverridePath : null,
            );
        }

        $report = $validator->validate(
            $materialization['issues_by_slug'],
            $blockedRegistryPath !== '' ? $blockedRegistryPath : null,
            $authorityOverridePath !== '' ? $authorityOverridePath : null,
        );
        $report['materialization'] = [
            'import_run_id' => $materialization['import_run_id'],
            'compile_run_id' => $materialization['compile_run_id'],
            'imported_slugs' => $materialization['imported_slugs'],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('wave_name='.$report['wave_name']);
        $this->line('publish_ready='.$report['counts']['publish_ready']);
        $this->line('partial='.$report['counts']['partial']);
        $this->line('blocked='.$report['counts']['blocked']);
        $this->line('import_run_id='.(string) ($materialization['import_run_id'] ?? ''));
        $this->line('compile_run_id='.(string) ($materialization['compile_run_id'] ?? ''));

        foreach ($report['occupations'] as $occupation) {
            $this->line(sprintf(
                '%s status=%s missing=%s',
                (string) $occupation['canonical_slug'],
                (string) $occupation['status'],
                implode(',', (array) $occupation['missing_requirements'])
            ));
        }

        return self::SUCCESS;
    }
}
