<?php

declare(strict_types=1);

namespace App\Services\Partners;

use App\DTO\Attempts\StartAttemptDTO;
use App\Services\Attempts\AttemptStartService;
use App\Support\OrgContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class PartnerApiService
{
    public function __construct(
        private readonly AttemptStartService $attemptStartService,
        private readonly PartnerWebhookSigner $signer,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function createSession(int $orgId, string $apiKeyId, array $payload, ?string $webhookSecret): array
    {
        $scaleCode = strtoupper(trim((string) ($payload['scale_code'] ?? '')));
        if ($scaleCode === '') {
            throw new \InvalidArgumentException('scale_code is required.');
        }

        $anonId = $this->buildPartnerAnonId(
            $apiKeyId,
            trim((string) ($payload['client_ref'] ?? ''))
        );

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['partner'] = [
            'api_key_id' => $apiKeyId,
            'client_ref' => trim((string) ($payload['client_ref'] ?? '')),
            'requested_at' => now()->toISOString(),
        ];

        $container = app();
        $previousOrgContext = $container->bound(OrgContext::class)
            ? $container->make(OrgContext::class)
            : null;
        $ctx = new OrgContext;
        $ctx->set($orgId, null, 'partner', $anonId);
        $container->instance(OrgContext::class, $ctx);

        $startPayload = [
            'scale_code' => $scaleCode,
            'region' => trim((string) ($payload['region'] ?? '')) ?: null,
            'locale' => trim((string) ($payload['locale'] ?? '')) ?: null,
            'anon_id' => $anonId,
            'client_platform' => 'partner_api',
            'client_version' => trim((string) ($payload['client_version'] ?? '')) ?: 'v0.4',
            'channel' => 'partner',
            'referrer' => trim((string) ($payload['referrer'] ?? '')) ?: 'partner_api',
            'meta' => $meta,
            'consent' => is_array($payload['consent'] ?? null) ? $payload['consent'] : [],
        ];

        try {
            $started = $this->attemptStartService->start($ctx, StartAttemptDTO::fromArray($startPayload));
        } finally {
            if ($previousOrgContext instanceof OrgContext) {
                $container->instance(OrgContext::class, $previousOrgContext);
            } else {
                $container->forgetInstance(OrgContext::class);
            }
        }
        $attemptId = trim((string) ($started['attempt_id'] ?? ''));
        if ($attemptId === '') {
            throw new \RuntimeException('attempt creation failed.');
        }

        $callbackUrl = trim((string) ($payload['callback_url'] ?? ''));
        if ($callbackUrl !== '') {
            $this->persistWebhookEndpoint($orgId, $apiKeyId, $callbackUrl, $webhookSecret);
        }

        return [
            'ok' => true,
            'session_id' => $attemptId,
            'attempt_id' => $attemptId,
            'status' => 'started',
            'status_path' => '/api/v0.4/partners/sessions/'.$attemptId.'/status',
            'attempt' => $started,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function status(int $orgId, string $apiKeyId, string $attemptId, ?string $webhookSecret): ?array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return null;
        }

        $attempt = DB::table('attempts')
            ->where('org_id', $orgId)
            ->where('id', $attemptId)
            ->first();
        if ($attempt === null) {
            return null;
        }

        $result = DB::table('results')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->first();

        $submission = $this->latestSubmission($orgId, $attemptId);
        $submissionState = $submission !== null
            ? strtolower(trim((string) ($submission->state ?? 'pending')))
            : null;

        $status = 'started';
        if ($result !== null || $submissionState === 'succeeded') {
            $status = 'completed';
        } elseif (in_array($submissionState, ['pending', 'running'], true)) {
            $status = 'processing';
        } elseif ($submissionState === 'failed') {
            $status = 'failed';
        }

        $payload = [
            'ok' => true,
            'session_id' => $attemptId,
            'attempt_id' => $attemptId,
            'status' => $status,
            'submission_state' => $submissionState,
            'result_ready' => $result !== null,
            'report_ready' => $result !== null,
            'updated_at' => $this->latestUpdatedAt($attempt, $submission, $result),
        ];

        if ($status === 'completed' && $webhookSecret !== null && trim($webhookSecret) !== '') {
            $callbackPayload = [
                'event' => 'report.completed',
                'session_id' => $attemptId,
                'attempt_id' => $attemptId,
                'org_id' => $orgId,
                'result_id' => (string) ($result->id ?? ''),
                'submission_state' => $submissionState,
                'occurred_at' => now()->toISOString(),
            ];
            $signed = $this->signer->signPayload($callbackPayload, $webhookSecret);

            $payload['callback'] = [
                'event' => 'report.completed',
                'payload' => $callbackPayload,
                'headers' => $signed['headers'],
            ];

            $endpointId = $this->resolveActiveEndpointId($orgId, $apiKeyId);
            $this->recordSignedDelivery(
                $orgId,
                $apiKeyId,
                $endpointId,
                'report.completed:'.$attemptId,
                'report.completed',
                $callbackPayload,
                (string) $signed['signature'],
                (int) $signed['timestamp']
            );
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *     payload_raw:string,
     *     timestamp:int,
     *     signature:string,
     *     headers:array{X-FM-Timestamp:string,X-FM-Signature:string}
     * }
     */
    public function signPayload(array $payload, string $secret, ?int $timestamp = null): array
    {
        return $this->signer->signPayload($payload, $secret, $timestamp);
    }

    private function buildPartnerAnonId(string $apiKeyId, string $clientRef): string
    {
        $clientRef = trim($clientRef);
        if ($clientRef !== '') {
            $normalized = preg_replace('/[^A-Za-z0-9_-]+/', '', $clientRef);
            if (is_string($normalized)) {
                $normalized = trim($normalized, '_-');
                if ($normalized !== '') {
                    return 'ptn_'.substr(strtolower($normalized), 0, 48);
                }
            }
        }

        return 'ptn_'.substr(hash('sha256', $apiKeyId.'|'.Str::uuid()), 0, 48);
    }

    private function persistWebhookEndpoint(int $orgId, string $apiKeyId, string $callbackUrl, ?string $webhookSecret): void
    {
        if (! Schema::hasTable('partner_webhook_endpoints')) {
            return;
        }

        $callbackUrl = trim($callbackUrl);
        if ($callbackUrl === '') {
            return;
        }

        $urlHash = hash('sha256', strtolower($callbackUrl));
        $now = now();
        $existing = DB::table('partner_webhook_endpoints')
            ->where('org_id', $orgId)
            ->where('partner_api_key_id', $apiKeyId)
            ->where('callback_url_hash', $urlHash)
            ->first();

        $secretEnc = null;
        if ($webhookSecret !== null && trim($webhookSecret) !== '') {
            $secretEnc = Crypt::encryptString(trim($webhookSecret));
        }

        if ($existing === null) {
            DB::table('partner_webhook_endpoints')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'partner_api_key_id' => $apiKeyId,
                'callback_url' => $callbackUrl,
                'callback_url_hash' => $urlHash,
                'signing_secret_enc' => $secretEnc,
                'status' => 'active',
                'last_delivered_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $updates = [
            'callback_url' => $callbackUrl,
            'status' => 'active',
            'updated_at' => $now,
        ];
        if ($secretEnc !== null) {
            $updates['signing_secret_enc'] = $secretEnc;
        }

        DB::table('partner_webhook_endpoints')
            ->where('id', (string) $existing->id)
            ->update($updates);
    }

    private function latestSubmission(int $orgId, string $attemptId): ?object
    {
        if (! Schema::hasTable('attempt_submissions')) {
            return null;
        }

        return DB::table('attempt_submissions')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function latestUpdatedAt(?object $attempt, ?object $submission, ?object $result): ?string
    {
        foreach ([$result?->updated_at ?? null, $submission?->updated_at ?? null, $attempt?->updated_at ?? null] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            return (string) $candidate;
        }

        return null;
    }

    private function resolveActiveEndpointId(int $orgId, string $apiKeyId): ?string
    {
        if (! Schema::hasTable('partner_webhook_endpoints')) {
            return null;
        }

        $row = DB::table('partner_webhook_endpoints')
            ->where('org_id', $orgId)
            ->where('partner_api_key_id', $apiKeyId)
            ->whereIn('status', ['active', 'ACTIVE'])
            ->orderByDesc('updated_at')
            ->first();

        if ($row === null) {
            return null;
        }

        return trim((string) ($row->id ?? '')) ?: null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordSignedDelivery(
        int $orgId,
        string $apiKeyId,
        ?string $endpointId,
        string $eventKey,
        string $eventType,
        array $payload,
        string $signature,
        int $timestamp
    ): void {
        if (! Schema::hasTable('partner_webhook_deliveries')) {
            return;
        }

        $eventKey = trim($eventKey);
        if ($eventKey === '') {
            return;
        }

        $now = now();
        DB::table('partner_webhook_deliveries')->updateOrInsert(
            [
                'org_id' => $orgId,
                'partner_api_key_id' => $apiKeyId,
                'event_key' => $eventKey,
            ],
            [
                'id' => (string) Str::uuid(),
                'endpoint_id' => $endpointId,
                'event_type' => $eventType,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'signature' => $signature,
                'signature_timestamp' => max(1, $timestamp),
                'delivery_status' => 'signed',
                'delivered_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        if ($endpointId !== null && Schema::hasTable('partner_webhook_endpoints')) {
            DB::table('partner_webhook_endpoints')
                ->where('id', $endpointId)
                ->update([
                    'last_delivered_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }
}
