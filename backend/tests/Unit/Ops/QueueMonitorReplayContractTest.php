<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use App\Filament\Ops\Pages\QueueMonitor;
use App\Services\Audit\AuditLogger;
use App\Services\Queue\QueueDlqService;
use App\Support\OrgContext;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class QueueMonitorReplayContractTest extends TestCase
{
    public function test_replay_uses_the_numeric_row_id_even_when_another_uuid_matches_it_numerically(): void
    {
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        (require database_path('migrations/2026_02_08_030000_create_queue_dlq_replays_table.php'))->up();

        $neighbor = [
            'id' => 11,
            'uuid' => '12af0000-0000-4000-8000-000000000011',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{"id":"neighbor"}',
            'exception' => 'test failure',
        ];
        $target = array_replace($neighbor, [
            'id' => 12,
            'uuid' => 'ab000000-0000-4000-8000-000000000012',
            'payload' => '{"id":"target"}',
        ]);
        DB::table('failed_jobs')->insert([$neighbor, $target]);

        // MySQL coerces UUID text in an integer comparison; the UUID provider
        // exposes the resulting UUID as "id" and removes the "uuid" property.
        $uuidProvider = Mockery::mock(DatabaseUuidFailedJobProvider::class);
        $uuidProvider->allows('find')->with(Mockery::on(static fn ($id) => $id === '12'))->andReturnNull();
        $uuidProvider->allows('find')->with(Mockery::on(static fn ($id) => $id === 12))->andReturn((object) [
            'id' => $neighbor['uuid'],
            'connection' => $neighbor['connection'],
            'queue' => $neighbor['queue'],
            'payload' => $neighbor['payload'],
        ]);
        app()->instance('queue.failer', $uuidProvider);

        $queue = Mockery::mock(Queue::class);
        $queue->expects('pushRaw')->with($target['payload'], 'default')->andReturn('target');
        $factory = Mockery::mock(QueueFactory::class);
        $factory->expects('connection')->with('redis')->andReturn($queue);
        $service = new QueueDlqService($factory);

        $result = $service->replayFailedJob(12, 'admin:73');

        self::assertSame('replayed', $result['status']);
        $this->assertDatabaseMissing('failed_jobs', ['id' => 12]);
        $this->assertDatabaseHas('failed_jobs', $neighbor);
        $this->assertDatabaseHas('queue_dlq_replays', [
            'failed_job_id' => 12,
            'failed_job_uuid' => $target['uuid'],
            'replayed_job_id' => 'target',
        ]);
        self::assertSame('already_replayed', $service->replayFailedJob(12, 'admin:73')['status']);
        self::assertSame('not_found', $service->replayFailedJob(12, 'admin:73', force: true)['status']);
    }

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

        $this->assertSame('失败任务 #42 已加入重试队列', $page->statusMessage);
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
