<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentAssignment extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'assessment_assignments';

    protected $fillable = [
        'org_id',
        'assessment_id',
        'subject_type',
        'subject_value',
        'invite_token',
        'started_at',
        'completed_at',
        'attempt_id',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'assessment_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }
}
