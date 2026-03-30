<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Cms\SeoSchemaPolicyService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalityProfileSeoMeta extends Model
{
    use HasFactory;

    public const PROFILE_FOREIGN_KEY = 'profile_id';

    protected $table = 'personality_profile_seo_meta';

    protected $fillable = [
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
        'profile_id' => 'integer',
        'jsonld_overrides_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PersonalityProfile::class, self::PROFILE_FOREIGN_KEY, 'id');
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
