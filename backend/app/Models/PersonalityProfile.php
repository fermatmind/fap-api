<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PersonalityProfile extends Model
{
    use HasFactory, HasOrgScope;

    public const SCALE_CODE_MBTI = 'MBTI';

    public const TYPE_CODES = [
        'INTJ',
        'INTP',
        'ENTJ',
        'ENTP',
        'INFJ',
        'INFP',
        'ENFJ',
        'ENFP',
        'ISTJ',
        'ISFJ',
        'ESTJ',
        'ESFJ',
        'ISTP',
        'ISFP',
        'ESTP',
        'ESFP',
    ];

    public const SUPPORTED_LOCALES = [
        'en',
        'zh-CN',
    ];

    protected $table = 'personality_profiles';

    protected $fillable = [
        'org_id',
        'scale_code',
        'type_code',
        'slug',
        'locale',
        'title',
        'subtitle',
        'excerpt',
        'hero_kicker',
        'hero_quote',
        'hero_image_url',
        'status',
        'is_public',
        'is_indexable',
        'published_at',
        'scheduled_at',
        'schema_version',
        'created_by_admin_user_id',
        'updated_by_admin_user_id',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'created_by_admin_user_id' => 'integer',
        'updated_by_admin_user_id' => 'integer',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PersonalityProfileSection::class, 'profile_id', 'id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function seoMeta(): HasOne
    {
        return $this->hasOne(PersonalityProfileSeoMeta::class, 'profile_id', 'id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PersonalityProfileRevision::class, 'profile_id', 'id')
            ->orderByDesc('revision_no')
            ->orderByDesc('id');
    }

    public function scopePublishedPublic($query)
    {
        return $query
            ->where('status', 'published')
            ->where('is_public', true);
    }

    public function scopeForLocale($query, string $locale)
    {
        return $query->where('locale', $locale);
    }

    public function scopeForType($query, string $typeCode)
    {
        return $query->where('type_code', strtoupper($typeCode));
    }
}
