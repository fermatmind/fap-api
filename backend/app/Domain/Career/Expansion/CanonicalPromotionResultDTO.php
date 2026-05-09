<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

final class CanonicalPromotionResultDTO
{
    /**
     * @param  array<string, mixed>  $promotionPlan
     * @param  array<string, mixed>  $postPromotionValidation
     * @param  array<string, mixed>|null  $rollback
     * @param  list<array<string, mixed>>  $auditLog
     * @param  list<array<string, mixed>>  $failures
     * @param  array<string, mixed>|null  $updatedManifest
     */
    public function __construct(
        public readonly string $status,
        public readonly CanonicalPromotionTransaction $transaction,
        public readonly bool $dryRun,
        public readonly array $promotionPlan,
        public readonly array $postPromotionValidation = [],
        public readonly ?array $rollback = null,
        public readonly array $auditLog = [],
        public readonly array $failures = [],
        public readonly ?array $updatedManifest = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'transaction' => $this->transaction->toArray(),
            'dry_run' => $this->dryRun,
            'read_only' => true,
            'writes_database' => false,
            'promotion_plan' => $this->promotionPlan,
            'post_promotion_validation' => $this->postPromotionValidation,
            'rollback' => $this->rollback,
            'audit_log' => $this->auditLog,
            'failures' => $this->failures,
            'updated_manifest' => $this->updatedManifest,
        ];
    }
}
