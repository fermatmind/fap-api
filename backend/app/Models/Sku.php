<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sku extends Model
{
    protected $table = 'skus';
    protected $primaryKey = 'sku';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
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
    ];

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
