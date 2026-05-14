<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleEditorialPackageImport extends Model
{
    use HasOrgScope;

    public const STATUS_DRY_RUN_PASSED = 'dry_run_passed';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    protected $table = 'article_editorial_package_imports';

    protected $fillable = [
        'org_id',
        'article_id',
        'slug',
        'locale',
        'title',
        'content_track',
        'status',
        'intended_status',
        'validation_summary_json',
        'claim_result_json',
        'exactness_json',
        'references_json',
        'media_json',
        'graph_json',
        'answer_surface_json',
        'body_hash',
        'heading_sequence_json',
        'references_count',
        'missing_fields_json',
        'blocked_reasons_json',
        'imported_by',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'article_id' => 'integer',
        'validation_summary_json' => 'array',
        'claim_result_json' => 'array',
        'exactness_json' => 'array',
        'references_json' => 'array',
        'media_json' => 'array',
        'graph_json' => 'array',
        'answer_surface_json' => 'array',
        'heading_sequence_json' => 'array',
        'references_count' => 'integer',
        'missing_fields_json' => 'array',
        'blocked_reasons_json' => 'array',
        'imported_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id', 'id')->withoutGlobalScopes();
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'imported_by', 'id');
    }
}
