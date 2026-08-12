<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Http\Middleware\RecordPublicContentRuntime;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\PublicContentRuntimeDaily;
use App\Models\Role;
use App\Services\Ops\PublicContentRuntimeMetricsService;
use App\Support\Career\CareerVerifyOnlyRequestAuthorizer;
use App\Support\Rbac\PermissionNames;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicContentRuntimeMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('public_content_observability.cache_store', 'array');
        config()->set('public_content_observability.enabled', true);
        $routes = (array) config('public_content_observability.routes', []);
        $routes['api/v0.5/__runtime-probe'] = [
            'family' => 'runtime_probe',
            'priority' => 'L3',
        ];
        config()->set('public_content_observability.routes', $routes);
        Cache::store('array')->flush();

        Route::middleware(RecordPublicContentRuntime::class)
            ->get('/api/v0.5/__runtime-probe', static fn () => response()->json(['ok' => true]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_allowlisted_anonymous_get_is_recorded_without_route_parameters_or_bodies(): void
    {
        CarbonImmutable::setTestNow('2026-07-13 10:00:00 UTC');

        $this->getJson('/api/v0.5/__runtime-probe?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $payload = app(PublicContentRuntimeMetricsService::class)->query(60);

        $this->assertTrue($payload['ok']);
        $this->assertCount(1, $payload['items']);
        $this->assertSame('runtime_probe', $payload['items'][0]['route_family']);
        $this->assertSame('zh-CN', $payload['items'][0]['locale']);
        $this->assertSame(1, $payload['items'][0]['request_count']);
        $this->assertSame(1.0, $payload['items'][0]['success_rate']);
        $this->assertStringNotContainsString('__runtime-probe', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('response_body', $payload['items'][0]);
    }

    public function test_authenticated_and_non_allowlisted_requests_are_not_recorded(): void
    {
        CarbonImmutable::setTestNow('2026-07-13 10:00:00 UTC');

        $this->withToken('private-token')->getJson('/api/v0.5/__runtime-probe?locale=en')->assertOk();
        $this->withHeader('Authorization', 'Basic private-token')
            ->getJson('/api/v0.5/__runtime-probe?locale=en')
            ->assertOk();
        $this->getJson('/api/v0.5/__runtime-probe?locale=en&org_id=9')->assertOk();
        $this->getJson('/api/v0.5/__runtime-probe?locale=en&org_id=invalid')->assertOk();
        $this->getJson('/api/v0.5/__runtime-probe?locale=en&org_id%5B%5D=0')->assertOk();
        $this->withHeader('X-Org-Id', '9')->getJson('/api/v0.5/__runtime-probe?locale=en')->assertOk();
        $this->getJson('/api/v0.5/__not-allowlisted?locale=en')->assertNotFound();

        $this->assertSame([], app(PublicContentRuntimeMetricsService::class)->query(60)['items']);
    }

    public function test_only_signed_exact_career_verify_request_bypasses_runtime_metrics_writes(): void
    {
        CarbonImmutable::setTestNow('2026-07-13 10:00:00 UTC');

        $this->withHeader('X-Fermat-Career-Verify-Only', '1')
            ->getJson('/api/v0.5/__runtime-probe?locale=en')
            ->assertOk();

        $items = app(PublicContentRuntimeMetricsService::class)->query(60)['items'];
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['request_count']);

        $authorizer = app(CareerVerifyOnlyRequestAuthorizer::class);
        $careerUri = '/api/v0.5/career/jobs/runtime-probe?locale=en';
        $careerRequest = Request::create($careerUri, 'GET');
        foreach ($this->verifyOnlyHeaders($careerUri) as $name => $value) {
            $careerRequest->headers->set($name, $value);
        }
        $this->assertTrue($authorizer->isAuthorized($careerRequest));

        $otherUri = '/api/v0.5/__runtime-probe?locale=en';
        $otherRequest = Request::create($otherUri, 'GET');
        foreach ($this->verifyOnlyHeaders($otherUri) as $name => $value) {
            $otherRequest->headers->set($name, $value);
        }
        $this->assertFalse($authorizer->isAuthorized($otherRequest));
    }

    /** @return array<string, string> */
    private function verifyOnlyHeaders(string $requestUri): array
    {
        $timestamp = (string) time();

        return [
            CareerVerifyOnlyRequestAuthorizer::MARKER_HEADER => '1',
            CareerVerifyOnlyRequestAuthorizer::TIMESTAMP_HEADER => $timestamp,
            CareerVerifyOnlyRequestAuthorizer::SIGNATURE_HEADER => hash_hmac(
                'sha256',
                CareerVerifyOnlyRequestAuthorizer::signaturePayload($requestUri, $timestamp),
                (string) config('app.key'),
            ),
        ];
    }

    public function test_every_configured_template_exists_and_has_runtime_middleware(): void
    {
        $configured = array_keys((array) config('public_content_observability.routes', []));
        $registered = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array($route->uri(), $configured, true)) {
                continue;
            }
            $registered[] = $route->uri();
            $this->assertTrue(
                collect($route->gatherMiddleware())->contains(
                    static fn (mixed $middleware): bool => str_contains(
                        (string) $middleware,
                        'RecordPublicContentRuntime',
                    ),
                ),
                "Missing runtime metrics middleware on {$route->uri()}",
            );
            $this->assertSame(
                0,
                preg_match('#/(attempts?|reports?|orders?|payments?|shortlist|internal|ops)(/|$)#i', $route->uri()),
                "Private route template entered observability allowlist: {$route->uri()}",
            );
        }

        sort($configured);
        sort($registered);
        $this->assertSame($configured, array_values(array_unique($registered)));
    }

    public function test_minute_metrics_roll_up_idempotently_and_keep_ninety_day_daily_retention(): void
    {
        CarbonImmutable::setTestNow('2026-07-13 10:00:00 UTC');
        $metrics = app(PublicContentRuntimeMetricsService::class);
        $metrics->record('runtime_probe', 'L3', 'en', 400, 25.0);
        $metrics->record('runtime_probe', 'L3', 'en', 404, 45.5);
        $metrics->record('runtime_probe', 'L3', 'en', 504, 125.5, true);

        CarbonImmutable::setTestNow('2026-07-13 10:01:00 UTC');
        $metrics->rollupPending();
        $this->assertDatabaseMissing('public_content_runtime_daily', [
            'day' => '2026-07-13',
            'route_family' => 'runtime_probe',
        ]);

        CarbonImmutable::setTestNow('2026-07-13 10:02:00 UTC');
        $metrics->rollupPending();
        $metrics->rollupPending();

        $this->assertDatabaseHas('public_content_runtime_daily', [
            'day' => '2026-07-13',
            'route_family' => 'runtime_probe',
            'locale' => 'en',
            'request_count' => 3,
            'not_found_count' => 1,
            'timeout_count' => 1,
        ]);

        $item = $metrics->query(60, 'runtime_probe', 'en')['items'][0];
        $this->assertSame(3, $item['request_count']);
        $this->assertSame(0.333333, $item['client_error_rate']);
        $this->assertSame(0.333333, $item['not_found_rate']);
        $this->assertSame(0.333333, $item['timeout_rate']);
        $this->assertSame(50.0, $item['p50_ms']);
        $this->assertSame(250.0, $item['p95_ms']);
    }

    public function test_long_window_uses_complete_daily_boundary_without_gap_or_overlap(): void
    {
        CarbonImmutable::setTestNow('2026-07-13 10:00:00 UTC');
        foreach ([
            ['day' => '2026-07-05', 'requests' => 1],
            ['day' => '2026-07-06', 'requests' => 2],
        ] as $fixture) {
            PublicContentRuntimeDaily::query()->create([
                'day' => $fixture['day'],
                'route_family' => 'runtime_probe',
                'priority' => 'L3',
                'locale' => 'en',
                'request_count' => $fixture['requests'],
                'success_count' => $fixture['requests'],
                'duration_count' => $fixture['requests'],
                'duration_sum_ms' => 100 * $fixture['requests'],
                'duration_max_ms' => 100,
                'duration_histogram' => ['100' => $fixture['requests']],
                'rolled_minutes' => [],
            ]);
        }

        $payload = app(PublicContentRuntimeMetricsService::class)->query(8 * 24 * 60, 'runtime_probe', 'en');

        $this->assertSame('daily_and_minute', $payload['aggregation_granularity']);
        $this->assertSame('2026-07-05T00:00:00+00:00', $payload['effective_start_at']);
        $this->assertSame(3, $payload['items'][0]['request_count']);
        $this->assertSame(1.0, $payload['items'][0]['success_rate']);
    }

    public function test_metrics_storage_failure_never_changes_public_response(): void
    {
        config()->set('public_content_observability.cache_store', 'missing-store');

        $this->getJson('/api/v0.5/__runtime-probe?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_ops_runtime_query_requires_admin_read_and_returns_only_aggregate_data(): void
    {
        $this->getJson('/api/v0.5/ops/public-content-health/runtime')->assertUnauthorized();

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $response = $this->withSession(['ops_org_id' => 7])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/public-content-health/runtime?window_minutes=60');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('scope', 'anonymous_org_0_public_get')
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('ip')
            ->assertJsonMissingPath('url');
    }

    public function test_ops_runtime_query_returns_bounded_unavailable_contract_on_storage_failure(): void
    {
        config()->set('public_content_observability.cache_store', 'missing-store');
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);

        $this->withSession(['ops_org_id' => 7])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/public-content-health/runtime')
            ->assertStatus(503)
            ->assertExactJson([
                'ok' => false,
                'scope' => 'anonymous_org_0_public_get',
                'error_code' => 'metrics_unavailable',
                'items' => [],
            ]);
    }

    /** @param list<string> $permissions */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(10)),
            'description' => null,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
