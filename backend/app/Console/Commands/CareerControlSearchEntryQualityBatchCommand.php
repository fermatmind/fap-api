<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Review\CareerSearchEntryQualityBatchControlService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Separately authorized apply/readback/rollback gate for one exact batch.
 *
 * @review-surface career_trust_manifest
 */
final class CareerControlSearchEntryQualityBatchCommand extends Command
{
    protected $signature = 'career:control-search-entry-quality-batch
        {--mode=preflight : preflight, apply, readback, or rollback}
        {--expected-package= : Exact private quality package path}
        {--active-release-sha= : Exact active production REVISION}
        {--active-release-name= : Exact active production release name}
        {--operation-id= : Exact authorized operation ID}
        {--rollback-identifier= : Exact rollback identifier}
        {--actor-admin-user-id= : Configured solo-owner admin user ID}
        {--expected-review-evidence-sha256= : Exact approved-all review evidence SHA}
        {--expected-apply-receipt-sha256= : Rollback only; exact apply receipt SHA}
        {--expected-rollback-authorization-sha256= : Rollback only; exact authorization SHA}
        {--json : Emit safe machine-readable JSON}';

    protected $description = 'Preflight, apply, read back, or rollback exact Career search-entry batch authority.';

    public function handle(CareerSearchEntryQualityBatchControlService $control): int
    {
        $mode = strtolower(trim((string) $this->option('mode')));
        try {
            $options = [
                'active_release_sha' => $this->option('active-release-sha'),
                'active_release_name' => $this->option('active-release-name'),
                'operation_id' => $this->option('operation-id'),
                'rollback_identifier' => $this->option('rollback-identifier'),
                'actor_admin_user_id' => $this->option('actor-admin-user-id'),
                'expected_review_evidence_sha256' => $this->option('expected-review-evidence-sha256'),
                'expected_apply_receipt_sha256' => $this->option('expected-apply-receipt-sha256'),
                'expected_rollback_authorization_sha256' => $this->option(
                    'expected-rollback-authorization-sha256'
                ),
            ];
            $payload = match ($mode) {
                'preflight' => $control->operationPreflight(
                    (string) $this->option('expected-package'),
                    $options,
                ),
                'apply' => $control->apply(
                    (string) $this->option('expected-package'),
                    $options,
                ),
                'readback' => $control->readback(
                    (string) $this->option('expected-package'),
                    $options,
                ),
                'rollback' => $control->rollback(
                    (string) $this->option('expected-package'),
                    $options,
                ),
                default => throw new \RuntimeException('--mode must be preflight, apply, readback, or rollback.'),
            };

            return $this->finish($payload);
        } catch (Throwable $throwable) {
            return $this->finish([
                'status' => 'HOLD_CAREER_SEARCH_ENTRY_BATCH_CONTROL',
                'mode' => $mode,
                'error' => (bool) $this->option('json')
                    ? 'Career search-entry batch control failed closed.'
                    : $throwable->getMessage(),
                'operation_write_count' => 0,
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
                'review_evidence_sha256',
                'preflight_state_sha256',
                'operation_receipt_sha256',
                'rollback_authorization_sha256',
                'rollback_receipt_sha256',
            ] as $field) {
                if (array_key_exists($field, $payload)) {
                    $this->line($field.'='.(string) ($payload[$field] ?? ''));
                }
            }
        }

        return $exitCode;
    }
}
