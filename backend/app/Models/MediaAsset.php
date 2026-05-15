<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MediaAsset extends Model
{
    use HasFactory, HasOrgScope;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const SYNC_PENDING = 'pending';

    public const SYNC_SYNCED = 'synced';

    public const SYNC_SKIPPED = 'skipped';

    public const SYNC_FAILED = 'failed';

    public const CDN_NOT_VERIFIED = 'not_verified';

    public const CDN_VERIFIED = 'verified';

    public const CDN_SKIPPED = 'skipped';

    public const CDN_FAILED = 'failed';

    protected $table = 'media_assets';

    protected $fillable = [
        'org_id',
        'asset_key',
        'disk',
        'path',
        'url',
        'mime_type',
        'width',
        'height',
        'bytes',
        'alt',
        'caption',
        'credit',
        'status',
        'is_public',
        'sync_status',
        'cdn_status',
        'synced_at',
        'verified_at',
        'last_error',
        'uploaded_by_admin_user_id',
        'payload_json',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
        'is_public' => 'boolean',
        'synced_at' => 'datetime',
        'verified_at' => 'datetime',
        'uploaded_by_admin_user_id' => 'integer',
        'payload_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class, 'media_asset_id', 'id')
            ->orderBy('variant_key');
    }

    public function scopePublishedPublic($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('is_public', true);
    }
}
