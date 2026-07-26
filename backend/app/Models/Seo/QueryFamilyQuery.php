<?php

declare(strict_types=1);

namespace App\Models\Seo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QueryFamilyQuery extends Model
{
    protected $connection = 'seo_intel';

    protected $table = 'seo_query_family_queries';

    protected $guarded = [];

    public function family(): BelongsTo
    {
        return $this->belongsTo(QueryFamily::class, 'query_family_id');
    }
}
