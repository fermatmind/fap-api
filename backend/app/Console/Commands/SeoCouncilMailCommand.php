<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Platform12\Notification\Platform12MailConfiguration;
use App\Services\SeoCouncil\Platform12\Notification\Platform12MailNotificationTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SeoCouncilMailCommand extends Command
{
    protected $signature = 'seo:council-mail {operation=preflight : preflight or test}';

    protected $description = 'Inspect mail configuration or send one staging-only fixed-recipient test; never resumes Council';

    public function handle(Platform12MailConfiguration $configuration, Platform12MailNotificationTransport $transport): int
    {
        $operation = $this->argument('operation');
        if (! in_array($operation, ['preflight', 'test'], true) || ($operation === 'test' && ! app()->environment('staging'))) {
            return $this->result('MAIL_TEST_ENVIRONMENT_DENIED');
        }
        $preflight = $configuration->preflight();
        if ($operation === 'preflight' || $preflight['state'] !== 'MAIL_CONFIGURATION_READY') {
            return $this->result($preflight['state']);
        }
        $armed = false;
        try {
            $settings = $configuration->settings();
            $path = (string) config('seo_council.release_revision_path');
            $sha = is_readable($path) ? trim((string) file_get_contents($path)) : '';
            $name = (string) config('seo_council.runtime_cache_store');
            if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1
                || config("cache.stores.$name.driver") !== 'redis') {
                return $this->result('MAIL_TEST_STORAGE_OR_RELEASE_HOLD');
            }
            $store = Cache::store($name);
            $id = hash('sha256', 'staging|'.$sha.'|'.hash('sha256', $settings['recipient']));
            $key = 'seo:council:mail-test:'.$id;
            // RedisLock with zero seconds uses SETNX without expiry. This is a
            // permanent send-attempt marker, deliberately never released. A
            // timed mutex alone cannot fence a process suspended past its lease.
            if (! $store->lock($key.':once', 0)->get()) {
                return $this->result($store->get($key) === 'SMTP_ACCEPTED'
                    ? 'SMTP_ALREADY_ACCEPTED_INBOX_UNCONFIRMED' : 'DELIVERY_ACK_UNKNOWN');
            }
            $armed = true;
            if (! $store->forever($key, 'DELIVERY_ACK_UNKNOWN')) {
                return $this->result('MAIL_TEST_STORAGE_OR_RELEASE_HOLD');
            }
            $transport->sendTest($id);
            if (! $store->forever($key, 'SMTP_ACCEPTED')) {
                return $this->result('DELIVERY_ACK_UNKNOWN');
            }

            return $this->result('SMTP_ACCEPTED_INBOX_UNCONFIRMED');
        } catch (Throwable) {
            return $this->result($armed ? 'DELIVERY_ACK_UNKNOWN' : 'MAIL_TEST_STORAGE_OR_RELEASE_HOLD');
        }
    }

    private function result(string $state): int
    {
        $this->line(json_encode(['state' => $state, 'council_resumed' => false,
            'inbox_delivery' => 'NOT_VERIFIED', 'outbox_drained' => false], JSON_THROW_ON_ERROR));

        return in_array($state, ['MAIL_CONFIGURATION_READY', 'SMTP_ACCEPTED_INBOX_UNCONFIRMED', 'SMTP_ALREADY_ACCEPTED_INBOX_UNCONFIRMED'], true)
            ? self::SUCCESS : self::FAILURE;
    }
}
