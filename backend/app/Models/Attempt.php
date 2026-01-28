<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Attempt extends Model
{
    use HasFactory;

    /**
     * 对应的表名
     */
    protected $table = 'attempts';

    /**
     * 主键是 UUID 字符串（char(36)），不是自增 int
     */
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * 创建时自动生成 UUID + Ticket Code
     *
     * - UUID：避免 "Field 'id' doesn't have a default value"
     * - ticket_code：FMT-XXXXXXXX（8位大写字母数字），用于跨设备找回
     */
    protected static function booted(): void
    {
        static::creating(function (self $m) {
            // 1) UUID（外部未传则自动补）
            if (empty($m->id)) {
                $m->id = (string) Str::uuid();
            }

            // 2) Ticket Code（外部显式传入就不覆盖）
            if (!empty($m->ticket_code)) {
                return;
            }

            // 生成：FMT-XXXXXXXX（总长 12），列长度 varchar(20) 足够
            // Phase A：做 5 次重试 + exists 检查，极小概率仍会被 DB unique 兜底拦截
            $maxAttempts = 5;

            for ($i = 0; $i < $maxAttempts; $i++) {
                $code = 'FMT-' . Str::upper(Str::random(8));

                // creating 阶段尚未写入 DB，需查库确认唯一（减少碰撞概率）
                if (!static::where('ticket_code', $code)->exists()) {
                    $m->ticket_code = $code;
                    return;
                }
            }

            throw new \RuntimeException("Failed to generate unique ticket_code after {$maxAttempts} attempts");
        });
    }

    /**
     * 允许批量写入的字段
     */
    protected $fillable = [
        // id 可以保留（允许外部显式传入），但一般不需要传
        'id',

        // ✅ 找回凭证（跨设备匿名）
        'ticket_code',

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

        // ✅ psychometrics snapshot
        'pack_id',
        'dir_version',
        'scoring_spec_version',
        'norm_version',
        'calculation_snapshot_json',

        // ✅ result cache
        'result_json',
        'type_code',
    ];

    /**
     * 字段类型转换
     *
     * 注意：
     * - answers_json / answers_summary_json 用 array cast，Controller 里应直接赋值数组，不要 json_encode()
     */
    protected $casts = [
        'answers_summary_json' => 'array',
        'answers_json'         => 'array',
        'calculation_snapshot_json' => 'array',
        'result_json'          => 'array',

        'started_at'           => 'datetime',
        'submitted_at'         => 'datetime',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    /**
     * 把 summary 作为一个“便捷对象”暴露出来：
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
     * - 约定：results.attempt_id -> attempts.id
     */
    public function result()
    {
        return $this->hasOne(Result::class, 'attempt_id', 'id');
    }
}
