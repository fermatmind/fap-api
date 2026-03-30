<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MethodPageRevision extends Model
{
    use HasFactory;

    protected $table = 'method_page_revisions';

    public $timestamps = false;

    protected $fillable = [
        'method_page_id',
        'revision_no',
        'snapshot_json',
        'note',
        'created_by_admin_user_id',
        'created_at',
    ];

    protected $casts = [
        'method_page_id' => 'integer',
        'revision_no' => 'integer',
        'snapshot_json' => 'array',
        'created_by_admin_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(MethodPage::class, 'method_page_id', 'id');
    }
}
