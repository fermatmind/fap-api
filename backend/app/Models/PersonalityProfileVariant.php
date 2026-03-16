<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class PersonalityProfileVariant extends Model
{
    use HasFactory;

    protected $table = 'personality_profile_variants';

    protected $fillable = [
        'personality_profile_id',
        'canonical_type_code',
        'variant_code',
        'runtime_type_code',
        'type_name',
        'nickname',
        'rarity_text',
        'keywords_json',
        'hero_summary_md',
        'hero_summary_html',
        'schema_version',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'personality_profile_id' => 'integer',
        'keywords_json' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            $variant->canonical_type_code = strtoupper(trim((string) $variant->canonical_type_code));
            $variant->variant_code = strtoupper(trim((string) $variant->variant_code));
            $variant->runtime_type_code = strtoupper(trim((string) $variant->runtime_type_code));
            $variant->schema_version = trim((string) $variant->schema_version) !== ''
                ? trim((string) $variant->schema_version)
                : PersonalityProfile::SCHEMA_VERSION_V2;
            $variant->keywords_json = self::normalizeKeywords($variant->keywords_json);

            if (! in_array($variant->variant_code, ['A', 'T'], true)) {
                throw new InvalidArgumentException('Personality profile variant_code must be A or T.');
            }

            if (preg_match('/^(?<base>[EI][SN][TF][JP])-(?<variant>[AT])$/', $variant->runtime_type_code, $matches) !== 1) {
                throw new InvalidArgumentException('Personality profile runtime_type_code must be a full MBTI runtime identity such as ENFJ-A.');
            }

            $baseType = (string) $matches['base'];
            $variantCode = (string) $matches['variant'];

            if ($variant->canonical_type_code === '') {
                $variant->canonical_type_code = $baseType;
            }

            if ($variant->canonical_type_code !== $baseType) {
                throw new InvalidArgumentException('Personality profile canonical_type_code must match the runtime_type_code base type.');
            }

            if ($variant->variant_code !== $variantCode) {
                throw new InvalidArgumentException('Personality profile variant_code must match the runtime_type_code suffix.');
            }
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PersonalityProfile::class, 'personality_profile_id', 'id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PersonalityProfileVariantSection::class, 'personality_profile_variant_id', 'id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function seoMeta(): HasOne
    {
        return $this->hasOne(PersonalityProfileVariantSeoMeta::class, 'personality_profile_variant_id', 'id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PersonalityProfileVariantRevision::class, 'personality_profile_variant_id', 'id')
            ->orderByDesc('revision_no')
            ->orderByDesc('id');
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
