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
}