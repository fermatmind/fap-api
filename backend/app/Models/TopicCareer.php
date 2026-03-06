<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicCareer extends Model
{
    use HasFactory;

    protected $table = 'topic_career_map';

    protected $fillable = [
        'topic_id',
        'career_id',
    ];

    protected $casts = [
        'topic_id' => 'integer',
        'career_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'topic_id', 'id');
    }
}
