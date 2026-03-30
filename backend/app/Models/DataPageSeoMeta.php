<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Cms\SeoSchemaPolicyService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataPageSeoMeta extends Model
{
    use HasFactory;

    protected $table = 'data_page_seo_meta';

    protected $fillable = [
        'data_page_id',
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
        'data_page_id' => 'integer',
        'jsonld_overrides_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(DataPage::class, 'data_page_id', 'id');
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
