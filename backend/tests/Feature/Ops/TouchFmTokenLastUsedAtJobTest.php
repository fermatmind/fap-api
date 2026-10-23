<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Jobs\Ops\TouchFmTokenLastUsedAtJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TouchFmTokenLastUsedAtJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_targets_existing_database_ops_worker_even_when_default_is_redis(): void
    {
        config()->set('queue.default', 'redis');
        $job = new TouchFmTokenLastUsedAtJob(hash('sha256', 'token-fixture'));
        $this->assertSame('database', $job->connection);
        $this->assertSame('ops', $job->queue);
    }

    public function test_legacy_job_updates_only_existing_token_and_repeated_delivery_is_throttled(): void
    {
        $this->freezeTime();
        $hash = hash('sha256', 'token-fixture');
        DB::table('auth_tokens')->insert([
            'token_hash' => $hash,
            'org_id' => 0,
            'role' => 'public',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $job = new TouchFmTokenLastUsedAtJob($hash);
        // Existing serialized jobs have no explicit connection and remain executable.
        $job->connection = null;
        $job->handle();
        $first = DB::table('auth_tokens')->where('token_hash', $hash)->value('last_used_at');
        $this->assertSame(now()->toDateTimeString(), $first);
        $this->travel(1)->minutes();
        $job->handle();
        (new TouchFmTokenLastUsedAtJob(hash('sha256', 'missing-token')))->handle();
        $this->assertSame($first, DB::table('auth_tokens')->where('token_hash', $hash)->value('last_used_at'));
        $this->assertSame(1, DB::table('auth_tokens')->count());
    }
}
