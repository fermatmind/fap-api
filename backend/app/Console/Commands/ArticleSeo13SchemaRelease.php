<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Seo13ArticleSchemaReleaseService;
use Illuminate\Console\Command;
use Throwable;

/**
 * @review-surface article
 */
final class ArticleSeo13SchemaRelease extends Command
{
    protected $signature = 'articles:seo13-schema-release
        {--dry-run : Inspect the exact 13 published revisions without writes}
        {--execute : Atomically release Article, Breadcrumb, and visible FAQ schema}
        {--expected-state-sha256= : Execute-only preflight state hash}
        {--expected-content-set-sha256= : Execute-only immutable content-set hash}
        {--expected-target-set-sha256= : Execute-only immutable target-set hash}
        {--confirm= : Execute-only exact command confirmation}
        {--json : Emit machine-readable output}
        {--no-publish : Required execute hold}
        {--no-hreflang : Required execute hold}
        {--no-revalidation : Required execute hold}
        {--no-sitemap-llms-change : Required execute hold}
        {--no-search : Required execute hold}';

    protected $description = 'Atomically release schema for the exact 13 SEO refresh articles after published-revision parity passes.';

    public function handle(Seo13ArticleSchemaReleaseService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $errors = [];

        if ($dryRun === $execute) {
            $errors[] = ['code' => 'exactly_one_mode_required'];
        }

        $snapshot = $service->preflight();
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
                'no-publish',
                'no-hreflang',
                'no-revalidation',
                'no-sitemap-llms-change',
                'no-search',
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
            'target_count' => Seo13ArticleSchemaReleaseService::TARGET_COUNT,
            'target_set_sha256' => (string) ($snapshot['target_set_sha256'] ?? ''),
            'content_set_sha256' => (string) ($snapshot['content_set_sha256'] ?? ''),
            'state_sha256' => (string) ($snapshot['state_sha256'] ?? ''),
            'held_count' => (int) ($snapshot['held_count'] ?? 0),
            'released_count' => (int) ($snapshot['released_count'] ?? 0),
            'apply_supported' => (bool) ($snapshot['apply_supported'] ?? false),
            'readback_complete' => (bool) ($snapshot['readback_complete'] ?? false),
            'expected_confirmation' => $expectedConfirmation,
            'schema_write_count' => 0,
            'revision_authority_write_count' => 0,
            'article_schema_enabled_count' => 0,
            'breadcrumb_schema_enabled_count' => 0,
            'faq_schema_enabled_count' => 0,
            'audit_write_count' => 0,
            'article_body_write_count' => 0,
            'revision_write_count' => 0,
            'publication_write_count' => 0,
            'indexability_write_count' => 0,
            'hreflang_write_count' => 0,
            'revalidation_count' => 0,
            'sitemap_eligibility_write_count' => 0,
            'llms_eligibility_write_count' => 0,
            'sitemap_cache_refresh_count' => 0,
            'llms_cache_refresh_count' => 0,
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
                    'held_count' => (int) ($after['held_count'] ?? 0),
                    'released_count' => (int) ($after['released_count'] ?? 0),
                    'apply_supported' => (bool) ($after['apply_supported'] ?? false),
                    'readback_complete' => (bool) ($after['readback_complete'] ?? false),
                    'schema_write_count' => (int) ($writes['schema_write_count'] ?? 0),
                    'revision_authority_write_count' => (int) ($writes['revision_authority_write_count'] ?? 0),
                    'revision_write_count' => (int) ($writes['revision_authority_write_count'] ?? 0),
                    'article_schema_enabled_count' => Seo13ArticleSchemaReleaseService::TARGET_COUNT,
                    'breadcrumb_schema_enabled_count' => Seo13ArticleSchemaReleaseService::TARGET_COUNT,
                    'faq_schema_enabled_count' => Seo13ArticleSchemaReleaseService::TARGET_COUNT,
                    'audit_write_count' => (int) ($writes['audit_write_count'] ?? 0),
                    'rows' => (array) ($after['rows'] ?? []),
                ]);
            } catch (Throwable) {
                $summary['ok'] = false;
                $summary['errors'][] = ['code' => 'atomic_apply_failed'];
            }
        }

        $this->emit($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function confirmation(string $stateSha256, string $contentSetSha256, string $targetSetSha256): string
    {
        return 'I explicitly approve SEO 13 atomic schema release state '
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
            ? 'SEO13 schema release check passed.'
            : 'SEO13 schema release check failed.');
    }
}
