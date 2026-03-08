<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicProfileSection extends Model
{
    use HasFactory;

    public const SECTION_KEYS = [
        'hero',
        'overview',
        'key_concepts',
        'why_it_matters',
        'who_should_read',
        'faq',
        'related_topics_intro',
    ];

    public const RENDER_VARIANTS = [
        'rich_text',
        'bullets',
        'cards',
        'faq',
        'links',
        'callout',
    ];

    protected $table = 'topic_profile_sections';

    protected $fillable = [
        'profile_id',
        'section_key',
        'title',
        'render_variant',
        'body_md',
        'body_html',
        'payload_json',
        'sort_order',
        'is_enabled',
    ];

    protected $casts = [
        'profile_id' => 'integer',
        'payload_json' => 'array',
        'sort_order' => 'integer',
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TopicProfile::class, 'profile_id', 'id');
    }
}
