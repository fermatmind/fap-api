<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetentionPolicy extends Model
{
    protected $table = 'retention_policies';

    protected $fillable = [
        'code',
        'subject_scope',
        'artifact_scope',
        'archive_after_days',
        'shrink_after_days',
        'purge_after_days',
        'delete_behavior',
        'delete_remote_archive',
        'active',
    ];

    protected $casts = [
        'archive_after_days' => 'integer',
        'shrink_after_days' => 'integer',
        'purge_after_days' => 'integer',
        'delete_remote_archive' => 'boolean',
        'active' => 'boolean',
    ];
}
