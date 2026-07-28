<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Seo13LegacyArticleMetadataBootstrapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * @review-surface article
 */
final class ArticleSeo13LegacyMetadataBootstrap extends Command
{
    protected $signature = 'articles:seo13-legacy-metadata-bootstrap
        {--dry-run : Inspect the exact five legacy records without writes}
        {--execute : Apply the exact atomic metadata bootstrap}
        {--expected-state-sha256= : Execute-only preflight state hash}
        {--expected-target-set-sha256= : Execute-only immutable target-set hash}
        {--confirm= : Execute-only exact command confirmation}
        {--json : Emit machine-readable output}';

    protected $description = 'Atomically bootstrap missing SEO metadata and taxonomy for the exact five SEO 13 legacy articles.';

    public function handle(Seo13LegacyArticleMetadataBootstrapService $service): int
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
        $expectedTargetSet = trim((string) $this->option('expected-target-set-sha256'));
        $expectedConfirmation = $this->confirmation(
            (string) ($snapshot['state_sha256'] ?? ''),
            (string) ($snapshot['target_set_sha256'] ?? ''),
        );

        if ($execute) {
            if (! hash_equals((string) ($snapshot['state_sha256'] ?? ''), $expectedState)) {
                $errors[] = ['code' => 'expected_state_sha256_mismatch'];
            }
            if (! hash_equals((string) ($snapshot['target_set_sha256'] ?? ''), $expectedTargetSet)) {
                $errors[] = ['code' => 'expected_target_set_sha256_mismatch'];
            }
            if (! hash_equals($expectedConfirmation, trim((string) $this->option('confirm')))) {
                $errors[] = ['code' => 'confirmation_mismatch'];
            }
            if (($snapshot['apply_supported'] ?? false) !== true) {
                $errors[] = ['code' => 'apply_not_supported'];
            }
        }

        $summary = [
            'ok' => $errors === [] && ($snapshot['ok'] ?? false) === true,
            'mode' => $execute ? 'apply' : 'preflight',
            'production_write_execution' => false,
            'target_count' => Seo13LegacyArticleMetadataBootstrapService::TARGET_COUNT,
            'target_set_sha256' => (string) ($snapshot['target_set_sha256'] ?? ''),
            'state_sha256' => (string) ($snapshot['state_sha256'] ?? ''),
            'missing_count' => (int) ($snapshot['missing_count'] ?? 0),
            'complete_count' => (int) ($snapshot['complete_count'] ?? 0),
            'repair_required' => (bool) ($snapshot['repair_required'] ?? false),
            'apply_supported' => (bool) ($snapshot['apply_supported'] ?? false),
            'readback_complete' => (bool) ($snapshot['readback_complete'] ?? false),
            'expected_confirmation' => $expectedConfirmation,
            'seo_meta_write_count' => 0,
            'category_write_count' => 0,
            'tag_mapping_write_count' => 0,
            'audit_write_count' => 0,
            'article_body_write_count' => 0,
            'revision_write_count' => 0,
            'publication_write_count' => 0,
            'indexability_write_count' => 0,
            'schema_write_count' => 0,
            'hreflang_write_count' => 0,
            'revalidation_count' => 0,
            'sitemap_eligibility_write_count' => 0,
            'llms_eligibility_write_count' => 0,
            'search_submission_count' => 0,
            'queue_dispatch_count' => 0,
            'deploy_count' => 0,
            'rows' => (array) ($snapshot['rows'] ?? []),
            'errors' => $errors,
        ];

        if (($summary['ok'] ?? false) === true && $execute) {
            try {
                $result = $service->apply($expectedState);
                $writes = (array) $result['writes'];
                $after = (array) $result['after'];
                $summary = array_merge($summary, [
                    'production_write_execution' => true,
                    'after_state_sha256' => (string) ($after['state_sha256'] ?? ''),
                    'missing_count' => (int) ($after['missing_count'] ?? 0),
                    'complete_count' => (int) ($after['complete_count'] ?? 0),
                    'repair_required' => (bool) ($after['repair_required'] ?? false),
                    'apply_supported' => (bool) ($after['apply_supported'] ?? false),
                    'readback_complete' => (bool) ($after['readback_complete'] ?? false),
                    'seo_meta_write_count' => (int) ($writes['seo_meta_write_count'] ?? 0),
                    'category_write_count' => (int) ($writes['category_write_count'] ?? 0),
                    'tag_mapping_write_count' => (int) ($writes['tag_mapping_write_count'] ?? 0),
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

    private function confirmation(string $stateSha256, string $targetSetSha256): string
    {
        return 'I explicitly approve SEO 13 legacy metadata bootstrap state '
            .$stateSha256.' target set '.$targetSetSha256.'.';
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

        $this->info(($summary['ok'] ?? false) === true ? 'SEO13 legacy metadata check passed.' : 'SEO13 legacy metadata check failed.');
    }
}
