<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'topics';

    protected $fillable = [
        'org_id',
        'name',
        'slug',
        'description',
        'seo_title',
        'seo_description',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function articleMaps(): HasMany
    {
        return $this->hasMany(TopicArticle::class, 'topic_id', 'id');
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(
            Article::class,
            'topic_article_map',
            'topic_id',
            'article_id'
        )->withTimestamps();
    }

    public function personalities(): HasMany
    {
        return $this->hasMany(TopicPersonality::class, 'topic_id', 'id');
    }

    public function careers(): HasMany
    {
        return $this->hasMany(TopicCareer::class, 'topic_id', 'id');
    }
}
