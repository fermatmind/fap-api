<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class CareerSearchEntryQualityBatchOperation extends Model
{
    protected $fillable = [
        'schema_version',
        'task_id',
        'operation_id',
        'operation_type',
        'active_release_sha',
        'active_release_name',
        'quality_package_sha256',
        'review_package_sha256',
        'target_set_sha256',
        'candidate_count',
        'bilingual_url_count',
        'review_attestation_id',
        'review_evidence_sha256',
        'actor_admin_user_id',
        'rollback_identifier',
        'apply_receipt_sha256',
        'receipt_sha256',
        'canonical_receipt_json',
    ];

    protected $casts = [
        'candidate_count' => 'integer',
        'bilingual_url_count' => 'integer',
        'review_attestation_id' => 'integer',
        'actor_admin_user_id' => 'integer',
        'canonical_receipt_json' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<ReviewAttestation, $this> */
    public function reviewAttestation(): BelongsTo
    {
        return $this->belongsTo(ReviewAttestation::class, 'review_attestation_id');
    }

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Career search-entry batch operation receipts are immutable.');
        });
        self::deleting(static function (): void {
            throw new LogicException('Career search-entry batch operation receipts cannot be deleted.');
        });
    }
}
