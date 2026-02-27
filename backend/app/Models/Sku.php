<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Sku extends Model
{
    protected $table = 'skus';
    protected $primaryKey = 'sku';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'org_id',
        'sku',
        'scale_code',
        'kind',
        'unit_qty',
        'benefit_code',
        'scope',
        'price_cents',
        'currency',
        'is_active',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'is_active' => 'bool',
        'unit_qty' => 'int',
        'price_cents' => 'int',
        'org_id' => 'int',
    ];

    /**
     * @param  Builder<Sku>  $query
     */
    public function scopeForOrg(Builder $query, int $orgId, bool $includeGlobal = true): Builder
    {
        $candidates = self::orgCandidates($orgId, $includeGlobal);

        return $query->whereIn('org_id', $candidates);
    }

    /**
     * @return list<int>
     */
    public static function orgCandidates(int $orgId, bool $includeGlobal = true): array
    {
        $candidates = [];
        if ($orgId > 0) {
            $candidates[] = $orgId;
        }

        if ($includeGlobal) {
            $candidates[] = 0;
            $legacyOrgId = (int) config('fap.legacy_org_id', 1);
            if ($legacyOrgId > 0) {
                $candidates[] = $legacyOrgId;
            }
        }

        if ($candidates === []) {
            $candidates[] = 0;
        }

        $candidates = array_values(array_unique(array_map(
            static fn (mixed $value): int => (int) $value,
            $candidates
        )));
        sort($candidates);

        return $candidates;
    }

    public function getMetadataJsonAttribute(): array
    {
        $value = $this->meta_json ?? [];
        return is_array($value) ? $value : [];
    }

    public function setMetadataJsonAttribute(mixed $value): void
    {
        $this->attributes['meta_json'] = $value;
    }
}
