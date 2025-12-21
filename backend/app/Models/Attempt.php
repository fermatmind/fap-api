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

        // ✅ 可复算/可审计三件套
        'answers_json',
        'answers_hash',
        'answers_storage_path',
    ];

    /**
     * 字段类型转换
     *
     * 注意：
     * - answers_json / answers_summary_json 用 array cast，Controller 里就应直接赋值数组，不要 json_encode()
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

        if ($sum === null || $sum === '' || $sum === []) {
            return null;
        }

        // 兼容极端情况：历史数据可能是 string（虽然 cast 应该会处理）
        if (is_string($sum)) {
            $decoded = json_decode($sum, true);
            $sum = is_array($decoded) ? $decoded : null;
        }

        return is_array($sum) ? (object) $sum : null;
    }

    /**
     * 是否有“可复算”的持久化答案
     * - answers_json 有内容 或 answers_storage_path 有值
     */
    public function hasPersistedAnswers(): bool
    {
        $ans = $this->answers_json;

        if (is_array($ans) && count($ans) > 0) {
            return true;
        }

        $p = $this->answers_storage_path;
        return is_string($p) && trim($p) !== '';
    }

    /**
     * 直接拿到答案数组（仅从 DB answers_json）
     * - 如果没有就返回空数组（上层可再去读 storage_path）
     */
    public function getAnswersArray(): array
    {
        $ans = $this->answers_json;

        if (is_string($ans)) {
            $decoded = json_decode($ans, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($ans) ? $ans : [];
    }

    /**
     * 关联：一次 Attempt 有一个 Result
     */
    public function result()
    {
        return $this->hasOne(Result::class, 'attempt_id', 'attempt_id');
    }
}