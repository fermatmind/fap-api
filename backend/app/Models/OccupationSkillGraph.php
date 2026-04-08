<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupationSkillGraph extends CareerFoundationModel
{
    protected $table = 'occupation_skill_graphs';

    protected $casts = [
        'skill_overlap_graph' => 'array',
        'task_overlap_graph' => 'array',
        'tool_overlap_graph' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(CareerImportRun::class, 'import_run_id', 'id');
    }
}
