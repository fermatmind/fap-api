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

    public const SCHEMA_VERSION_V1 = 'v1';

    public const SCHEMA_VERSION_V2 = 'v2';

    public const BASE_TYPE_CODES = [
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

    public const TYPE_CODES = self::BASE_TYPE_CODES;

    public const SUPPORTED_LOCALES = [
        'en',
        'zh-CN',
    ];

    protected $table = 'personality_profiles';

    protected $fillable = [
        'org_id',
        'scale_code',
        'type_code',
        'canonical_type_code',
        'slug',
        'locale',
        'title',
        'type_name',
        'nickname',
        'rarity_text',
        'keywords_json',
        'subtitle',
        'excerpt',
        'hero_kicker',
        'hero_quote',
        'hero_summary_md',
        'hero_summary_html',
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
        'keywords_json' => 'array',
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

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            $profile->scale_code = strtoupper(trim((string) $profile->scale_code));
            $profile->type_code = strtoupper(trim((string) $profile->type_code));
            $profile->canonical_type_code = self::resolveCanonicalTypeCode(
                (string) $profile->scale_code,
                $profile->canonical_type_code,
                (string) $profile->type_code
            );
            $profile->schema_version = self::normalizeSchemaVersion($profile->schema_version);
            $profile->keywords_json = self::normalizeKeywords($profile->keywords_json);
        });
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

    public function variants(): HasMany
    {
        return $this->hasMany(PersonalityProfileVariant::class, 'personality_profile_id', 'id')
            ->orderBy('variant_code')
            ->orderBy('id');
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

    private static function resolveCanonicalTypeCode(
        string $scaleCode,
        mixed $canonicalTypeCode,
        string $typeCode
    ): ?string {
        $normalizedCanonical = strtoupper(trim((string) $canonicalTypeCode));

        if ($scaleCode === self::SCALE_CODE_MBTI && in_array($typeCode, self::BASE_TYPE_CODES, true)) {
            return $typeCode;
        }

        return $normalizedCanonical !== '' ? $normalizedCanonical : null;
    }

    private static function normalizeSchemaVersion(mixed $schemaVersion): string
    {
        $normalized = trim((string) $schemaVersion);

        return $normalized === self::SCHEMA_VERSION_V2
            ? self::SCHEMA_VERSION_V2
            : self::SCHEMA_VERSION_V1;
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeKeywords(mixed $keywords): array
    {
        if (! is_array($keywords)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $keyword): ?string {
            if (! is_scalar($keyword)) {
                return null;
            }

            $normalized = trim((string) $keyword);

            return $normalized !== '' ? $normalized : null;
        }, $keywords)));
    }
}
