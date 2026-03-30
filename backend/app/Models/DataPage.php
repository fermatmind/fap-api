<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasContentGovernance;
use App\Models\Concerns\HasIntentRegistry;
use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DataPage extends Model
{
    use HasContentGovernance, HasFactory, HasIntentRegistry, HasOrgScope;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const SUPPORTED_LOCALES = [
        'en',
        'zh-CN',
    ];

    protected $table = 'data_pages';

    protected $fillable = [
        'org_id',
        'data_code',
        'slug',
        'locale',
        'title',
        'subtitle',
        'excerpt',
        'hero_kicker',
        'body_md',
        'body_html',
        'sample_size_label',
        'time_window_label',
        'methodology_md',
        'limitations_md',
        'summary_statement_md',
        'cover_image_url',
        'status',
        'is_public',
        'is_indexable',
        'published_at',
        'scheduled_at',
        'schema_version',
        'sort_order',
        'created_by_admin_user_id',
        'updated_by_admin_user_id',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'sort_order' => 'integer',
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

    public function seoMeta(): HasOne
    {
        return $this->hasOne(DataPageSeoMeta::class, 'data_page_id', 'id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DataPageRevision::class, 'data_page_id', 'id')
            ->orderByDesc('revision_no')
            ->orderByDesc('id');
    }

    public function scopePublishedPublic($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('is_public', true);
    }

    public function scopeIndexable($query)
    {
        return $query->where('is_indexable', true);
    }

    public function scopeForLocale($query, string $locale)
    {
        return $query->where('locale', trim($locale));
    }

    public function scopeForSlug($query, string $slug)
    {
        return $query->where('slug', strtolower(trim($slug)));
    }
}
