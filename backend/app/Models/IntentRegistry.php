<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IntentRegistry extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'intent_registry';

    protected $fillable = [
        'org_id',
        'page_type',
        'primary_query',
        'canonical_governable_type',
        'canonical_governable_id',
        'resolution_strategy',
        'exception_reason',
        'latest_similarity_score',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'canonical_governable_id' => 'integer',
        'latest_similarity_score' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function governable(): MorphTo
    {
        return $this->morphTo();
    }

    public function canonicalGovernable(): MorphTo
    {
        return $this->morphTo('canonical_governable');
    }
}
