<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Services\Content\ContentStore;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AppServiceProviderContentStoreIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_store_attempt_lookup_scopes_by_request_attribute_org_id_and_ignores_input_org_id(): void
    {
        $attemptId = $this->seedCrossTenantAttempt(orgId: 999);

        $request = Request::create('/api/v0.2/content-packs', 'GET', [
            'attempt_id' => $attemptId,
            'org_id' => 999,
        ]);
        $request->attributes->set('org_id', 1);

        [$queries, $store] = $this->resolveStoreWithCapturedQueries($request);
        $attemptQuery = $this->firstAttemptsQuery($queries);

        $this->assertNotNull($attemptQuery);
        $this->assertStringContainsString('org_id', strtolower((string) ($attemptQuery['sql'] ?? '')));

        $orgBindings = $this->numericBindings($attemptQuery['bindings'] ?? []);
        $this->assertContains(1, $orgBindings);
        $this->assertNotContains(999, $orgBindings);

        $highlights = $store->loadHighlights();
        $this->assertSame('MBTI-CN-v0.2.2', (string) ($highlights['meta']['package'] ?? ''));
        $this->assertNotSame('MBTI-CN-v0.2.1-TEST', (string) ($highlights['meta']['package'] ?? ''));
    }

    public function test_content_store_attempt_lookup_falls_back_to_org_context_when_request_attrs_missing(): void
    {
        $attemptId = $this->seedCrossTenantAttempt(orgId: 999);

        $request = Request::create('/api/v0.2/content-packs', 'GET', [
            'attempt_id' => $attemptId,
            'org_id' => 999,
        ]);

        $orgContext = app(OrgContext::class);
        $orgContext->set(1, null, 'public');
        app()->instance(OrgContext::class, $orgContext);

        [$queries, $store] = $this->resolveStoreWithCapturedQueries($request);
        $attemptQuery = $this->firstAttemptsQuery($queries);

        $this->assertNotNull($attemptQuery);
        $this->assertStringContainsString('org_id', strtolower((string) ($attemptQuery['sql'] ?? '')));

        $orgBindings = $this->numericBindings($attemptQuery['bindings'] ?? []);
        $this->assertContains(1, $orgBindings);
        $this->assertNotContains(999, $orgBindings);

        $highlights = $store->loadHighlights();
        $this->assertSame('MBTI-CN-v0.2.2', (string) ($highlights['meta']['package'] ?? ''));
        $this->assertNotSame('MBTI-CN-v0.2.1-TEST', (string) ($highlights['meta']['package'] ?? ''));
    }

    private function seedCrossTenantAttempt(int $orgId): string
    {
        config()->set('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.2');
        config()->set('content_packs.default_dir_version', 'MBTI-CN-v0.2.2');
        config()->set('content_packs.default_region', 'CN_MAINLAND');
        config()->set('content_packs.default_locale', 'zh-CN');

        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => 'tenant_inject_anon',
            'user_id' => '1001',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST',
            'dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'content_package_version' => 'v0.2.1-TEST',
            'scoring_spec_version' => '2026.01',
        ]);

        return $attemptId;
    }

    /**
     * @return array{0:array<int,array{sql:string,bindings:array}>,1:ContentStore}
     */
    private function resolveStoreWithCapturedQueries(Request $request): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = [
                'sql' => (string) ($query->sql ?? ''),
                'bindings' => is_array($query->bindings ?? null) ? $query->bindings : [],
            ];
        });

        $this->app->instance('request', $request);
        $this->app->forgetInstance(ContentStore::class);

        /** @var ContentStore $store */
        $store = app(ContentStore::class);

        return [$queries, $store];
    }

    /**
     * @param array<int,array{sql:string,bindings:array}> $queries
     * @return array{sql:string,bindings:array}|null
     */
    private function firstAttemptsQuery(array $queries): ?array
    {
        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['sql'] ?? ''));
            if (str_contains($sql, 'from "attempts"') || str_contains($sql, 'from `attempts`')) {
                return $query;
            }
        }

        return null;
    }

    /**
     * @param array<int,mixed> $bindings
     * @return array<int,int>
     */
    private function numericBindings(array $bindings): array
    {
        $values = [];
        foreach ($bindings as $binding) {
            if (is_numeric($binding)) {
                $values[] = (int) $binding;
            }
        }

        return $values;
    }
}
