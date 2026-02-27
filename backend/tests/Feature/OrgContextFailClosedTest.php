<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\OrgContextMissingException;
use App\Models\Attempt;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrgContextFailClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_query_fails_closed_when_api_request_has_no_explicit_org_context_marker(): void
    {
        $this->seedAttempt(0, 'anon_fail_closed_0');

        $request = Request::create('/api/v0.3/org-context-fail-closed', 'GET');
        $this->app->instance('request', $request);

        $ctx = app(OrgContext::class);
        $ctx->set(0, null, 'public', 'anon_fail_closed_0');
        $this->app->instance(OrgContext::class, $ctx);

        $this->expectException(OrgContextMissingException::class);
        Attempt::query()->count();
    }

    public function test_attempt_query_allows_org_zero_when_context_is_explicitly_resolved(): void
    {
        $this->seedAttempt(0, 'anon_ctx_resolved_0');
        $this->seedAttempt(1, 'anon_ctx_resolved_1');

        $request = Request::create('/api/v0.3/org-context-resolved', 'GET');
        $request->attributes->set('org_context_resolved', true);
        $this->app->instance('request', $request);

        $ctx = app(OrgContext::class);
        $ctx->set(0, null, 'public', 'anon_ctx_resolved_0');
        $this->app->instance(OrgContext::class, $ctx);

        $ids = Attempt::query()->orderBy('id')->pluck('org_id')->all();
        $this->assertSame([0], array_values(array_map('intval', $ids)));
    }

    public function test_ops_system_context_can_explicitly_bypass_org_zero_guard(): void
    {
        $this->seedAttempt(0, 'anon_ops_bypass_0');
        $this->seedAttempt(2, 'anon_ops_bypass_2');

        $request = Request::create('/ops/internal/org-context-bypass', 'GET');
        $request->attributes->set('org_context_bypass', true);
        $this->app->instance('request', $request);

        $ctx = app(OrgContext::class);
        $ctx->set(0, null, 'system', 'anon_ops_bypass_0');
        $this->app->instance(OrgContext::class, $ctx);

        $ids = Attempt::query()->orderBy('id')->pluck('org_id')->all();
        $this->assertSame([0], array_values(array_map('intval', $ids)));
    }

    private function seedAttempt(int $orgId, string $anonId): void
    {
        Attempt::create([
            'id' => (string) Str::uuid(),
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'calculation_snapshot_json' => ['seed' => true],
        ]);
    }
}
