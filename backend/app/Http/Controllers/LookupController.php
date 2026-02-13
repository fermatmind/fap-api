<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesOrgId;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Abuse\RateLimiter;
use App\Services\Audit\LookupEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LookupController extends Controller
{
    use ResolvesOrgId;

    /**
     * GET /api/v0.2/lookup/ticket/{code}
     */
    public function lookupTicket(Request $request, string $code): JsonResponse
    {
        $ip = (string) ($request->ip() ?? '');
        $limiter = app(RateLimiter::class);
        $logger = app(LookupEventLogger::class);

        $limitIp = $limiter->limit('FAP_RATE_LOOKUP_IP', 60);
        if ($ip !== '' && !$limiter->hit("lookup_ticket:ip:{$ip}", $limitIp, 60)) {
            $logger->log('lookup_ticket', false, $request, null, [
                'error' => 'RATE_LIMITED',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'RATE_LIMITED',
                'message' => 'Too many requests from this IP.',
            ], 429);
        }

        $raw = trim($code);
        $normalized = strtoupper($raw);

        if (!preg_match('/^FMT-[A-Z0-9]{8}$/', $normalized)) {
            $logger->log('lookup_ticket', false, $request, null, [
                'error' => 'INVALID_FORMAT',
                'ticket_code' => $normalized,
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_FORMAT',
                'message' => 'ticket_code format invalid (expected FMT-XXXXXXXX).',
            ], 422);
        }

        $orgId = $this->resolveOrgId($request);

        $attempt = Attempt::query()
            ->where('ticket_code', $normalized)
            ->where('org_id', $orgId)
            ->first();

        if (!$attempt) {
            $logger->log('lookup_ticket', false, $request, null, [
                'error' => 'NOT_FOUND',
                'ticket_code' => $normalized,
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'ticket_code not found.',
            ], 404);
        }

        $id = $attempt->id;

        $logger->log('lookup_ticket', true, $request, null, [
            'attempt_id' => $id,
            'ticket_code' => $attempt->ticket_code,
        ]);

        return response()->json([
            'ok' => true,
            'attempt_id' => $id,
            'ticket_code' => $attempt->ticket_code,
            'result_api' => "/api/v0.2/attempts/{$id}/result",
            'report_api' => "/api/v0.2/attempts/{$id}/report",
            'result_page' => null,
            'report_page' => null,
        ]);
    }

    /**
     * POST /api/v0.2/lookup/device
     */
    public function lookupDevice(Request $request): JsonResponse
    {
        $ip = (string) ($request->ip() ?? '');
        $limiter = app(RateLimiter::class);
        $logger = app(LookupEventLogger::class);

        $limitIp = $limiter->limit('FAP_RATE_LOOKUP_IP', 60);
        if ($ip !== '' && !$limiter->hit("lookup_device:ip:{$ip}", $limitIp, 60)) {
            $logger->log('lookup_device', false, $request, null, [
                'error' => 'RATE_LIMITED',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'RATE_LIMITED',
                'message' => 'Too many requests from this IP.',
            ], 429);
        }

        $attemptIds = $request->input('attempt_ids');

        if (!is_array($attemptIds)) {
            $logger->log('lookup_device', false, $request, null, [
                'error' => 'INVALID_PAYLOAD',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_PAYLOAD',
                'message' => 'attempt_ids must be an array.',
            ], 422);
        }

        $attemptIds = array_values(array_filter(array_map(function ($v) {
            return is_string($v) ? trim($v) : null;
        }, $attemptIds), function ($v) {
            return is_string($v) && $v !== '';
        }));

        $max = 20;
        if (count($attemptIds) > $max) {
            $attemptIds = array_slice($attemptIds, 0, $max);
        }

        if (count($attemptIds) === 0) {
            $logger->log('lookup_device', true, $request, null, [
                'attempt_id_count' => 0,
                'items_count' => 0,
            ]);
            return response()->json([
                'ok' => true,
                'items' => [],
            ]);
        }

        $uuidRe = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
        foreach ($attemptIds as $id) {
            if (!preg_match($uuidRe, $id)) {
                $logger->log('lookup_device', false, $request, null, [
                    'error' => 'INVALID_ID',
                ]);
                return response()->json([
                    'ok' => false,
                    'error_code' => 'INVALID_ID',
                    'message' => 'attempt_ids contains invalid uuid.',
                ], 422);
            }
        }

        $orgId = $this->resolveOrgId($request);

        $rows = Attempt::query()
            ->select(['id', 'ticket_code'])
            ->whereIn('id', $attemptIds)
            ->where('org_id', $orgId)
            ->get()
            ->keyBy('id');

        $missing = [];
        foreach ($attemptIds as $id) {
            if (!$rows->has($id)) {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $logger->log('lookup_device', false, $request, null, [
                'error' => 'NOT_FOUND',
                'attempt_id_count' => count($attemptIds),
                'missing_count' => count($missing),
            ]);

            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'attempt not found.',
            ], 404);
        }

        $items = [];
        foreach ($attemptIds as $id) {
            $a = $rows->get($id);
            if (!$a) continue;

            $items[] = [
                'attempt_id' => $a->id,
                'ticket_code' => $a->ticket_code,
                'result_api' => "/api/v0.2/attempts/{$a->id}/result",
                'report_api' => "/api/v0.2/attempts/{$a->id}/report",
            ];
        }

        $logger->log('lookup_device', true, $request, null, [
            'attempt_id_count' => count($attemptIds),
            'items_count' => count($items),
        ]);

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * POST /api/v0.2/lookup/order
     */
    public function lookupOrder(Request $request): JsonResponse
    {
        $ip = (string) ($request->ip() ?? '');
        $limiter = app(RateLimiter::class);
        $logger = app(LookupEventLogger::class);

        $limitIp = $limiter->limit('FAP_RATE_LOOKUP_IP', 60);
        if ($ip !== '' && !$limiter->hit("lookup_order:ip:{$ip}", $limitIp, 60)) {
            $logger->log('lookup_order', false, $request, null, [
                'error' => 'RATE_LIMITED',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'RATE_LIMITED',
                'message' => 'Too many requests from this IP.',
            ], 429);
        }

        if (!$this->lookupOrderEnabled()) {
            $logger->log('lookup_order', false, $request, null, [
                'error' => 'NOT_ENABLED',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_ENABLED',
                'message' => 'lookup/order disabled.',
            ]);
        }

        $orderNo = trim((string) $request->input('order_no', $request->query('order_no', '')));
        if ($orderNo === '') {
            $logger->log('lookup_order', false, $request, null, [
                'error' => 'INVALID_ORDER',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_ORDER',
                'message' => 'order_no is required.',
            ], 422);
        }

        $table = $this->resolveOrderTable();
        if ($table === '') {
            $logger->log('lookup_order', false, $request, null, [
                'error' => 'NOT_SUPPORTED',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_SUPPORTED',
                'message' => 'order lookup not supported.',
            ]);
        }

        $orderColumn = $this->resolveOrderColumn($table);
        if ($orderColumn === null) {
            $logger->log('lookup_order', false, $request, null, [
                'error' => 'NOT_SUPPORTED',
                'table' => $table,
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_SUPPORTED',
                'message' => 'order lookup not supported.',
            ]);
        }

        $row = DB::table($table)->where($orderColumn, $orderNo)->first();
        if (!$row) {
            $logger->log('lookup_order', false, $request, null, [
                'error' => 'NOT_FOUND',
                'order_no_hash' => hash('sha256', $orderNo),
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'order not found.',
            ], 404);
        }

        $attemptId = $this->extractAttemptId($row);
        $resp = [
            'ok' => true,
            'order_no' => $orderNo,
            'attempt_id' => $attemptId !== '' ? $attemptId : null,
        ];
        if ($attemptId !== '') {
            $resp['result_api'] = "/api/v0.2/attempts/{$attemptId}/result";
            $resp['report_api'] = "/api/v0.2/attempts/{$attemptId}/report";
        }

        $logger->log('lookup_order', true, $request, null, [
            'order_no_hash' => hash('sha256', $orderNo),
            'attempt_id' => $attemptId !== '' ? $attemptId : null,
            'table' => $table,
            'order_column' => $orderColumn,
        ]);

        return response()->json($resp);
    }

    private function lookupOrderEnabled(): bool
    {
        $raw = \App\Support\RuntimeConfig::value('LOOKUP_ORDER', '0');
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private function resolveOrderTable(): string
    {
        if (\App\Support\SchemaBaseline::hasTable('orders')) return 'orders';
        if (\App\Support\SchemaBaseline::hasTable('payments')) return 'payments';
        return '';
    }

    private function resolveOrderColumn(string $table): ?string
    {
        $candidates = ['order_no', 'order_id', 'order_number', 'order_sn'];
        foreach ($candidates as $col) {
            if (\App\Support\SchemaBaseline::hasColumn($table, $col)) {
                return $col;
            }
        }
        return null;
    }

    private function extractAttemptId(object $row): string
    {
        $candidates = ['attempt_id', 'attempt_uuid', 'attempt'];
        foreach ($candidates as $col) {
            if (property_exists($row, $col)) {
                $val = trim((string) ($row->{$col} ?? ''));
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return '';
    }

    /**
     * ✅ GET /api/v0.2/me/attempts  (fm_token gate)
     *
     * Query:
     * - limit: default 20, max 50
     */
    public function meAttempts(Request $request): JsonResponse
    {
        $limit = (int)($request->query('limit', 20));
        if ($limit <= 0) $limit = 20;
        if ($limit > 50) $limit = 50;

        // ✅ Step 4：优先用 middleware 注入的 identity
        $userId = $request->attributes->get('fm_user_id')
            ?? $request->attributes->get('user_id')
            ?? null;

        $anonId = $request->attributes->get('fm_anon_id')
            ?? $request->attributes->get('anon_id')
            ?? null;

        // ✅ 兼容兜底：允许旧 header（短期过渡）
        if (!$anonId) {
            $anonId = trim((string) $request->header('X-FM-Anon-Id', ''));
            if ($anonId === '') $anonId = trim((string) $request->header('X-Anon-Id', ''));
            if ($anonId === '') $anonId = trim((string) $request->header('X-Device-Anon-Id', ''));
            if ($anonId === '') $anonId = null;
        }

        if (!$userId && !$anonId) {
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'missing identity on request (user_id/anon_id).',
            ], 401);
        }

        $q = Attempt::query()
            ->select([
                'id',
                'ticket_code',
                'scale_code',
                'scale_version',
                'anon_id',
                'user_id',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($userId) {
            $q->where('user_id', $userId);
        } else {
            $q->where('anon_id', $anonId);
        }

        $attempts = $q->get();

        if ($attempts->isEmpty()) {
            return response()->json([
                'ok' => true,
                'items' => [],
            ]);
        }

        $ids = $attempts->pluck('id')->all();
        $resultMap = Result::query()
            ->select(['attempt_id', 'type_code'])
            ->whereIn('attempt_id', $ids)
            ->get()
            ->keyBy('attempt_id');

        $items = [];
        foreach ($attempts as $a) {
            $r = $resultMap->get($a->id);

            $items[] = [
                'attempt_id' => $a->id,
                'ticket_code' => $a->ticket_code,
                'scale_code' => $a->scale_code,
                'scale_version' => $a->scale_version,
                'type_code' => $r?->type_code ?? null,
                'created_at' => $a->created_at ? $a->created_at->toISOString() : null,
                'result_api' => "/api/v0.2/attempts/{$a->id}/result",
                'report_api' => "/api/v0.2/attempts/{$a->id}/report",
            ];
        }

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }
}
