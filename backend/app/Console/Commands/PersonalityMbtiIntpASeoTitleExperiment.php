<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiIntpASeoTitleExperimentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** @review-surface mbti_approval_batch */
final class PersonalityMbtiIntpASeoTitleExperiment extends Command
{
    private const CONFIRMATION_PHRASE = 'I authorize one inactive staging CMS revision for the zh-CN INTP-A seo_title experiment. No production, live SEO metadata, publish, indexability, sitemap, llms, search, or deploy write is authorized.';

    private const WRITE_SAFETY_FLAGS = [
        'draft-only',
        'no-publish',
        'no-indexability-change',
        'no-sitemap',
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:mbti-intp-a-seo-title-experiment
        {--package= : Path to the exact one-record experiment package}
        {--confirm-package-sha256= : Required exact package SHA-256}
        {--target-env= : Must be staging}
        {--operator-approved= : Required exact staging draft confirmation phrase}
        {--dry-run : Validate and plan without database writes}
        {--write : Create or idempotently read back the inactive draft revision}
        {--draft-only : Required with --write}
        {--no-publish : Required with --write}
        {--no-indexability-change : Required with --write}
        {--no-sitemap : Required with --write}
        {--no-llms : Required with --write}
        {--no-search-release : Required with --write}
        {--allow-testing : Permit APP_ENV=testing with sqlite for automated tests only}
        {--json : Emit the full sanitized JSON receipt}
        {--output= : Optional path to write the sanitized JSON receipt}';

    protected $description = 'Create one staging-only inactive INTP-A seo_title experiment revision without changing live authority.';

    public function handle(MbtiIntpASeoTitleExperimentService $service): int
    {
        $outputPath = null;

        try {
            $outputPath = $this->prepareOutputPath();
            $summary = $this->guardedRun($service);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable) {
            $summary = $this->failureSummary(
                'unexpected_error',
                'Unexpected failure; inspect protected runtime logs.',
            );
        }

        if ($outputPath !== null) {
            try {
                $this->writeOutput($summary, $outputPath);
            } catch (Throwable) {
                $summary = $this->failureSummary(
                    'receipt_write_error',
                    'Unable to write the preflighted receipt destination; inspect protected runtime logs.',
                );
            }
        }
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function guardedRun(MbtiIntpASeoTitleExperimentService $service): array
    {
        $dryRun = (bool) $this->option('dry-run');
        $write = (bool) $this->option('write');
        if ($dryRun === $write) {
            throw new RuntimeException('Exactly one of --dry-run or --write is required.');
        }

        $targetEnvironment = strtolower(trim((string) $this->option('target-env')));
        if ($targetEnvironment !== 'staging') {
            throw new RuntimeException('--target-env must be staging.');
        }
        $this->guardRuntimeEnvironment();

        if (trim((string) $this->option('operator-approved')) !== self::CONFIRMATION_PHRASE) {
            throw new RuntimeException('--operator-approved must match the exact staging draft confirmation phrase.');
        }

        if ($write) {
            foreach (self::WRITE_SAFETY_FLAGS as $flag) {
                if (! (bool) $this->option($flag)) {
                    throw new RuntimeException('--'.$flag.' is required with --write.');
                }
            }
        }

        $packagePath = $this->resolvePackagePath();
        $packageRaw = (string) File::get($packagePath);
        $packageSha256 = hash('sha256', $packageRaw);
        $confirmedSha256 = strtolower(trim((string) $this->option('confirm-package-sha256')));
        if (! hash_equals($packageSha256, $confirmedSha256)) {
            throw new RuntimeException('Package SHA-256 mismatch; refusing the experiment.');
        }

        $package = json_decode($packageRaw, true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON object.');
        }

        return $write
            ? $service->write($package, $packageSha256, $targetEnvironment)
            : $service->plan($package, $packageSha256, $targetEnvironment);
    }

    private function guardRuntimeEnvironment(): void
    {
        $appEnvironment = app()->environment();
        if (in_array($appEnvironment, ['production', 'prod'], true)) {
            throw new RuntimeException('Production is not authorized for this command.');
        }

        if ((bool) $this->option('allow-testing')) {
            if ($appEnvironment !== 'testing' || config('database.default') !== 'sqlite') {
                throw new RuntimeException('--allow-testing is valid only for APP_ENV=testing with sqlite.');
            }

            return;
        }

        if ($appEnvironment !== 'staging') {
            throw new RuntimeException('Target staging requires APP_ENV=staging.');
        }
    }

    private function resolvePackagePath(): string
    {
        $path = trim((string) $this->option('package'));
        if ($path === '') {
            throw new RuntimeException('--package is required.');
        }

        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Package file was not found.');
        }

        return $resolved;
    }

    private function prepareOutputPath(): ?string
    {
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            return null;
        }

        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (File::isDirectory($resolved)) {
            throw new RuntimeException('--output must name a writable file, not a directory.');
        }

        File::ensureDirectoryExists(dirname($resolved));
        if (File::put($resolved, '') === false || ! File::isFile($resolved) || ! is_writable($resolved)) {
            throw new RuntimeException('--output could not be prepared as a writable receipt file.');
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function writeOutput(array $summary, string $resolved): void
    {
        if (File::put($resolved, $this->encoded($summary).PHP_EOL, true) === false) {
            throw new RuntimeException('Unable to write the sanitized receipt.');
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encoded($summary));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? 'fail'));
        $this->line('target_environment='.(string) ($summary['target_environment'] ?? ''));
        $this->line('revision_created_count='.(string) ($summary['revision_created_count'] ?? 0));
        $this->line('idempotent_count='.(string) ($summary['idempotent_count'] ?? 0));
        $this->line('writes_committed='.(($summary['writes_committed'] ?? false) ? '1' : '0'));
        $this->line('live_projection_changes='.(string) ($summary['live_projection_changes'] ?? 0));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function encoded(array $summary): string
    {
        return (string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'schema_version' => 'personality.mbti-seo-title-experiment-receipt.v1',
            'ok' => false,
            'status' => 'fail',
            'target_environment' => 'staging',
            'revision_created_count' => 0,
            'idempotent_count' => 0,
            'writes_committed' => false,
            'live_projection_changes' => 0,
            'negative_guarantees' => [
                'production_write' => false,
                'live_seo_meta_write' => false,
                'publication' => false,
                'active_revision_pointer_change' => false,
                'indexability_change' => false,
                'sitemap_llms_change' => false,
                'search_channel_change' => false,
                'deployment' => false,
            ],
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
        ];
    }
}
