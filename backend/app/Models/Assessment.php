<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'assessments';

    protected $fillable = [
        'org_id',
        'scale_code',
        'title',
        'created_by',
        'due_at',
        'status',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'created_by' => 'integer',
        'due_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(AssessmentAssignment::class, 'assessment_id');
    }
}
