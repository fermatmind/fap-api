<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ReviewAttestationTargetEvidence extends Model
{
    protected $table = 'review_attestation_target_evidences';

    protected $fillable = [
        'review_attestation_id',
        'target_identity',
        'target_sha256',
        'target_decision',
        'exception_json',
        'evidence_sha256',
    ];

    protected $casts = [
        'review_attestation_id' => 'integer',
        'exception_json' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<ReviewAttestation, $this>
     */
    public function attestation(): BelongsTo
    {
        return $this->belongsTo(ReviewAttestation::class, 'review_attestation_id');
    }

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Expanded review target evidence is immutable.');
        });
        self::deleting(static function (): void {
            throw new LogicException('Expanded review target evidence cannot be deleted through the application.');
        });
    }
}
