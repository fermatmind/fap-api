<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\PublicProjectionMigration;
use App\Services\Ops\CacheLifecycleAlerts;
use Illuminate\Console\Command;

final class ManagePublicProjectionCache extends Command
{
    protected $signature = 'cache:public-projection {operation : config, prepare, activate, rollback, accept, retire or status} {--sha=}';

    protected $description = 'Manage only the isolated public projection Redis through the existing deployment lane.';

    public function handle(PublicProjectionMigration $migration): int
    {
        try {
            $operation = (string) $this->argument('operation');
            $result = match ($operation) {
                'config' => $this->configuration(),
                'prepare' => $this->prepare($migration),
                'activate' => $migration->activate(),
                'rollback' => $migration->synchronize(true),
                'accept' => $this->accept($migration),
                'verify-mail' => $this->verifyMail(),
                'retire' => $migration->retire(),
                'status' => ['status' => \App\Support\PublicProjectionCache::state()['mode']],
                default => throw new \InvalidArgumentException('Invalid public projection operation.'),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            if ($error->getCode() !== PublicProjectionMigration::INTEGRITY_FAILURE) {
                app(CacheLifecycleAlerts::class)->observe('redis_capacity', false, true);
            }
            $this->error('Public projection operation failed; automatic read switch was not accepted.');

            return self::FAILURE;
        }
    }

    private function prepare(PublicProjectionMigration $migration): array
    {
        $migration->prepare();

        return ['status' => 'prepared'];
    }

    private function accept(PublicProjectionMigration $migration): array
    {
        $migration->accept((string) $this->option('sha'));

        return ['status' => 'accepted'];
    }

    private function verifyMail(): array
    {
        if (app()->environment('production')) {
            app(CacheLifecycleAlerts::class)->verifyDelivery();
        }

        return ['status' => 'mail_verified_or_nonproduction'];
    }

    private function configuration(): array
    {
        PublicProjectionMigration::headroom();
        $password = config('database.redis.public_projection.password') ?? '';
        if (! is_string($password) || ($password === '' && ! app()->environment(['staging', 'testing'])) || preg_match('/[\r\n\x00]/', $password)) {
            throw new \RuntimeException('Existing Redis credential unavailable.');
        }
        $text = "bind 127.0.0.1\nprotected-mode yes\nport 6380\ndaemonize no\nsupervised no\n"
            ."dir /var/lib/redis-public-projection\ndbfilename dump.rdb\nsave \"\"\n"
            ."appendonly yes\nappendfsync everysec\nauto-aof-rewrite-percentage 100\nauto-aof-rewrite-min-size 64mb\n"
            ."maxmemory 2147483648\nmaxmemory-policy noeviction\nlogfile \"\"\n"
            .'requirepass '.json_encode($password, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $path = storage_path('app/private/public-projection-redis.conf');
        $old = umask(0077);
        try {
            if (file_put_contents($path, $text, LOCK_EX) !== strlen($text)) {
                throw new \RuntimeException('Public Redis configuration write failed.');
            }
            chmod($path, 0600);
        } finally {
            umask($old);
        }

        return ['status' => 'configuration_ready'];
    }
}
