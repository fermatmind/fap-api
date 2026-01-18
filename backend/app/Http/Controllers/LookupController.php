<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Result;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    /**
     * GET /api/v0.2/lookup/ticket/{code}
     */
    public function lookupTicket(Request $request, string $code): JsonResponse
    {
        $raw = trim($code);
        $normalized = strtoupper($raw);

        if (!preg_match('/^FMT-[A-Z0-9]{8}$/', $normalized)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_format',
                'message' => 'ticket_code format invalid (expected FMT-XXXXXXXX).',
            ], 422);
        }

        $attempt = Attempt::query()
            ->where('ticket_code', $normalized)
            ->first();

        if (!$attempt) {
            return response()->json([
                'ok' => false,
                'error' => 'not_found',
                'message' => 'ticket_code not found.',
            ], 404);
        }

        $id = $attempt->id;

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
        $attemptIds = $request->input('attempt_ids');

        if (!is_array($attemptIds)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_payload',
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
            return response()->json([
                'ok' => true,
                'items' => [],
            ]);
        }

        $uuidRe = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
        foreach ($attemptIds as $id) {
            if (!preg_match($uuidRe, $id)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'invalid_id',
                    'message' => 'attempt_ids contains invalid uuid.',
                ], 422);
            }
        }

        $rows = Attempt::query()
            ->select(['id', 'ticket_code'])
            ->whereIn('id', $attemptIds)
            ->get()
            ->keyBy('id');

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

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
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
                'error' => 'UNAUTHORIZED',
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