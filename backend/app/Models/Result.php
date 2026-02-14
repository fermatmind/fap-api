<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Result extends Model
{
    use HasFactory, HasOrgScope;

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
        'org_id',
        'scale_code',
        'scale_version',
        'type_code',
        'scores_json',
        'scores_pct',
        'axis_states',
        'profile_version',
        'content_package_version',
        'result_json',
        'pack_id',
        'dir_version',
        'scoring_spec_version',
        'report_engine_version',
        'is_valid',
        'computed_at',
    ];

    /**
     * 字段类型转换
     */
    protected $casts = [
        'scores_json' => 'array',
        'scores_pct'  => 'array',
        'axis_states' => 'array',
        'result_json' => 'array',
        'computed_at' => 'datetime',
        'is_valid'    => 'boolean',
        'org_id'      => 'integer',
    ];

    /**
     * 关联：Result 属于一个 Attempt
     * （如果你之后建了 Attempt 模型，这个关联就能直接用）
     */
    public function attempt()
    {
        return $this->belongsTo(Attempt::class, 'attempt_id', 'id');
    }

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }
}
