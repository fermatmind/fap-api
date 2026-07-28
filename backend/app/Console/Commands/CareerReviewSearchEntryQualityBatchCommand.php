<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Review\CareerSearchEntryQualityBatchControlService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Review-only gate for the exact first Career search-entry batch.
 *
 * @review-surface career_trust_manifest
 */
final class CareerReviewSearchEntryQualityBatchCommand extends Command
{
    protected $signature = 'career:review-search-entry-quality-batch
        {--expected-package= : Exact private quality package path}
        {--actor-admin-user-id= : Configured solo-owner admin user ID}
        {--bind : Bind exact approved-all evidence; omitted is zero-write preflight}
        {--json : Emit safe machine-readable JSON}';

    protected $description = 'Preflight or bind exact review evidence without applying search-entry eligibility.';

    public function handle(CareerSearchEntryQualityBatchControlService $control): int
    {
        try {
            $actor = filter_var($this->option('actor-admin-user-id'), FILTER_VALIDATE_INT);
            if (! is_int($actor) || $actor <= 0) {
                throw new \RuntimeException('--actor-admin-user-id must be a positive integer.');
            }
            $payload = (bool) $this->option('bind')
                ? $control->bindReview((string) $this->option('expected-package'), $actor)
                : $control->reviewPreflight((string) $this->option('expected-package'), $actor);

            return $this->finish($payload);
        } catch (Throwable $throwable) {
            return $this->finish([
                'status' => 'HOLD_CAREER_SEARCH_ENTRY_REVIEW',
                'error' => (bool) $this->option('json')
                    ? 'Career search-entry review gate failed closed.'
                    : $throwable->getMessage(),
                'review_write_count' => 0,
                'production_write_execution' => false,
            ], self::FAILURE);
        }
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exitCode = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            foreach ([
                'status',
                'quality_package_sha256',
                'review_package_sha256',
                'target_set_sha256',
                'candidate_count',
                'bilingual_url_count',
                'review_target_count',
                'review_state',
                'review_evidence_sha256',
                'preflight_state_sha256',
            ] as $field) {
                if (array_key_exists($field, $payload)) {
                    $this->line($field.'='.(string) ($payload[$field] ?? ''));
                }
            }
        }

        return $exitCode;
    }
}
