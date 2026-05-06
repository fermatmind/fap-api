<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalityProfileSection extends Model
{
    use HasFactory, HasOrgScope;

    public const SECTION_KEYS = [
        'hero',
        'core_snapshot',
        'strengths',
        'growth_edges',
        'work_style',
        'relationships',
        'communication',
        'stress_and_recovery',
        'career_fit',
        'faq',
        'related_content',
    ];

    public const RENDER_VARIANTS = [
        'rich_text',
        'bullets',
        'cards',
        'faq',
        'links',
        'callout',
        'letters_intro',
        'trait_dimension_grid',
        'preferred_role_list',
        'premium_teaser',
    ];

    protected $table = 'personality_profile_sections';

    protected $fillable = [
        'org_id',
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
        'org_id' => 'integer',
        'profile_id' => 'integer',
        'payload_json' => 'array',
        'sort_order' => 'integer',
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $section): void {
            if ((int) ($section->org_id ?? 0) > 0) {
                return;
            }

            $profile = PersonalityProfile::query()
                ->withoutGlobalScopes()
                ->find((int) $section->profile_id);

            if ($profile instanceof PersonalityProfile) {
                $section->org_id = (int) $profile->org_id;
            }
        });
    }

    public static function publicContextOrgId(): ?int
    {
        return 0;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PersonalityProfile::class, 'profile_id', 'id');
    }
}
