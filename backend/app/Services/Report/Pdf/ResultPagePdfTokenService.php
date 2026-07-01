<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use App\Models\Attempt;

final class ResultPagePdfTokenService
{
    /**
     * @param  array<string,mixed>  $gate
     */
    public function issueForMbtiResultPageExport(
        Attempt $attempt,
        array $gate,
        string $locale,
        ?string $surfaceVersion = null
    ): string {
        $surfaceVersion = trim((string) ($surfaceVersion ?? ''));
        $surfaceVersion = $surfaceVersion !== ''
            ? $surfaceVersion
            : ReportPdfDocumentService::MBTI_RESULT_PAGE_SNAPSHOT_SURFACE_VERSION;

        $payload = [
            'v' => 1,
            'typ' => 'mbti_result_page_pdf',
            'org_id' => (int) ($attempt->org_id ?? 0),
            'attempt_id' => (string) $attempt->id,
            'user_id' => (string) ($attempt->user_id ?? ''),
            'owner_id' => (string) (($attempt->user_id ?? null) ?: ($attempt->anon_id ?? '')),
            'locale' => $locale,
            'surface' => $surfaceVersion,
            'engine' => ReportPdfDocumentService::RESULT_PAGE_EXPORT_ENGINE,
            'entitlement' => ((bool) ($gate['locked'] ?? true)) ? 'locked' : 'unlocked',
            'variant' => $this->normalizeVariant((string) ($gate['variant'] ?? 'free')),
            'exp' => now()->addMinutes(10)->getTimestamp(),
        ];

        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $encodedPayload.'.'.$this->sign($encodedPayload);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function verifyMbtiResultPageExport(string $token, int $orgId, string $attemptId): ?array
    {
        $token = trim($token);
        if ($token === '' || substr_count($token, '.') !== 1) {
            return null;
        }

        [$encodedPayload, $signature] = explode('.', $token, 2);
        if ($encodedPayload === '' || $signature === '' || ! hash_equals($this->sign($encodedPayload), $signature)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            return null;
        }

        if (
            (int) ($payload['v'] ?? 0) !== 1
            || (string) ($payload['typ'] ?? '') !== 'mbti_result_page_pdf'
            || (int) ($payload['org_id'] ?? $orgId) !== max(0, $orgId)
            || (string) ($payload['attempt_id'] ?? '') !== $attemptId
            || (string) ($payload['surface'] ?? '') !== ReportPdfDocumentService::MBTI_RESULT_PAGE_SNAPSHOT_SURFACE_VERSION
            || (string) ($payload['engine'] ?? '') !== ReportPdfDocumentService::RESULT_PAGE_EXPORT_ENGINE
            || (int) ($payload['exp'] ?? 0) <= now()->getTimestamp()
        ) {
            return null;
        }

        $attempt = Attempt::query()
            ->where('org_id', max(0, $orgId))
            ->where('id', $attemptId)
            ->first();

        if (! $attempt instanceof Attempt || strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== 'MBTI') {
            return null;
        }

        $payloadUserId = trim((string) ($payload['user_id'] ?? ''));
        $currentUserId = trim((string) ($attempt->user_id ?? ''));
        if (! hash_equals($currentUserId, $payloadUserId)) {
            return null;
        }

        $payloadOwnerId = trim((string) ($payload['owner_id'] ?? ''));
        $currentOwnerId = $currentUserId !== ''
            ? $currentUserId
            : trim((string) ($attempt->anon_id ?? ''));
        if ($payloadOwnerId === '' || $currentOwnerId === '' || ! hash_equals($currentOwnerId, $payloadOwnerId)) {
            return null;
        }

        $entitlement = strtolower(trim((string) ($payload['entitlement'] ?? '')));
        $variant = $this->normalizeVariant((string) ($payload['variant'] ?? 'free'));
        if (! in_array($entitlement, ['locked', 'unlocked'], true)) {
            return null;
        }

        if ($entitlement === 'unlocked' && $variant !== 'full') {
            return null;
        }

        if ($entitlement === 'locked' && $variant !== 'free') {
            return null;
        }

        $payload['entitlement'] = $entitlement;
        $payload['variant'] = $variant;

        return $payload;
    }

    private function normalizeVariant(string $variant): string
    {
        $variant = strtolower(trim($variant));

        return in_array($variant, ['free', 'full'], true) ? $variant : 'free';
    }

    private function sign(string $encodedPayload): string
    {
        return hash_hmac('sha256', $encodedPayload, $this->secret());
    }

    private function secret(): string
    {
        $secret = trim((string) config('gotenberg.result_print_token_secret', ''));
        if ($secret !== '') {
            return $secret;
        }

        return 'fap-result-page-pdf-local-key';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $padded = str_pad($normalized, strlen($normalized) + ((4 - strlen($normalized) % 4) % 4), '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : null;
    }
}
