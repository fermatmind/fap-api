<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailSubscriber;
use App\Support\PiiCipher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EmailLifecycleRolloutService
{
    private const COOLDOWN_MINUTES = 10;

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
     *         unsubscribe_confirmation:array{candidates:int,enqueued:int}
     *     }
     * }
     */
    public function rollout(bool $dryRun = false): array
    {
        if (! Schema::hasTable('email_subscribers') || ! Schema::hasTable('email_outbox')) {
            return [
                'dry_run' => $dryRun,
                'candidates' => 0,
                'enqueued' => 0,
                'templates' => [
                    'preferences_updated' => ['candidates' => 0, 'enqueued' => 0],
                    'unsubscribe_confirmation' => ['candidates' => 0, 'enqueued' => 0],
                ],
            ];
        }

        $summary = [
            'dry_run' => $dryRun,
            'candidates' => 0,
            'enqueued' => 0,
            'templates' => [
                'preferences_updated' => ['candidates' => 0, 'enqueued' => 0],
                'unsubscribe_confirmation' => ['candidates' => 0, 'enqueued' => 0],
            ],
        ];

        $summary = $this->processTemplate(
            $summary,
            'preferences_updated',
            $this->preferencesUpdatedCandidates(),
            $dryRun,
            fn (EmailSubscriber $subscriber): array => $this->emailOutbox->queuePreferencesUpdatedConfirmation($subscriber)
        );

        return $this->processTemplate(
            $summary,
            'unsubscribe_confirmation',
            $this->unsubscribeConfirmationCandidates(),
            $dryRun,
            fn (EmailSubscriber $subscriber): array => $this->emailOutbox->queueUnsubscribeConfirmation($subscriber)
        );
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
    private function processTemplate(array $summary, string $templateKey, Collection $subscribers, bool $dryRun, \Closure $enqueue): array
    {
        foreach ($subscribers as $subscriber) {
            if (! $subscriber instanceof EmailSubscriber) {
                continue;
            }

            $email = $this->decryptSubscriberEmail($subscriber);
            if ($email === null) {
                continue;
            }

            if ($this->hasPendingTemplate($subscriber, $templateKey)) {
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
     * @return Collection<int,EmailSubscriber>
     */
    private function preferencesUpdatedCandidates(): Collection
    {
        $threshold = now()->subMinutes(self::COOLDOWN_MINUTES);

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
        $threshold = now()->subMinutes(self::COOLDOWN_MINUTES);

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

    private function hasPendingTemplate(EmailSubscriber $subscriber, string $templateKey): bool
    {
        $query = \Illuminate\Support\Facades\DB::table('email_outbox')
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

        $query->where(function ($builder) use ($subscriber, $hasEmailHash, $hasToEmailHash): void {
            if ($hasEmailHash) {
                $builder->where('email_hash', (string) $subscriber->email_hash);
            }

            if ($hasToEmailHash) {
                $method = $hasEmailHash ? 'orWhere' : 'where';
                $builder->{$method}('to_email_hash', (string) $subscriber->email_hash);
            }
        });

        return $query->exists();
    }
}
