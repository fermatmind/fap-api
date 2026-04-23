<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CmsTranslationRevision extends Model
{
    use HasFactory;

    public const STATUS_SOURCE = 'source';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_MACHINE_DRAFT = 'machine_draft';

    public const STATUS_HUMAN_REVIEW = 'human_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_STALE = 'stale';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'cms_translation_revisions';

    protected $fillable = [
        'org_id',
        'content_type',
        'content_id',
        'source_content_id',
        'translation_group_id',
        'locale',
        'source_locale',
        'revision_number',
        'revision_status',
        'source_version_hash',
        'translated_from_version_hash',
        'payload_json',
        'supersedes_revision_id',
        'created_by_admin_id',
        'reviewed_at',
        'approved_at',
        'archived_at',
        'published_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'content_id' => 'integer',
        'source_content_id' => 'integer',
        'revision_number' => 'integer',
        'payload_json' => 'array',
        'supersedes_revision_id' => 'integer',
        'created_by_admin_id' => 'integer',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'archived_at' => 'datetime',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_revision_id');
    }
}
