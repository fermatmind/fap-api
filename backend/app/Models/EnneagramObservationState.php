<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class EnneagramObservationState extends Model
{
    use HasOrgScope;
    use HasUuids;

    protected $table = 'enneagram_observation_states';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'org_id',
        'user_id',
        'anon_id',
        'attempt_id',
        'scale_code',
        'form_code',
        'interpretation_context_id',
        'status',
        'assigned_at',
        'day3_submitted_at',
        'day7_submitted_at',
        'resonance_feedback_submitted_at',
        'user_confirmed_at',
        'user_confirmed_type',
        'user_disagreed_reason',
        'resonance_score',
        'observation_completion_rate',
        'suggested_next_action',
        'payload_json',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'payload_json' => 'array',
        'assigned_at' => 'datetime',
        'day3_submitted_at' => 'datetime',
        'day7_submitted_at' => 'datetime',
        'resonance_feedback_submitted_at' => 'datetime',
        'user_confirmed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function publicContextOrgId(): ?int
    {
        return self::resolvedPublicContextOrgId();
    }
}
