<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalityProfileVariantSeoMeta extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'personality_profile_variant_seo_meta';

    protected $fillable = [
        'org_id',
        'personality_profile_variant_id',
        'seo_title',
        'seo_description',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image_url',
        'twitter_title',
        'twitter_description',
        'twitter_image_url',
        'robots',
        'jsonld_overrides_json',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'personality_profile_variant_id' => 'integer',
        'jsonld_overrides_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $seoMeta): void {
            if ((int) ($seoMeta->org_id ?? 0) > 0) {
                return;
            }

            $variant = PersonalityProfileVariant::query()
                ->withoutGlobalScopes()
                ->find((int) $seoMeta->personality_profile_variant_id);

            if ($variant instanceof PersonalityProfileVariant) {
                $seoMeta->org_id = (int) $variant->org_id;
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
