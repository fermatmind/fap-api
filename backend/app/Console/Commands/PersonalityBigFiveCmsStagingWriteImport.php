<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\BigFiveCmsImportDraftDryRunPlanner;
use App\Services\Cms\BigFiveCmsImportDraftStagingWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveCmsStagingWriteImport extends Command
{
    private const CONFIRMATION_PHRASE = 'I authorize Big Five CMS staging/dev draft import only. No production import, publish, indexability, sitemap, llms, JSON-LD runtime, deploy, or search release is authorized.';

    protected $signature = 'personality:big-five-cms-staging-write-import
        {--package= : Path to the Big Five cms-import-draft package}
        {--authorization-packet= : Path to the non-secret authorization packet}
        {--confirm-package-sha256= : Required package SHA-256}
        {--target-env= : Required target environment, staging or dev}
        {--operator-approved= : Required exact confirmation phrase}
        {--dry-run : Validate and plan without database writes}
        {--write : Write draft rows to staging/dev CMS}
        {--allow-testing : Permit APP_ENV=testing with sqlite for automated tests only}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}';

    protected $description = 'Controlled staging/dev-only Big Five CMS draft write import.';

    public function handle(BigFiveCmsImportDraftDryRunPlanner $planner, BigFiveCmsImportDraftStagingWriter $writer): int
    {
        try {
            $summary = $this->guardedRun($planner, $writer);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary($exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary($exception->getMessage(), 'unexpected_error');
        }

        $this->writeOutputFile($summary);
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function guardedRun(BigFiveCmsImportDraftDryRunPlanner $planner, BigFiveCmsImportDraftStagingWriter $writer): array
    {
        $dryRun = (bool) $this->option('dry-run');
        $write = (bool) $this->option('write');
        if ($dryRun === $write) {
            throw new RuntimeException('Exactly one of --dry-run or --write is required.');
        }

        $targetEnvironment = strtolower(trim((string) $this->option('target-env')));
        if (! in_array($targetEnvironment, ['staging', 'dev'], true)) {
            throw new RuntimeException('--target-env must be staging or dev.');
        }

        $this->guardRuntimeEnvironment($targetEnvironment);

        if (trim((string) $this->option('operator-approved')) !== self::CONFIRMATION_PHRASE) {
            throw new RuntimeException('--operator-approved must match the Big Five staging/dev draft import confirmation phrase.');
        }

        $packagePath = $this->resolvePath((string) $this->option('package'), '--package');
        $packetPath = $this->resolvePath((string) $this->option('authorization-packet'), '--authorization-packet');
        $packageRaw = (string) File::get($packagePath);
        $sourceSha256 = hash('sha256', $packageRaw);
        $expectedSha256 = strtolower(trim((string) $this->option('confirm-package-sha256')));
        if ($expectedSha256 === '' || $expectedSha256 !== $sourceSha256) {
            throw new RuntimeException('Package SHA-256 mismatch; refusing staging/dev import.');
        }

        $packet = $this->decodeJsonFile($packetPath, 'authorization packet');
        $this->guardAuthorizationPacket($packet, $targetEnvironment, $packagePath, $sourceSha256);

        $package = json_decode($packageRaw, true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON array or object.');
        }

        $plan = $planner->plan($package, $sourceSha256);
        if (($plan['ok'] ?? false) !== true) {
            throw new RuntimeException('No-write import planner failed; refusing staging/dev import.');
        }

        if ((int) ($plan['row_count'] ?? 0) !== 42 || ($plan['row_count_matches_expected'] ?? false) !== true) {
            throw new RuntimeException('Expected exactly 42 valid Big Five CMS rows.');
        }

        if ((int) ($plan['old_short_big_five_route_residue_count'] ?? 0) !== 0) {
            throw new RuntimeException('Old /zh/big-five or /en/big-five route residue blocks staging/dev import.');
        }

        return $write
            ? $writer->write($package, $plan, $sourceSha256, $packagePath, $targetEnvironment)
            : $writer->plan($package, $plan, $sourceSha256, $packagePath, $targetEnvironment);
    }

    private function guardRuntimeEnvironment(string $targetEnvironment): void
    {
        $appEnvironment = app()->environment();
        if (in_array($appEnvironment, ['production', 'prod'], true) || $targetEnvironment === 'production') {
            throw new RuntimeException('Production environment is not authorized for this command.');
        }

        if ((bool) $this->option('allow-testing')) {
            if ($appEnvironment !== 'testing' || config('database.default') !== 'sqlite') {
                throw new RuntimeException('--allow-testing is only valid for APP_ENV=testing with sqlite.');
            }

            return;
        }

        if ($targetEnvironment === 'staging' && $appEnvironment !== 'staging') {
            throw new RuntimeException('Target staging requires APP_ENV=staging.');
        }

        if ($targetEnvironment === 'dev' && ! in_array($appEnvironment, ['dev', 'local'], true)) {
            throw new RuntimeException('Target dev requires APP_ENV=dev or APP_ENV=local.');
        }
    }

    private function resolvePath(string $path, string $option): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException($option.' is required.');
        }

        $resolved = str_starts_with($path, '/')
            ? $path
            : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException($option.' file not found: '.$resolved);
        }

        return $resolved;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonFile(string $path, string $label): array
    {
        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function guardAuthorizationPacket(array $packet, string $targetEnvironment, string $packagePath, string $sourceSha256): void
    {
        if ((bool) ($packet['contains_secrets'] ?? true) !== false) {
            throw new RuntimeException('Authorization packet must not contain secrets.');
        }

        if ((string) data_get($packet, 'target.environment') !== $targetEnvironment) {
            throw new RuntimeException('Authorization packet target environment mismatch.');
        }

        $packetSourcePath = (string) data_get($packet, 'source.package_path', '');
        if ($packetSourcePath !== '' && ! str_ends_with($packagePath, $packetSourcePath)) {
            throw new RuntimeException('Authorization packet package path mismatch.');
        }

        if ((string) data_get($packet, 'source.package_sha256') !== $sourceSha256) {
            throw new RuntimeException('Authorization packet package SHA-256 mismatch.');
        }

        if ((int) data_get($packet, 'source.expected_row_count') !== 42) {
            throw new RuntimeException('Authorization packet expected row count must be 42.');
        }

        foreach ([
            'production_import',
            'publish',
            'indexability_release',
            'sitemap_release',
            'llms_release',
            'jsonld_runtime_release',
            'search_submission',
            'manual_deploy',
            'staging_deploy_wait',
            'production_deploy',
        ] as $forbiddenAction) {
            if ((bool) data_get($packet, 'authorized_actions.'.$forbiddenAction, true) !== false) {
                throw new RuntimeException('Authorization packet must explicitly forbid '.$forbiddenAction.'.');
            }
        }

        if ((string) data_get($packet, 'operator_authorization.confirmation_phrase') !== self::CONFIRMATION_PHRASE) {
            throw new RuntimeException('Authorization packet confirmation phrase mismatch.');
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? 'fail'));
        $this->line('target_environment='.(string) ($summary['target_environment'] ?? ''));
        $this->line('row_count='.(string) ($summary['row_count'] ?? 0));
        $this->line('writes_committed='.(($summary['writes_committed'] ?? false) ? '1' : '0'));
        $this->line('cms_write_attempted='.(($summary['cms_write_attempted'] ?? false) ? '1' : '0'));
        $this->line('created_asset_count='.(string) ($summary['created_asset_count'] ?? 0));
        $this->line('updated_asset_count='.(string) ($summary['updated_asset_count'] ?? 0));
        $this->line('skipped_existing_count='.(string) ($summary['skipped_existing_count'] ?? 0));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeOutputFile(array $summary): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }

        $resolved = str_starts_with($output, '/')
            ? $output
            : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, ((string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        )).PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $message, string $code = 'runtime_error'): array
    {
        return [
            'artifact' => 'BIG5-CMS-STAGING-WRITE-IMPORT-10',
            'status' => 'fail',
            'ok' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'jsonld_runtime_release_attempted' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
