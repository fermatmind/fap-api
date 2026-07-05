<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\BigFiveCmsImportDraftDryRunPlanner;
use App\Services\Cms\BigFiveCmsPublishGateWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveCmsPublishGate extends Command
{
    public const CONFIRMATION_PHRASE = 'I authorize these 20 zh-CN Big Five v2 pages for CMS content-ready only. Keep noindex, no sitemap, no llms, no JSON-LD runtime, no English pages, no SEO release, and no production deploy.';

    protected $signature = 'personality:big-five-cms-publish-gate
        {--package= : Path to the Big Five cms-import-draft package}
        {--confirm-package-sha256= : Required package SHA-256}
        {--authorized-slugs= : Required comma-separated exact 20 zh-CN trait/range slug allowlist}
        {--target-env= : Required target environment, production, staging, or dev}
        {--operator-approved= : Required exact confirmation phrase}
        {--dry-run : Validate and plan without database writes}
        {--write : Write only the authorized 20 zh-CN content-ready rows}
        {--allow-testing : Permit APP_ENV=testing with sqlite for automated tests only}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}';

    protected $description = 'Controlled Big Five CMS content-ready gate for exactly 20 authorized zh-CN v2 trait/range pages.';

    public function handle(BigFiveCmsImportDraftDryRunPlanner $planner, BigFiveCmsPublishGateWriter $writer): int
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
    private function guardedRun(BigFiveCmsImportDraftDryRunPlanner $planner, BigFiveCmsPublishGateWriter $writer): array
    {
        $dryRun = (bool) $this->option('dry-run');
        $write = (bool) $this->option('write');
        if ($dryRun === $write) {
            throw new RuntimeException('Exactly one of --dry-run or --write is required.');
        }

        $targetEnvironment = strtolower(trim((string) $this->option('target-env')));
        if (! in_array($targetEnvironment, ['production', 'staging', 'dev'], true)) {
            throw new RuntimeException('--target-env must be production, staging, or dev.');
        }

        $this->guardRuntimeEnvironment($targetEnvironment);

        if (trim((string) $this->option('operator-approved')) !== self::CONFIRMATION_PHRASE) {
            throw new RuntimeException('--operator-approved must match the Big Five CMS publish gate confirmation phrase.');
        }

        $authorizedSlugs = $this->authorizedSlugs();
        $packagePath = $this->resolvePath((string) $this->option('package'), '--package');
        $packageRaw = (string) File::get($packagePath);
        $sourceSha256 = hash('sha256', $packageRaw);
        $expectedSha256 = strtolower(trim((string) $this->option('confirm-package-sha256')));
        if ($expectedSha256 === '' || $expectedSha256 !== $sourceSha256) {
            throw new RuntimeException('Package SHA-256 mismatch; refusing Big Five CMS publish gate.');
        }

        $package = json_decode($packageRaw, true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON array or object.');
        }

        $plan = $planner->plan($package, $sourceSha256);
        if (($plan['ok'] ?? false) !== true) {
            throw new RuntimeException('No-write import planner failed; refusing Big Five CMS publish gate.');
        }

        if ((int) ($plan['row_count'] ?? 0) !== 42 || ($plan['row_count_matches_expected'] ?? false) !== true) {
            throw new RuntimeException('Expected exactly 42 valid Big Five CMS rows.');
        }

        if ((int) ($plan['old_short_big_five_route_residue_count'] ?? 0) !== 0) {
            throw new RuntimeException('Old /zh/big-five or /en/big-five route residue blocks Big Five CMS publish gate.');
        }

        return $write
            ? $writer->write($package, $plan, $sourceSha256, $packagePath, $targetEnvironment, $authorizedSlugs)
            : $writer->plan($package, $plan, $sourceSha256, $packagePath, $targetEnvironment, $authorizedSlugs);
    }

    private function guardRuntimeEnvironment(string $targetEnvironment): void
    {
        $appEnvironment = app()->environment();

        if ((bool) $this->option('allow-testing')) {
            if ($appEnvironment !== 'testing' || config('database.default') !== 'sqlite') {
                throw new RuntimeException('--allow-testing is only valid for APP_ENV=testing with sqlite.');
            }

            return;
        }

        if ($targetEnvironment === 'production' && ! in_array($appEnvironment, ['production', 'prod'], true)) {
            throw new RuntimeException('Target production requires APP_ENV=production.');
        }

        if ($targetEnvironment === 'staging' && $appEnvironment !== 'staging') {
            throw new RuntimeException('Target staging requires APP_ENV=staging.');
        }

        if ($targetEnvironment === 'dev' && ! in_array($appEnvironment, ['dev', 'local'], true)) {
            throw new RuntimeException('Target dev requires APP_ENV=dev or APP_ENV=local.');
        }
    }

    /**
     * @return list<string>
     */
    private function authorizedSlugs(): array
    {
        $raw = trim((string) $this->option('authorized-slugs'));
        if ($raw === '') {
            throw new RuntimeException('--authorized-slugs is required.');
        }

        return array_values(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            explode(',', $raw)
        )));
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
            'artifact' => 'BIG5-CMS-PUBLISH-GATE-12',
            'status' => 'fail',
            'ok' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'content_ready_attempted' => false,
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
