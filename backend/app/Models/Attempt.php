<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attempt extends Model
{
    use HasFactory;

    /**
     * 对应的表名
     */
    protected $table = 'attempts';

    /**
     * 主键是 UUID 字符串，不是自增 int
     */
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * 允许批量写入的字段
     */
    protected $fillable = [
        'id',
        'anon_id',
        'user_id',
        'scale_code',
        'scale_version',
        'question_count',
        'answers_summary_json',
        'client_platform',
        'client_version',
        'channel',
        'referrer',
        'started_at',
        'submitted_at',
        'answers_json',
        'answers_hash',
        'answers_storage_path',
    ];

    /**
     * 字段类型转换
     */
    protected $casts = [
        'answers_summary_json' => 'array',
        'answers_json'         => 'array',
        'started_at'           => 'datetime',
        'submitted_at'         => 'datetime',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    /**
     * 把 summary 作为一个“便捷 result 对象”暴露出来：
     * 让你可以直接写：$attempt->summary?->type_code
     */
    protected $appends = ['summary'];

    public function getSummaryAttribute(): ?object
    {
        $sum = $this->answers_summary_json;

        if (!$sum) {
            return null;
        }

        // 保险：万一某些场景下 cast 没生效（或返回 string）
        if (is_string($sum)) {
            $sum = json_decode($sum, true);
        }

        return is_array($sum) ? (object) $sum : null;
    }

    /**
     * 关联：一次 Attempt 有一个 Result
     */
    public function result()
    {
        return $this->hasOne(Result::class, 'attempt_id', 'id');
    }
}