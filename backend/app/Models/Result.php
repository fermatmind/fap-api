<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Result extends Model
{
    use HasFactory;

    /**
     * 对应的表名
     */
    protected $table = 'results';

    /**
     * 主键是字符串 UUID，不是自增 int
     */
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * 允许批量写入的字段
     */
    protected $fillable = [
        'id',
        'attempt_id',
        'scale_code',
        'scale_version',
        'type_code',
        'scores_json',
        'profile_version',
        'is_valid',
        'computed_at',
    ];

    /**
     * 字段类型转换
     */
    protected $casts = [
        'scores_json'   => 'array',    // JSON -> PHP array
        'is_valid'      => 'boolean',
        'computed_at'   => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    /**
     * 关联：Result 属于一个 Attempt
     * （如果你之后建了 Attempt 模型，这个关联就能直接用）
     */
    public function attempt()
    {
        return $this->belongsTo(Attempt::class, 'attempt_id', 'id');
    }
}