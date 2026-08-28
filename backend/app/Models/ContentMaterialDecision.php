<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ContentMaterialDecision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'org_id' => 'integer',
        'material_changed' => 'boolean',
        'material_changed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Content material decisions are append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Content material decisions are append-only.');
        });
    }
}
