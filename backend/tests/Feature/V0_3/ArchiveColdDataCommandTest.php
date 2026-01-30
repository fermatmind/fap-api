<?php

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArchiveColdDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_command_generates_audit_and_file(): void
    {
        config()->set('fap_attempts.archive_driver', 'file');
        config()->set('fap_attempts.archive_path', storage_path('app/archives/pr21-test'));

        DB::table('attempt_answer_rows')->insert([
            'attempt_id' => (string) Str::uuid(),
            'org_id' => 0,
            'scale_code' => 'DEMO_ANSWERS',
            'question_id' => 'DEMO-SLIDER-1',
            'question_index' => 0,
            'question_type' => 'slider',
            'answer_json' => json_encode(['value' => 3], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'duration_ms' => 1000,
            'submitted_at' => '1999-12-31 00:00:00',
            'created_at' => now(),
        ]);

        DB::table('events')->insert([
            'id' => (string) Str::uuid(),
            'event_code' => 'test_event',
            'event_name' => 'test_event',
            'org_id' => 0,
            'occurred_at' => '1999-12-31 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('fap:archive:cold-data', ['--before' => '2000-01-01'])
            ->assertExitCode(0);

        $audit = DB::table('archive_audits')->orderByDesc('id')->first();
        $this->assertNotNull($audit);
        $this->assertNotSame('', (string) ($audit->object_uri ?? ''));

        $objectUri = (string) ($audit->object_uri ?? '');
        $path = str_replace('file://', '', $objectUri);
        $this->assertFileExists($path);
    }
}
