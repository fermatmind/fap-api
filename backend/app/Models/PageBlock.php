<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PageBlock extends Model
{
    use HasFactory;

    public const LATEST_PUBLISHED_INDEXABLE = 'latest_published_indexable';

    protected $table = 'page_blocks';

    protected $fillable = [
        'landing_surface_id',
        'block_key',
        'block_type',
        'title',
        'payload_json',
        'sort_order',
        'is_enabled',
    ];

    protected $casts = [
        'landing_surface_id' => 'integer',
        'payload_json' => 'array',
        'sort_order' => 'integer',
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function publicPayloadWithoutArticles(mixed $payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        $items = $payload['items'] ?? null;
        $selectionMode = strtolower(trim((string) ($payload['selection_mode'] ?? '')));
        if ((is_array($items) && $items !== []) || $selectionMode === self::LATEST_PUBLISHED_INDEXABLE) {
            $payload['items'] = [];
        }

        return $payload;
    }

    public function surface(): BelongsTo
    {
        return $this->belongsTo(LandingSurface::class, 'landing_surface_id', 'id');
    }
}
