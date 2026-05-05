<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\AttemptEmailBinding;
use App\Models\Result;
use App\Services\Email\EmailCaptureService;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Support\PiiCipher;
use Illuminate\Support\Facades\DB;

final class AttemptEmailBindingService
{
    private const BINDABLE_PUBLIC_SCALES = [
        'MBTI',
        'BIG5_OCEAN',
        'ENNEAGRAM',
        'RIASEC',
        'IQ_RAVEN',
        'EQ_60',
    ];

    private const EXCLUDED_SENSITIVE_SCALES = [
        'SDS_20',
        'CLINICAL_COMBO_68',
    ];

    public function __construct(
        private readonly PiiCipher $piiCipher,
        private readonly ScaleCodeResponseProjector $scaleCodeProjector,
        private readonly EmailCaptureService $emailCaptureService,
    ) {}

    /**
     * @param  array{locale?:string|null,surface?:string|null}  $context
     * @return array<string,mixed>
     */
    public function bind(Attempt $attempt, string $email, ?string $userId, ?string $anonId, array $context = []): array
    {
        $orgId = (int) ($attempt->org_id ?? 0);
        $attemptId = trim((string) ($attempt->id ?? ''));
        if ($attemptId === '') {
            throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
        }

        $result = Result::query()
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->first();

        if (! $result instanceof Result) {
            throw new ApiProblemException(404, 'RESULT_NOT_FOUND', 'result not found.');
        }

        $scaleCode = $this->resolveScaleCode($attempt, $result);
        if (in_array($scaleCode, self::EXCLUDED_SENSITIVE_SCALES, true)) {
            throw new ApiProblemException(422, 'EMAIL_BIND_UNSUPPORTED_SCALE', 'email result binding is not available for this scale.');
        }
        if (! in_array($scaleCode, self::BINDABLE_PUBLIC_SCALES, true)) {
            throw new ApiProblemException(422, 'EMAIL_BIND_UNSUPPORTED_SCALE', 'email result binding is not available for this scale.');
        }

        $normalizedEmail = $this->piiCipher->normalizeEmail($email);
        $emailHash = $this->piiCipher->emailHash($normalizedEmail);
        $emailEnc = $this->piiCipher->encrypt($normalizedEmail);
        if ($emailEnc === null) {
            throw new ApiProblemException(422, 'EMAIL_INVALID', 'email invalid.');
        }

        $source = $this->normalizeSource($context['surface'] ?? null);
        $now = now();

        $binding = DB::transaction(function () use (
            $orgId,
            $attemptId,
            $emailHash,
            $emailEnc,
            $userId,
            $anonId,
            $source,
            $now
        ): AttemptEmailBinding {
            $binding = AttemptEmailBinding::query()
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->where('email_hash', $emailHash)
                ->lockForUpdate()
                ->first();

            if (! $binding instanceof AttemptEmailBinding) {
                $binding = new AttemptEmailBinding([
                    'org_id' => $orgId,
                    'attempt_id' => $attemptId,
                    'email_hash' => $emailHash,
                    'first_bound_at' => $now,
                ]);
            }

            $binding->pii_email_key_version = (string) $this->piiCipher->currentKeyVersion();
            $binding->email_enc = $emailEnc;
            $binding->bound_user_id = $this->normalizeNullableString($userId, 64);
            $binding->bound_anon_id = $this->normalizeNullableString($anonId, 128);
            $binding->status = AttemptEmailBinding::STATUS_ACTIVE;
            $binding->source = $source;
            $binding->last_accessed_at = $now;
            $binding->save();

            return $binding;
        });

        $this->captureEmailLifecycle($normalizedEmail, $attemptId, $context);

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'status' => AttemptEmailBinding::STATUS_ACTIVE,
            'binding_id' => (string) $binding->getKey(),
            'result_url' => $this->localizedResultUrl($attemptId, $context['locale'] ?? null),
        ];
    }

    private function resolveScaleCode(Attempt $attempt, Result $result): string
    {
        $projected = $this->scaleCodeProjector->project(
            (string) (($result->scale_code ?? null) ?: ($attempt->scale_code ?? '')),
            (string) (($result->scale_code_v2 ?? null) ?: ($attempt->scale_code_v2 ?? '')),
            ($result->scale_uid ?? null) !== null
                ? (string) $result->scale_uid
                : (($attempt->scale_uid ?? null) !== null ? (string) $attempt->scale_uid : null)
        );

        return strtoupper(trim((string) ($projected['scale_code_legacy'] ?: $projected['scale_code'])));
    }

    /**
     * @param  array{locale?:string|null,surface?:string|null}  $context
     */
    private function captureEmailLifecycle(string $email, string $attemptId, array $context): void
    {
        try {
            $this->emailCaptureService->capture($email, [
                'locale' => $this->normalizeNullableString($context['locale'] ?? null, 16),
                'surface' => 'result',
                'attempt_id' => $attemptId,
                'entrypoint' => $this->normalizeSource($context['surface'] ?? null),
                'marketing_consent' => false,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function localizedResultUrl(string $attemptId, mixed $locale): string
    {
        $normalized = strtolower(trim((string) $locale));
        $prefix = str_starts_with($normalized, 'zh') ? '/zh' : '/en';

        return "{$prefix}/result/{$attemptId}";
    }

    private function normalizeSource(mixed $source): string
    {
        $normalized = $this->normalizeNullableString($source, 64);

        return $normalized ?? 'result_gate';
    }

    private function normalizeNullableString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }
}
