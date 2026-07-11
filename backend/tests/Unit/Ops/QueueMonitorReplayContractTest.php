<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use App\Filament\Ops\Pages\QueueMonitor;
use App\Services\Audit\AuditLogger;
use App\Services\Queue\QueueDlqService;
use App\Support\OrgContext;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

final class QueueMonitorReplayContractTest extends TestCase
{
    public function test_queue_monitor_routes_replay_through_dlq_service_once(): void
    {
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('user')->once()->andReturn(new class
        {
            public function getAuthIdentifier(): int
            {
                return 73;
            }
        });
        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $orgContext = new OrgContext;
        $orgContext->set(17, 73, 'admin');
        app()->instance(OrgContext::class, $orgContext);

        $dlq = Mockery::mock(QueueDlqService::class);
        $dlq->shouldReceive('replayFailedJob')
            ->once()
            ->with(42, 'admin:73')
            ->andReturn([
                'ok' => true,
                'status' => 'replayed',
                'failed_job_id' => 42,
                'replayed_job_id' => 'replayed-42',
                'replay_log_id' => 91,
            ]);
        app()->instance(QueueDlqService::class, $dlq);

        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')
            ->once()
            ->withArgs(static function (
                mixed $request,
                string $action,
                string $targetType,
                string $targetId,
                array $meta,
                string $reason,
                string $result,
            ): bool {
                return $action === 'queue_failed_job_replay'
                    && $targetType === 'failed_jobs'
                    && $targetId === '42'
                    && $meta['actor_admin_id'] === 73
                    && $meta['org_id'] === 17
                    && $meta['failed_job_id'] === 42
                    && $meta['replay_log_id'] === 91
                    && $meta['replay_status'] === 'replayed'
                    && $reason === 'ops_replay_failed_job'
                    && $result === 'success';
            });
        app()->instance(AuditLogger::class, $audit);

        $page = new QueueMonitor;
        $page->retry(42);

        $this->assertSame('Replay queued for failed job #42', $page->statusMessage);
    }

    public function test_queue_monitor_source_cannot_bypass_dlq_service_with_artisan_retry(): void
    {
        $source = file_get_contents(app_path('Filament/Ops/Pages/QueueMonitor.php'));
        $this->assertIsString($source);

        $this->assertStringContainsString('QueueDlqService::class', $source);
        $this->assertStringContainsString('->replayFailedJob(', $source);
        $this->assertStringNotContainsString("Artisan::call('queue:retry'", $source);
        $this->assertStringNotContainsString('queue:retry', $source);
    }
}
