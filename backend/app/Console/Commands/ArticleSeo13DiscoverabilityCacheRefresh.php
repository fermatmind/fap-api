<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Seo13ArticleDiscoverabilityCacheRefreshService;
use Illuminate\Console\Command;
use Throwable;

/**
 * @review-surface article
 */
final class ArticleSeo13DiscoverabilityCacheRefresh extends Command
{
    protected $signature = 'articles:seo13-discoverability-cache-refresh
        {--dry-run : Inspect the exact 13 released articles without writes}
        {--execute : Refresh only the bounded sitemap and llms derived caches}
        {--expected-state-sha256= : Execute-only preflight state hash}
        {--expected-content-set-sha256= : Execute-only immutable content-set hash}
        {--expected-target-set-sha256= : Execute-only immutable target-set hash}
        {--confirm= : Execute-only exact command confirmation}
        {--json : Emit machine-readable output}
        {--no-authority-change : Required execute hold}
        {--no-eligibility-change : Required execute hold}
        {--no-hreflang : Required execute hold}
        {--no-search : Required execute hold}
        {--no-deploy : Required execute hold}';

    protected $description = 'Refresh only the exact SEO 13 sitemap and llms derived caches after schema release.';

    public function handle(Seo13ArticleDiscoverabilityCacheRefreshService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $errors = [];

        if ($dryRun === $execute) {
            $errors[] = ['code' => 'exactly_one_mode_required'];
        }

        try {
            $snapshot = $service->preflight();
        } catch (Throwable) {
            $snapshot = ['errors' => [['code' => 'preflight_failed']]];
        }
        foreach ((array) ($snapshot['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $errors[] = $error;
            }
        }

        $expectedState = trim((string) $this->option('expected-state-sha256'));
        $expectedContentSet = trim((string) $this->option('expected-content-set-sha256'));
        $expectedTargetSet = trim((string) $this->option('expected-target-set-sha256'));
        $expectedConfirmation = $this->confirmation(
            (string) ($snapshot['state_sha256'] ?? ''),
            (string) ($snapshot['content_set_sha256'] ?? ''),
            (string) ($snapshot['target_set_sha256'] ?? ''),
        );

        if ($execute) {
            foreach ([
                'no-authority-change',
                'no-eligibility-change',
                'no-hreflang',
                'no-search',
                'no-deploy',
            ] as $flag) {
                if ((bool) $this->option($flag) !== true) {
                    $errors[] = ['code' => 'required_hold_missing', 'flag' => $flag];
                }
            }
            foreach ([
                'expected_state_sha256_mismatch' => [$snapshot['state_sha256'] ?? '', $expectedState],
                'expected_content_set_sha256_mismatch' => [$snapshot['content_set_sha256'] ?? '', $expectedContentSet],
                'expected_target_set_sha256_mismatch' => [$snapshot['target_set_sha256'] ?? '', $expectedTargetSet],
                'confirmation_mismatch' => [$expectedConfirmation, trim((string) $this->option('confirm'))],
            ] as $code => [$actual, $expected]) {
                if (! hash_equals((string) $actual, (string) $expected)) {
                    $errors[] = ['code' => $code];
                }
            }
            if (($snapshot['apply_supported'] ?? false) !== true) {
                $errors[] = ['code' => 'apply_not_supported'];
            }
        }

        $summary = [
            'ok' => $errors === [] && ($snapshot['ok'] ?? false) === true,
            'mode' => $execute ? 'apply' : 'preflight',
            'production_write_execution' => false,
            'target_count' => Seo13ArticleDiscoverabilityCacheRefreshService::TARGET_COUNT,
            'state_sha256' => (string) ($snapshot['state_sha256'] ?? ''),
            'content_set_sha256' => (string) ($snapshot['content_set_sha256'] ?? ''),
            'target_set_sha256' => (string) ($snapshot['target_set_sha256'] ?? ''),
            'schema_released_count' => (int) ($snapshot['schema_released_count'] ?? 0),
            'readback_complete' => (bool) ($snapshot['readback_complete'] ?? false),
            'frontend_revalidation_endpoint_count' => (int) ($snapshot['frontend_revalidation_endpoint_count'] ?? 0),
            'frontend_revalidation_token_present' => (bool) ($snapshot['frontend_revalidation_token_present'] ?? false),
            'frontend_revalidation_token_output' => false,
            'apply_supported' => (bool) ($snapshot['apply_supported'] ?? false),
            'expected_confirmation' => $expectedConfirmation,
            'cache_invalidation_count' => 0,
            'cache_warm_write_count' => 0,
            'sitemap_cache_refresh_count' => 0,
            'llms_cache_refresh_count' => 0,
            'frontend_revalidation_count' => 0,
            'cms_authority_write_count' => 0,
            'publication_write_count' => 0,
            'schema_write_count' => 0,
            'hreflang_write_count' => 0,
            'sitemap_eligibility_write_count' => 0,
            'llms_eligibility_write_count' => 0,
            'search_submission_count' => 0,
            'gsc_request_count' => 0,
            'url_inspection_count' => 0,
            'queue_dispatch_count' => 0,
            'deploy_count' => 0,
            'rows' => (array) ($snapshot['rows'] ?? []),
            'errors' => $errors,
        ];

        if (($summary['ok'] ?? false) === true && $execute) {
            try {
                $result = $service->apply($expectedState, $expectedContentSet, $expectedTargetSet);
                $writes = (array) ($result['writes'] ?? []);
                $after = (array) ($result['after'] ?? []);
                $summary = array_merge($summary, [
                    'production_write_execution' => true,
                    'after_state_sha256' => (string) ($after['state_sha256'] ?? ''),
                    'cache_invalidation_count' => (int) ($writes['cache_invalidation_count'] ?? 0),
                    'cache_warm_write_count' => (int) ($writes['cache_warm_write_count'] ?? 0),
                    'sitemap_cache_refresh_count' => (int) ($writes['sitemap_cache_refresh_count'] ?? 0),
                    'llms_cache_refresh_count' => (int) ($writes['llms_cache_refresh_count'] ?? 0),
                    'frontend_revalidation_count' => (int) ($writes['frontend_revalidation_count'] ?? 0),
                    'rows' => (array) ($after['rows'] ?? []),
                ]);
            } catch (Throwable) {
                $summary['ok'] = false;
                $summary['errors'][] = ['code' => 'cache_refresh_failed'];
            }
        }

        $this->emit($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function confirmation(string $stateSha256, string $contentSetSha256, string $targetSetSha256): string
    {
        return 'I explicitly approve SEO 13 derived discoverability cache refresh state '
            .$stateSha256.' content set '.$contentSetSha256.' target set '.$targetSetSha256.'.';
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emit(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        $this->info(($summary['ok'] ?? false) === true
            ? 'SEO13 discoverability cache check passed.'
            : 'SEO13 discoverability cache check failed.');
    }
}
