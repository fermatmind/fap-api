<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final class PartnerApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Schema::hasTable('partner_api_keys')) {
            return response()->json([
                'ok' => false,
                'error_code' => 'PARTNER_API_NOT_READY',
                'message' => 'partner api tables are not ready.',
            ], 503);
        }

        $providedKey = $this->resolveProvidedApiKey($request);
        if ($providedKey === '') {
            return $this->unauthorized('missing_partner_api_key');
        }

        $now = now();
        $row = DB::table('partner_api_keys')
            ->where('key_hash', hash('sha256', $providedKey))
            ->whereIn('status', ['active', 'ACTIVE'])
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->first();

        if ($row === null) {
            return $this->unauthorized('invalid_partner_api_key');
        }

        $apiKeyId = trim((string) ($row->id ?? ''));
        $orgId = (int) ($row->org_id ?? 0);

        if ($apiKeyId === '' || $orgId <= 0) {
            return $this->unauthorized('partner_api_key_misconfigured');
        }

        $request->attributes->set('partner_api_key_id', $apiKeyId);
        $request->attributes->set('partner_org_id', $orgId);
        $request->attributes->set('org_id', $orgId);
        $request->attributes->set('fm_org_id', $orgId);
        $request->attributes->set('partner_key_name', trim((string) ($row->key_name ?? '')));
        $request->attributes->set('partner_key_prefix', trim((string) ($row->key_prefix ?? '')));

        $webhookSecret = $this->decryptWebhookSecret($row->webhook_secret_enc ?? null);
        if ($webhookSecret !== null) {
            $request->attributes->set('partner_webhook_secret', $webhookSecret);
        }

        DB::table('partner_api_keys')
            ->where('id', $apiKeyId)
            ->update([
                'last_used_at' => $now,
                'updated_at' => $now,
            ]);

        $startedAtMs = (int) floor(microtime(true) * 1000);
        $response = $next($request);
        $finishedAtMs = (int) floor(microtime(true) * 1000);

        $this->recordUsage(
            $apiKeyId,
            $orgId,
            $request,
            max(0, $finishedAtMs - $startedAtMs),
            $response->getStatusCode()
        );

        return $response;
    }

    private function resolveProvidedApiKey(Request $request): string
    {
        $headerCandidates = [
            $request->header('X-FM-Partner-Key'),
            $request->header('X-Partner-Key'),
            $request->query('partner_key'),
        ];

        foreach ($headerCandidates as $candidate) {
            $key = trim((string) $candidate);
            if ($key !== '') {
                return $key;
            }
        }

        $authorization = trim((string) $request->header('Authorization', ''));
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authorization, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function decryptWebhookSecret(mixed $encrypted): ?string
    {
        $raw = trim((string) $encrypted);
        if ($raw === '') {
            return null;
        }

        try {
            $secret = Crypt::decryptString($raw);
            $secret = trim($secret);

            return $secret !== '' ? $secret : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function recordUsage(
        string $apiKeyId,
        int $orgId,
        Request $request,
        int $latencyMs,
        int $statusCode
    ): void {
        if (! Schema::hasTable('partner_api_usages')) {
            return;
        }

        try {
            DB::table('partner_api_usages')->insert([
                'partner_api_key_id' => $apiKeyId,
                'org_id' => $orgId,
                'route_path' => '/'.ltrim((string) $request->path(), '/'),
                'http_method' => strtoupper((string) $request->method()),
                'http_status' => max(0, $statusCode),
                'latency_ms' => max(0, $latencyMs),
                'request_id' => $this->resolveRequestId($request),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('PARTNER_API_USAGE_RECORD_FAILED', [
                'org_id' => $orgId,
                'partner_api_key_id' => $apiKeyId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveRequestId(Request $request): ?string
    {
        $raw = trim((string) ($request->header('X-Request-Id', $request->header('X-Request-ID', ''))));

        return $raw !== '' ? $raw : null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
            'message' => $message,
        ], 401);
    }
}
