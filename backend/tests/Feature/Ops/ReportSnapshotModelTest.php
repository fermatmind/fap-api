<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\ReportSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReportSnapshotModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_snapshot_identity_fields_are_immutable_after_creation(): void
    {
        $snapshot = ReportSnapshot::query()->create([
            'org_id' => 7,
            'attempt_id' => 'attempt_report_snapshot_identity',
            'order_no' => 'ord_report_snapshot_identity',
            'scale_code' => 'MBTI',
            'pack_id' => 'pack_identity',
            'dir_version' => 'dir_identity',
            'scoring_spec_version' => 'scoring_identity',
            'report_engine_version' => 'engine_identity',
            'snapshot_version' => 'snapshot_identity',
            'report_json' => ['status' => 'ready'],
            'report_free_json' => [],
            'report_full_json' => [],
            'status' => 'ready',
            'last_error' => null,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Report snapshot identity fields are immutable after creation.');

        $snapshot->fill([
            'org_id' => 8,
            'attempt_id' => 'attempt_report_snapshot_reassigned',
        ])->save();
    }

    public function test_report_snapshot_non_identity_fields_remain_saveable(): void
    {
        $snapshot = ReportSnapshot::query()->create([
            'org_id' => 7,
            'attempt_id' => 'attempt_report_snapshot_status',
            'order_no' => 'ord_report_snapshot_status',
            'scale_code' => 'MBTI',
            'pack_id' => 'pack_status',
            'dir_version' => 'dir_status',
            'scoring_spec_version' => 'scoring_status',
            'report_engine_version' => 'engine_status',
            'snapshot_version' => 'snapshot_status',
            'report_json' => ['status' => 'pending'],
            'report_free_json' => [],
            'report_full_json' => [],
            'status' => 'pending',
            'last_error' => null,
        ]);

        $snapshot->fill([
            'status' => 'ready',
            'last_error' => null,
        ])->save();

        $this->assertSame('ready', $snapshot->refresh()->status);
        $this->assertSame(7, (int) $snapshot->org_id);
        $this->assertSame('attempt_report_snapshot_status', (string) $snapshot->attempt_id);
    }
}
