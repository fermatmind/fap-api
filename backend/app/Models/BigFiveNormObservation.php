<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class BigFiveNormObservation extends Model
{
    protected $table = 'big_five_norm_observations';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'observation_schema_version',
        'observation_idempotency_key',
        'observation_source',
        'environment',
        'scale_code',
        'form_code',
        'content_version',
        'score_version',
        'norm_version_at_scoring',
        'score_trace_hash',
        'norm_eligibility_status',
        'norm_excluded',
        'exclusion_reasons_json',
        'quality_level',
        'quality_flags_json',
        'locale',
        'region',
        'age_band',
        'gender_bucket',
        'occupation_bucket',
        'raw_domain_scores_json',
        'raw_facet_scores_json',
        'attempt_submitted_at',
        'observed_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'norm_excluded' => 'boolean',
        'exclusion_reasons_json' => 'array',
        'quality_flags_json' => 'array',
        'raw_domain_scores_json' => 'array',
        'raw_facet_scores_json' => 'array',
        'attempt_submitted_at' => 'datetime',
        'observed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Big Five norm observations are append-only and cannot be updated.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Big Five norm observations are append-only and cannot be deleted.');
        });
    }
}
