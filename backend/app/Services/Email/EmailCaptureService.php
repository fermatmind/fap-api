<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailPreference;
use App\Models\EmailSubscriber;
use App\Models\EmailSuppression;
use App\Support\PiiCipher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EmailCaptureService
{
    public function __construct(
        private readonly PiiCipher $piiCipher,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *     ok:bool,
     *     subscriber_id:string,
     *     status:string,
     *     subscriber_status:string,
     *     captured_at:string,
     *     marketing_consent:bool,
     *     transactional_recovery_enabled:bool,
     *     preferences:array{marketing_updates:bool,report_recovery:bool,product_updates:bool}
     * }
     */
    public function capture(string $email, array $context = []): array
    {
        $captureStatus = 'captured';
        $subscriber = $this->upsertSubscriber($email, $context, $captureStatus);
        $preference = $subscriber->preference instanceof EmailPreference
            ? $subscriber->preference
            : $this->ensurePreference($subscriber, null, null);

        return [
            'ok' => true,
            'subscriber_id' => (string) $subscriber->getKey(),
            'status' => $captureStatus,
            'subscriber_status' => (string) ($subscriber->status ?? EmailSubscriber::STATUS_ACTIVE),
            'captured_at' => $subscriber->last_captured_at?->toIso8601String() ?? now()->toIso8601String(),
            'marketing_consent' => $subscriber->allowsMarketing(),
            'transactional_recovery_enabled' => $subscriber->allowsTransactionalRecovery(),
            'preferences' => $this->preferencesPayload($preference),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function ensureSubscriber(string $email, array $context = []): EmailSubscriber
    {
        $captureStatus = 'captured';

        return $this->upsertSubscriber($email, $context, $captureStatus);
    }

    /**
     * @return array{
     *     subscriber_id:?string,
     *     subscriber_status:string,
     *     marketing_consent:bool,
     *     transactional_recovery_enabled:bool,
     *     first_source:?string,
     *     last_source:?string,
     *     first_context_json:array<string,mixed>,
     *     last_context_json:array<string,mixed>,
     *     first_captured_at:?string,
     *     last_captured_at:?string
     * }
     */
    public function subscriberLifecycleSnapshot(string $email): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === null) {
            return $this->defaultLifecycleSnapshot();
        }

        $emailHash = $this->piiCipher->emailHash($normalizedEmail);
        $suppressed = EmailSuppression::query()
            ->where('email_hash', $emailHash)
            ->exists();

        $subscriber = EmailSubscriber::query()
            ->with('preference')
            ->where('email_hash', $emailHash)
            ->first();

        if (! $subscriber) {
            return [
                'subscriber_id' => null,
                'subscriber_status' => $suppressed ? EmailSubscriber::STATUS_SUPPRESSED : EmailSubscriber::STATUS_ACTIVE,
                'marketing_consent' => false,
                'transactional_recovery_enabled' => true,
                'first_source' => null,
                'last_source' => null,
                'first_context_json' => [],
                'last_context_json' => [],
                'first_captured_at' => null,
                'last_captured_at' => null,
            ];
        }

        $preference = $subscriber->preference instanceof EmailPreference
            ? $subscriber->preference
            : null;

        $marketingConsent = $preference instanceof EmailPreference
            ? $preference->allowsMarketing()
            : $subscriber->allowsMarketing();
        $transactionalRecoveryEnabled = $preference instanceof EmailPreference
            ? $preference->allowsReportRecovery()
            : $subscriber->allowsTransactionalRecovery();
        $subscriberStatus = $this->resolveLifecycleStatus(
            $suppressed,
            $marketingConsent,
            $transactionalRecoveryEnabled,
            $subscriber->unsubscribed_at
        );

        if ((string) ($subscriber->status ?? '') !== $subscriberStatus) {
            $subscriber->status = $subscriberStatus;
            $subscriber->save();
        }

        return [
            'subscriber_id' => (string) $subscriber->getKey(),
            'subscriber_status' => $subscriberStatus,
            'marketing_consent' => $marketingConsent,
            'transactional_recovery_enabled' => $transactionalRecoveryEnabled,
            'first_source' => $subscriber->first_source,
            'last_source' => $subscriber->last_source,
            'first_context_json' => is_array($subscriber->first_context_json) ? $subscriber->first_context_json : [],
            'last_context_json' => is_array($subscriber->last_context_json) ? $subscriber->last_context_json : [],
            'first_captured_at' => $subscriber->first_captured_at?->toIso8601String(),
            'last_captured_at' => $subscriber->last_captured_at?->toIso8601String(),
        ];
    }

    public function isSuppressed(string $email): bool
    {
        return $this->subscriberLifecycleSnapshot($email)['subscriber_status'] === EmailSubscriber::STATUS_SUPPRESSED;
    }

    public function allowsReportRecovery(string $email): bool
    {
        $snapshot = $this->subscriberLifecycleSnapshot($email);

        if ($snapshot['subscriber_status'] === EmailSubscriber::STATUS_SUPPRESSED) {
            return false;
        }

        return (bool) $snapshot['transactional_recovery_enabled'];
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

    /**
     * @param  array<string,mixed>  $context
     */
    private function upsertSubscriber(string $email, array $context, string &$captureStatus): EmailSubscriber
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === null) {
            throw new InvalidArgumentException('email invalid');
        }

        $emailHash = $this->piiCipher->emailHash($normalizedEmail);
        $emailEnc = $this->piiCipher->encrypt($normalizedEmail);
        if ($emailEnc === null) {
            throw new InvalidArgumentException('email invalid');
        }

        $suppressed = EmailSuppression::query()
            ->where('email_hash', $emailHash)
            ->exists();
        $locale = $this->truncateString($context['locale'] ?? null, 16);
        $source = $this->resolveSource($context);
        $contextPayload = $this->normalizeContext($context);
        $marketingConsent = $this->resolveMarketingConsent($context);
        $marketingConsentProvided = array_key_exists('marketing_consent', $context);
        $transactionalRecoveryEnabled = $this->resolveTransactionalRecoveryEnabled($context);
        $transactionalRecoveryProvided = array_key_exists('transactional_recovery_enabled', $context);
        $capturedAt = now();
        $created = false;

        $subscriber = DB::transaction(function () use (
            $emailHash,
            $emailEnc,
            $locale,
            $source,
            $contextPayload,
            $marketingConsent,
            $marketingConsentProvided,
            $transactionalRecoveryEnabled,
            $transactionalRecoveryProvided,
            $suppressed,
            $capturedAt,
            &$created
        ): EmailSubscriber {
            $subscriber = EmailSubscriber::query()
                ->where('email_hash', $emailHash)
                ->lockForUpdate()
                ->first();

            if (! $subscriber) {
                $subscriber = new EmailSubscriber([
                    'id' => (string) Str::uuid(),
                    'email_hash' => $emailHash,
                    'status' => EmailSubscriber::STATUS_ACTIVE,
                    'marketing_consent' => false,
                    'transactional_recovery_enabled' => true,
                ]);
                $created = true;
            }

            $subscriber->email_enc = $emailEnc;
            $subscriber->pii_email_key_version = (string) $this->piiCipher->currentKeyVersion();

            if ($locale !== null) {
                $subscriber->locale = $locale;
            }

            if ($source !== null && $subscriber->first_source === null) {
                $subscriber->first_source = $source;
            }
            if ($source !== null) {
                $subscriber->last_source = $source;
            }

            if ($contextPayload !== []) {
                $firstContext = is_array($subscriber->first_context_json) ? $subscriber->first_context_json : [];
                $lastContext = is_array($subscriber->last_context_json) ? $subscriber->last_context_json : [];

                if ($firstContext === []) {
                    $subscriber->first_context_json = $contextPayload;
                }
                $subscriber->last_context_json = array_replace_recursive($lastContext, $contextPayload);
            }

            if ($subscriber->first_captured_at === null) {
                $subscriber->first_captured_at = $capturedAt;
            }
            $subscriber->last_captured_at = $capturedAt;

            if ($marketingConsentProvided && $marketingConsent !== null) {
                $subscriber->marketing_consent = $marketingConsent;
                $subscriber->last_marketing_consent_at = $capturedAt;
            }
            if ($transactionalRecoveryProvided && $transactionalRecoveryEnabled !== null) {
                $subscriber->transactional_recovery_enabled = $transactionalRecoveryEnabled;
                $subscriber->last_transactional_recovery_change_at = $capturedAt;
            }

            $subscriber->save();

            $preference = $this->ensurePreference(
                $subscriber,
                $marketingConsentProvided ? $marketingConsent : null,
                $transactionalRecoveryProvided ? $transactionalRecoveryEnabled : null
            );

            $subscriber->transactional_recovery_enabled = $preference->allowsReportRecovery();
            $subscriber->marketing_consent = $preference->allowsMarketing();
            $subscriber->status = $this->resolveLifecycleStatus(
                $suppressed,
                $subscriber->allowsMarketing(),
                $subscriber->allowsTransactionalRecovery(),
                $subscriber->unsubscribed_at
            );
            if ($subscriber->status === EmailSubscriber::STATUS_UNSUBSCRIBED && $subscriber->unsubscribed_at === null) {
                $subscriber->unsubscribed_at = $capturedAt;
            }
            if ($subscriber->status === EmailSubscriber::STATUS_ACTIVE) {
                $subscriber->unsubscribed_at = null;
            }
            $subscriber->save();
            $subscriber->setRelation('preference', $preference);

            return $subscriber->fresh(['preference']);
        });

        $captureStatus = $suppressed ? 'suppressed' : ($created ? 'captured' : 'updated');

        return $subscriber;
    }

    private function ensurePreference(
        EmailSubscriber $subscriber,
        ?bool $marketingConsent,
        ?bool $transactionalRecoveryEnabled
    ): EmailPreference {
        $preference = EmailPreference::query()
            ->where('subscriber_id', $subscriber->getKey())
            ->first();

        if (! $preference) {
            $preference = new EmailPreference([
                'id' => (string) Str::uuid(),
                'subscriber_id' => (string) $subscriber->getKey(),
                'marketing_updates' => $marketingConsent ?? $subscriber->allowsMarketing(),
                'report_recovery' => $transactionalRecoveryEnabled ?? $subscriber->allowsTransactionalRecovery(),
                'product_updates' => $marketingConsent ?? $subscriber->allowsMarketing(),
            ]);
            $preference->save();

            return $preference;
        }

        $dirty = false;
        if ($marketingConsent !== null) {
            $dirty = $dirty || (bool) $preference->marketing_updates !== $marketingConsent;
            $dirty = $dirty || (bool) $preference->product_updates !== $marketingConsent;
            $preference->marketing_updates = $marketingConsent;
            $preference->product_updates = $marketingConsent;
        }
        if ($transactionalRecoveryEnabled !== null) {
            $dirty = $dirty || (bool) $preference->report_recovery !== $transactionalRecoveryEnabled;
            $preference->report_recovery = $transactionalRecoveryEnabled;
        }

        if ($dirty) {
            $preference->save();
        }

        return $preference;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        foreach ([
            'locale' => 16,
            'surface' => 64,
            'order_no' => 64,
            'attempt_id' => 64,
            'share_id' => 128,
            'compare_invite_id' => 128,
            'share_click_id' => 128,
            'entrypoint' => 128,
            'referrer' => 2048,
            'landing_path' => 2048,
        ] as $field => $maxLength) {
            $value = $this->truncateString($context[$field] ?? null, $maxLength);
            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        $utm = $this->normalizeUtm($context['utm'] ?? null);
        if ($utm !== []) {
            $normalized['utm'] = $utm;
        }

        if (array_key_exists('marketing_consent', $context)) {
            $consent = $this->resolveMarketingConsent($context);
            if ($consent !== null) {
                $normalized['marketing_consent'] = $consent;
            }
        }
        if (array_key_exists('transactional_recovery_enabled', $context)) {
            $recovery = $this->resolveTransactionalRecoveryEnabled($context);
            if ($recovery !== null) {
                $normalized['transactional_recovery_enabled'] = $recovery;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function resolveSource(array $context): ?string
    {
        return $this->truncateString($context['surface'] ?? ($context['entrypoint'] ?? null), 64);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function resolveMarketingConsent(array $context): ?bool
    {
        if (! array_key_exists('marketing_consent', $context)) {
            return null;
        }

        $value = filter_var($context['marketing_consent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? false;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function resolveTransactionalRecoveryEnabled(array $context): ?bool
    {
        if (! array_key_exists('transactional_recovery_enabled', $context)) {
            return null;
        }

        $value = filter_var($context['transactional_recovery_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? false;
    }

    /**
     * @return array<string,string>
     */
    private function normalizeUtm(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $candidate = $this->truncateString($value[$key] ?? null, 512);
            if ($candidate !== null) {
                $normalized[$key] = $candidate;
            }
        }

        return $normalized;
    }

    private function truncateString(mixed $value, int $maxLength): ?string
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

    private function resolveLifecycleStatus(
        bool $suppressed,
        bool $marketingConsent,
        bool $transactionalRecoveryEnabled,
        mixed $unsubscribedAt = null
    ): string {
        if ($suppressed) {
            return EmailSubscriber::STATUS_SUPPRESSED;
        }

        if ($unsubscribedAt !== null || (! $marketingConsent && ! $transactionalRecoveryEnabled)) {
            return EmailSubscriber::STATUS_UNSUBSCRIBED;
        }

        return EmailSubscriber::STATUS_ACTIVE;
    }

    /**
     * @return array{
     *     subscriber_id:?string,
     *     subscriber_status:string,
     *     marketing_consent:bool,
     *     transactional_recovery_enabled:bool,
     *     first_source:?string,
     *     last_source:?string,
     *     first_context_json:array<string,mixed>,
     *     last_context_json:array<string,mixed>,
     *     first_captured_at:?string,
     *     last_captured_at:?string
     * }
     */
    private function defaultLifecycleSnapshot(): array
    {
        return [
            'subscriber_id' => null,
            'subscriber_status' => EmailSubscriber::STATUS_ACTIVE,
            'marketing_consent' => false,
            'transactional_recovery_enabled' => true,
            'first_source' => null,
            'last_source' => null,
            'first_context_json' => [],
            'last_context_json' => [],
            'first_captured_at' => null,
            'last_captured_at' => null,
        ];
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
