<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\BigFiveCmsImportDraftDryRunPlanner;
use App\Services\Cms\BigFiveSeoDiscoverabilityReleaseWriter;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveSeoDiscoverabilityRelease extends Command
{
    public const CONFIRMATION_PHRASE = 'I authorize Big Five SEO discoverability release for exactly 20 zh-CN v2 trait/range pages. Enable index, sitemap, llms, and JSON-LD runtime only for these reviewed pages. No English pages, no unreviewed pages, no search submission, no frontend deploy.';

    protected $signature = 'personality:big-five-seo-discoverability-release
        {--package= : Path to the locked Big Five cms-import-draft package}
        {--confirm-package-sha256= : Required package SHA-256}
        {--authorized-slugs= : Required comma-separated exact 20 zh-CN trait/range slug allowlist}
        {--target-env= : Required target environment, production, staging, or dev}
        {--operator-approved= : Required exact confirmation phrase}
        {--dry-run : Validate and plan without database writes}
        {--release : Release only the authorized 20 zh-CN pages to index/sitemap/llms/JSON-LD runtime}
        {--allow-testing : Permit APP_ENV=testing with sqlite for automated tests only}
        {--no-content-change : Required release-mode hold: do not modify CMS body, FAQ, SEO text, or links}
        {--no-english : Required release-mode hold: do not release English pages}
        {--no-unreviewed : Required release-mode hold: do not release hub, combination, cross-reading, result-review, or draft pages}
        {--no-search : Required release-mode hold: do not submit Search Console or other search channels}
        {--no-frontend-revalidation : Required release-mode hold: do not trigger frontend deploy or revalidation}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}';

    protected $description = 'Controlled Big Five SEO discoverability release for exactly 20 reviewed zh-CN v2 trait/range pages.';

    public function handle(
        BigFiveCmsImportDraftDryRunPlanner $planner,
        BigFiveSeoDiscoverabilityReleaseWriter $writer,
        SeoDiscoverabilityCacheInvalidator $cacheInvalidator
    ): int {
        try {
            $summary = $this->guardedRun($planner, $writer, $cacheInvalidator);
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
    private function guardedRun(
        BigFiveCmsImportDraftDryRunPlanner $planner,
        BigFiveSeoDiscoverabilityReleaseWriter $writer,
        SeoDiscoverabilityCacheInvalidator $cacheInvalidator
    ): array {
        $dryRun = (bool) $this->option('dry-run');
        $release = (bool) $this->option('release');
        if ($dryRun === $release) {
            throw new RuntimeException('Exactly one of --dry-run or --release is required.');
        }

        $targetEnvironment = strtolower(trim((string) $this->option('target-env')));
        if (! in_array($targetEnvironment, ['production', 'staging', 'dev'], true)) {
            throw new RuntimeException('--target-env must be production, staging, or dev.');
        }

        $this->guardRuntimeEnvironment($targetEnvironment);

        if (trim((string) $this->option('operator-approved')) !== self::CONFIRMATION_PHRASE) {
            throw new RuntimeException('--operator-approved must match the Big Five SEO discoverability release confirmation phrase.');
        }

        if ($release) {
            foreach (['no-content-change', 'no-english', 'no-unreviewed', 'no-search', 'no-frontend-revalidation'] as $flag) {
                if ((bool) $this->option($flag) !== true) {
                    throw new RuntimeException('Release mode requires all no-side-effect safety flags.');
                }
            }
        }

        $authorizedSlugs = $this->authorizedSlugs();
        $packagePath = $this->resolvePath((string) $this->option('package'), '--package');
        $packageRaw = (string) File::get($packagePath);
        $sourceSha256 = hash('sha256', $packageRaw);
        $expectedSha256 = strtolower(trim((string) $this->option('confirm-package-sha256')));
        if ($expectedSha256 === '' || $expectedSha256 !== $sourceSha256) {
            throw new RuntimeException('Package SHA-256 mismatch; refusing Big Five SEO discoverability release.');
        }

        $package = json_decode($packageRaw, true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON array or object.');
        }

        $plan = $planner->plan($package, $sourceSha256);
        if (($plan['ok'] ?? false) !== true) {
            throw new RuntimeException('No-write import planner failed; refusing Big Five SEO discoverability release.');
        }

        if ((int) ($plan['row_count'] ?? 0) !== 42 || ($plan['row_count_matches_expected'] ?? false) !== true) {
            throw new RuntimeException('Expected exactly 42 valid Big Five CMS rows.');
        }

        if ((int) ($plan['old_short_big_five_route_residue_count'] ?? 0) !== 0) {
            throw new RuntimeException('Old /zh/big-five or /en/big-five route residue blocks Big Five SEO discoverability release.');
        }

        return $release
            ? $writer->release($plan, $sourceSha256, $packagePath, $targetEnvironment, $authorizedSlugs, $cacheInvalidator)
            : $writer->plan($plan, $sourceSha256, $packagePath, $targetEnvironment, $authorizedSlugs);
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
            'artifact' => 'BIG5-SEO-DISCOVERABILITY-RELEASE-13',
            'status' => 'fail',
            'ok' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'jsonld_runtime_release_attempted' => false,
            'frontend_revalidation_attempted' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
