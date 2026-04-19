<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MediaVariant extends Model
{
    use HasFactory;

    protected $table = 'media_variants';

    protected $fillable = [
        'media_asset_id',
        'variant_key',
        'path',
        'url',
        'mime_type',
        'width',
        'height',
        'bytes',
        'payload_json',
    ];

    protected $casts = [
        'media_asset_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
        'payload_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id', 'id');
    }
}
