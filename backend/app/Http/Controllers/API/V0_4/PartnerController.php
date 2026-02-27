<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_4;

use App\Http\Controllers\Controller;
use App\Services\Partners\PartnerApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    public function __construct(
        private readonly PartnerApiService $partnerApiService,
    ) {}

    /**
     * POST /api/v0.4/partners/sessions
     */
    public function createSession(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'scale_code' => ['required', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:16'],
            'client_ref' => ['nullable', 'string', 'max:64'],
            'client_version' => ['nullable', 'string', 'max:32'],
            'referrer' => ['nullable', 'string', 'max:255'],
            'callback_url' => ['nullable', 'url', 'max:2048'],
            'meta' => ['sometimes', 'array'],
            'consent' => ['sometimes', 'array'],
            'consent.accepted' => ['sometimes', 'boolean'],
            'consent.version' => ['sometimes', 'string', 'max:128'],
            'consent.hash' => ['sometimes', 'string', 'size:64'],
        ]);

        $orgId = $this->resolveOrgId($request);
        $apiKeyId = $this->resolveApiKeyId($request);
        if ($orgId <= 0 || $apiKeyId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'partner auth context missing.',
            ], 401);
        }

        try {
            $result = $this->partnerApiService->createSession(
                $orgId,
                $apiKeyId,
                $payload,
                $this->resolveWebhookSecret($request)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result, 200);
    }

    /**
     * GET /api/v0.4/partners/sessions/{attempt_id}/status
     */
    public function status(Request $request, string $attempt_id): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        $apiKeyId = $this->resolveApiKeyId($request);
        if ($orgId <= 0 || $apiKeyId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'partner auth context missing.',
            ], 401);
        }

        $result = $this->partnerApiService->status(
            $orgId,
            $apiKeyId,
            $attempt_id,
            $this->resolveWebhookSecret($request)
        );

        if ($result === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'session not found.',
            ], 404);
        }

        return response()->json($result, 200);
    }

    /**
     * POST /api/v0.4/partners/webhooks/sign
     */
    public function signWebhook(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'payload' => ['required', 'array'],
            'timestamp' => ['nullable', 'integer', 'min:1'],
        ]);

        $secret = $this->resolveWebhookSecret($request);
        if ($secret === null || trim($secret) === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SIGNING_SECRET_MISSING',
                'message' => 'partner webhook secret missing.',
            ], 422);
        }

        $signed = $this->partnerApiService->signPayload(
            (array) ($payload['payload'] ?? []),
            $secret,
            isset($payload['timestamp']) ? (int) $payload['timestamp'] : null
        );

        return response()->json([
            'ok' => true,
            'timestamp' => (int) ($signed['timestamp'] ?? 0),
            'signature' => (string) ($signed['signature'] ?? ''),
            'headers' => is_array($signed['headers'] ?? null) ? $signed['headers'] : [],
            'payload' => (array) ($payload['payload'] ?? []),
        ], 200);
    }

    private function resolveOrgId(Request $request): int
    {
        $raw = (string) ($request->attributes->get('partner_org_id') ?? $request->attributes->get('org_id') ?? '');
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return 0;
        }

        return (int) $raw;
    }

    private function resolveApiKeyId(Request $request): ?string
    {
        $value = trim((string) $request->attributes->get('partner_api_key_id', ''));

        return $value !== '' ? $value : null;
    }

    private function resolveWebhookSecret(Request $request): ?string
    {
        $value = trim((string) $request->attributes->get('partner_webhook_secret', ''));

        return $value !== '' ? $value : null;
    }
}
