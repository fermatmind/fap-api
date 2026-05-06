<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalityProfileSeoMeta extends Model
{
    use HasFactory, HasOrgScope;

    public const PROFILE_FOREIGN_KEY = 'profile_id';

    protected $table = 'personality_profile_seo_meta';

    protected $fillable = [
        'org_id',
        'profile_id',
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
        'profile_id' => 'integer',
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

            $profile = PersonalityProfile::query()
                ->withoutGlobalScopes()
                ->find((int) $seoMeta->profile_id);

            if ($profile instanceof PersonalityProfile) {
                $seoMeta->org_id = (int) $profile->org_id;
            }
        });
    }

    public static function publicContextOrgId(): ?int
    {
        return 0;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PersonalityProfile::class, self::PROFILE_FOREIGN_KEY, 'id');
    }
}
