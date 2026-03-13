<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailPreference;
use App\Models\EmailSubscriber;
use App\Models\EmailSuppression;
use App\Support\PiiCipher;
use Illuminate\Support\Str;

class EmailPreferenceService
{
    private const TOKEN_PREFIX = 'pref_';

    public function __construct(
        private readonly PiiCipher $piiCipher,
        private readonly EmailCaptureService $emailCapture,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     */
    public function issueTokenForEmail(string $email, array $context = []): string
    {
        $subscriber = $this->emailCapture->ensureSubscriber($email, $context);

        return $this->issueTokenForSubscriber($subscriber);
    }

    public function issueTokenForSubscriber(EmailSubscriber $subscriber): string
    {
        $payload = json_encode([
            'v' => 1,
            'subscriber_id' => (string) $subscriber->getKey(),
            'email_hash' => (string) $subscriber->email_hash,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $encrypted = $this->piiCipher->encrypt($payload ?: '');
        if ($encrypted === null) {
            $encrypted = $this->piiCipher->encrypt((string) Str::uuid()) ?? '';
        }

        return self::TOKEN_PREFIX.$this->base64UrlEncode($encrypted);
    }

    /**
     * @return array{
     *     allowed:bool,
     *     status:string,
     *     reason:?string,
     *     suppressed:bool,
     *     report_recovery:bool,
     *     marketing_updates:bool,
     *     product_updates:bool
     * }
     */
    public function deliveryPolicyForEmail(string $email, string $templateKey): array
    {
        $snapshot = $this->emailCapture->subscriberLifecycleSnapshot($email);
        if ($this->normalizeEmail($email) === null) {
            return [
                'allowed' => false,
                'status' => 'failed',
                'reason' => 'invalid_recipient',
                'suppressed' => false,
                'report_recovery' => false,
                'marketing_updates' => false,
                'product_updates' => false,
            ];
        }

        $subscriberStatus = (string) ($snapshot['subscriber_status'] ?? EmailSubscriber::STATUS_ACTIVE);
        $reportRecovery = (bool) ($snapshot['transactional_recovery_enabled'] ?? true);
        $marketingUpdates = (bool) ($snapshot['marketing_consent'] ?? false);
        $productUpdates = (bool) ($snapshot['marketing_consent'] ?? false);

        if ($subscriberStatus === EmailSubscriber::STATUS_SUPPRESSED) {
            return [
                'allowed' => false,
                'status' => 'suppressed',
                'reason' => 'email_suppressed',
                'suppressed' => true,
                'report_recovery' => $reportRecovery,
                'marketing_updates' => $marketingUpdates,
                'product_updates' => $productUpdates,
            ];
        }

        if ($this->requiresReportRecovery($templateKey) && ! $reportRecovery) {
            return [
                'allowed' => false,
                'status' => 'skipped',
                'reason' => 'report_recovery_disabled',
                'suppressed' => false,
                'report_recovery' => false,
                'marketing_updates' => $marketingUpdates,
                'product_updates' => $productUpdates,
            ];
        }

        return [
            'allowed' => true,
            'status' => 'allowed',
            'reason' => null,
            'suppressed' => false,
            'report_recovery' => $reportRecovery,
            'marketing_updates' => $marketingUpdates,
            'product_updates' => $productUpdates,
        ];
    }

    /**
     * @return array{
     *     ok:bool,
     *     status:int,
     *     error_code?:string,
     *     email_masked?:string,
     *     preferences?:array{marketing_updates:bool,report_recovery:bool,product_updates:bool}
     * }
     */
    public function showByToken(string $token): array
    {
        $resolved = $this->resolveByToken($token);
        if (! ($resolved['ok'] ?? false)) {
            return $resolved;
        }

        /** @var EmailSubscriber $subscriber */
        $subscriber = $resolved['subscriber'];
        /** @var EmailPreference $preference */
        $preference = $resolved['preference'];

        return [
            'ok' => true,
            'status' => 200,
            'email_masked' => $this->maskEmail($subscriber),
            'preferences' => $this->preferencesPayload($preference),
        ];
    }

    /**
     * @param  array<string,mixed>  $preferences
     * @return array{
     *     ok:bool,
     *     status:int,
     *     error_code?:string,
     *     preferences?:array{marketing_updates:bool,report_recovery:bool,product_updates:bool}
     * }
     */
    public function updateByToken(string $token, array $preferences): array
    {
        $resolved = $this->resolveByToken($token);
        if (! ($resolved['ok'] ?? false)) {
            return $resolved;
        }

        /** @var EmailSubscriber $subscriber */
        $subscriber = $resolved['subscriber'];
        /** @var EmailPreference $preference */
        $preference = $resolved['preference'];

        $preference->marketing_updates = (bool) ($preferences['marketing_updates'] ?? false);
        $preference->report_recovery = (bool) ($preferences['report_recovery'] ?? false);
        $preference->product_updates = (bool) ($preferences['product_updates'] ?? false);
        $preference->save();

        $subscriber->marketing_consent = $preference->allowsMarketing();
        $subscriber->transactional_recovery_enabled = $preference->allowsReportRecovery();
        $subscriber->last_marketing_consent_at = now();
        $subscriber->last_transactional_recovery_change_at = now();
        $subscriber->unsubscribed_at = $preference->allowsMarketing() || $preference->allowsReportRecovery()
            ? null
            : now();
        $subscriber->status = $this->resolveSubscriberStatus(
            $subscriber,
            $subscriber->marketing_consent,
            $subscriber->transactional_recovery_enabled
        );
        $subscriber->save();

        return [
            'ok' => true,
            'status' => 200,
            'preferences' => $this->preferencesPayload($preference),
        ];
    }

    /**
     * @return array{ok:bool,status:int,error_code?:string,status_text?:string}
     */
    public function unsubscribeByToken(string $token, ?string $reason = null): array
    {
        $resolved = $this->resolveByToken($token);
        if (! ($resolved['ok'] ?? false)) {
            return $resolved;
        }

        /** @var EmailSubscriber $subscriber */
        $subscriber = $resolved['subscriber'];
        /** @var EmailPreference $preference */
        $preference = $resolved['preference'];

        $preference->marketing_updates = false;
        $preference->report_recovery = false;
        $preference->product_updates = false;
        $preference->save();

        $subscriber->marketing_consent = false;
        $subscriber->transactional_recovery_enabled = false;
        $subscriber->last_marketing_consent_at = now();
        $subscriber->last_transactional_recovery_change_at = now();
        $subscriber->unsubscribed_at = now();
        $subscriber->status = EmailSubscriber::STATUS_UNSUBSCRIBED;

        if (is_string($reason) && trim($reason) !== '') {
            $lastContext = is_array($subscriber->last_context_json) ? $subscriber->last_context_json : [];
            $lastContext['unsubscribe_reason'] = trim($reason);
            $subscriber->last_context_json = $lastContext;
        }
        $subscriber->save();

        return [
            'ok' => true,
            'status' => 200,
            'status_text' => 'unsubscribed',
        ];
    }

    /**
     * @return array{
     *     ok:bool,
     *     status:int,
     *     error_code?:string,
     *     subscriber?:EmailSubscriber,
     *     preference?:EmailPreference
     * }
     */
    private function resolveByToken(string $token): array
    {
        $payload = $this->decodeToken($token);
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'status' => 422,
                'error_code' => 'INVALID_TOKEN',
            ];
        }

        $subscriberId = trim((string) ($payload['subscriber_id'] ?? ''));
        $emailHash = trim((string) ($payload['email_hash'] ?? ''));
        if ($subscriberId === '' || $emailHash === '') {
            return [
                'ok' => false,
                'status' => 422,
                'error_code' => 'INVALID_TOKEN',
            ];
        }

        $subscriber = EmailSubscriber::query()
            ->with('preference')
            ->find($subscriberId);
        if (! $subscriber || (string) ($subscriber->email_hash ?? '') !== $emailHash) {
            return [
                'ok' => false,
                'status' => 404,
                'error_code' => 'TOKEN_NOT_FOUND',
            ];
        }

        $preference = $subscriber->preference instanceof EmailPreference
            ? $subscriber->preference
            : new EmailPreference([
                'id' => (string) Str::uuid(),
                'subscriber_id' => (string) $subscriber->getKey(),
                'marketing_updates' => $subscriber->allowsMarketing(),
                'report_recovery' => $subscriber->allowsTransactionalRecovery(),
                'product_updates' => $subscriber->allowsMarketing(),
            ]);
        if (! $preference->exists) {
            $preference->save();
            $subscriber->setRelation('preference', $preference);
        }

        return [
            'ok' => true,
            'status' => 200,
            'subscriber' => $subscriber,
            'preference' => $preference,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeToken(string $token): ?array
    {
        $token = trim($token);
        if (! str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        $encoded = substr($token, strlen(self::TOKEN_PREFIX));
        $decoded = $this->base64UrlDecode($encoded);
        if ($decoded === null) {
            return null;
        }

        $json = $this->piiCipher->decrypt($decoded);
        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function requiresReportRecovery(string $templateKey): bool
    {
        return in_array(trim($templateKey), ['payment_success', 'report_claim'], true);
    }

    private function normalizeEmail(string $email): ?string
    {
        $normalized = mb_strtolower(trim($email), 'UTF-8');
        if ($normalized === '') {
            return null;
        }

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr(trim($value), '-_', '+/');
        if ($normalized === '') {
            return null;
        }

        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return is_string($decoded) ? $decoded : null;
    }

    private function resolveSubscriberStatus(
        EmailSubscriber $subscriber,
        bool $marketingConsent,
        bool $transactionalRecoveryEnabled
    ): string {
        $suppressed = EmailSuppression::query()
            ->where('email_hash', (string) ($subscriber->email_hash ?? ''))
            ->exists();

        if ($suppressed) {
            return EmailSubscriber::STATUS_SUPPRESSED;
        }

        if ($subscriber->unsubscribed_at !== null || (! $marketingConsent && ! $transactionalRecoveryEnabled)) {
            return EmailSubscriber::STATUS_UNSUBSCRIBED;
        }

        return EmailSubscriber::STATUS_ACTIVE;
    }

    private function maskEmail(EmailSubscriber $subscriber): string
    {
        $email = $this->piiCipher->decrypt((string) ($subscriber->email_enc ?? ''));
        if ($email === null || ! str_contains($email, '@')) {
            return '***';
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $prefixLength = max(1, min(2, mb_strlen($localPart, 'UTF-8')));
        $prefix = mb_substr($localPart, 0, $prefixLength, 'UTF-8');

        return $prefix.'***@'.$domain;
    }

    /**
     * @return array{marketing_updates:bool,report_recovery:bool,product_updates:bool}
     */
    private function preferencesPayload(EmailPreference $preference): array
    {
        return [
            'marketing_updates' => (bool) $preference->marketing_updates,
            'report_recovery' => (bool) $preference->report_recovery,
            'product_updates' => (bool) $preference->product_updates,
        ];
    }
}
