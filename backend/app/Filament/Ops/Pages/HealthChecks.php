<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthChecks extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'SRE';

    protected static ?string $navigationLabel = 'Health Checks';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'health-checks';

    protected static string $view = 'filament.ops.pages.health-checks';

    /** @var array<string,mixed> */
    public array $checks = [];

    public function mount(): void
    {
        $this->refreshChecks();
    }

    public function refreshChecks(): void
    {
        $this->checks = [
            'db' => $this->checkDb(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];
    }

    /** @return array<string,mixed> */
    private function checkDb(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true, 'message' => 'ok'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function checkRedis(): array
    {
        try {
            $driver = (string) config('cache.default', 'file');
            $queueDriver = (string) config('queue.default', 'sync');
            if ($driver !== 'redis' && $queueDriver !== 'redis') {
                return ['ok' => true, 'message' => 'not required'];
            }

            $pong = Redis::connection()->ping();

            return ['ok' => true, 'message' => (string) $pong];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function checkQueue(): array
    {
        try {
            $driver = (string) config('queue.default', 'sync');
            $failed = \App\Support\SchemaBaseline::hasTable('failed_jobs')
                ? (int) DB::table('failed_jobs')->count()
                : 0;

            return ['ok' => true, 'message' => 'driver='.$driver.', failed_jobs='.$failed];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
