<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateReportSnapshotJob;
use Tests\TestCase;

final class GenerateReportSnapshotJobTest extends TestCase
{
    private string|false $originalReportConnectionEnv;

    private string|false $originalReportQueueEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalReportConnectionEnv = getenv('FAP_REPORT_QUEUE_CONNECTION');
        $this->originalReportQueueEnv = getenv('FAP_REPORT_QUEUE');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('FAP_REPORT_QUEUE_CONNECTION', $this->originalReportConnectionEnv);
        $this->restoreEnv('FAP_REPORT_QUEUE', $this->originalReportQueueEnv);
        $this->reloadReportQueueConfig();

        parent::tearDown();
    }

    public function test_generate_report_snapshot_job_uses_default_report_queue_binding(): void
    {
        $this->setEnv('FAP_REPORT_QUEUE_CONNECTION', null);
        $this->setEnv('FAP_REPORT_QUEUE', null);
        $this->reloadReportQueueConfig();

        $job = new GenerateReportSnapshotJob(0, 'attempt-default', 'submit', null);

        $this->assertSame('database_reports', $job->connection);
        $this->assertSame('reports', $job->queue);
    }

    public function test_generate_report_snapshot_job_uses_env_overridden_report_queue_binding(): void
    {
        $this->setEnv('FAP_REPORT_QUEUE_CONNECTION', 'redis');
        $this->setEnv('FAP_REPORT_QUEUE', 'default');
        $this->reloadReportQueueConfig();

        $job = new GenerateReportSnapshotJob(0, 'attempt-redis', 'submit', null);

        $this->assertSame('redis', $job->connection);
        $this->assertSame('default', $job->queue);
    }

    private function reloadReportQueueConfig(): void
    {
        /** @var array{queue:array{report_connection:string|null,report_queue:string}} $config */
        $config = require config_path('fap.php');

        config()->set('fap.queue.report_connection', $config['queue']['report_connection']);
        config()->set('fap.queue.report_queue', $config['queue']['report_queue']);
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function restoreEnv(string $key, string|false $value): void
    {
        if ($value === false) {
            $this->setEnv($key, null);

            return;
        }

        $this->setEnv($key, $value);
    }
}
