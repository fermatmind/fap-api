<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Services\Abuse\RateLimiter;
use App\Services\Audit\LookupEventLogger;
use App\Services\Email\EmailOutboxService;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    /**
     * GET /api/v0.2/claim/report?token=...
     */
    public function report(Request $request)
    {
        $ip = (string) ($request->ip() ?? '');
        $limiter = app(RateLimiter::class);
        $logger = app(LookupEventLogger::class);

        $limitIp = $limiter->limit('FAP_RATE_CLAIM_REPORT_IP', 30);
        if ($ip !== '' && !$limiter->hit("claim_report:ip:{$ip}", $limitIp, 60)) {
            $logger->log('claim_report', false, $request, null, [
                'error_code' => 'RATE_LIMITED',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'RATE_LIMITED',
                'message' => 'Too many requests from this IP.',
            ], 429);
        }

        $token = trim((string) $request->query('token', ''));

        if ($token === '') {
            $logger->log('claim_report', false, $request, null, [
                'error_code' => 'INVALID_TOKEN',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_TOKEN',
                'message' => 'token is required.',
            ], 422);
        }

        /** @var EmailOutboxService $svc */
        $svc = app(EmailOutboxService::class);
        $res = $svc->claimReport($token);

        if (!($res['ok'] ?? false)) {
            $status = (int) ($res['status'] ?? 422);
            $logger->log('claim_report', false, $request, null, [
                'error_code' => (string) ($res['error'] ?? 'INVALID_TOKEN'),
                'token_hash' => hash('sha256', $token),
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => $res['error'] ?? 'INVALID_TOKEN',
                'message' => $res['message'] ?? 'claim token invalid.',
            ], $status);
        }

        $logger->log('claim_report', true, $request, null, [
            'attempt_id' => (string) ($res['attempt_id'] ?? ''),
            'token_hash' => hash('sha256', $token),
        ]);

        return response()->json([
            'ok' => true,
            'attempt_id' => (string) ($res['attempt_id'] ?? ''),
            'report_url' => $res['report_url'] ?? null,
        ]);
    }
}
