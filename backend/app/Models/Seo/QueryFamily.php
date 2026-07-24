<?php

declare(strict_types=1);

namespace App\Models\Seo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QueryFamily extends Model
{
    protected $connection = 'seo_intel';

    protected $table = 'seo_query_families';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    public function queries(): HasMany
    {
        return $this->hasMany(QueryFamilyQuery::class, 'query_family_id');
    }

    public function urlBindings(): HasMany
    {
        return $this->hasMany(QueryUrlBinding::class, 'query_family_id');
    }
}
