<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ArticleTag extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'article_tags';

    protected $fillable = [
        'org_id',
        'slug',
        'name',
        'is_active',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(
            Article::class,
            'article_tag_map',
            'tag_id',
            'article_id'
        )
            ->withPivot(['org_id', 'created_at'])
            ->withTimestamps();
    }
}
