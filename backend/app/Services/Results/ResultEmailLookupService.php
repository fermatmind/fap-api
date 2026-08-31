<?php

declare(strict_types=1);

namespace App\Services\Results;

use App\Models\Attempt;
use App\Models\AttemptEmailBinding;
use App\Models\Result;
use App\Services\Email\EmailOutboxService;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Support\PiiCipher;
use Illuminate\Support\Facades\RateLimiter;

final class ResultEmailLookupService
{
    private const LOOKUP_PUBLIC_SCALES = [
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
        private readonly ResultAccessTokenService $resultAccessTokens,
        private readonly ScaleCodeResponseProjector $scaleCodeProjector,
        private readonly EmailOutboxService $emailOutbox,
    ) {}

    /**
     * @return array{ok:bool,items:list<array<string,mixed>>,email_verification_required:bool,recovery_email_requested?:bool,message:string}
     */
    public function lookup(
        string $email,
        int $orgId,
        ?string $locale = null,
        ?int $userId = null,
        mixed $tokenAnonId = null
    ): array {
        $normalizedEmail = $this->piiCipher->normalizeEmail($email);
        $emailHash = $normalizedEmail !== '' ? $this->piiCipher->emailHash($normalizedEmail) : null;
        $ownerUserId = $userId !== null && $userId > 0 ? (string) $userId : null;
        $ownerAnonIds = $this->ownerAnonIds($tokenAnonId);

        if ($emailHash === null) {
            return $this->verificationRequiredResponse();
        }

        if ($ownerUserId === null && $ownerAnonIds === []) {
            $this->queueRecoveryEmails($normalizedEmail, $emailHash, $orgId, $locale);

            return $this->verificationRequiredResponse();
        }

        $bindings = AttemptEmailBinding::query()
            ->where('org_id', max(0, $orgId))
            ->where('email_hash', $emailHash)
            ->where('status', AttemptEmailBinding::STATUS_ACTIVE)
            ->where(function ($query) use ($ownerUserId, $ownerAnonIds): void {
                if ($ownerUserId !== null) {
                    $query->orWhere('bound_user_id', $ownerUserId);
                }

                foreach ($ownerAnonIds as $anonId) {
                    $query->orWhere('bound_anon_id', $anonId);
                }
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $items = [];
        foreach ($bindings as $binding) {
            if (! $binding instanceof AttemptEmailBinding) {
                continue;
            }

            $item = $this->lookupItemForBinding($binding, $locale);
            if ($item === null) {
                continue;
            }

            $items[] = $item;
            if (count($items) >= 10) {
                break;
            }
        }

        if ($items !== []) {
            return [
                'ok' => true,
                'items' => $items,
                'email_verification_required' => false,
                'message' => 'Saved results are listed only when the email and current session match an active binding.',
            ];
        }

        $this->queueRecoveryEmails($normalizedEmail, $emailHash, $orgId, $locale);

        return $this->verificationRequiredResponse();
    }

    /**
     * @return array{ok:bool,items:list<array<string,mixed>>,email_verification_required:bool,recovery_email_requested:bool,message:string}
     */
    private function verificationRequiredResponse(): array
    {
        return [
            'ok' => true,
            'items' => [],
            'email_verification_required' => true,
            'recovery_email_requested' => true,
            'message' => 'If matching saved results exist, access links will be sent to the supplied email address.',
        ];
    }

    private function queueRecoveryEmails(string $email, string $emailHash, int $orgId, ?string $locale): void
    {
        $rateLimitKey = 'result_email_recovery|org:'.max(0, $orgId).'|email:'.$emailHash;

        RateLimiter::attempt($rateLimitKey, 1, function () use ($email, $emailHash, $orgId, $locale): void {
            $bindings = AttemptEmailBinding::query()
                ->where('org_id', max(0, $orgId))
                ->where('email_hash', $emailHash)
                ->where('status', AttemptEmailBinding::STATUS_ACTIVE)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();

            foreach ($bindings as $binding) {
                if (! $binding instanceof AttemptEmailBinding) {
                    continue;
                }

                $item = $this->lookupItemForBinding($binding, $locale);
                if ($item === null) {
                    continue;
                }

                $token = trim((string) ($item['result_access_token'] ?? ''));
                $expiresAt = trim((string) ($item['result_access_token_expires_at'] ?? ''));
                $resultUrl = trim((string) ($item['result_url'] ?? ''));
                if ($token === '' || $expiresAt === '' || $resultUrl === '') {
                    continue;
                }

                $this->emailOutbox->queueResultAccessLink(
                    $binding,
                    $email,
                    [
                        'token' => $token,
                        'expires_at' => $expiresAt,
                    ],
                    $resultUrl,
                    $locale,
                    [
                        'surface' => 'result_email_lookup',
                    ],
                );
            }
        }, 300);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lookupItemForBinding(AttemptEmailBinding $binding, ?string $locale): ?array
    {
        $orgId = (int) ($binding->org_id ?? 0);
        $attemptId = trim((string) ($binding->attempt_id ?? ''));
        if ($attemptId === '') {
            return null;
        }

        $attempt = Attempt::query()
            ->where('org_id', $orgId)
            ->where('id', $attemptId)
            ->first();
        $result = Result::query()
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->first();
        if (! $attempt instanceof Attempt || ! $result instanceof Result) {
            return null;
        }

        $scale = $this->scaleCodeProjector->project(
            (string) (($result->scale_code ?? null) ?: ($attempt->scale_code ?? '')),
            (string) (($result->scale_code_v2 ?? null) ?: ($attempt->scale_code_v2 ?? '')),
            ($result->scale_uid ?? null) !== null
                ? (string) $result->scale_uid
                : (($attempt->scale_uid ?? null) !== null ? (string) $attempt->scale_uid : null)
        );
        $legacyScaleCode = strtoupper(trim((string) ($scale['scale_code_legacy'] ?: $scale['scale_code'])));
        if (in_array($legacyScaleCode, self::EXCLUDED_SENSITIVE_SCALES, true)) {
            return null;
        }
        if (! in_array($legacyScaleCode, self::LOOKUP_PUBLIC_SCALES, true)) {
            return null;
        }

        $token = $this->resultAccessTokens->issueForBinding($binding);
        $rawToken = (string) $token['token'];

        return [
            'attempt_id' => $attemptId,
            'result_id' => (string) ($result->getKey() ?? ''),
            'scale_code' => (string) $scale['scale_code'],
            'scale_code_legacy' => (string) $scale['scale_code_legacy'],
            'scale_code_v2' => (string) $scale['scale_code_v2'],
            'scale_uid' => $scale['scale_uid'],
            'scale_version' => (string) (($result->scale_version ?? null) ?: ($attempt->scale_version ?? '')),
            'type_code' => $this->resolveTypeCode($result),
            'submitted_at' => $this->dateString($attempt->submitted_at ?? null),
            'computed_at' => $this->dateString($result->computed_at ?? null),
            'bound_at' => $this->dateString($binding->first_bound_at ?? $binding->created_at ?? null),
            'result_url' => $this->appendAccessTokenToUrl($this->localizedResultUrl($attemptId, $locale), $rawToken),
            'result_access_token' => $rawToken,
            'result_access_token_expires_at' => (string) $token['expires_at'],
        ];
    }

    private function resolveTypeCode(Result $result): string
    {
        $direct = trim((string) ($result->type_code ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];

        return trim((string) ($payload['type_code'] ?? ''));
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function localizedResultUrl(string $attemptId, mixed $locale): string
    {
        $normalized = strtolower(trim((string) $locale));
        $prefix = str_starts_with($normalized, 'zh') ? '/zh' : '/en';

        return "{$prefix}/result/{$attemptId}";
    }

    private function appendAccessTokenToUrl(string $url, string $token): string
    {
        $fragment = '';
        $base = $url;
        $fragmentPosition = strpos($url, '#');
        if ($fragmentPosition !== false) {
            $base = substr($url, 0, $fragmentPosition);
            $fragment = substr($url, $fragmentPosition);
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.'access_token='.rawurlencode($token).$fragment;
    }

    /**
     * @return list<string>
     */
    private function ownerAnonIds(mixed ...$values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $owner = $this->normalizeOwnerString($value);
            if ($owner !== null) {
                $normalized[$owner] = true;
            }
        }

        return array_keys($normalized);
    }

    private function normalizeOwnerString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
