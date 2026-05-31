<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class EqJourneyState extends Model
{
    use HasOrgScope;
    use HasUuids;

    protected $table = 'eq_journey_states';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'org_id',
        'user_id',
        'anon_id',
        'attempt_id',
        'scale_code',
        'eq_report_mode',
        'core_formulation_id',
        'route_id',
        'quality_level',
        'confidence_label',
        'status',
        'read_depth',
        'result_resonance',
        'action_completion',
        'retest_intent',
        'consent_to_store',
        'resonance_feedback_submitted_at',
        'action_completed_at',
        'retest_intent_recorded_at',
        'payload_json',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'consent_to_store' => 'boolean',
        'payload_json' => 'array',
        'resonance_feedback_submitted_at' => 'datetime',
        'action_completed_at' => 'datetime',
        'retest_intent_recorded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function publicContextOrgId(): ?int
    {
        return self::resolvedPublicContextOrgId();
    }
}
