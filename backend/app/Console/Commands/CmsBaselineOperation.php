<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

final class CmsBaselineOperation extends Command
{
    public const MODES = [
        'initialization',
        'db-recovery',
        'disaster-recovery',
        'explicit-publish',
    ];

    public const ENVIRONMENTS = [
        'local',
        'testing',
        'staging',
        'production',
    ];

    protected $signature = 'cms:baseline-operation
        {--mode= : Required operation mode: initialization, db-recovery, disaster-recovery, explicit-publish}
        {--environment= : Required target environment: local, testing, staging, production}
        {--apply : Apply database writes; omitted means dry-run}
        {--production-authorization= : Exact production write authorization phrase}
        {--upsert : Update existing records instead of create-missing only}
        {--status=published : Import records as draft or published}
        {--landing-source-dir= : Override the landing surface baseline source directory}
        {--content-source-dir= : Override the content page baseline source directory}';

    protected $description = 'Dry-run or explicitly apply CMS baseline initialization/recovery imports outside ordinary deploys.';

    public function handle(): int
    {
        try {
            $mode = strtolower(trim((string) $this->option('mode')));
            $environment = strtolower(trim((string) $this->option('environment')));
            $apply = (bool) $this->option('apply');

            $this->assertOperationContract($mode, $environment, $apply);

            $common = [
                '--dry-run' => ! $apply,
                '--upsert' => (bool) $this->option('upsert'),
                '--status' => trim((string) $this->option('status')),
            ];
            if ($apply) {
                $common['--operation-mode'] = $mode;
                $common['--environment'] = $environment;
                $common['--operation-authorized'] = true;
                if ($environment === 'production') {
                    $common['--production-authorization'] = trim((string) $this->option('production-authorization'));
                }
            }
            $landing = $common;
            $content = $common;
            $this->addSourceDir($landing, 'landing-source-dir');
            $this->addSourceDir($content, 'content-source-dir');

            $this->line('cms_baseline_operation_mode='.$mode);
            $this->line('cms_baseline_operation_environment='.$environment);
            $this->line('cms_baseline_operation_dry_run='.($apply ? '0' : '1'));

            $landingStatus = $this->call('landing-surfaces:import-local-baseline', $landing);
            if ($landingStatus !== self::SUCCESS) {
                throw new RuntimeException('Landing surface baseline operation failed.');
            }

            $contentStatus = $this->call('content-pages:import-local-baseline', $content);
            if ($contentStatus !== self::SUCCESS) {
                throw new RuntimeException('Content page baseline operation failed.');
            }

            $this->info($apply
                ? 'CMS baseline operation applied through an explicit non-deploy entry point.'
                : 'CMS baseline operation dry-run complete; no database writes were requested.');

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    public static function productionAuthorizationPhrase(string $mode): string
    {
        return sprintf(
            'I explicitly authorize production CMS baseline import for mode %s',
            $mode,
        );
    }

    private function assertOperationContract(string $mode, string $environment, bool $apply): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException('A valid explicit --mode is required.');
        }
        if (! in_array($environment, self::ENVIRONMENTS, true)) {
            throw new RuntimeException('A valid explicit --environment is required.');
        }

        $authorization = trim((string) $this->option('production-authorization'));
        if (! $apply) {
            if ($authorization !== '') {
                throw new RuntimeException('--production-authorization is valid only with --apply.');
            }

            return;
        }

        if ($environment === 'production') {
            $expected = self::productionAuthorizationPhrase($mode);
            if ($authorization === '' || ! hash_equals($expected, $authorization)) {
                throw new RuntimeException('Production apply requires the exact mode-bound --production-authorization phrase.');
            }
        } elseif ($authorization !== '') {
            throw new RuntimeException('--production-authorization must not be supplied outside production.');
        }

        if (! app()->environment($environment)) {
            throw new RuntimeException(sprintf(
                'Apply refused because declared environment %s does not match runtime environment %s.',
                $environment,
                app()->environment(),
            ));
        }
    }

    /** @param array<string, mixed> $arguments */
    private function addSourceDir(array &$arguments, string $option): void
    {
        $sourceDir = trim((string) $this->option($option));
        if ($sourceDir !== '') {
            $arguments['--source-dir'] = $sourceDir;
        }
    }
}
