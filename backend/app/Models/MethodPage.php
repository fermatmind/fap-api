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

class MethodPage extends Model
{
    use HasContentGovernance, HasFactory, HasIntentRegistry, HasOrgScope;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const SUPPORTED_LOCALES = [
        'en',
        'zh-CN',
    ];

    protected $table = 'method_pages';

    protected $fillable = [
        'org_id',
        'method_code',
        'slug',
        'locale',
        'title',
        'subtitle',
        'excerpt',
        'hero_kicker',
        'body_md',
        'body_html',
        'definition_summary_md',
        'boundary_notes_md',
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
        return $this->hasOne(MethodPageSeoMeta::class, 'method_page_id', 'id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MethodPageRevision::class, 'method_page_id', 'id')
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
