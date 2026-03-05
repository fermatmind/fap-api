<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleSeoMeta extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'article_seo_meta';

    protected $fillable = [
        'org_id',
        'article_id',
        'locale',
        'seo_title',
        'seo_description',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image_url',
        'robots',
        'schema_json',
        'is_indexable',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'article_id' => 'integer',
        'schema_json' => 'array',
        'is_indexable' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id', 'id');
    }
}

