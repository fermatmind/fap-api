<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiIntpASeoTitleProductionPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** @review-surface mbti_approval_batch */
final class PersonalityMbtiIntpASeoTitleProductionPromotion extends Command
{
    protected $signature = 'personality:mbti-intp-a-seo-title-production-promotion
        {--package= : Path to the exact production promotion package}
        {--confirm-package-sha256= : Exact production promotion package SHA-256}
        {--control-sha= : Exact latest-main control SHA}
        {--active-revision= : Exact active production REVISION}
        {--staging-run-id= : Exact successful staging workflow run id}
        {--staging-receipt-sha256= : Exact staging workflow receipt SHA-256}
        {--operator-approved= : Exact SHA-bound production authorization phrase}
        {--dry-run : Validate and plan with zero writes}
        {--write : Promote the one allowed seo_title field}
        {--rollback : Restore the old title after a failed live QA}
        {--allow-testing : Permit APP_ENV=testing with sqlite for automated tests only}
        {--json : Emit a sanitized JSON receipt}
        {--output= : Optional new receipt path}';

    protected $description = 'Promote or safely roll back the exact zh-CN INTP-A seo_title production override.';

    public function handle(MbtiIntpASeoTitleProductionPromotionService $service): int
    {
        $outputPath = null;

        try {
            $input = $this->validatedInput();
            $outputPath = $this->prepareOutputPath();
            $summary = match ($input['mode']) {
                'dry-run' => $service->plan($input['package'], $input['package_sha256'], $input['active_revision']),
                'write' => $service->promote($input['package'], $input['package_sha256'], $input['active_revision']),
                'rollback' => $service->rollback($input['package'], $input['package_sha256'], $input['active_revision']),
            };
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable) {
            $summary = $this->failureSummary('unexpected_error', 'Unexpected failure; inspect protected runtime logs.');
        }

        if ($outputPath !== null) {
            try {
                if (File::put($outputPath, $this->encoded($summary).PHP_EOL, true) === false) {
                    throw new RuntimeException('Unable to write the sanitized receipt.');
                }
            } catch (Throwable) {
                $summary['ok'] = false;
                $summary['status'] = 'receipt_write_error';
                $summary['errors'][] = [
                    'field' => 'output',
                    'code' => 'receipt_write_error',
                    'message' => 'Unable to write the preflighted receipt destination; inspect protected runtime logs.',
                ];
            }
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{mode:string,package:array<string,mixed>,package_sha256:string,active_revision:string} */
    private function validatedInput(): array
    {
        $modes = array_values(array_filter([
            (bool) $this->option('dry-run') ? 'dry-run' : null,
            (bool) $this->option('write') ? 'write' : null,
            (bool) $this->option('rollback') ? 'rollback' : null,
        ]));
        if (count($modes) !== 1) {
            throw new RuntimeException('Exactly one of --dry-run, --write, or --rollback is required.');
        }
        $mode = $modes[0];
        $this->guardRuntimeEnvironment();

        $controlSha = strtolower(trim((string) $this->option('control-sha')));
        $activeRevision = strtolower(trim((string) $this->option('active-revision')));
        if (preg_match('/^[a-f0-9]{40}$/', $controlSha) !== 1 || ! hash_equals($controlSha, $activeRevision)) {
            throw new RuntimeException('Control SHA and active production revision must be the same exact 40-character SHA.');
        }
        $stagingRunId = trim((string) $this->option('staging-run-id'));
        $stagingReceiptSha = strtolower(trim((string) $this->option('staging-receipt-sha256')));
        if ($stagingRunId !== '31395530368'
            || $stagingReceiptSha !== 'd5bdc286f156f7b07f4694b0dc702461eeb64f1fdd359d0cfc42b22beef1d57a') {
            throw new RuntimeException('Staging evidence identity mismatch.');
        }

        $packagePath = $this->resolvePackagePath();
        $raw = (string) File::get($packagePath);
        $packageSha = hash('sha256', $raw);
        if (! hash_equals($packageSha, strtolower(trim((string) $this->option('confirm-package-sha256'))))) {
            throw new RuntimeException('Production promotion package SHA-256 mismatch.');
        }
        $package = json_decode($raw, true);
        if (! is_array($package)) {
            throw new RuntimeException('Production promotion package must be a JSON object.');
        }

        $expectedApproval = sprintf(
            'I authorize zh-CN INTP-A seo_title production promotion mode %s for control SHA %s, active revision %s, package SHA %s, staging run %s, staging receipt %s; no other content, publication, indexability, discoverability, or search change.',
            $mode,
            $controlSha,
            $activeRevision,
            $packageSha,
            $stagingRunId,
            $stagingReceiptSha,
        );
        if (! hash_equals($expectedApproval, trim((string) $this->option('operator-approved')))) {
            throw new RuntimeException('Production approval phrase does not match the exact SHA-bound contract.');
        }

        return [
            'mode' => $mode,
            'package' => $package,
            'package_sha256' => $packageSha,
            'active_revision' => $activeRevision,
        ];
    }

    private function guardRuntimeEnvironment(): void
    {
        if ((bool) $this->option('allow-testing')) {
            if (! app()->environment('testing') || config('database.default') !== 'sqlite') {
                throw new RuntimeException('--allow-testing is valid only for APP_ENV=testing with sqlite.');
            }

            return;
        }
        if (! app()->environment('production')) {
            throw new RuntimeException('This command requires APP_ENV=production.');
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
            throw new RuntimeException('Production promotion package file was not found.');
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
        if (File::exists($resolved) || File::isDirectory($resolved)) {
            throw new RuntimeException('--output must name a new receipt file.');
        }
        File::ensureDirectoryExists(dirname($resolved));
        $handle = @fopen($resolved, 'x');
        if ($handle === false) {
            throw new RuntimeException('--output could not be prepared.');
        }
        fclose($handle);

        return $resolved;
    }

    /** @param array<string,mixed> $summary */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encoded($summary));

            return;
        }
        foreach (['ok', 'status', 'seo_title_changes', 'audit_revision_created_count', 'other_seo_field_changes', 'cache_invalidations', 'writes_committed'] as $key) {
            $value = $summary[$key] ?? null;
            $this->line($key.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
        }
    }

    /** @param array<string,mixed> $summary */
    private function encoded(array $summary): string
    {
        return (string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return array<string,mixed> */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'schema_version' => 'personality.mbti-seo-title-production-promotion-receipt.v1',
            'ok' => false,
            'status' => 'fail',
            'seo_title_changes' => 0,
            'idempotent_count' => 0,
            'audit_revision_created_count' => 0,
            'other_seo_field_changes' => 0,
            'live_projection_forbidden_changes' => 0,
            'cache_invalidations' => 0,
            'writes_committed' => false,
            'publication_changes' => 0,
            'indexability_changes' => 0,
            'discoverability_changes' => 0,
            'search_changes' => 0,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
        ];
    }
}
