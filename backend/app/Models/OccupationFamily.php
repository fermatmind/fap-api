<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class OccupationFamily extends CareerFoundationModel
{
    protected $table = 'occupation_families';

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function occupations(): HasMany
    {
        return $this->hasMany(Occupation::class, 'family_id', 'id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(OccupationAlias::class, 'family_id', 'id');
    }
}
