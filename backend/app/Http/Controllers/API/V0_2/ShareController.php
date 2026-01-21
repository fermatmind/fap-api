<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class ShareController extends Controller
{
    /**
     * POST /api/v0.2/shares/{shareId}/click
     *
     * 目标：
     * 1) 永远 JSON（不 302）
     * 2) 记录 share_click event
     * 3) 返回 report，并确保 report._meta / report._explain 可透传（A 方案白名单）
     */
    public function click(Request $request, string $shareId)
    {
        // 1) 手动 Validator：确保永远 JSON 返回（避免 ValidationException 走 302）
        $v = Validator::make($request->all(), [
            'attempt_id'  => ['nullable', 'string', 'max:64'],
            'anon_id'     => ['nullable', 'string', 'max:128'],
            'occurred_at' => ['nullable', 'date'],
            'meta_json'   => ['nullable', 'array'],

            // AB / 版本：允许 body 传（也支持 header）
            'experiment'  => ['nullable', 'string', 'max:64'],
            'version'     => ['nullable', 'string', 'max:64'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'ok'     => false,
                'error'  => 'VALIDATION_FAILED',
                'errors' => $v->errors(),
            ], 422);
        }

        $data = $v->validated();

        // 2) 查 share_generate（用 share_id 关联）
        // ✅ 用 Laravel JSON path：跨 DB（sqlite/mysql）兼容
        $gen = Event::where('event_code', 'share_generate')
            ->where('meta_json->share_id', $shareId)
            ->orderByDesc('occurred_at')
            ->first();

        if (!$gen) {
            return response()->json([
                'ok'      => false,
                'error'   => 'SHARE_NOT_FOUND',
                'message' => 'No share_generate found for this share_id.',
            ], 404);
        }

        // 3) 归一化 gen.meta_json（你的项目里通常 cast 成 array，但这里做兜底）
        $genMeta = $gen->meta_json;
        if (is_string($genMeta)) {
            $decoded = json_decode($genMeta, true);
            $genMeta = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($genMeta)) $genMeta = [];

        // 4) attempt_id：优先 body，其次 share_generate.attempt_id
        $attemptId = (string) ($data['attempt_id'] ?? '');
        if ($attemptId === '') {
            $attemptId = (string) ($gen->attempt_id ?? '');
        }
        if ($attemptId === '') {
            return response()->json([
                'ok'      => false,
                'error'   => 'ATTEMPT_NOT_FOUND',
                'message' => 'share_generate exists but attempt_id cannot be resolved.',
            ], 404);
        }

        // 5) experiment/version：优先 header，其次 body，其次 share_generate.meta_json 继承
        $experiment = (string) ($request->header('X-Experiment') ?? ($data['experiment'] ?? ''));
        $version    = (string) ($request->header('X-App-Version') ?? ($data['version'] ?? ''));

        if ($experiment === '') $experiment = (string) ($genMeta['experiment'] ?? '');
        if ($version === '')    $version    = (string) ($genMeta['version'] ?? '');

        // ====== 从 share_generate 继承的关键信息（用于补齐 share_click meta）======
        $typeCode  = (string) ($genMeta['type_code'] ?? '');
        $packV     = (string) ($genMeta['content_package_version'] ?? '');
        $engV      = (string) ($genMeta['engine_version'] ?? ($genMeta['engine'] ?? ''));
        $profileV  = (string) ($genMeta['profile_version'] ?? '');

        // 分享漏斗字段（header 优先，其次 genMeta 继承）
        $channel        = (string) ($request->header('X-Channel') ?? ($genMeta['channel'] ?? ''));
        $clientPlatform = (string) ($request->header('X-Client-Platform') ?? ($genMeta['client_platform'] ?? ''));
        $entryPage      = (string) ($request->header('X-Entry-Page') ?? '');

        // 6) meta 合并（先拿客户端 meta_json）
        $meta = is_array($data['meta_json'] ?? null) ? $data['meta_json'] : [];

        // ✅ share_click 最小字段（M3 漏斗串联）
        if (!isset($meta['share_id']))   $meta['share_id'] = $shareId;
        if (!isset($meta['attempt_id'])) $meta['attempt_id'] = $attemptId;

        // ✅ 把 share_generate 的关键字段补齐进来（不覆盖客户端已有）
        if ($typeCode !== '' && !isset($meta['type_code'])) $meta['type_code'] = $typeCode;

        if ($engV !== '' && !isset($meta['engine_version'])) $meta['engine_version'] = $engV;
        if ($packV !== '' && !isset($meta['content_package_version'])) $meta['content_package_version'] = $packV;

        // ✅ 兼容字段（老查询可能读 engine）
        if ($engV !== '' && !isset($meta['engine'])) $meta['engine'] = $engV;

        if ($profileV !== '' && !isset($meta['profile_version'])) $meta['profile_version'] = $profileV;

        // ✅ AB 字段：header/body > genMeta（不覆盖客户端已有）
        if ($experiment !== '' && !isset($meta['experiment'])) $meta['experiment'] = $experiment;
        if ($version !== '' && !isset($meta['version']))       $meta['version'] = $version;

        // ✅ 分享来源/平台字段（不覆盖客户端已有）
        if ($channel !== '' && !isset($meta['channel'])) $meta['channel'] = $channel;
        if ($clientPlatform !== '' && !isset($meta['client_platform'])) $meta['client_platform'] = $clientPlatform;
        if ($entryPage !== '' && !isset($meta['entry_page'])) $meta['entry_page'] = $entryPage;

        // ✅ 关联到 share_generate（便于排查/归因）
        if (!isset($meta['share_generate_event_id'])) $meta['share_generate_event_id'] = (string) ($gen->id ?? '');
        if (!isset($meta['share_generate_occurred_at'])) $meta['share_generate_occurred_at'] = (string) ($gen->occurred_at ?? '');

        // 通用字段（不覆盖客户端 meta）
        if (!isset($meta['ua']))  $meta['ua']  = (string) ($request->userAgent() ?? '');
        if (!isset($meta['ip']))  $meta['ip']  = (string) ($request->ip() ?? '');
        if (!isset($meta['ref'])) $meta['ref'] = (string) ($request->header('Referer') ?? '');

        // 清理空值（保留 0/false）
        $meta = array_filter($meta, fn($v) => !($v === null || $v === ''));

        // 7) 写 share_click event
        $event = new Event();
        $event->id = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();

        $event->event_code = 'share_click';

        // ✅ anon_id 归因：客户端优先，其次继承 share_generate 的 anon_id
// ✅ M3 hard：占位符/污染值不允许落库（命中黑名单就当作没传）
$isBadAnon = function(?string $v): bool {
    if ($v === null) return true;
    $s = trim($v);
    if ($s === '') return true;

    $lower = mb_strtolower($s, 'UTF-8');
    $blacklist = [
        'todo',
        'placeholder',
        'fixme',
        'tbd',
        '把你查到的anon_id填这里',
        '把你查到的 anon_id 填这里',
        '填这里',
    ];

    foreach ($blacklist as $bad) {
        $b = trim((string) $bad);
        if ($b === '') continue;
        if (mb_strpos($lower, mb_strtolower($b, 'UTF-8')) !== false) {
            return true;
        }
    }
    return false;
};

$clickAnonId = null;

// 1) 客户端 body anon_id（只有在非占位符时才采用）
if (isset($data['anon_id']) && is_string($data['anon_id'])) {
    $cand = trim((string) $data['anon_id']);
    if (!$isBadAnon($cand)) {
        $clickAnonId = $cand;
    }
}

// 2) 否则继承 share_generate 的 anon_id（同样过滤占位符）
if (($clickAnonId === null || $clickAnonId === '') && !empty($gen->anon_id)) {
    $cand = trim((string) $gen->anon_id);
    if (!$isBadAnon($cand)) {
        $clickAnonId = $cand;
    }
}

$event->anon_id = ($clickAnonId !== null && $clickAnonId !== '') ? $clickAnonId : null;
        $event->attempt_id  = $attemptId;
        $event->meta_json   = $meta;
        $event->occurred_at = !empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : now();

        $event->save();

        // 8) 生成/拿到 report（你现在 share_generate.meta_json 没有 report，所以这里必须“现场生成”）
        $engine = (string) ($genMeta['engine'] ?? '');

        $report = null;

        try {
            $composer = $this->resolveReportComposer();
            if ($composer) {
                $ctx = [
                    'share_id'                => $shareId,
                    'attempt_id'              => $attemptId,
                    'experiment'              => $experiment,
                    'version'                 => $version,
                    'type_code'               => $typeCode,
                    'engine'                  => $engine,
                    'profile_version'         => $profileV,
                    'content_package_version' => $packV,
                ];

                if (method_exists($composer, 'compose')) {
                    $report = $composer->compose($attemptId, $ctx);
                } elseif (method_exists($composer, 'composeReport')) {
                    $report = $composer->composeReport($attemptId, $ctx);
                } elseif (method_exists($composer, 'build')) {
                    $report = $composer->build($attemptId, $ctx);
                } elseif (method_exists($composer, 'make')) {
                    $report = $composer->make($attemptId, $ctx);
                } elseif (is_callable($composer)) {
                    $report = $composer($attemptId, $ctx);
                }

                // 可能返回 {ok:true, report:{...}} 的包裹结构
                if (is_array($report) && isset($report['report']) && is_array($report['report'])) {
                    $report = $report['report'];
                }

                // 可能返回对象（Resource/DTO），尽量转 array
                if (is_object($report)) {
                    if (method_exists($report, 'toArray')) {
                        $report = $report->toArray();
                    } elseif (method_exists($report, 'jsonSerialize')) {
                        $report = $report->jsonSerialize();
                    }
                }
            }
        } catch (\Throwable $e) {
            $report = null;
        }

        if (!is_array($report)) $report = [];

        // 9) ✅ 确保 report._meta 存在
        if (!isset($report['_meta']) || !is_array($report['_meta'])) {
            $report['_meta'] = [];
        }

        // 不覆盖 composer 已有的 _meta，只补缺失字段
        $metaFill = [
            'share_id'                => $shareId,
            'attempt_id'              => $attemptId,
            'type_code'               => $typeCode,
            'engine'                  => $engine,
            'profile_version'         => $profileV,
            'content_package_version' => $packV,
            'experiment'              => $experiment,
            'version'                 => $version,
            'generated_at'            => now()->toISOString(),
        ];
        foreach ($metaFill as $k => $v) {
            if ($v === '' || $v === null) continue;
            if (!array_key_exists($k, $report['_meta'])) {
                $report['_meta'][$k] = $v;
            }
        }

        // 10) A 方案：白名单过滤（收敛到你真实用到的顶层 keys）
        $report = $this->filterReport($report);

        return response()->json([
            'ok'         => true,
            'id'         => $event->id,
            'share_id'   => $shareId,
            'attempt_id' => $attemptId,
            'report'     => $report,
        ]);
    }

    /**
     * ✅ A 方案白名单：按你实际 click 响应 keys 收敛后的最小闭包（123test 风格）
     */
    private function allowedReportKeys(): array
    {
        $keys = config('report.share_report_allowed_keys', []);
        $keys = is_array($keys) ? $keys : [];

        // ✅ content_graph 打开时：share_click 响应允许透传 recommended_reads
        if ((bool) env('CONTENT_GRAPH_ENABLED', false)) {
            if (!in_array('recommended_reads', $keys, true)) {
                $keys[] = 'recommended_reads';
            }
        }

        return $keys;
    }

    /**
     * explain 是否允许对外暴露（建议默认 false）
     * - 本地/开发可开
     * - 线上按需开：RE_EXPLAIN_PAYLOAD=true
     */
    private function shouldExposeExplain(): bool
    {
        if (app()->environment('local', 'development')) return true;
        return (bool) config('report.expose_explain', false);
    }

    private function filterReport(array $report): array
    {
        $allowed = array_flip($this->allowedReportKeys());
        $out = [];

        foreach ($report as $k => $v) {
            if (!isset($allowed[$k])) continue;

            // 线上默认不透传 _explain
            if ($k === '_explain' && !$this->shouldExposeExplain()) continue;

            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * 自动寻找你项目里的 ReportComposer
     */
    private function resolveReportComposer(): ?object
    {
        $candidates = [
            'App\\Services\\Report\\ReportComposer', // 你项目里就是这个
            'App\\Services\\ReportComposer',
            'App\\Services\\Reports\\ReportComposer',
            'App\\Domain\\Report\\ReportComposer',
            'App\\Application\\Report\\Composer',
        ];

        foreach ($candidates as $cls) {
            if (class_exists($cls)) {
                return app($cls);
            }
        }

        return null;
    }
}