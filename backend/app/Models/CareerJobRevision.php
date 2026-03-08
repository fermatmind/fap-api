<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerJobRevision extends Model
{
    use HasFactory;

    protected $table = 'career_job_revisions';

    public $timestamps = false;

    protected $fillable = [
        'job_id',
        'revision_no',
        'snapshot_json',
        'note',
        'created_by_admin_user_id',
        'created_at',
    ];

    protected $casts = [
        'job_id' => 'integer',
        'revision_no' => 'integer',
        'snapshot_json' => 'array',
        'created_by_admin_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(CareerJob::class, 'job_id', 'id');
    }
}
