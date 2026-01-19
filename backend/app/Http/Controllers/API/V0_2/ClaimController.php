<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Services\Email\EmailOutboxService;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    /**
     * GET /api/v0.2/claim/report?token=...
     */
    public function report(Request $request)
    {
        $token = trim((string) $request->query('token', ''));

        if ($token === '') {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_TOKEN',
                'message' => 'token is required.',
            ], 422);
        }

        /** @var EmailOutboxService $svc */
        $svc = app(EmailOutboxService::class);
        $res = $svc->claimReport($token);

        if (!($res['ok'] ?? false)) {
            $status = (int) ($res['status'] ?? 422);
            return response()->json([
                'ok' => false,
                'error' => $res['error'] ?? 'INVALID_TOKEN',
                'message' => $res['message'] ?? 'claim token invalid.',
            ], $status);
        }

        return response()->json([
            'ok' => true,
            'attempt_id' => (string) ($res['attempt_id'] ?? ''),
            'report_url' => $res['report_url'] ?? null,
        ]);
    }
}
