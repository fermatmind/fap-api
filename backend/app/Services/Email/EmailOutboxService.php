<?php

namespace App\Services\Email;

use App\Models\EmailPreference;
use App\Models\EmailSubscriber;
use App\Support\PiiCipher;
use App\Support\PiiReadFallbackMonitor;
use App\Support\RuntimeConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class EmailOutboxService
{
    public function __construct(
        private readonly PiiCipher $piiCipher,
        private readonly PiiReadFallbackMonitor $fallbackMonitor,
        private readonly EmailPreferenceService $emailPreferences,
        private readonly EmailCaptureService $emailCaptures,
    ) {}

    /**
     * Queue a report-claim email task (outbox only, no send).
     *
     * @return array {ok:bool, claim_token?:string, claim_url?:string, expires_at?:string}
     */
    public function queueReportClaim(
        string $userId,
        string $email,
        string $attemptId,
        ?string $orderNo = null,
        array $attribution = [],
        ?string $preferredLocale = null
    ): array {
        if (! \App\Support\SchemaBaseline::hasTable('email_outbox')) {
            return ['ok' => false, 'error' => 'TABLE_MISSING'];
        }

        $userId = trim($userId);
        $email = trim($email);
        $attemptId = trim($attemptId);
        $orderNo = trim((string) $orderNo);

        if ($userId === '' || $email === '' || $attemptId === '') {
            return ['ok' => false, 'error' => 'INVALID_INPUT'];
        }

        $emailHash = $this->piiCipher->emailHash($email);
        $emailEnc = $this->piiCipher->encrypt($email);
        $keyVersion = $this->piiCipher->currentKeyVersion();
        $maskedEmail = $this->maskedLegacyEmail($emailHash);

        $token = 'claim_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addMinutes(15);
        $locale = $this->resolveRequestedLocale($attemptId, $preferredLocale);
        $subject = $this->defaultSubjectForTemplate('report_claim', $locale);

        $reportUrl = $this->reportUrl($attemptId);
        $reportPdfUrl = $this->reportPdfUrl($attemptId);
        $claimUrl = "/api/v0.3/claim/report?token={$token}";
        $lifecycleContext = $this->buildLifecyclePayloadContext($email, $orderNo !== '' ? $orderNo : null, $attribution);
        $normalizedAttribution = is_array($lifecycleContext['attribution'] ?? null)
            ? $lifecycleContext['attribution']
            : [];

        $payload = [
            'attempt_id' => $attemptId,
            'order_no' => $orderNo !== '' ? $orderNo : null,
            'report_url' => $reportUrl,
            'report_pdf_url' => $reportPdfUrl,
            'claim_expires_at' => $expiresAt->toIso8601String(),
            'locale' => $locale,
            'template_key' => 'report_claim',
            'to_email' => $email,
            'subject' => $subject,
            'subscriber_status' => (string) ($lifecycleContext['subscriber_status'] ?? 'active'),
            'marketing_consent' => (bool) ($lifecycleContext['marketing_consent'] ?? false),
            'transactional_recovery_enabled' => (bool) ($lifecycleContext['transactional_recovery_enabled'] ?? true),
            'surface' => $lifecycleContext['surface'] ?? null,
            'attribution' => $this->payloadAttribution($normalizedAttribution),
        ];
        $payloadJson = $this->encodePayloadJson($this->sanitizePayloadForJson($payload));
        $payloadEnc = $this->encodePayloadEncrypted($payload);

        $pending = null;
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'attempt_id')) {
            $pending = DB::table('email_outbox')
                ->where('user_id', $userId)
                ->where('attempt_id', $attemptId)
                ->where('template', 'report_claim')
                ->where('status', 'pending')
                ->where('claim_expires_at', '>', now())
                ->orderByDesc('updated_at')
                ->first();
        }

        if ($pending) {
            $update = [
                'email' => $maskedEmail,
                'attempt_id' => $attemptId,
                'payload_json' => $payloadJson,
                'claim_token_hash' => $tokenHash,
                'claim_expires_at' => $expiresAt,
                'updated_at' => now(),
            ];
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_hash')) {
                $update['email_hash'] = $emailHash;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_enc')) {
                $update['email_enc'] = $emailEnc;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_hash')) {
                $update['to_email_hash'] = $emailHash;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_enc')) {
                $update['to_email_enc'] = $emailEnc;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
                $update['payload_enc'] = $payloadEnc;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_schema_version')) {
                $update['payload_schema_version'] = 'v1';
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'key_version')) {
                $update['key_version'] = $keyVersion;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'locale')) {
                $update['locale'] = $locale;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
                $update['template_key'] = 'report_claim';
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email')) {
                $update['to_email'] = $maskedEmail;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'subject')) {
                $update['subject'] = $subject;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'body_html')) {
                $update['body_html'] = null;
            }

            DB::table('email_outbox')
                ->where('id', $pending->id)
                ->update($update);

            return [
                'ok' => true,
                'claim_token' => $token,
                'claim_url' => $claimUrl,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }

        $row = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'email' => $maskedEmail,
            'attempt_id' => $attemptId,
            'template' => 'report_claim',
            'payload_json' => $payloadJson,
            'claim_token_hash' => $tokenHash,
            'claim_expires_at' => $expiresAt,
            'status' => 'pending',
            'sent_at' => null,
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_hash')) {
            $row['email_hash'] = $emailHash;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_enc')) {
            $row['email_enc'] = $emailEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_hash')) {
            $row['to_email_hash'] = $emailHash;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_enc')) {
            $row['to_email_enc'] = $emailEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
            $row['payload_enc'] = $payloadEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_schema_version')) {
            $row['payload_schema_version'] = 'v1';
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'key_version')) {
            $row['key_version'] = $keyVersion;
        }

        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'locale')) {
            $row['locale'] = $locale;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
            $row['template_key'] = 'report_claim';
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email')) {
            $row['to_email'] = $maskedEmail;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'subject')) {
            $row['subject'] = $subject;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'body_html')) {
            $row['body_html'] = null;
        }

        DB::table('email_outbox')->insert($row);

        return [
            'ok' => true,
            'claim_token' => $token,
            'claim_url' => $claimUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Queue a payment-success email task (outbox only, no send).
     *
     * @return array{ok:bool,error?:string}
     */
    public function queuePaymentSuccess(
        string $userId,
        string $email,
        string $attemptId,
        ?string $orderNo = null,
        ?string $productSummary = null,
        array $attribution = [],
        ?string $preferredLocale = null
    ): array {
        if (! \App\Support\SchemaBaseline::hasTable('email_outbox')) {
            return ['ok' => false, 'error' => 'TABLE_MISSING'];
        }

        $userId = trim($userId);
        $email = trim($email);
        $attemptId = trim($attemptId);
        $orderNo = trim((string) $orderNo);
        $productSummary = trim((string) $productSummary);
        if ($userId === '' || $email === '' || $attemptId === '') {
            return ['ok' => false, 'error' => 'INVALID_INPUT'];
        }

        $emailHash = $this->piiCipher->emailHash($email);
        $emailEnc = $this->piiCipher->encrypt($email);
        $keyVersion = $this->piiCipher->currentKeyVersion();
        $maskedEmail = $this->maskedLegacyEmail($emailHash);

        $locale = $this->resolveRequestedLocale($attemptId, $preferredLocale);
        $subject = $this->defaultSubjectForTemplate('payment_success', $locale);
        $reportUrl = $this->reportUrl($attemptId);
        $reportPdfUrl = $this->reportPdfUrl($attemptId);
        $nonce = hash('sha256', 'payment_success|'.$attemptId.'|'.Str::uuid()->toString());
        $lifecycleContext = $this->buildLifecyclePayloadContext($email, $orderNo !== '' ? $orderNo : null, $attribution);
        $normalizedAttribution = is_array($lifecycleContext['attribution'] ?? null)
            ? $lifecycleContext['attribution']
            : [];

        $payload = [
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'report_url' => $reportUrl,
            'report_pdf_url' => $reportPdfUrl,
            'product_summary' => $productSummary,
            'locale' => $locale,
            'template_key' => 'payment_success',
            'to_email' => $email,
            'subject' => $subject,
            'subscriber_status' => (string) ($lifecycleContext['subscriber_status'] ?? 'active'),
            'marketing_consent' => (bool) ($lifecycleContext['marketing_consent'] ?? false),
            'transactional_recovery_enabled' => (bool) ($lifecycleContext['transactional_recovery_enabled'] ?? true),
            'surface' => $lifecycleContext['surface'] ?? null,
            'attribution' => $this->payloadAttribution($normalizedAttribution),
        ];
        $payloadJson = $this->encodePayloadJson($this->sanitizePayloadForJson($payload));
        $payloadEnc = $this->encodePayloadEncrypted($payload);

        $pending = null;
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'attempt_id')) {
            $pending = DB::table('email_outbox')
                ->where('user_id', $userId)
                ->where('attempt_id', $attemptId)
                ->where('template', 'payment_success')
                ->where('status', 'pending')
                ->orderByDesc('updated_at')
                ->first();
        }

        if ($pending) {
            $update = [
                'email' => $maskedEmail,
                'attempt_id' => $attemptId,
                'payload_json' => $payloadJson,
                'claim_token_hash' => $nonce,
                'claim_expires_at' => null,
                'updated_at' => now(),
            ];
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_hash')) {
                $update['email_hash'] = $emailHash;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_enc')) {
                $update['email_enc'] = $emailEnc;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_hash')) {
                $update['to_email_hash'] = $emailHash;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_enc')) {
                $update['to_email_enc'] = $emailEnc;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
                $update['payload_enc'] = $payloadEnc;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_schema_version')) {
                $update['payload_schema_version'] = 'v1';
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'key_version')) {
                $update['key_version'] = $keyVersion;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'locale')) {
                $update['locale'] = $locale;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
                $update['template_key'] = 'payment_success';
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email')) {
                $update['to_email'] = $maskedEmail;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'subject')) {
                $update['subject'] = $subject;
            }
            if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'body_html')) {
                $update['body_html'] = null;
            }
            DB::table('email_outbox')
                ->where('id', $pending->id)
                ->update($update);

            return ['ok' => true];
        }

        $row = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'email' => $maskedEmail,
            'attempt_id' => $attemptId,
            'template' => 'payment_success',
            'payload_json' => $payloadJson,
            'claim_token_hash' => $nonce,
            'claim_expires_at' => null,
            'status' => 'pending',
            'sent_at' => null,
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_hash')) {
            $row['email_hash'] = $emailHash;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_enc')) {
            $row['email_enc'] = $emailEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_hash')) {
            $row['to_email_hash'] = $emailHash;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_enc')) {
            $row['to_email_enc'] = $emailEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
            $row['payload_enc'] = $payloadEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_schema_version')) {
            $row['payload_schema_version'] = 'v1';
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'key_version')) {
            $row['key_version'] = $keyVersion;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'locale')) {
            $row['locale'] = $locale;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
            $row['template_key'] = 'payment_success';
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email')) {
            $row['to_email'] = $maskedEmail;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'subject')) {
            $row['subject'] = $subject;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'body_html')) {
            $row['body_html'] = null;
        }
        DB::table('email_outbox')->insert($row);

        return ['ok' => true];
    }

    /**
     * @return array{ok:bool,queued:bool,error?:string,reason?:string}
     */
    public function queuePreferencesUpdatedConfirmation(EmailSubscriber $subscriber, ?string $preferredLocale = null): array
    {
        return $this->queueSubscriberLifecycleEmail(
            $subscriber,
            'preferences_updated',
            'email_preferences',
            null,
            null,
            $preferredLocale
        );
    }

    /**
     * @return array{ok:bool,queued:bool,error?:string,reason?:string}
     */
    public function queueUnsubscribeConfirmation(EmailSubscriber $subscriber, ?string $preferredLocale = null): array
    {
        return $this->queueSubscriberLifecycleEmail(
            $subscriber,
            'unsubscribe_confirmation',
            'email_unsubscribe',
            null,
            null,
            $preferredLocale
        );
    }

    /**
     * @return array{ok:bool,queued:bool,error?:string,reason?:string}
     */
    public function queueWelcome(EmailSubscriber $subscriber, ?string $preferredLocale = null): array
    {
        return $this->queueSubscriberLifecycleEmail(
            $subscriber,
            'welcome',
            'welcome',
            null,
            null,
            $preferredLocale,
            [
                'attempt_id' => null,
                'order_no' => null,
                'report_url' => null,
                'report_pdf_url' => null,
            ],
            ['pending', 'sent', 'consumed'],
            false
        );
    }

    /**
     * @return array{ok:bool,queued:bool,error?:string,reason?:string}
     */
    public function queuePostPurchaseFollowup(
        EmailSubscriber $subscriber,
        string $attemptId,
        string $orderNo,
        ?string $preferredLocale = null
    ): array {
        return $this->queueSubscriberLifecycleEmail(
            $subscriber,
            'post_purchase_followup',
            'post_purchase_followup',
            $attemptId,
            $orderNo,
            $preferredLocale,
            [],
            ['pending', 'sent', 'consumed']
        );
    }

    /**
     * @return array{ok:bool,queued:bool,error?:string,reason?:string}
     */
    public function queueReportReactivation(
        EmailSubscriber $subscriber,
        string $attemptId,
        string $orderNo,
        ?string $preferredLocale = null
    ): array {
        return $this->queueSubscriberLifecycleEmail(
            $subscriber,
            'report_reactivation',
            'report_reactivation',
            $attemptId,
            $orderNo,
            $preferredLocale,
            [],
            ['pending', 'sent', 'consumed']
        );
    }

    /**
     * @return array{ok:bool,queued:bool,error?:string,reason?:string}
     */
    public function queueOnboarding(
        EmailSubscriber $subscriber,
        string $attemptId,
        string $orderNo,
        ?string $preferredLocale = null
    ): array {
        $attemptId = $this->trimOrNull($attemptId);
        $orderNo = $this->trimOrNull($orderNo);

        if ($attemptId === null || $orderNo === null) {
            return ['ok' => false, 'queued' => false, 'error' => 'INVALID_INPUT'];
        }

        if ($this->hasLifecycleTemplateRowForOrder('report_claim', $orderNo, $attemptId, ['pending', 'sent', 'consumed'])) {
            return ['ok' => true, 'queued' => false, 'reason' => 'existing_order_report_claim'];
        }

        return $this->queueSubscriberLifecycleEmail(
            $subscriber,
            'onboarding',
            'first_report_view_onboarding',
            $attemptId,
            $orderNo,
            $preferredLocale,
            [],
            ['pending', 'sent', 'consumed'],
            true,
            ['report_pdf_url']
        );
    }

    /**
     * @param  array<string,mixed>  $payloadOverrides
     * @param  array<int,string>|null  $dedupeStatuses
     * @param  array<int,string>  $payloadUnsetKeys
     * @return array{ok:bool,queued:bool,error?:string,reason?:string}
     */
    private function queueSubscriberLifecycleEmail(
        EmailSubscriber $subscriber,
        string $templateKey,
        string $surface,
        ?string $attemptId = null,
        ?string $orderNo = null,
        ?string $preferredLocale = null,
        array $payloadOverrides = [],
        ?array $dedupeStatuses = null,
        bool $useLastContextFallback = true,
        array $payloadUnsetKeys = []
    ): array {
        if (! \App\Support\SchemaBaseline::hasTable('email_outbox')) {
            return ['ok' => false, 'queued' => false, 'error' => 'TABLE_MISSING'];
        }

        $subscriber->loadMissing('preference');
        $email = $this->normalizeEmailAddress($this->piiCipher->decrypt((string) ($subscriber->email_enc ?? '')));
        if ($email === null) {
            return ['ok' => false, 'queued' => false, 'error' => 'INVALID_RECIPIENT'];
        }

        $lastContext = $useLastContextFallback && is_array($subscriber->last_context_json)
            ? $subscriber->last_context_json
            : [];
        $attemptId = $this->trimOrNull($attemptId) ?? $this->trimOrNull((string) ($lastContext['attempt_id'] ?? ''));
        $orderNo = $this->trimOrNull($orderNo) ?? $this->trimOrNull((string) ($lastContext['order_no'] ?? ''));
        $emailHash = $this->piiCipher->emailHash($email);

        if ($orderNo !== null && in_array($templateKey, ['post_purchase_followup', 'report_reactivation', 'onboarding'], true)) {
            if ($this->hasLifecycleTemplateRowForOrder($templateKey, $orderNo, $attemptId, $dedupeStatuses ?? ['pending', 'sent', 'consumed'])) {
                return ['ok' => true, 'queued' => false, 'reason' => 'existing_order_template'];
            }
        } elseif ($this->hasLifecycleTemplateRowForEmailHash($emailHash, $templateKey, $dedupeStatuses ?? ['pending'])) {
            return ['ok' => true, 'queued' => false, 'reason' => 'existing_subscriber_template'];
        }

        $locale = $this->normalizeLocale((string) ($preferredLocale ?? ($subscriber->locale ?? '')));
        if ($attemptId !== null && $preferredLocale === null) {
            $locale = $this->resolveRequestedLocale($attemptId, null);
        }

        $subject = $this->defaultSubjectForTemplate($templateKey, $locale);
        $emailEnc = $this->piiCipher->encrypt($email);
        $preferenceSnapshot = $this->preferenceSnapshotForSubscriber($subscriber);
        $lifecycleContext = $this->buildLifecyclePayloadContext($email, $orderNo, [
            'surface' => $surface,
            'locale' => $locale,
        ]);
        $normalizedAttribution = is_array($lifecycleContext['attribution'] ?? null)
            ? $lifecycleContext['attribution']
            : [];

        $payload = array_replace([
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'report_url' => $attemptId !== null ? $this->reportUrl($attemptId) : null,
            'report_pdf_url' => $attemptId !== null ? $this->reportPdfUrl($attemptId) : null,
            'locale' => $locale,
            'template_key' => $templateKey,
            'to_email' => $email,
            'subject' => $subject,
            'subscriber_status' => (string) ($lifecycleContext['subscriber_status'] ?? $subscriber->status ?? EmailSubscriber::STATUS_ACTIVE),
            'marketing_consent' => (bool) ($lifecycleContext['marketing_consent'] ?? $subscriber->marketing_consent ?? false),
            'transactional_recovery_enabled' => (bool) ($lifecycleContext['transactional_recovery_enabled'] ?? $subscriber->transactional_recovery_enabled ?? true),
            'surface' => $surface,
            'preferences' => $preferenceSnapshot,
            'marketing_updates' => $preferenceSnapshot['marketing_updates'],
            'report_recovery' => $preferenceSnapshot['report_recovery'],
            'product_updates' => $preferenceSnapshot['product_updates'],
            'preferences_changed_at' => $subscriber->last_preferences_changed_at?->toIso8601String(),
            'unsubscribed_at' => $subscriber->unsubscribed_at?->toIso8601String(),
            'attribution' => $this->payloadAttribution($normalizedAttribution),
        ], $payloadOverrides);

        foreach ($payloadUnsetKeys as $key) {
            unset($payload[$key]);
        }

        $payloadJson = $this->encodePayloadJson($this->sanitizePayloadForJson($payload));
        $payloadEnc = $this->encodePayloadEncrypted($payload);
        DB::table('email_outbox')->insert($this->buildLifecycleOutboxRow(
            $subscriber->lifecycleOutboxUserId(),
            $templateKey,
            $emailHash,
            $emailEnc,
            $attemptId,
            $locale,
            $subject,
            $payloadJson,
            $payloadEnc
        ));

        return ['ok' => true, 'queued' => true];
    }

    /**
     * @return array{mailer:string,sent:int,blocked:int,failed:int,processed:int}
     */
    public function sendPending(int $limit, string $mailer): array
    {
        $limit = max(1, min($limit, 500));

        $rows = DB::table('email_outbox')
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('claim_expires_at')
                    ->orWhere('claim_expires_at', '>', now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $sent = 0;
        $blocked = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $prepared = $this->preparePendingDelivery($row);
            if (! ($prepared['ok'] ?? false)) {
                $this->markDeliveryFailed(
                    $row,
                    $prepared['payload'] ?? [],
                    (string) ($prepared['locale'] ?? 'en'),
                    (string) ($prepared['template_key'] ?? ''),
                    (string) ($prepared['subject'] ?? ''),
                    (string) ($prepared['body_html'] ?? ''),
                    (string) ($prepared['error_code'] ?? 'invalid_payload'),
                    (string) ($prepared['error_message'] ?? 'Unable to prepare email delivery.'),
                    $mailer
                );
                $failed++;

                continue;
            }

            $payload = $prepared['payload'];
            $locale = (string) $prepared['locale'];
            $templateKey = (string) $prepared['template_key'];
            $subject = (string) $prepared['subject'];
            $bodyHtml = (string) $prepared['body_html'];
            $bodyText = (string) $prepared['body_text'];
            $recipientEmail = (string) $prepared['recipient_email'];
            $guard = is_array($prepared['guard'] ?? null) ? $prepared['guard'] : [];

            if (! ($guard['allowed'] ?? false)) {
                $this->markDeliveryBlocked(
                    $row,
                    $payload,
                    $locale,
                    $templateKey,
                    $subject,
                    $bodyHtml,
                    (string) ($guard['status'] ?? 'suppressed'),
                    (string) ($guard['reason'] ?? 'delivery_blocked'),
                    $mailer
                );
                $blocked++;

                continue;
            }

            try {
                $this->sendMessage($mailer, $recipientEmail, $subject, $bodyHtml, $bodyText);
            } catch (\Throwable $e) {
                $this->markDeliveryFailed(
                    $row,
                    $payload,
                    $locale,
                    $templateKey,
                    $subject,
                    $bodyHtml,
                    'send_failed',
                    $e->getMessage(),
                    $mailer
                );
                $failed++;

                continue;
            }

            $this->markDeliverySent(
                $row,
                $payload,
                $locale,
                $templateKey,
                $recipientEmail,
                $subject,
                $bodyHtml,
                $mailer
            );
            $sent++;
        }

        return [
            'mailer' => $mailer,
            'sent' => $sent,
            'blocked' => $blocked,
            'failed' => $failed,
            'processed' => $sent + $blocked + $failed,
        ];
    }

    /**
     * @return array{
     *     ok:bool,
     *     payload:array<string,mixed>,
     *     locale:string,
     *     template_key:string,
     *     subject:string,
     *     body_html:string,
     *     body_text:string,
     *     recipient_email:string,
     *     guard:array<string,mixed>,
     *     error_code?:string,
     *     error_message?:string
     * }
     */
    private function preparePendingDelivery(object $row): array
    {
        $payload = $this->decodePayloadFromRow($row);
        $locale = $this->normalizeLocale((string) ($row->locale ?? ($payload['locale'] ?? 'en')));
        $templateKey = $this->resolveTemplateKeyFromRow($row, $payload);
        $subject = $this->resolveSubjectFromRow($row, $payload, $templateKey, $locale);
        $recipientEmail = $this->extractRecipientEmailFromOutboxRow($row, $payload);

        if ($recipientEmail === '') {
            return [
                'ok' => false,
                'payload' => $payload,
                'locale' => $locale,
                'template_key' => $templateKey,
                'subject' => $subject,
                'body_html' => '',
                'body_text' => '',
                'recipient_email' => '',
                'guard' => [],
                'error_code' => 'recipient_missing',
                'error_message' => 'Recipient email is missing.',
            ];
        }

        $guard = $this->resolveDeliveryGuard($recipientEmail, $templateKey);
        $bodyHtml = $this->resolveBodyHtmlFromRow($row, $payload, $templateKey, $locale, $recipientEmail, $guard);
        $bodyText = $this->resolveBodyTextFromPayload($payload, $locale, $templateKey, $recipientEmail);

        return [
            'ok' => true,
            'payload' => $payload,
            'locale' => $locale,
            'template_key' => $templateKey,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'recipient_email' => $recipientEmail,
            'guard' => $guard,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveDeliveryGuard(string $recipientEmail, string $templateKey): array
    {
        $guard = $this->emailPreferences->deliveryPolicyForEmail($recipientEmail, $templateKey);

        if ($templateKey !== 'welcome') {
            return $guard;
        }

        $snapshot = $this->emailCaptures->subscriberLifecycleSnapshot($recipientEmail);
        $subscriberStatus = (string) ($snapshot['subscriber_status'] ?? EmailSubscriber::STATUS_ACTIVE);
        $marketingConsent = (bool) ($snapshot['marketing_consent'] ?? false);
        $suppressed = $subscriberStatus === EmailSubscriber::STATUS_SUPPRESSED || (bool) ($guard['suppressed'] ?? false);

        if ($suppressed) {
            return array_replace($guard, [
                'allowed' => false,
                'status' => 'suppressed',
                'reason' => 'email_suppressed',
                'suppressed' => true,
                'marketing_updates' => $marketingConsent,
                'product_updates' => $marketingConsent,
            ]);
        }

        if ($subscriberStatus !== EmailSubscriber::STATUS_ACTIVE) {
            return array_replace($guard, [
                'allowed' => false,
                'status' => 'skipped',
                'reason' => 'subscriber_inactive',
                'suppressed' => false,
                'marketing_updates' => $marketingConsent,
                'product_updates' => $marketingConsent,
            ]);
        }

        if (! $marketingConsent) {
            return array_replace($guard, [
                'allowed' => false,
                'status' => 'skipped',
                'reason' => 'marketing_consent_disabled',
                'suppressed' => false,
                'marketing_updates' => false,
                'product_updates' => false,
            ]);
        }

        return array_replace($guard, [
            'allowed' => true,
            'status' => 'allowed',
            'reason' => null,
            'suppressed' => false,
            'marketing_updates' => true,
            'product_updates' => true,
        ]);
    }

    private function sendMessage(string $mailer, string $recipientEmail, string $subject, string $bodyHtml, string $bodyText): void
    {
        if ($bodyHtml !== '') {
            Mail::mailer($mailer)->html($bodyHtml, function ($message) use ($recipientEmail, $subject): void {
                $message->to($recipientEmail)->subject($subject);
            });

            return;
        }

        Mail::mailer($mailer)->raw($bodyText, function ($message) use ($recipientEmail, $subject): void {
            $message->to($recipientEmail)->subject($subject);
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function markDeliverySent(
        object $row,
        array $payload,
        string $locale,
        string $templateKey,
        string $recipientEmail,
        string $subject,
        string $bodyHtml,
        string $mailer
    ): void {
        $emailHash = $this->piiCipher->emailHash($recipientEmail);
        $sentAt = now();
        $payload = $this->appendExecutionMeta($payload, [
            'status' => 'sent',
            'mailer' => $mailer,
            'guard' => 'allowed',
            'attempted_at' => $sentAt->toIso8601String(),
        ]);

        $update = [
            'status' => 'sent',
            'payload_json' => $this->encodePayloadJson($this->sanitizePayloadForJson($payload)),
            'updated_at' => $sentAt,
        ];

        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
            $update['payload_enc'] = $this->encodePayloadEncrypted($payload);
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'locale')) {
            $update['locale'] = $locale;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
            $update['template_key'] = $templateKey;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email')) {
            $update['to_email'] = $this->maskedLegacyEmail($emailHash);
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'subject')) {
            $update['subject'] = $subject;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'body_html')) {
            $update['body_html'] = $bodyHtml !== '' ? $bodyHtml : null;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'sent_at')) {
            $update['sent_at'] = $sentAt;
        }

        $updated = DB::table('email_outbox')
            ->where('id', $row->id)
            ->where('status', 'pending')
            ->update($update);

        if ($updated > 0) {
            $this->recordLifecycleSubscriberDelivery($templateKey, $recipientEmail, $sentAt);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function markDeliveryBlocked(
        object $row,
        array $payload,
        string $locale,
        string $templateKey,
        string $subject,
        string $bodyHtml,
        string $status,
        string $reason,
        string $mailer
    ): void {
        $payload = $this->appendExecutionMeta($payload, [
            'status' => $status,
            'mailer' => $mailer,
            'guard' => $reason,
            'attempted_at' => now()->toIso8601String(),
        ]);

        $update = [
            'status' => $status,
            'payload_json' => $this->encodePayloadJson($this->sanitizePayloadForJson($payload)),
            'updated_at' => now(),
        ];

        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
            $update['payload_enc'] = $this->encodePayloadEncrypted($payload);
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'locale')) {
            $update['locale'] = $locale;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
            $update['template_key'] = $templateKey;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'subject')) {
            $update['subject'] = $subject;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'body_html')) {
            $update['body_html'] = $bodyHtml !== '' ? $bodyHtml : null;
        }

        DB::table('email_outbox')
            ->where('id', $row->id)
            ->where('status', 'pending')
            ->update($update);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function markDeliveryFailed(
        object $row,
        array $payload,
        string $locale,
        string $templateKey,
        string $subject,
        string $bodyHtml,
        string $errorCode,
        string $errorMessage,
        string $mailer
    ): void {
        $payload = $this->appendExecutionMeta($payload, [
            'status' => 'failed',
            'mailer' => $mailer,
            'guard' => 'send_failed',
            'attempted_at' => now()->toIso8601String(),
            'error_code' => $errorCode,
            'error_message' => $this->truncateExecutionMessage($errorMessage),
        ]);

        $update = [
            'status' => 'failed',
            'payload_json' => $this->encodePayloadJson($this->sanitizePayloadForJson($payload)),
            'updated_at' => now(),
        ];

        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
            $update['payload_enc'] = $this->encodePayloadEncrypted($payload);
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'locale')) {
            $update['locale'] = $locale;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
            $update['template_key'] = $templateKey;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'subject')) {
            $update['subject'] = $subject;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'body_html')) {
            $update['body_html'] = $bodyHtml !== '' ? $bodyHtml : null;
        }

        DB::table('email_outbox')
            ->where('id', $row->id)
            ->where('status', 'pending')
            ->update($update);
    }

    /**
     * Claim report by token.
     *
     * @return array {ok:bool, attempt_id?:string, report_url?:string, status?:int, error?:string, message?:string}
     */
    public function claimReport(string $token): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 128) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'INVALID_TOKEN',
                'message' => 'token invalid.',
            ];
        }

        if (! \App\Support\SchemaBaseline::hasTable('email_outbox')) {
            return [
                'ok' => false,
                'status' => 410,
                'error' => 'TOKEN_GONE',
                'message' => 'claim token expired.',
            ];
        }

        $tokenHash = hash('sha256', $token);
        $row = DB::table('email_outbox')
            ->where('claim_token_hash', $tokenHash)
            ->first();

        if (! $row) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'INVALID_TOKEN',
                'message' => 'claim token not found.',
            ];
        }

        if (! empty($row->claim_expires_at)) {
            try {
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($row->claim_expires_at))) {
                    return [
                        'ok' => false,
                        'status' => 410,
                        'error' => 'TOKEN_EXPIRED',
                        'message' => 'claim token expired.',
                    ];
                }
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'status' => 410,
                    'error' => 'TOKEN_EXPIRED',
                    'message' => 'claim token expired.',
                ];
            }
        }

        $status = strtolower(trim((string) ($row->status ?? 'pending')));
        if ($status !== '' && ! in_array($status, ['pending', 'sent'], true)) {
            return [
                'ok' => false,
                'status' => 410,
                'error' => 'TOKEN_USED',
                'message' => 'claim token already used.',
            ];
        }

        $payload = $this->decodePayloadFromRow($row);
        $attemptId = (string) ($payload['attempt_id'] ?? '');
        if ($attemptId === '') {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'INVALID_PAYLOAD',
                'message' => 'attempt_id missing.',
            ];
        }

        $update = [
            'status' => 'consumed',
            'updated_at' => now(),
        ];
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'consumed_at')) {
            $update['consumed_at'] = now();
        }

        $updated = DB::table('email_outbox')
            ->where('id', $row->id)
            ->whereIn('status', ['pending', 'sent'])
            ->update($update);

        if ($updated < 1) {
            return [
                'ok' => false,
                'status' => 410,
                'error' => 'TOKEN_USED',
                'message' => 'claim token already used.',
            ];
        }

        $reportUrl = $payload['report_url'] ?? "/api/v0.3/attempts/{$attemptId}/report";
        $reportPdfUrl = $payload['report_pdf_url'] ?? $this->reportPdfUrl($attemptId);

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'report_url' => $reportUrl,
            'report_pdf_url' => $reportPdfUrl,
        ];
    }

    /**
     * @return array{email:?string,user_id:?string}
     */
    public function resolvePaymentSuccessRecipient(
        ?string $userId,
        ?string $anonId,
        ?string $attemptId,
        ?string $orderNo = null
    ): array {
        $attemptId = $this->trimOrNull($attemptId);
        if ($attemptId === null) {
            return [
                'email' => null,
                'user_id' => null,
            ];
        }

        $normalizedUserId = $this->trimOrNull($userId);
        $normalizedAnonId = $this->trimOrNull($anonId);
        $resolvedOutboxUserId = $this->resolveOutboxUserId($normalizedUserId, $normalizedAnonId, $attemptId);
        $email = $this->resolveUserEmailById($normalizedUserId);
        if ($email !== '') {
            return [
                'email' => $email,
                'user_id' => $resolvedOutboxUserId,
            ];
        }

        if (! \App\Support\SchemaBaseline::hasTable('email_outbox')
            || ! \App\Support\SchemaBaseline::hasColumn('email_outbox', 'attempt_id')) {
            return [
                'email' => null,
                'user_id' => null,
            ];
        }

        $normalizedOrderNo = $this->trimOrNull($orderNo);
        $rows = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->whereIn('template', ['payment_success', 'report_claim'])
            ->orderByDesc('updated_at')
            ->get();

        foreach ($rows as $row) {
            $payload = null;
            if ((string) ($row->template ?? '') === 'payment_success' && $normalizedOrderNo !== null) {
                $payload = $this->decodePayloadFromRow($row);
                $payloadOrderNo = $this->trimOrNull((string) ($payload['order_no'] ?? ''));
                if ($payloadOrderNo !== null && $payloadOrderNo !== $normalizedOrderNo) {
                    continue;
                }
            }

            $email = $this->extractRecipientEmailFromOutboxRow($row, $payload);
            if ($email === '') {
                continue;
            }

            $rowUserId = $this->trimOrNull((string) ($row->user_id ?? ''));

            return [
                'email' => $email,
                'user_id' => $this->resolveOutboxUserId($rowUserId, $normalizedAnonId, $attemptId),
            ];
        }

        return [
            'email' => null,
            'user_id' => null,
        ];
    }

    public function resolveDeliveryUserId(?string $userId, ?string $anonId, ?string $attemptId): ?string
    {
        $attemptId = $this->trimOrNull($attemptId);
        if ($attemptId === null) {
            return null;
        }

        return $this->resolveOutboxUserId(
            $this->trimOrNull($userId),
            $this->trimOrNull($anonId),
            $attemptId
        );
    }

    public function hasHistoricalRecipient(?string $attemptId, ?string $orderNo, string $email): bool
    {
        $attemptId = $this->trimOrNull($attemptId);
        $email = $this->normalizeEmailAddress($email);
        if ($attemptId === null || $email === null) {
            return false;
        }

        if (! \App\Support\SchemaBaseline::hasTable('email_outbox')
            || ! \App\Support\SchemaBaseline::hasColumn('email_outbox', 'attempt_id')) {
            return false;
        }

        $normalizedOrderNo = $this->trimOrNull($orderNo);
        $emailHash = $this->piiCipher->emailHash($email);
        $query = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->whereIn('template', ['payment_success', 'report_claim'])
            ->orderByDesc('updated_at');

        $hasEmailHash = \App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_hash');
        $hasToEmailHash = \App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_hash');

        if ($hasEmailHash || $hasToEmailHash) {
            $query->where(function ($builder) use ($emailHash, $hasEmailHash, $hasToEmailHash): void {
                if ($hasEmailHash) {
                    $builder->orWhere('email_hash', $emailHash);
                }

                if ($hasToEmailHash) {
                    $builder->orWhere('to_email_hash', $emailHash);
                }
            });
        }

        $rows = $query->get();

        foreach ($rows as $row) {
            if ($normalizedOrderNo !== null) {
                $payload = $this->decodePayloadFromRow($row);
                $payloadOrderNo = $this->trimOrNull((string) ($payload['order_no'] ?? ''));
                if ($payloadOrderNo !== null && $payloadOrderNo !== $normalizedOrderNo) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    public function lastDeliveryEmailSentAt(?string $attemptId, ?string $orderNo = null): ?string
    {
        $attemptId = $this->trimOrNull($attemptId);
        if ($attemptId === null
            || ! \App\Support\SchemaBaseline::hasTable('email_outbox')
            || ! \App\Support\SchemaBaseline::hasColumn('email_outbox', 'attempt_id')
            || ! \App\Support\SchemaBaseline::hasColumn('email_outbox', 'sent_at')) {
            return null;
        }

        $normalizedOrderNo = $this->trimOrNull($orderNo);
        $rows = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->whereIn('template', ['payment_success', 'report_claim'])
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->limit($normalizedOrderNo === null ? 1 : 25)
            ->get();

        foreach ($rows as $row) {
            if ($normalizedOrderNo !== null) {
                $payload = $this->decodePayloadFromRow($row);
                $payloadOrderNo = $this->trimOrNull((string) ($payload['order_no'] ?? ''));
                if ($payloadOrderNo !== null && $payloadOrderNo !== $normalizedOrderNo) {
                    continue;
                }
            }

            $sentAt = $this->trimOrNull((string) ($row->sent_at ?? ''));
            if ($sentAt !== null) {
                try {
                    return \Illuminate\Support\Carbon::parse($sentAt)->toIso8601String();
                } catch (\Throwable) {
                    return $sentAt;
                }
            }
        }

        return null;
    }

    private function decodePayload($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function decodePayloadFromRow(object $row): array
    {
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
            $decrypted = $this->piiCipher->decrypt((string) ($row->payload_enc ?? ''));
            if ($decrypted !== null) {
                $decoded = json_decode($decrypted, true);
                if (is_array($decoded)) {
                    $this->fallbackMonitor->record('email_outbox.payload_read', false);

                    return $decoded;
                }
            }
        }

        $fallbackPayload = $this->decodePayload($row->payload_json ?? null);
        $this->fallbackMonitor->record('email_outbox.payload_read', $fallbackPayload !== []);

        return $fallbackPayload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveTemplateKeyFromRow(object $row, array $payload): string
    {
        foreach ([
            $row->template_key ?? null,
            $row->template ?? null,
            $payload['template_key'] ?? null,
            $payload['template'] ?? null,
        ] as $candidate) {
            $value = strtolower(trim((string) $candidate));
            if ($value !== '') {
                return $value;
            }
        }

        return 'report_claim';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveSubjectFromRow(object $row, array $payload, string $templateKey, string $locale): string
    {
        foreach ([
            $row->subject ?? null,
            $payload['subject'] ?? null,
        ] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return $this->defaultSubjectForTemplate($templateKey, $locale);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $guard
     */
    private function resolveBodyHtmlFromRow(
        object $row,
        array $payload,
        string $templateKey,
        string $locale,
        string $recipientEmail,
        array $guard
    ): string {
        $existing = trim((string) ($row->body_html ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $inline = trim((string) ($payload['body_html'] ?? ''));
        if ($inline !== '') {
            return $inline;
        }

        $view = $this->resolveEmailViewName($templateKey, $locale);
        if ($view === '' || ! View::exists($view)) {
            return '';
        }

        return trim((string) view(
            $view,
            $this->buildTemplateData($row, $payload, $locale, $templateKey, $recipientEmail, $guard)
        )->render());
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveBodyTextFromPayload(array $payload, string $locale, string $templateKey, string $recipientEmail): string
    {
        $body = trim((string) ($payload['body'] ?? ''));
        if ($body !== '') {
            return $body;
        }

        $data = $this->buildTemplateData((object) [], $payload, $locale, $templateKey, $recipientEmail, []);
        $reportUrl = trim((string) ($data['report_url'] ?? ''));
        if ($reportUrl !== '') {
            return $reportUrl;
        }

        $orderLookupUrl = trim((string) ($data['order_lookup_url'] ?? ''));
        if ($orderLookupUrl !== '') {
            return $orderLookupUrl;
        }

        $preferencesUrl = trim((string) ($data['email_preferences_url'] ?? ''));
        if ($preferencesUrl !== '') {
            return $preferencesUrl;
        }

        $unsubscribeUrl = trim((string) ($data['email_unsubscribe_url'] ?? ''));
        if ($unsubscribeUrl !== '') {
            return $unsubscribeUrl;
        }

        return 'Please contact support@fermatmind.com for assistance.';
    }

    private function resolveEmailViewName(string $templateKey, string $locale): string
    {
        $lang = strtolower((string) explode('-', $this->normalizeLocale($locale))[0]) === 'zh'
            ? 'zh'
            : 'en';
        $supported = [
            'payment_success',
            'report_claim',
            'refund_notice',
            'support_contact',
            'preferences_updated',
            'unsubscribe_confirmation',
            'post_purchase_followup',
            'report_reactivation',
            'welcome',
            'onboarding',
        ];
        if (! in_array($templateKey, $supported, true)) {
            return '';
        }

        $view = "emails.{$lang}.{$templateKey}";
        if (View::exists($view)) {
            return $view;
        }

        $fallback = "emails.en.{$templateKey}";

        return View::exists($fallback) ? $fallback : '';
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function buildLifecyclePayloadContext(string $email, ?string $orderNo = null, array $context = []): array
    {
        $subscriberSnapshot = $this->emailCaptures->subscriberLifecycleSnapshot($email);
        $orderContext = $this->resolveOrderLifecycleContext($orderNo);

        $subscriberAttribution = $this->normalizeAttribution(
            is_array($subscriberSnapshot['last_context_json'] ?? null) ? $subscriberSnapshot['last_context_json'] : []
        );
        $orderAttribution = is_array($orderContext['attribution'] ?? null) ? $orderContext['attribution'] : [];
        $contextAttribution = $this->normalizeAttribution(array_replace(
            is_array($context['attribution'] ?? null) ? $context['attribution'] : [],
            $context
        ));

        $surface = $this->firstNonEmptyString([
            $context['surface'] ?? null,
            data_get($orderContext, 'email_capture.surface'),
            data_get($subscriberSnapshot, 'last_context_json.surface'),
        ], 64);

        return [
            'subscriber_status' => $this->firstLifecycleStatus([
                $subscriberSnapshot['subscriber_status'] ?? null,
                data_get($orderContext, 'email_capture.subscriber_status'),
                $context['subscriber_status'] ?? null,
            ]) ?? 'active',
            'marketing_consent' => $this->firstBoolean([
                $context['marketing_consent'] ?? null,
                $subscriberSnapshot['marketing_consent'] ?? null,
                data_get($orderContext, 'email_capture.marketing_consent'),
            ]) ?? false,
            'transactional_recovery_enabled' => $this->firstBoolean([
                $context['transactional_recovery_enabled'] ?? null,
                $subscriberSnapshot['transactional_recovery_enabled'] ?? null,
                data_get($orderContext, 'email_capture.transactional_recovery_enabled'),
            ]) ?? true,
            'surface' => $surface,
            'attribution' => array_replace_recursive($subscriberAttribution, $orderAttribution, $contextAttribution),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $guard
     * @return array<string,mixed>
     */
    private function buildTemplateData(
        object $row,
        array $payload,
        string $locale,
        string $templateKey,
        string $recipientEmail,
        array $guard
    ): array {
        $backendBaseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $frontendBaseUrl = $this->frontendBaseUrl($backendBaseUrl);
        $legalUrls = $this->resolveLegalUrls($locale, $frontendBaseUrl);
        $supportEmail = $this->resolveSupportEmail();
        $normalizedAttribution = $this->normalizeAttribution(is_array($payload['attribution'] ?? null) ? $payload['attribution'] : []);

        $orderNo = trim((string) ($payload['order_no'] ?? $payload['orderNo'] ?? ''));
        $attemptId = trim((string) ($payload['attempt_id'] ?? ''));
        $reportUrl = $this->absoluteUrl((string) ($payload['report_url'] ?? ''), $backendBaseUrl);
        $reportPdfUrl = $this->absoluteUrl((string) ($payload['report_pdf_url'] ?? ''), $backendBaseUrl);

        $tokenContext = [
            'locale' => $locale,
            'surface' => 'email_delivery',
            'order_no' => $orderNo !== '' ? $orderNo : null,
            'attempt_id' => $attemptId !== '' ? $attemptId : null,
            'share_id' => $normalizedAttribution['share_id'] ?? null,
            'compare_invite_id' => $normalizedAttribution['compare_invite_id'] ?? null,
            'share_click_id' => $normalizedAttribution['share_click_id'] ?? null,
            'entrypoint' => $normalizedAttribution['entrypoint'] ?? "email_{$templateKey}",
            'referrer' => $normalizedAttribution['referrer'] ?? null,
            'landing_path' => $normalizedAttribution['landing_path'] ?? null,
            'utm' => $normalizedAttribution['utm'] ?? null,
        ];
        $preferenceToken = $this->emailPreferences->issueTokenForEmail($recipientEmail, $tokenContext);
        $query = $this->deepLinkQuery($normalizedAttribution, $locale, $orderNo, $attemptId);
        $helpPath = strtolower((string) explode('-', $this->normalizeLocale($locale))[0]) === 'zh'
            ? '/zh/help'
            : '/en/help';

        $orderLookupUrl = $this->frontendUrl($frontendBaseUrl, '/orders/lookup', $query);
        $emailPreferencesUrl = $this->frontendUrl($frontendBaseUrl, '/email/preferences', ['token' => $preferenceToken] + $query);
        $emailUnsubscribeUrl = $this->frontendUrl($frontendBaseUrl, '/email/unsubscribe', ['token' => $preferenceToken] + $query);
        $helpUrl = $this->frontendUrl($frontendBaseUrl, $helpPath, $query);
        $preferences = is_array($payload['preferences'] ?? null) ? $payload['preferences'] : [];

        $data = [
            'locale' => $locale,
            'template_key' => $templateKey,
            'orderNo' => $orderNo,
            'order_no' => $orderNo,
            'attemptId' => $attemptId,
            'attempt_id' => $attemptId,
            'productSummary' => trim((string) ($payload['product_summary'] ?? $payload['item_summary'] ?? '')),
            'product_summary' => trim((string) ($payload['product_summary'] ?? $payload['item_summary'] ?? '')),
            'reportUrl' => $reportUrl,
            'report_url' => $reportUrl,
            'reportPdfUrl' => $reportPdfUrl,
            'report_pdf_url' => $reportPdfUrl,
            'orderLookupUrl' => $orderLookupUrl,
            'order_lookup_url' => $orderLookupUrl,
            'emailPreferencesUrl' => $emailPreferencesUrl,
            'email_preferences_url' => $emailPreferencesUrl,
            'emailUnsubscribeUrl' => $emailUnsubscribeUrl,
            'email_unsubscribe_url' => $emailUnsubscribeUrl,
            'helpUrl' => $helpUrl,
            'help_url' => $helpUrl,
            'preferences' => [
                'marketing_updates' => (bool) ($preferences['marketing_updates'] ?? ($payload['marketing_updates'] ?? false)),
                'report_recovery' => (bool) ($preferences['report_recovery'] ?? ($payload['report_recovery'] ?? true)),
                'product_updates' => (bool) ($preferences['product_updates'] ?? ($payload['product_updates'] ?? false)),
            ],
            'marketing_updates' => (bool) ($preferences['marketing_updates'] ?? ($payload['marketing_updates'] ?? false)),
            'report_recovery' => (bool) ($preferences['report_recovery'] ?? ($payload['report_recovery'] ?? true)),
            'product_updates' => (bool) ($preferences['product_updates'] ?? ($payload['product_updates'] ?? false)),
            'preferences_changed_at' => trim((string) ($payload['preferences_changed_at'] ?? '')),
            'unsubscribed_at' => trim((string) ($payload['unsubscribed_at'] ?? '')),
            'refundStatus' => trim((string) ($payload['refund_status'] ?? '')),
            'refund_status' => trim((string) ($payload['refund_status'] ?? '')),
            'refundEta' => trim((string) ($payload['refund_eta'] ?? '')),
            'refund_eta' => trim((string) ($payload['refund_eta'] ?? '')),
            'subscriberStatus' => trim((string) ($payload['subscriber_status'] ?? '')),
            'subscriber_status' => trim((string) ($payload['subscriber_status'] ?? '')),
            'marketingConsent' => (bool) ($payload['marketing_consent'] ?? false),
            'marketing_consent' => (bool) ($payload['marketing_consent'] ?? false),
            'transactionalRecoveryEnabled' => (bool) ($payload['transactional_recovery_enabled'] ?? true),
            'transactional_recovery_enabled' => (bool) ($payload['transactional_recovery_enabled'] ?? true),
            'surface' => trim((string) ($payload['surface'] ?? '')),
            'supportEmail' => $supportEmail,
            'support_email' => $supportEmail,
            'supportTicketUrl' => $this->absoluteUrl((string) ($payload['support_ticket_url'] ?? ''), $frontendBaseUrl),
            'support_ticket_url' => $this->absoluteUrl((string) ($payload['support_ticket_url'] ?? ''), $frontendBaseUrl),
            'privacyUrl' => $legalUrls['privacy'],
            'privacy_url' => $legalUrls['privacy'],
            'termsUrl' => $legalUrls['terms'],
            'terms_url' => $legalUrls['terms'],
            'refundUrl' => $legalUrls['refund'],
            'refund_url' => $legalUrls['refund'],
            'outboxId' => trim((string) ($row->id ?? '')),
            'outbox_id' => trim((string) ($row->id ?? '')),
            'guard' => $guard,
            'attribution' => $normalizedAttribution,
            'share_id' => $normalizedAttribution['share_id'] ?? '',
            'compare_invite_id' => $normalizedAttribution['compare_invite_id'] ?? '',
            'share_click_id' => $normalizedAttribution['share_click_id'] ?? '',
            'entrypoint' => $normalizedAttribution['entrypoint'] ?? '',
            'referrer' => $normalizedAttribution['referrer'] ?? '',
            'landing_path' => $normalizedAttribution['landing_path'] ?? '',
            'utm_source' => (string) data_get($normalizedAttribution, 'utm.source', ''),
            'utm_medium' => (string) data_get($normalizedAttribution, 'utm.medium', ''),
            'utm_campaign' => (string) data_get($normalizedAttribution, 'utm.campaign', ''),
            'utm_term' => (string) data_get($normalizedAttribution, 'utm.term', ''),
            'utm_content' => (string) data_get($normalizedAttribution, 'utm.content', ''),
        ];

        return $data;
    }

    /**
     * @param  array<string,mixed>  $attribution
     * @return array<string,string>
     */
    private function deepLinkQuery(array $attribution, string $locale, string $orderNo, string $attemptId): array
    {
        $query = [];

        if ($locale !== '') {
            $query['locale'] = $locale;
        }
        if ($orderNo !== '') {
            $query['order_no'] = $orderNo;
        }
        if ($attemptId !== '') {
            $query['attempt_id'] = $attemptId;
        }

        foreach (['share_id', 'compare_invite_id', 'share_click_id', 'entrypoint', 'referrer', 'landing_path'] as $field) {
            $value = $this->trimOrNull((string) ($attribution[$field] ?? ''));
            if ($value !== null) {
                $query[$field] = $value;
            }
        }

        if (is_array($attribution['utm'] ?? null)) {
            foreach (['source', 'medium', 'campaign', 'term', 'content'] as $field) {
                $value = $this->trimOrNull((string) (($attribution['utm'][$field] ?? '')));
                if ($value !== null) {
                    $query['utm_'.$field] = $value;
                }
            }
        }

        return $query;
    }

    /**
     * @param  array<string,string>  $query
     */
    private function frontendUrl(string $base, string $path, array $query = []): string
    {
        $url = rtrim($base, '/').'/'.ltrim($path, '/');
        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function frontendBaseUrl(string $backendBaseUrl): string
    {
        $configured = trim((string) (RuntimeConfig::raw('FAP_BASE_URL') ?? ''));

        return $configured !== '' ? rtrim($configured, '/') : $backendBaseUrl;
    }

    /**
     * @return array{terms:string,privacy:string,refund:string}
     */
    private function resolveLegalUrls(string $locale, string $baseUrl): array
    {
        $global = [
            'terms' => trim((string) config('regions.regions.US.legal_urls.terms', $baseUrl.'/terms')),
            'privacy' => trim((string) config('regions.regions.US.legal_urls.privacy', $baseUrl.'/privacy')),
            'refund' => trim((string) config('regions.regions.US.legal_urls.refund', $baseUrl.'/refund')),
        ];
        $cn = [
            'terms' => trim((string) config('regions.regions.CN_MAINLAND.legal_urls.terms', $baseUrl.'/zh/terms')),
            'privacy' => trim((string) config('regions.regions.CN_MAINLAND.legal_urls.privacy', $baseUrl.'/zh/privacy')),
            'refund' => trim((string) config('regions.regions.CN_MAINLAND.legal_urls.refund', $baseUrl.'/zh/refund')),
        ];

        $target = strtolower((string) explode('-', $this->normalizeLocale($locale))[0]) === 'zh' ? $cn : $global;

        return [
            'terms' => $target['terms'] !== '' ? $target['terms'] : $global['terms'],
            'privacy' => $target['privacy'] !== '' ? $target['privacy'] : $global['privacy'],
            'refund' => $target['refund'] !== '' ? $target['refund'] : $global['refund'],
        ];
    }

    private function resolveSupportEmail(): string
    {
        $support = trim((string) config('fap.support_email', ''));
        if ($support !== '') {
            return $support;
        }

        $from = trim((string) config('mail.from.address', ''));
        if ($from !== '') {
            return $from;
        }

        return 'support@fermatmind.com';
    }

    private function absoluteUrl(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return rtrim($base, '/').'/'.ltrim($url, '/');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function appendExecutionMeta(array $payload, array $meta): array
    {
        $payload['delivery_execution'] = $meta;

        return $payload;
    }

    private function truncateExecutionMessage(string $message): string
    {
        $normalized = trim($message);
        if ($normalized === '') {
            return 'Unknown delivery error.';
        }

        return mb_strlen($normalized, 'UTF-8') > 512
            ? mb_substr($normalized, 0, 512, 'UTF-8')
            : $normalized;
    }

    /**
     * Keep payload_json as a minimal non-sensitive fallback while payload_enc remains authoritative.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizePayloadForJson(array $payload): array
    {
        unset(
            $payload['email'],
            $payload['to_email'],
            $payload['claim_token'],
            $payload['claim_url']
        );

        return $payload;
    }

    /**
     * @return array{marketing_updates:bool,report_recovery:bool,product_updates:bool}
     */
    private function preferenceSnapshotForSubscriber(EmailSubscriber $subscriber): array
    {
        $preference = $subscriber->preference instanceof EmailPreference
            ? $subscriber->preference
            : null;

        return [
            'marketing_updates' => $preference instanceof EmailPreference
                ? (bool) $preference->marketing_updates
                : (bool) $subscriber->marketing_consent,
            'report_recovery' => $preference instanceof EmailPreference
                ? (bool) $preference->report_recovery
                : (bool) $subscriber->transactional_recovery_enabled,
            'product_updates' => $preference instanceof EmailPreference
                ? (bool) $preference->product_updates
                : (bool) $subscriber->marketing_consent,
        ];
    }

    private function buildLifecycleOutboxRow(
        string $userId,
        string $templateKey,
        string $emailHash,
        ?string $emailEnc,
        ?string $attemptId,
        string $locale,
        string $subject,
        string $payloadJson,
        ?string $payloadEnc
    ): array {
        $row = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'email' => $this->maskedLegacyEmail($emailHash),
            'template' => $templateKey,
            'payload_json' => $payloadJson,
            'claim_token_hash' => hash('sha256', $templateKey.'|'.$userId.'|'.Str::uuid()->toString()),
            'claim_expires_at' => null,
            'status' => 'pending',
            'sent_at' => null,
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'attempt_id')) {
            $row['attempt_id'] = $attemptId;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_hash')) {
            $row['email_hash'] = $emailHash;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_enc')) {
            $row['email_enc'] = $emailEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_hash')) {
            $row['to_email_hash'] = $emailHash;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_enc')) {
            $row['to_email_enc'] = $emailEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
            $row['payload_enc'] = $payloadEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'payload_schema_version')) {
            $row['payload_schema_version'] = 'v1';
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'key_version')) {
            $row['key_version'] = $this->piiCipher->currentKeyVersion();
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'locale')) {
            $row['locale'] = $locale;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
            $row['template_key'] = $templateKey;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email')) {
            $row['to_email'] = $this->maskedLegacyEmail($emailHash);
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'subject')) {
            $row['subject'] = $subject;
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'body_html')) {
            $row['body_html'] = null;
        }

        return $row;
    }

    private function hasPendingLifecycleTemplateRow(string $emailHash, string $templateKey): bool
    {
        return $this->hasLifecycleTemplateRowForEmailHash($emailHash, $templateKey, ['pending']);
    }

    /**
     * @param  array<int,string>  $statuses
     */
    private function hasLifecycleTemplateRowForEmailHash(string $emailHash, string $templateKey, array $statuses): bool
    {
        if ($statuses === []) {
            return false;
        }

        $query = DB::table('email_outbox')
            ->whereIn('status', $statuses)
            ->where(function ($builder) use ($templateKey): void {
                if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
                    $builder->where('template_key', $templateKey);

                    return;
                }

                $builder->where('template', $templateKey);
            });

        $hasEmailHash = \App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_hash');
        $hasToEmailHash = \App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_hash');
        if (! $hasEmailHash && ! $hasToEmailHash) {
            return false;
        }

        $query->where(function ($builder) use ($emailHash, $hasEmailHash, $hasToEmailHash): void {
            if ($hasEmailHash) {
                $builder->where('email_hash', $emailHash);
            }

            if ($hasToEmailHash) {
                $method = $hasEmailHash ? 'orWhere' : 'where';
                $builder->{$method}('to_email_hash', $emailHash);
            }
        });

        return $query->exists();
    }

    /**
     * @param  array<int,string>  $statuses
     */
    private function hasLifecycleTemplateRowForOrder(string $templateKey, string $orderNo, ?string $attemptId, array $statuses): bool
    {
        $query = DB::table('email_outbox')
            ->whereIn('status', $statuses)
            ->where(function ($builder) use ($templateKey): void {
                if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
                    $builder->where('template_key', $templateKey);

                    return;
                }

                $builder->where('template', $templateKey);
            })
            ->orderByDesc('updated_at');

        if ($attemptId !== null && \App\Support\SchemaBaseline::hasColumn('email_outbox', 'attempt_id')) {
            $query->where('attempt_id', $attemptId);
        }

        foreach ($query->get() as $row) {
            $payload = $this->decodePayloadFromRow($row);
            if ($this->trimOrNull((string) ($payload['order_no'] ?? '')) === $orderNo) {
                return true;
            }
        }

        return false;
    }

    private function recordLifecycleSubscriberDelivery(string $templateKey, string $recipientEmail, \Illuminate\Support\Carbon $sentAt): void
    {
        if (! in_array($templateKey, [
            'preferences_updated',
            'unsubscribe_confirmation',
            'post_purchase_followup',
            'report_reactivation',
            'welcome',
            'onboarding',
        ], true)) {
            return;
        }

        $subscriber = EmailSubscriber::query()
            ->where('email_hash', $this->piiCipher->emailHash($recipientEmail))
            ->first();

        if (! $subscriber) {
            return;
        }

        $subscriber->recordLifecycleSend(
            $templateKey,
            $sentAt,
            EmailLifecycleRolloutService::nextEligibleAtForTemplate($templateKey, $sentAt)
        );
        $subscriber->save();
    }

    private function extractRecipientEmailFromOutboxRow(object $row, ?array $payload = null): string
    {
        $candidates = [];

        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'to_email_enc')) {
            $candidates[] = $this->piiCipher->decrypt((string) ($row->to_email_enc ?? ''));
        }
        if (\App\Support\SchemaBaseline::hasColumn('email_outbox', 'email_enc')) {
            $candidates[] = $this->piiCipher->decrypt((string) ($row->email_enc ?? ''));
        }

        if ($payload === null) {
            $payload = $this->decodePayloadFromRow($row);
        }

        $candidates[] = $payload['to_email'] ?? null;
        $candidates[] = $payload['email'] ?? null;

        foreach ($candidates as $candidate) {
            $email = $this->normalizeEmailAddress($candidate);
            if ($email !== null) {
                return $email;
            }
        }

        return '';
    }

    private function maskedLegacyEmail(string $emailHash): string
    {
        return $this->piiCipher->legacyEmailPlaceholder($emailHash);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encodePayloadJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encodePayloadEncrypted(array $payload): ?string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        return $this->piiCipher->encrypt($encoded);
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim(str_replace('_', '-', $locale));
        if ($locale === '') {
            return 'en';
        }

        $lang = strtolower((string) explode('-', $locale)[0]);

        return $lang === 'zh' ? 'zh-CN' : 'en';
    }

    private function resolveAttemptLocale(string $attemptId): string
    {
        if (! \App\Support\SchemaBaseline::hasTable('attempts')) {
            return 'en';
        }
        if (! \App\Support\SchemaBaseline::hasColumn('attempts', 'locale')) {
            return 'en';
        }

        $locale = trim((string) DB::table('attempts')->where('id', $attemptId)->value('locale'));
        if ($locale === '') {
            return 'en';
        }

        $lang = strtolower((string) explode('-', str_replace('_', '-', $locale))[0]);

        return $lang === 'zh' ? 'zh-CN' : 'en';
    }

    private function resolveRequestedLocale(string $attemptId, ?string $preferredLocale = null): string
    {
        $preferredLocale = $this->trimOrNull($preferredLocale);
        if ($preferredLocale !== null) {
            $lang = strtolower((string) explode('-', str_replace('_', '-', $preferredLocale))[0]);

            return $lang === 'zh' ? 'zh-CN' : 'en';
        }

        return $this->resolveAttemptLocale($attemptId);
    }

    private function defaultSubjectForTemplate(string $templateKey, string $locale): string
    {
        $lang = strtolower((string) explode('-', str_replace('_', '-', $locale))[0]);
        if ($templateKey === 'report_claim') {
            return $lang === 'zh' ? '你的报告链接已准备好' : 'Your report link is ready';
        }
        if ($templateKey === 'payment_success') {
            return $lang === 'zh' ? '支付成功与报告交付通知' : 'Payment successful and report delivered';
        }
        if ($templateKey === 'refund_notice') {
            return $lang === 'zh' ? '退款处理通知' : 'Refund processing notice';
        }
        if ($templateKey === 'support_contact') {
            return $lang === 'zh' ? '客服联系信息' : 'Support contact details';
        }
        if ($templateKey === 'preferences_updated') {
            return $lang === 'zh' ? '邮件偏好已更新' : 'Your email preferences were updated';
        }
        if ($templateKey === 'unsubscribe_confirmation') {
            return $lang === 'zh' ? '你已退订邮件' : 'You have been unsubscribed from emails';
        }
        if ($templateKey === 'post_purchase_followup') {
            return $lang === 'zh' ? '你的报告已准备好查看' : 'Your report is ready to view';
        }
        if ($templateKey === 'report_reactivation') {
            return $lang === 'zh' ? '回来继续查看你的报告' : 'Come back to your report';
        }
        if ($templateKey === 'welcome') {
            return $lang === 'zh' ? '欢迎来到 FermatMind' : 'Welcome to FermatMind';
        }
        if ($templateKey === 'onboarding') {
            return $lang === 'zh' ? '如何更好地使用你的 FermatMind 报告' : 'How to get more from your FermatMind report';
        }

        return $lang === 'zh' ? 'FermatMind 通知' : 'FermatMind notification';
    }

    private function resolveUserEmailById(?string $userId): string
    {
        $userId = $this->trimOrNull($userId);
        if ($userId === null) {
            return '';
        }

        if (! \App\Support\SchemaBaseline::hasTable('users')
            || ! \App\Support\SchemaBaseline::hasColumn('users', 'email')) {
            return '';
        }

        $email = DB::table('users')->where('id', $userId)->value('email');
        $normalized = $this->normalizeEmailAddress($email);

        return $normalized ?? '';
    }

    private function resolveOutboxUserId(?string $userId, ?string $anonId, string $attemptId): ?string
    {
        $userId = $this->trimOrNull($userId);
        if ($userId !== null) {
            return substr($userId, 0, 64);
        }

        $anonId = $this->trimOrNull($anonId);
        if ($anonId !== null) {
            return 'anon_'.substr(hash('sha256', $anonId), 0, 24);
        }

        $attemptId = $this->trimOrNull($attemptId);
        if ($attemptId !== null) {
            return 'attempt_'.substr(hash('sha256', $attemptId), 0, 24);
        }

        return null;
    }

    private function normalizeEmailAddress(mixed $email): ?string
    {
        $email = mb_strtolower(trim((string) $email), 'UTF-8');
        if ($email === '') {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function trimOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function reportUrl(string $attemptId): string
    {
        return "/api/v0.3/attempts/{$attemptId}/report";
    }

    private function reportPdfUrl(string $attemptId): string
    {
        return "/api/v0.3/attempts/{$attemptId}/report.pdf";
    }

    /**
     * @return array{attribution:array<string,mixed>,email_capture:array<string,mixed>}
     */
    private function resolveOrderLifecycleContext(?string $orderNo): array
    {
        $normalizedOrderNo = $this->trimOrNull($orderNo);
        if ($normalizedOrderNo === null || ! \App\Support\SchemaBaseline::hasTable('orders')) {
            return [
                'attribution' => [],
                'email_capture' => [],
            ];
        }

        $order = DB::table('orders')
            ->select(['meta_json', 'contact_email_hash'])
            ->where('order_no', $normalizedOrderNo)
            ->orderByDesc('updated_at')
            ->first();

        if (! $order) {
            return [
                'attribution' => [],
                'email_capture' => [],
            ];
        }

        $meta = $this->decodeMetaValue($order->meta_json ?? null);
        $attribution = $this->normalizeAttribution(is_array($meta['attribution'] ?? null) ? $meta['attribution'] : []);
        $emailCapture = $this->normalizeLifecycleEmailCapture(
            is_array($meta['email_capture'] ?? null) ? $meta['email_capture'] : [],
            $this->trimHash((string) ($order->contact_email_hash ?? ''))
        );

        return [
            'attribution' => $attribution,
            'email_capture' => $emailCapture,
        ];
    }

    /**
     * @param  array<string,mixed>  $emailCapture
     * @return array<string,mixed>
     */
    private function normalizeLifecycleEmailCapture(array $emailCapture, ?string $contactEmailHash = null): array
    {
        $normalized = [];

        $resolvedHash = $contactEmailHash ?? $this->trimHash((string) ($emailCapture['contact_email_hash'] ?? ''));
        if ($resolvedHash !== null) {
            $normalized['contact_email_hash'] = $resolvedHash;
        }

        $status = $this->firstLifecycleStatus([$emailCapture['subscriber_status'] ?? null]);
        if ($status !== null) {
            $normalized['subscriber_status'] = $status;
        }

        foreach (['marketing_consent', 'transactional_recovery_enabled'] as $field) {
            $value = $this->firstBoolean([$emailCapture[$field] ?? null]);
            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        foreach ([
            'captured_at' => 64,
            'surface' => 64,
            'attempt_id' => 64,
        ] as $field => $maxLength) {
            $value = $this->firstNonEmptyString([$emailCapture[$field] ?? null], $maxLength);
            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        return array_replace($normalized, $this->normalizeAttribution($emailCapture));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMetaValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function trimHash(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private function firstBoolean(array $candidates): ?bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $value = filter_var($candidate, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private function firstLifecycleStatus(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = strtolower(trim((string) $candidate));
            if (in_array($value, ['active', 'unsubscribed', 'suppressed'], true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private function firstNonEmptyString(array $candidates, int $maxLength): ?string
    {
        foreach ($candidates as $candidate) {
            $value = $this->trimOrNull(is_scalar($candidate) ? (string) $candidate : null);
            if ($value !== null) {
                return mb_strlen($value, 'UTF-8') > $maxLength
                    ? mb_substr($value, 0, $maxLength, 'UTF-8')
                    : $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $attribution
     * @return array<string,mixed>
     */
    private function normalizeAttribution(array $attribution): array
    {
        $normalized = [];

        foreach ([
            'share_id' => 128,
            'compare_invite_id' => 128,
            'share_click_id' => 128,
            'entrypoint' => 128,
            'referrer' => 2048,
            'landing_path' => 2048,
        ] as $field => $maxLength) {
            $value = $this->trimOrNull(is_scalar($attribution[$field] ?? null) ? (string) $attribution[$field] : null);
            if ($value === null) {
                continue;
            }

            $normalized[$field] = mb_strlen($value, 'UTF-8') > $maxLength
                ? mb_substr($value, 0, $maxLength, 'UTF-8')
                : $value;
        }

        $utm = $attribution['utm'] ?? null;
        if (is_array($utm)) {
            $normalizedUtm = [];
            foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
                $value = $this->trimOrNull(is_scalar($utm[$key] ?? null) ? (string) $utm[$key] : null);
                if ($value !== null) {
                    $normalizedUtm[$key] = mb_strlen($value, 'UTF-8') > 512
                        ? mb_substr($value, 0, 512, 'UTF-8')
                        : $value;
                }
            }

            if ($normalizedUtm !== []) {
                $normalized['utm'] = $normalizedUtm;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $attribution
     */
    private function payloadAttribution(array $attribution): mixed
    {
        return $attribution === [] ? new \stdClass : $attribution;
    }
}
