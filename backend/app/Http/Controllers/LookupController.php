<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    /**
     * GET /api/v0.2/lookup/ticket/{code}
     *
     * Phase A (P0):
     * - validate ticket code format: ^FMT-[A-Z0-9]{8}$
     * - lookup attempts.ticket_code
     * - return attempt_id + API entrypoints
     */
    public function lookupTicket(Request $request, string $code): JsonResponse
    {
        $raw = trim($code);
        $normalized = strtoupper($raw);

        // 1) format validation
        if (!preg_match('/^FMT-[A-Z0-9]{8}$/', $normalized)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_format',
                'message' => 'ticket_code format invalid (expected FMT-XXXXXXXX).',
            ], 422);
        }

        // 2) lookup
        $attempt = Attempt::query()
            ->where('ticket_code', $normalized)
            ->first();

        // 3) not found
        if (!$attempt) {
            return response()->json([
                'ok' => false,
                'error' => 'not_found',
                'message' => 'ticket_code not found.',
            ], 404);
        }

        $id = $attempt->id;

        // 4) success payload (Phase A: just return entrypoints)
        return response()->json([
            'ok' => true,
            'attempt_id' => $id,
            'ticket_code' => $attempt->ticket_code,
            'result_api' => "/api/v0.2/attempts/{$id}/result",
            'report_api' => "/api/v0.2/attempts/{$id}/report",

            // Optional placeholders (Phase A can keep null)
            'result_page' => null,
            'report_page' => null,
        ]);
    }

    /**
     * POST /api/v0.2/lookup/device
     *
     * Body:
     * {
     *   "attempt_ids": ["uuid1", "uuid2", ...]
     * }
     *
     * Phase A (P0):
     * - front-end stores latest attempt ids in localStorage/cookie
     * - backend returns existing attempts in the same order
     */
    public function lookupDevice(Request $request): JsonResponse
    {
        $attemptIds = $request->input('attempt_ids');

        // 1) basic validation
        if (!is_array($attemptIds)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_payload',
                'message' => 'attempt_ids must be an array.',
            ], 422);
        }

        // normalize: trim, keep strings only
        $attemptIds = array_values(array_filter(array_map(function ($v) {
            return is_string($v) ? trim($v) : null;
        }, $attemptIds), function ($v) {
            return is_string($v) && $v !== '';
        }));

        // cap size (Phase A: keep small & safe)
        $max = 20;
        if (count($attemptIds) > $max) {
            $attemptIds = array_slice($attemptIds, 0, $max);
        }

        // allow empty list: return empty items
        if (count($attemptIds) === 0) {
            return response()->json([
                'ok' => true,
                'items' => [],
            ]);
        }

        // 2) validate uuid format (attempt id is char(36) uuid)
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

        // 3) query attempts
        $rows = Attempt::query()
            ->select(['id', 'ticket_code'])
            ->whereIn('id', $attemptIds)
            ->get()
            ->keyBy('id');

        // 4) preserve input order + build payload
        $items = [];
        foreach ($attemptIds as $id) {
            $a = $rows->get($id);
            if (!$a) {
                continue;
            }

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
}