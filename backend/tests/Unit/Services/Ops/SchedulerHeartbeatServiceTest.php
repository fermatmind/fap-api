<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ops;

use App\Services\Ops\SchedulerHeartbeatService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SchedulerHeartbeatServiceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/scheduler-heartbeat-'.bin2hex(random_bytes(8)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    #[Test]
    public function completed_zero_exit_code_is_the_only_healthy_state(): void
    {
        $service = new SchedulerHeartbeatService($this->path);
        $now = CarbonImmutable::parse('2026-08-27T13:45:30Z');
        $service->record('started', null, $now->subSecond());
        $recorded = $service->record('completed', 0, $now);
        $checked = $service->check(180, $now->addSeconds(30));

        $this->assertSame('healthy', $recorded['status']);
        $this->assertTrue($checked['ok']);
        $this->assertSame('healthy', $checked['reason']);
        $this->assertSame(30, $checked['age_seconds']);
        $this->assertSame(SchedulerHeartbeatService::schedulerContractRevision(), $checked['scheduler_contract_revision']);
        $this->assertSame(SchedulerHeartbeatService::RUNNER, $checked['runner']);
    }

    #[Test]
    public function stale_future_failed_and_overlap_heartbeats_fail_closed(): void
    {
        $service = new SchedulerHeartbeatService($this->path);
        $now = CarbonImmutable::parse('2026-08-27T13:45:30Z');

        $service->record('completed', 0, $now->subSeconds(181));
        $this->assertSame('stale', $service->check(180, $now)['reason']);

        $service->record('completed', 0, $now->addSeconds(6));
        $this->assertSame('future', $service->check(180, $now)['reason']);

        $service->record('completed', 1, $now);
        $this->assertSame('failed', $service->check(180, $now)['reason']);

        $service->record('completed', 0, $now->subSecond());
        $service->record('overlap', null, $now);
        $service->record('completed', 0, $now->addSecond());
        $this->assertSame('overlap', $service->check(180, $now)['reason']);
    }

    #[Test]
    public function malformed_or_wrong_revision_heartbeat_fails_closed(): void
    {
        $service = new SchedulerHeartbeatService($this->path);
        file_put_contents($this->path, "not-json\n");
        $this->assertSame('missing_or_malformed', $service->check(180)['reason']);

        $payload = $service->record('completed', 0, CarbonImmutable::parse('2026-08-27T13:45:30Z'));
        $payload['scheduler_contract_revision'] = str_repeat('0', 64);
        file_put_contents($this->path, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertSame('contract_mismatch', $service->check(180, CarbonImmutable::parse('2026-08-27T13:45:31Z'))['reason']);
    }
}
