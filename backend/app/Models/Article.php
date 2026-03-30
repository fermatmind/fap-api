<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasContentGovernance;
use App\Models\Concerns\HasIntentRegistry;
use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Article extends Model
{
    use HasContentGovernance, HasFactory, HasIntentRegistry, HasOrgScope;

    protected $table = 'articles';

    protected $fillable = [
        'org_id',
        'category_id',
        'author_admin_user_id',
        'slug',
        'locale',
        'title',
        'excerpt',
        'content_md',
        'content_html',
        'cover_image_url',
        'status',
        'lifecycle_state',
        'lifecycle_changed_at',
        'lifecycle_changed_by_admin_user_id',
        'lifecycle_note',
        'is_public',
        'is_indexable',
        'published_at',
        'scheduled_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'category_id' => 'integer',
        'author_admin_user_id' => 'integer',
        'lifecycle_changed_by_admin_user_id' => 'integer',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
        'lifecycle_changed_at' => 'datetime',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id', 'id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            ArticleTag::class,
            'article_tag_map',
            'article_id',
            'tag_id'
        );
    }

    public function scopePublished($query)
    {
        return $query
            ->where('status', 'published')
            ->where('is_public', true);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleRevision::class, 'article_id', 'id');
    }

    public function seoMeta(): HasOne
    {
        return $this->hasOne(ArticleSeoMeta::class, 'article_id', 'id');
    }

    public static function findBySlug(string $slug, string $locale = 'en'): ?self
    {
        return static::query()
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->first();
    }
}
