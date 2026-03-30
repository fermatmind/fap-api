<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Cms\SeoSchemaPolicyService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalityProfileVariantSeoMeta extends Model
{
    use HasFactory;

    protected $table = 'personality_profile_variant_seo_meta';

    protected $fillable = [
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
        'personality_profile_variant_id' => 'integer',
        'jsonld_overrides_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PersonalityProfileVariant::class, 'personality_profile_variant_id', 'id');
    }

    public function setJsonldOverridesJsonAttribute(mixed $value): void
    {
        if (! is_array($value)) {
            $this->attributes['jsonld_overrides_json'] = null;

            return;
        }

        $sanitized = SeoSchemaPolicyService::sanitizeStoredOverrides($value);
        $this->attributes['jsonld_overrides_json'] = $sanitized !== null
            ? json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
    }
}
