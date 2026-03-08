<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicProfileEntry extends Model
{
    use HasFactory;

    public const ENTRY_TYPES = [
        'article',
        'personality_profile',
        'scale',
        'custom_link',
    ];

    public const GROUP_KEYS = [
        'featured',
        'articles',
        'personalities',
        'tests',
        'related',
    ];

    protected $table = 'topic_profile_entries';

    protected $fillable = [
        'profile_id',
        'entry_type',
        'group_key',
        'target_key',
        'target_locale',
        'title_override',
        'excerpt_override',
        'badge_label',
        'cta_label',
        'target_url_override',
        'payload_json',
        'sort_order',
        'is_featured',
        'is_enabled',
    ];

    protected $casts = [
        'profile_id' => 'integer',
        'payload_json' => 'array',
        'sort_order' => 'integer',
        'is_featured' => 'boolean',
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TopicProfile::class, 'profile_id', 'id');
    }

    public function isCustomLink(): bool
    {
        return $this->entry_type === 'custom_link';
    }

    public function effectiveTargetLocale(?string $fallbackLocale = null): ?string
    {
        return $this->target_locale ?: $fallbackLocale;
    }
}
