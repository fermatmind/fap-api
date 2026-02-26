<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScaleSlug extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'scale_slugs';

    protected $fillable = [
        'org_id',
        'slug',
        'scale_code',
        'is_primary',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'is_primary' => 'boolean',
    ];

    /**
     * @param  list<int>  $orgIds
     */
    public static function queryByOrgWhitelist(array $orgIds): Builder
    {
        $normalizedOrgIds = self::normalizeOrgWhitelist($orgIds);

        $query = static::query()->withoutGlobalScope(TenantScope::class);
        if ($normalizedOrgIds === []) {
            return $query->where('org_id', -1);
        }

        return $query->whereIn('org_id', $normalizedOrgIds);
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    private static function normalizeOrgWhitelist(array $orgIds): array
    {
        $set = [];
        foreach ($orgIds as $orgId) {
            $normalized = (int) $orgId;
            if ($normalized < 0) {
                continue;
            }
            $set[$normalized] = true;
        }

        return array_values(array_keys($set));
    }
}
