<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectionLineage extends CareerImmutableFoundationModel
{
    protected $table = 'projection_lineages';

    protected $casts = [
        'diff_summary' => 'array',
        'created_at' => 'datetime',
    ];

    public function parentProjection(): BelongsTo
    {
        return $this->belongsTo(ProfileProjection::class, 'parent_projection_id', 'id');
    }

    public function childProjection(): BelongsTo
    {
        return $this->belongsTo(ProfileProjection::class, 'child_projection_id', 'id');
    }

    public function triggerContextSnapshot(): BelongsTo
    {
        return $this->belongsTo(ContextSnapshot::class, 'trigger_context_snapshot_id', 'id');
    }
}
