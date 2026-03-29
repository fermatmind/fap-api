<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleRevision extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'article_revisions';

    public $timestamps = false;

    protected $fillable = [
        'org_id',
        'article_id',
        'revision_no',
        'editor_admin_user_id',
        'title',
        'excerpt',
        'content_md',
        'content_html',
        'change_note',
        'payload_json',
        'created_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'article_id' => 'integer',
        'revision_no' => 'integer',
        'editor_admin_user_id' => 'integer',
        'payload_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id', 'id');
    }
}
