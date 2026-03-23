<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportArtifactVersion extends Model
{
    protected $table = 'report_artifact_versions';

    protected $fillable = [
        'artifact_slot_id',
        'version_no',
        'source_type',
        'report_snapshot_id',
        'storage_blob_id',
        'created_from_receipt_id',
        'supersedes_version_id',
        'manifest_hash',
        'dir_version',
        'scoring_spec_version',
        'report_engine_version',
        'content_hash',
        'byte_size',
        'metadata_json',
    ];

    protected $casts = [
        'artifact_slot_id' => 'integer',
        'version_no' => 'integer',
        'created_from_receipt_id' => 'integer',
        'supersedes_version_id' => 'integer',
        'byte_size' => 'integer',
        'metadata_json' => 'array',
    ];
}
