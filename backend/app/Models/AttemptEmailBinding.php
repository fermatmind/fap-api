<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttemptEmailBinding extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    protected $table = 'attempt_email_bindings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'org_id',
        'attempt_id',
        'pii_email_key_version',
        'email_hash',
        'email_enc',
        'bound_anon_id',
        'bound_user_id',
        'status',
        'source',
        'first_bound_at',
        'last_accessed_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'first_bound_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'attempt_id', 'id');
    }
}
