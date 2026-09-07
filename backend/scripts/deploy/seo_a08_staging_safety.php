<?php

declare(strict_types=1);

use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use App\Services\SeoCouncil\Platform12\Platform12SchedulerStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! app()->environment('staging') || ! function_exists('pcntl_alarm')) {
        throw new RuntimeException('STAGING_IDENTITY_OR_DEADLINE_HOLD');
    }
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): never {
        throw new RuntimeException('STAGING_EXECUTION_DEADLINE_HOLD');
    });
    pcntl_alarm(120);
    $control = app(Platform12RuntimeControl::class);
    $cache = Cache::store(config('seo_council.runtime_cache_store'));
    $original = $cache->get(Platform12RuntimeControl::CACHE_KEY);
    if (! $control->businessGuardsClosed()) {
        throw new RuntimeException('STAGING_BUSINESS_GUARDS_HOLD');
    }
    if (! is_array($original) || ($original['paused'] ?? null) !== true
        || ($original['generation'] ?? null) !== '43ddd93f59c870725de92b6a40dd5715') {
        echo json_encode(['schema_version' => 'seo.a08_staging_safety.v1', 'environment' => 'staging',
            'sha' => trim(file_get_contents(config('seo_council.release_revision_path'))),
            'state' => 'OPERATOR_STATE_CHANGED', 'pause_resume_verified' => false, 'business_write_enabled' => false])."\n";
        exit(0);
    }
    $key = 'a08:isolated:'.bin2hex(random_bytes(12));
    $lock = $cache->lock($key, 30);
    if (! $lock->get()) {
        throw new RuntimeException('STAGING_SHARED_CACHE_HOLD');
    }
    try {
        $other = $cache->lock($key, 30);
        if ($other->get()) {
            $other->release();
            throw new RuntimeException('STAGING_CACHE_CONTENTION_HOLD');
        }
    } finally {
        $lock->release();
    }
    $connection = DB::connection(config('seo_council.connection', 'seo_intel'));
    $store = app(Platform12SchedulerStore::class);
    $owner = bin2hex(random_bytes(24));
    $nextOwner = bin2hex(random_bytes(24));
    $connection->beginTransaction();
    try {
        $first = $store->acquire($key, $owner, 180);
        $contender = $store->acquire($key, $nextOwner, 180);
        if (! $first['acquired'] || $contender['acquired']) {
            throw new RuntimeException('STAGING_LEASE_CONTENTION_HOLD');
        }
        $connection->table('seo_council_scheduler_leases')->where('lease_key', $key)
            ->update(['lease_expires_at' => now('UTC')->subSecond()]);
        $next = $store->acquire($key, $nextOwner, 180);
        if (! $next['acquired'] || $next['fencing_token'] <= $first['fencing_token']) {
            throw new RuntimeException('STAGING_FENCE_TAKEOVER_HOLD');
        }
        $store->release($key, $owner, $first['fencing_token']);
        $live = $connection->table('seo_council_scheduler_leases')->where('lease_key', $key)->first();
        if ($live === null || $live->fencing_token != $next['fencing_token']
            || strtotime($live->lease_expires_at) <= time()) {
            throw new RuntimeException('STAGING_OLD_OWNER_HOLD');
        }
    } finally {
        $connection->rollBack();
    }
    if ($connection->table('seo_council_scheduler_leases')->where('lease_key', $key)->exists()) {
        throw new RuntimeException('STAGING_TRANSACTION_ROLLBACK_HOLD');
    }
    $changed = null;
    try {
        $active = $control->change(false, [Platform12DailyMissionSet::IDS[0]], $original['generation']);
        $changed = $active['generation'] ?? null;
        if (($active['state'] ?? null) !== 'ACTIVE_READ_ONLY' || $active['effective_mission_ids'] !== []) {
            throw new RuntimeException('STAGING_CONTROLLED_GATE_HOLD');
        }
        $paused = $control->change(true, [], $changed);
        $changed = $paused['generation'] ?? null;
        if (($paused['state'] ?? null) !== 'PAUSED') {
            throw new RuntimeException('STAGING_PAUSE_HOLD');
        }
    } finally {
        if ($changed !== null) {
            $control->withControlLock(function () use ($cache, $changed, $original): void {
                $current = $cache->get(Platform12RuntimeControl::CACHE_KEY);
                if (($current['generation'] ?? null) !== $changed) {
                    throw new RuntimeException('STAGING_NEW_OPERATOR_ACTION_PRESERVED');
                }
                $cache->forever(Platform12RuntimeControl::CACHE_KEY, $original);
            });
        }
    }
    if ($cache->get(Platform12RuntimeControl::CACHE_KEY) !== $original) {
        throw new RuntimeException('STAGING_RESTORE_HOLD');
    }
    echo json_encode(['schema_version' => 'seo.a08_staging_safety.v1', 'environment' => 'staging',
        'sha' => trim(file_get_contents(config('seo_council.release_revision_path'))),
        'pause_resume_verified' => true, 'shared_cache_contention_verified' => true,
        'transaction_rollback_verified' => true, 'fencing_verified' => true,
        'mail_sent' => false, 'business_write_enabled' => false], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    $reason = preg_match('/^(?:A08|STAGING|M1|NEW_OPERATOR|END_TO_END|CONTROLLED)_[A-Z_]{1,80}$/D', $error->getMessage()) === 1
        ? $error->getMessage() : 'A08_STAGING_SAFETY_HOLD';
    fwrite(STDERR, $reason."\n");
    exit(1);
}
