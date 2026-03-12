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
     *     marketing_consent:bool,
     *     preferences:array{marketing_updates:bool,report_recovery:bool,product_updates:bool}
     * }
     */
    public function capture(string $email, array $context = []): array
    {
        $status = 'captured';
        $subscriber = $this->upsertSubscriber($email, $context, $status);
        $preference = $subscriber->preference instanceof EmailPreference
            ? $subscriber->preference
            : $this->ensurePreference($subscriber, null);

        return [
            'ok' => true,
            'subscriber_id' => (string) $subscriber->getKey(),
            'status' => $status,
            'marketing_consent' => (bool) ($subscriber->marketing_consent ?? false),
            'preferences' => $this->preferencesPayload($preference),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function ensureSubscriber(string $email, array $context = []): EmailSubscriber
    {
        $status = 'captured';

        return $this->upsertSubscriber($email, $context, $status);
    }

    public function isSuppressed(string $email): bool
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === null) {
            return false;
        }

        return EmailSuppression::query()
            ->where('email_hash', $this->piiCipher->emailHash($normalized))
            ->exists();
    }

    public function allowsReportRecovery(string $email): bool
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === null) {
            return false;
        }

        if ($this->isSuppressed($normalized)) {
            return false;
        }

        $subscriber = EmailSubscriber::query()
            ->with('preference')
            ->where('email_hash', $this->piiCipher->emailHash($normalized))
            ->first();

        if (! $subscriber) {
            return true;
        }

        $preference = $subscriber->preference instanceof EmailPreference
            ? $subscriber->preference
            : null;
        if ($preference instanceof EmailPreference) {
            return (bool) $preference->report_recovery;
        }

        return (bool) ($subscriber->transactional_recovery_enabled ?? true);
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
    private function upsertSubscriber(string $email, array $context, string &$status): EmailSubscriber
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
        $created = false;
        $subscriber = DB::transaction(function () use (
            $emailHash,
            $emailEnc,
            $locale,
            $source,
            $contextPayload,
            $marketingConsent,
            $marketingConsentProvided,
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

            if ($created && $source !== null && $subscriber->first_source === null) {
                $subscriber->first_source = $source;
            }
            if ($source !== null) {
                $subscriber->last_source = $source;
            }

            if ($created && $contextPayload !== [] && empty($subscriber->first_context_json)) {
                $subscriber->first_context_json = $contextPayload;
            }
            if ($contextPayload !== []) {
                $subscriber->last_context_json = $contextPayload;
            }

            if ($marketingConsentProvided && $marketingConsent !== null) {
                $subscriber->marketing_consent = $marketingConsent;
            }

            $subscriber->save();

            $preference = $this->ensurePreference($subscriber, $marketingConsentProvided ? $marketingConsent : null);
            $subscriber->transactional_recovery_enabled = (bool) $preference->report_recovery;
            $subscriber->marketing_consent = (bool) ($preference->marketing_updates || $preference->product_updates);
            $subscriber->save();
            $subscriber->setRelation('preference', $preference);

            return $subscriber;
        });

        $status = $suppressed ? 'suppressed' : ($created ? 'captured' : 'updated');

        return $subscriber;
    }

    private function ensurePreference(EmailSubscriber $subscriber, ?bool $marketingConsent): EmailPreference
    {
        $preference = EmailPreference::query()
            ->where('subscriber_id', $subscriber->getKey())
            ->first();

        if (! $preference) {
            $preference = new EmailPreference([
                'id' => (string) Str::uuid(),
                'subscriber_id' => (string) $subscriber->getKey(),
                'marketing_updates' => (bool) $marketingConsent,
                'report_recovery' => true,
                'product_updates' => (bool) $marketingConsent,
            ]);
            $preference->save();

            return $preference;
        }

        if ($marketingConsent !== null) {
            $preference->marketing_updates = $marketingConsent;
            $preference->product_updates = $marketingConsent;
            $preference->save();
        }

        return $preference;
    }

    /**
     * @param  array<string,mixed>  $context
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
