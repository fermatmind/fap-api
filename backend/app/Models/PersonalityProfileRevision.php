<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalityProfileRevision extends Model
{
    use HasFactory;

    protected $table = 'personality_profile_revisions';

    public $timestamps = false;

    protected $fillable = [
        'profile_id',
        'revision_no',
        'snapshot_json',
        'note',
        'created_by_admin_user_id',
        'created_at',
    ];

    protected $casts = [
        'profile_id' => 'integer',
        'revision_no' => 'integer',
        'snapshot_json' => 'array',
        'created_by_admin_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PersonalityProfile::class, 'profile_id', 'id');
    }
}
