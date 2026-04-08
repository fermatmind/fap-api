<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupationAlias extends CareerFoundationModel
{
    protected $table = 'occupation_aliases';

    protected $casts = [
        'precision_score' => 'float',
        'confidence_score' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(OccupationFamily::class, 'family_id', 'id');
    }
}
