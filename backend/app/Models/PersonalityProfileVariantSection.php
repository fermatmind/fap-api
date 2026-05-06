<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalityProfileVariantSection extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'personality_profile_variant_sections';

    protected $fillable = [
        'org_id',
        'personality_profile_variant_id',
        'section_key',
        'render_variant',
        'body_md',
        'body_html',
        'payload_json',
        'sort_order',
        'is_enabled',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'personality_profile_variant_id' => 'integer',
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

            $variant = PersonalityProfileVariant::query()
                ->withoutGlobalScopes()
                ->find((int) $section->personality_profile_variant_id);

            if ($variant instanceof PersonalityProfileVariant) {
                $section->org_id = (int) $variant->org_id;
            }
        });
    }

    public static function publicContextOrgId(): ?int
    {
        return 0;
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PersonalityProfileVariant::class, 'personality_profile_variant_id', 'id');
    }
}
