<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'actor_admin_id',
        'action',
        'target_type',
        'target_id',
        'meta_json',
        'ip',
        'user_agent',
        'request_id',
        'created_at',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_admin_id');
    }
}
