<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LandingSurface extends Model
{
    use HasFactory, HasOrgScope;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'landing_surfaces';

    protected $fillable = [
        'org_id',
        'surface_key',
        'locale',
        'title',
        'description',
        'schema_version',
        'payload_json',
        'status',
        'is_public',
        'is_indexable',
        'published_at',
        'scheduled_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'payload_json' => 'array',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(PageBlock::class, 'landing_surface_id', 'id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function scopePublishedPublic($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('is_public', true);
    }
}
