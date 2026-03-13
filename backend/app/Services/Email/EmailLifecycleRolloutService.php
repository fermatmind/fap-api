<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailSubscriber;
use App\Support\PiiCipher;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailLifecycleRolloutService
{
    public const CONFIRMATION_COOLDOWN_MINUTES = 10;

    public const POST_PURCHASE_FOLLOWUP_COOLDOWN_DAYS = 7;

    public const REPORT_REACTIVATION_COOLDOWN_DAYS = 14;

    /**
     * @var array<string,EmailSubscriber|null>
     */
    private array $subscriberCache = [];

    /**
     * @var array<string,bool>
     */
    private array $suppressedHashCache = [];

    private ?Carbon $now = null;

    public function __construct(
        private readonly EmailOutboxService $emailOutbox,
        private readonly PiiCipher $piiCipher,
    ) {}

    /**
     * @return array{
     *     dry_run:bool,
     *     candidates:int,
     *     enqueued:int,
     *     templates:array{
     *         preferences_updated:array{candidates:int,enqueued:int},
     *         unsubscribe_confirmation:array{candidates:int,enqueued:int},
     *         post_purchase_followup:array{candidates:int,enqueued:int},
     *         report_reactivation:array{candidates:int,enqueued:int}
     *     }
     * }
     */
    public function rollout(bool $dryRun = false): array
    {
        $this->now = now();
        $this->subscriberCache = [];
        $this->suppressedHashCache = [];

        if (! Schema::hasTable('email_subscribers') || ! Schema::hasTable('email_outbox')) {
            return [
                'dry_run' => $dryRun,
                'candidates' => 0,
                'enqueued' => 0,
                'templates' => $this->emptyTemplateSummary(),
            ];
        }

        $summary = [
            'dry_run' => $dryRun,
            'candidates' => 0,
            'enqueued' => 0,
            'templates' => $this->emptyTemplateSummary(),
        ];

        $summary = $this->processSubscriberTemplate(
            $summary,
            'preferences_updated',
            $this->preferencesUpdatedCandidates(),
            $dryRun,
            fn (EmailSubscriber $subscriber): array => $this->emailOutbox->queuePreferencesUpdatedConfirmation($subscriber)
        );

        $summary = $this->processSubscriberTemplate(
            $summary,
            'unsubscribe_confirmation',
            $this->unsubscribeConfirmationCandidates(),
            $dryRun,
            fn (EmailSubscriber $subscriber): array => $this->emailOutbox->queueUnsubscribeConfirmation($subscriber)
        );

        $summary = $this->processOrderTemplate(
            $summary,
            'post_purchase_followup',
            $this->postPurchaseFollowupCandidates(),
            $dryRun,
            fn (object $order, EmailSubscriber $subscriber): array => $this->emailOutbox->queuePostPurchaseFollowup(
                $subscriber,
                (string) $order->target_attempt_id,
                (string) $order->order_no
            )
        );

        return $this->processOrderTemplate(
            $summary,
            'report_reactivation',
            $this->reportReactivationCandidates(),
            $dryRun,
            fn (object $order, EmailSubscriber $subscriber): array => $this->emailOutbox->queueReportReactivation(
                $subscriber,
                (string) $order->target_attempt_id,
                (string) $order->order_no
            )
        );
    }

    public static function nextEligibleAtForTemplate(string $templateKey, CarbonInterface $sentAt): Carbon
    {
        return Carbon::parse($sentAt)->add(self::cooldownIntervalForTemplate($templateKey));
    }

    /**
     * @return array{
     *     preferences_updated:array{candidates:int,enqueued:int},
     *     unsubscribe_confirmation:array{candidates:int,enqueued:int},
     *     post_purchase_followup:array{candidates:int,enqueued:int},
     *     report_reactivation:array{candidates:int,enqueued:int}
     * }
     */
    private function emptyTemplateSummary(): array
    {
        return [
            'preferences_updated' => ['candidates' => 0, 'enqueued' => 0],
            'unsubscribe_confirmation' => ['candidates' => 0, 'enqueued' => 0],
            'post_purchase_followup' => ['candidates' => 0, 'enqueued' => 0],
            'report_reactivation' => ['candidates' => 0, 'enqueued' => 0],
        ];
    }

    /**
     * @param  array{
     *     dry_run:bool,
     *     candidates:int,
     *     enqueued:int,
     *     templates:array<string,array{candidates:int,enqueued:int}>
     * }  $summary
     * @param  \Closure(EmailSubscriber):array{ok:bool,queued:bool,error?:string,reason?:string}  $enqueue
     * @return array{
     *     dry_run:bool,
     *     candidates:int,
     *     enqueued:int,
     *     templates:array<string,array{candidates:int,enqueued:int}>
     * }
     */
    private function processSubscriberTemplate(array $summary, string $templateKey, Collection $subscribers, bool $dryRun, \Closure $enqueue): array
    {
        foreach ($subscribers as $subscriber) {
            if (! $subscriber instanceof EmailSubscriber) {
                continue;
            }

            if ($this->decryptSubscriberEmail($subscriber) === null) {
                continue;
            }

            if ($this->hasPendingTemplateForEmailHash((string) $subscriber->email_hash, $templateKey)) {
                continue;
            }

            $summary['candidates']++;
            $summary['templates'][$templateKey]['candidates']++;

            if ($dryRun) {
                continue;
            }

            $result = $enqueue($subscriber);
            if (($result['ok'] ?? false) && ($result['queued'] ?? false)) {
                $summary['enqueued']++;
                $summary['templates'][$templateKey]['enqueued']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array{
     *     dry_run:bool,
     *     candidates:int,
     *     enqueued:int,
     *     templates:array<string,array{candidates:int,enqueued:int}>
     * }  $summary
     * @param  Collection<int,array{order:object,subscriber:EmailSubscriber}>  $candidates
     * @param  \Closure(object,EmailSubscriber):array{ok:bool,queued:bool,error?:string,reason?:string}  $enqueue
     * @return array{
     *     dry_run:bool,
     *     candidates:int,
     *     enqueued:int,
     *     templates:array<string,array{candidates:int,enqueued:int}>
     * }
     */
    private function processOrderTemplate(array $summary, string $templateKey, Collection $candidates, bool $dryRun, \Closure $enqueue): array
    {
        foreach ($candidates as $candidate) {
            $order = $candidate['order'] ?? null;
            $subscriber = $candidate['subscriber'] ?? null;

            if (! is_object($order) || ! $subscriber instanceof EmailSubscriber) {
                continue;
            }

            $summary['candidates']++;
            $summary['templates'][$templateKey]['candidates']++;

            if ($dryRun) {
                continue;
            }

            $result = $enqueue($order, $subscriber);
            if (($result['ok'] ?? false) && ($result['queued'] ?? false)) {
                $summary['enqueued']++;
                $summary['templates'][$templateKey]['enqueued']++;
            }
        }

        return $summary;
    }

    /**
     * @return Collection<int,EmailSubscriber>
     */
    private function preferencesUpdatedCandidates(): Collection
    {
        $threshold = $this->cooldownThresholdForTemplate('preferences_updated');

        $query = EmailSubscriber::query()
            ->with('preference')
            ->outsideLifecycleCooldown($threshold)
            ->where('status', '!=', EmailSubscriber::STATUS_UNSUBSCRIBED)
            ->whereNotNull('last_preferences_changed_at')
            ->where(function ($builder): void {
                $builder->whereNull('last_preferences_confirmation_sent_at')
                    ->orWhereColumn('last_preferences_confirmation_sent_at', '<', 'last_preferences_changed_at');
            })
            ->orderBy('last_preferences_changed_at');

        return $this->excludeSuppressed($query)->get();
    }

    /**
     * @return Collection<int,EmailSubscriber>
     */
    private function unsubscribeConfirmationCandidates(): Collection
    {
        $threshold = $this->cooldownThresholdForTemplate('unsubscribe_confirmation');

        $query = EmailSubscriber::query()
            ->with('preference')
            ->outsideLifecycleCooldown($threshold)
            ->where('status', EmailSubscriber::STATUS_UNSUBSCRIBED)
            ->whereNotNull('unsubscribed_at')
            ->where(function ($builder): void {
                $builder->whereNull('last_unsubscribe_confirmation_sent_at')
                    ->orWhereColumn('last_unsubscribe_confirmation_sent_at', '<', 'unsubscribed_at');
            })
            ->orderBy('unsubscribed_at');

        return $this->excludeSuppressed($query)->get();
    }

    /**
     * @return Collection<int,array{order:object,subscriber:EmailSubscriber}>
     */
    private function postPurchaseFollowupCandidates(): Collection
    {
        if (! $this->canEvaluateOrderFollowups()) {
            return collect();
        }

        return DB::table('orders')
            ->select([
                'id',
                'order_no',
                'status',
                'target_attempt_id',
                'contact_email_hash',
                'paid_at',
                'fulfilled_at',
            ])
            ->whereIn('status', ['paid', 'fulfilled'])
            ->whereNotNull('target_attempt_id')
            ->whereNotNull('contact_email_hash')
            ->where(function ($builder): void {
                $builder->whereNotNull('fulfilled_at')
                    ->orWhereNotNull('paid_at');
            })
            ->orderBy('fulfilled_at')
            ->orderBy('paid_at')
            ->get()
            ->map(function (object $order): ?array {
                $attemptId = trim((string) ($order->target_attempt_id ?? ''));
                $orderNo = trim((string) ($order->order_no ?? ''));
                $businessAt = $this->businessTimeForPostPurchase($order);

                if ($attemptId === '' || $orderNo === '' || ! $businessAt instanceof Carbon) {
                    return null;
                }

                if ($businessAt->gt($this->now()->copy()->subDay())) {
                    return null;
                }

                $subscriber = $this->subscriberForEmailHash((string) ($order->contact_email_hash ?? ''));
                if (! $this->subscriberAllowedForRecoveryFollowup($subscriber, 'post_purchase_followup')) {
                    return null;
                }

                if ($this->attemptHasEvent($attemptId, ['report_view', 'report_pdf_view'])) {
                    return null;
                }

                if ($this->hasOutboxRowForOrder($orderNo, $attemptId, ['report_claim'])) {
                    return null;
                }

                if ($this->paymentSuccessSentCountForOrder($orderNo, $attemptId) !== 1) {
                    return null;
                }

                if ($this->hasOutboxRowForOrder($orderNo, $attemptId, ['post_purchase_followup'], ['pending', 'sent', 'consumed'])) {
                    return null;
                }

                return [
                    'order' => $order,
                    'subscriber' => $subscriber,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int,array{order:object,subscriber:EmailSubscriber}>
     */
    private function reportReactivationCandidates(): Collection
    {
        if (! $this->canEvaluateOrderFollowups()) {
            return collect();
        }

        return DB::table('orders')
            ->select([
                'id',
                'order_no',
                'status',
                'target_attempt_id',
                'contact_email_hash',
            ])
            ->whereIn('status', ['paid', 'fulfilled'])
            ->whereNotNull('target_attempt_id')
            ->whereNotNull('contact_email_hash')
            ->orderBy('updated_at')
            ->get()
            ->map(function (object $order): ?array {
                $attemptId = trim((string) ($order->target_attempt_id ?? ''));
                $orderNo = trim((string) ($order->order_no ?? ''));

                if ($attemptId === '' || $orderNo === '') {
                    return null;
                }

                $subscriber = $this->subscriberForEmailHash((string) ($order->contact_email_hash ?? ''));
                if (! $this->subscriberAllowedForRecoveryFollowup($subscriber, 'report_reactivation')) {
                    return null;
                }

                $lastReportViewAt = $this->latestEventAt($attemptId, 'report_view');
                if (! $lastReportViewAt instanceof Carbon) {
                    return null;
                }

                if ($lastReportViewAt->gt($this->now()->copy()->subDays(self::REPORT_REACTIVATION_COOLDOWN_DAYS))) {
                    return null;
                }

                if ($this->hasReportPdfViewAfter($attemptId, $lastReportViewAt)) {
                    return null;
                }

                if ($this->hasOutboxRowForOrder($orderNo, $attemptId, ['report_claim'])) {
                    return null;
                }

                if ($this->hasOutboxRowForOrder($orderNo, $attemptId, ['report_reactivation'], ['pending', 'sent', 'consumed'])) {
                    return null;
                }

                return [
                    'order' => $order,
                    'subscriber' => $subscriber,
                ];
            })
            ->filter()
            ->values();
    }

    private function canEvaluateOrderFollowups(): bool
    {
        return Schema::hasTable('orders')
            && Schema::hasTable('events')
            && Schema::hasTable('email_outbox');
    }

    private function subscriberForEmailHash(string $emailHash): ?EmailSubscriber
    {
        $normalizedHash = strtolower(trim($emailHash));
        if ($normalizedHash === '') {
            return null;
        }

        if (array_key_exists($normalizedHash, $this->subscriberCache)) {
            return $this->subscriberCache[$normalizedHash];
        }

        return $this->subscriberCache[$normalizedHash] = EmailSubscriber::query()
            ->with('preference')
            ->where('email_hash', $normalizedHash)
            ->first();
    }

    private function subscriberAllowedForRecoveryFollowup(?EmailSubscriber $subscriber, string $templateKey): bool
    {
        if (! $subscriber instanceof EmailSubscriber) {
            return false;
        }

        if ((string) ($subscriber->status ?? '') !== EmailSubscriber::STATUS_ACTIVE) {
            return false;
        }

        if (! $subscriber->allowsTransactionalRecovery()) {
            return false;
        }

        if ($this->isSuppressedHash((string) $subscriber->email_hash)) {
            return false;
        }

        if (! $this->subscriberOutsideLifecycleCooldown($subscriber, $templateKey)) {
            return false;
        }

        return $this->decryptSubscriberEmail($subscriber) !== null;
    }

    private function subscriberOutsideLifecycleCooldown(EmailSubscriber $subscriber, string $templateKey): bool
    {
        if (Schema::hasColumn('email_subscribers', 'next_lifecycle_eligible_at')) {
            $nextEligibleAt = $subscriber->getAttribute('next_lifecycle_eligible_at');
            if ($nextEligibleAt instanceof CarbonInterface) {
                return $nextEligibleAt->lessThanOrEqualTo($this->now());
            }

            if (is_string($nextEligibleAt) && trim($nextEligibleAt) !== '') {
                try {
                    return Carbon::parse($nextEligibleAt)->lessThanOrEqualTo($this->now());
                } catch (\Throwable) {
                    // Fall through to legacy last sent timestamp.
                }
            }
        }

        $lastSentAt = $subscriber->last_lifecycle_email_sent_at;
        if (! $lastSentAt instanceof CarbonInterface) {
            return true;
        }

        return $lastSentAt->lessThanOrEqualTo($this->cooldownThresholdForTemplate($templateKey));
    }

    private function businessTimeForPostPurchase(object $order): ?Carbon
    {
        foreach ([$order->fulfilled_at ?? null, $order->paid_at ?? null] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return Carbon::parse($candidate);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $eventCodes
     */
    private function attemptHasEvent(string $attemptId, array $eventCodes): bool
    {
        return DB::table('events')
            ->where('attempt_id', $attemptId)
            ->whereIn('event_code', $eventCodes)
            ->exists();
    }

    private function latestEventAt(string $attemptId, string $eventCode): ?Carbon
    {
        $value = DB::table('events')
            ->where('attempt_id', $attemptId)
            ->where('event_code', $eventCode)
            ->max('occurred_at');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasReportPdfViewAfter(string $attemptId, CarbonInterface $anchor): bool
    {
        return DB::table('events')
            ->where('attempt_id', $attemptId)
            ->where('event_code', 'report_pdf_view')
            ->where('occurred_at', '>', $anchor->toDateTimeString())
            ->exists();
    }

    /**
     * @param  array<int,string>  $templateKeys
     * @param  array<int,string>|null  $statuses
     */
    private function hasOutboxRowForOrder(string $orderNo, string $attemptId, array $templateKeys, ?array $statuses = null): bool
    {
        return $this->matchingOutboxRowsForOrder($orderNo, $attemptId, $templateKeys, $statuses)->isNotEmpty();
    }

    private function paymentSuccessSentCountForOrder(string $orderNo, string $attemptId): int
    {
        return $this->matchingOutboxRowsForOrder($orderNo, $attemptId, ['payment_success'], ['sent'])->count();
    }

    /**
     * @param  array<int,string>  $templateKeys
     * @param  array<int,string>|null  $statuses
     * @return Collection<int,object>
     */
    private function matchingOutboxRowsForOrder(string $orderNo, string $attemptId, array $templateKeys, ?array $statuses = null): Collection
    {
        $query = DB::table('email_outbox')
            ->whereIn('template', $templateKeys)
            ->orderByDesc('updated_at');

        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }

        if (Schema::hasColumn('email_outbox', 'attempt_id')) {
            $query->where('attempt_id', $attemptId);
        }

        return $query->get()->filter(function (object $row) use ($orderNo): bool {
            $payload = $this->decodePayload((string) ($row->payload_json ?? ''));

            return trim((string) ($payload['order_no'] ?? '')) === $orderNo;
        })->values();
    }

    private function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isSuppressedHash(string $emailHash): bool
    {
        $normalizedHash = strtolower(trim($emailHash));
        if ($normalizedHash === '' || ! Schema::hasTable('email_suppressions')) {
            return false;
        }

        if (array_key_exists($normalizedHash, $this->suppressedHashCache)) {
            return $this->suppressedHashCache[$normalizedHash];
        }

        return $this->suppressedHashCache[$normalizedHash] = DB::table('email_suppressions')
            ->where('email_hash', $normalizedHash)
            ->exists();
    }

    private function excludeSuppressed($query)
    {
        if (! Schema::hasTable('email_suppressions')) {
            return $query;
        }

        return $query->whereNotIn('email_hash', function ($builder): void {
            $builder->select('email_hash')->from('email_suppressions');
        });
    }

    private function decryptSubscriberEmail(EmailSubscriber $subscriber): ?string
    {
        $email = $this->piiCipher->decrypt((string) ($subscriber->email_enc ?? ''));

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? mb_strtolower(trim($email), 'UTF-8')
            : null;
    }

    private function hasPendingTemplateForEmailHash(string $emailHash, string $templateKey): bool
    {
        $normalizedHash = strtolower(trim($emailHash));
        if ($normalizedHash === '') {
            return false;
        }

        $query = DB::table('email_outbox')
            ->where('status', 'pending')
            ->where(function ($builder) use ($templateKey): void {
                if (Schema::hasColumn('email_outbox', 'template_key')) {
                    $builder->where('template_key', $templateKey);

                    return;
                }

                $builder->where('template', $templateKey);
            });

        $hasEmailHash = Schema::hasColumn('email_outbox', 'email_hash');
        $hasToEmailHash = Schema::hasColumn('email_outbox', 'to_email_hash');
        if (! $hasEmailHash && ! $hasToEmailHash) {
            return false;
        }

        $query->where(function ($builder) use ($normalizedHash, $hasEmailHash, $hasToEmailHash): void {
            if ($hasEmailHash) {
                $builder->where('email_hash', $normalizedHash);
            }

            if ($hasToEmailHash) {
                $method = $hasEmailHash ? 'orWhere' : 'where';
                $builder->{$method}('to_email_hash', $normalizedHash);
            }
        });

        return $query->exists();
    }

    private function cooldownThresholdForTemplate(string $templateKey): Carbon
    {
        return $this->now()->copy()->sub(self::cooldownIntervalForTemplate($templateKey));
    }

    private static function cooldownIntervalForTemplate(string $templateKey): CarbonInterval
    {
        return match (trim($templateKey)) {
            'post_purchase_followup' => CarbonInterval::days(self::POST_PURCHASE_FOLLOWUP_COOLDOWN_DAYS),
            'report_reactivation' => CarbonInterval::days(self::REPORT_REACTIVATION_COOLDOWN_DAYS),
            default => CarbonInterval::minutes(self::CONFIRMATION_COOLDOWN_MINUTES),
        };
    }

    private function now(): Carbon
    {
        return $this->now instanceof Carbon ? $this->now : now();
    }
}
