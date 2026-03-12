<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\ClaimReportRequest;
use App\Services\Abuse\RateLimiter;
use App\Services\Audit\LookupEventLogger;
use App\Services\Commerce\OrderManager;
use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    /**
     * GET /api/v0.3/claim/report?token=...
     */
    public function report(Request $request)
    {
        $ip = (string) ($request->ip() ?? '');
        $limiter = app(RateLimiter::class);
        $logger = app(LookupEventLogger::class);

        $limitIp = $limiter->limit('FAP_RATE_CLAIM_REPORT_IP', 30);
        if ($ip !== '' && ! $limiter->hit("claim_report:ip:{$ip}", $limitIp, 60)) {
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

        if (! ($res['ok'] ?? false)) {
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
            'report_pdf_url' => $res['report_pdf_url'] ?? null,
        ]);
    }

    /**
     * POST /api/v0.3/claim/report
     */
    public function requestReport(
        ClaimReportRequest $request,
        EmailCaptureService $captures,
        OrderManager $orders,
        EmailOutboxService $outbox
    ): JsonResponse {
        $payload = $request->validated();
        $email = (string) ($payload['email'] ?? '');
        $orderNo = (string) ($payload['order_no'] ?? '');
        $orgId = is_numeric($request->attributes->get('org_id')) ? (int) $request->attributes->get('org_id') : 0;
        $logger = app(LookupEventLogger::class);
        $limiter = app(RateLimiter::class);
        $ip = (string) ($request->ip() ?? '');

        $limitIp = $limiter->limit('FAP_RATE_CLAIM_REPORT_IP', 30);
        if ($ip !== '' && ! $limiter->hit("claim_report_request:ip:{$ip}", $limitIp, 60)) {
            $logger->log('claim_report_request', false, $request, null, [
                'error_code' => 'RATE_LIMITED',
            ]);

            return response()->json([
                'ok' => false,
                'error_code' => 'RATE_LIMITED',
                'message' => 'Too many requests from this IP.',
            ], 429);
        }

        $captures->capture($email, array_replace($payload, [
            'marketing_consent' => false,
        ]));

        $eligible = $orders->resolveClaimRequestContext($orgId, $orderNo, $email);
        $queued = false;
        if (($eligible['eligible'] ?? false) === true && $captures->allowsReportRecovery($email)) {
            $order = $eligible['order'] ?? null;
            $requestAttribution = $this->normalizeAttribution($payload);
            $mergedAttribution = array_replace(
                is_array($eligible['attribution'] ?? null) ? $eligible['attribution'] : [],
                $requestAttribution
            );

            $queued = (bool) ($outbox->queueReportClaim(
                (string) ($eligible['outbox_user_id'] ?? ''),
                $email,
                (string) ($eligible['attempt_id'] ?? ''),
                is_object($order) ? (string) ($order->order_no ?? '') : $orderNo,
                $mergedAttribution,
                is_string($payload['locale'] ?? null) ? (string) $payload['locale'] : null
            )['ok'] ?? false);
        }

        $logger->log('claim_report_request', true, $request, null, [
            'org_id' => $orgId,
            'order_no' => $orderNo,
            'queued' => $queued,
        ]);

        return response()->json([
            'ok' => true,
            'queued' => true,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizeAttribution(array $payload): array
    {
        $normalized = [];

        foreach ([
            'share_id' => 128,
            'compare_invite_id' => 128,
            'entrypoint' => 128,
            'referrer' => 2048,
            'landing_path' => 2048,
        ] as $field => $maxLength) {
            $value = is_scalar($payload[$field] ?? null) ? trim((string) $payload[$field]) : '';
            if ($value === '') {
                continue;
            }

            $normalized[$field] = mb_strlen($value, 'UTF-8') > $maxLength
                ? mb_substr($value, 0, $maxLength, 'UTF-8')
                : $value;
        }

        $utm = $payload['utm'] ?? null;
        if (is_array($utm)) {
            $normalizedUtm = [];
            foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
                $value = is_scalar($utm[$key] ?? null) ? trim((string) $utm[$key]) : '';
                if ($value === '') {
                    continue;
                }

                $normalizedUtm[$key] = mb_strlen($value, 'UTF-8') > 512
                    ? mb_substr($value, 0, 512, 'UTF-8')
                    : $value;
            }

            if ($normalizedUtm !== []) {
                $normalized['utm'] = $normalizedUtm;
            }
        }

        return $normalized;
    }
}
