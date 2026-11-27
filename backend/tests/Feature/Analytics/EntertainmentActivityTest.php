<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Filament\Ops\Pages\EntertainmentStatisticsPage;
use App\Filament\Ops\Widgets\EntertainmentStatisticsWidget;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Analytics\EntertainmentActivity;
use App\Support\Rbac\PermissionNames;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

final class EntertainmentActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(EntertainmentActivity::CATALOG_CACHE);
        Cache::forget(EntertainmentActivity::CATALOG_CACHE.':last-known');
        Http::fake(['https://fermatmind.com/api/entertainment-catalog' => Http::response([], 503)]);
    }

    public function test_registry_refreshes_new_public_tests_and_exposes_a_known_fallback_on_outage(): void
    {
        $baseline = app(EntertainmentActivity::class)->catalog();
        Cache::forget(EntertainmentActivity::CATALOG_CACHE);
        $new = ['slug' => 'new-published-quiz', 'title' => ['en' => 'New quiz', 'zh' => '新测试'], 'path' => '/tests/explore/new-published-quiz', 'privacy' => 'explicit_opt_in', 'roles' => ['single']];
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://fermatmind.com/api/entertainment-catalog' => Http::sequence()
            ->push(['schema' => 'entertainment.catalog.v1', 'items' => $baseline])
            ->push(['schema' => 'entertainment.catalog.v1', 'items' => [...$baseline, $new]])]);
        $this->assertSame('live', app(EntertainmentActivity::class)->catalogState()['source']);
        $data = $this->envelope(slug: 'new-published-quiz');
        $data['payload']['consent_state'] = 'activity_opt_in';
        $this->send($data)->assertStatus(202);
        $this->send($this->envelope(slug: 'new-published-quiz'))->assertUnprocessable();
        Cache::forget(EntertainmentActivity::CATALOG_CACHE);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://fermatmind.com/api/entertainment-catalog' => Http::response([], 503)]);
        $state = app(EntertainmentActivity::class)->catalogState();
        $this->assertSame('last_known', $state['source']);
        $this->assertSame('new-published-quiz', end($state['items'])['slug']);
    }

    public function test_career_match_uses_its_public_path_and_still_requires_explicit_opt_in(): void
    {
        $item = $this->careerMatchCatalogItem();
        $items = [...app(EntertainmentActivity::class)->catalog(), $item];
        $this->fakeLiveCatalog($items);

        $this->assertSame(['items' => $items, 'source' => 'live'], app(EntertainmentActivity::class)->catalogState());
        $data = $this->envelope(slug: 'career-match');
        $this->send($data)->assertUnprocessable();
        $data['payload']['consent_state'] = 'activity_opt_in';
        $this->send($data)->assertStatus(202);
        $this->assertSame(1, DB::table('events')->where('event_code', 'fun_start')->count());
    }

    #[TestWith([['slug' => 'match']], 'another slug poses as the career path')]
    #[TestWith([['slug' => 'another-quiz', 'path' => '/tests/explore/wrong-suffix']], 'ordinary path has the wrong slug')]
    #[TestWith([['path' => '/tests/explore/career-match']], 'career slug has a different path')]
    #[TestWith([['path' => '/career/match/']], 'career path has an extra slash')]
    #[TestWith([['privacy' => 'analytics_consent']], 'career consent is downgraded')]
    #[TestWith([['roles' => ['friend']]], 'career role is changed')]
    #[TestWith([[], true], 'career slug is duplicated')]
    public function test_registry_rejects_invalid_career_match_and_preserves_its_path_and_identity_rules(array $changes, bool $duplicate = false): void
    {
        $item = array_replace($this->careerMatchCatalogItem(), $changes);
        $this->fakeLiveCatalog($duplicate ? [$item, $item] : [$item]);

        $this->assertSame('baseline', app(EntertainmentActivity::class)->catalogState()['source']);
        $this->assertNull(Cache::get(EntertainmentActivity::CATALOG_CACHE.':last-known'));
    }

    public function test_registry_keeps_last_known_career_match_when_a_refresh_has_the_wrong_slug(): void
    {
        $item = $this->careerMatchCatalogItem();
        $this->fakeLiveCatalog([$item]);
        $activity = app(EntertainmentActivity::class);
        $this->assertSame('live', $activity->catalogState()['source']);

        $this->fakeLiveCatalog([array_replace($item, ['slug' => 'match'])]);

        $this->assertSame(['items' => [$item], 'source' => 'last_known'], $activity->catalogState());
        $this->assertSame([$item], Cache::get(EntertainmentActivity::CATALOG_CACHE.':last-known'));
    }

    private function careerMatchCatalogItem(): array
    {
        return [
            'slug' => 'career-match',
            'title' => ['en' => 'Career Match: Compare Interests and Roles', 'zh' => '职业匹配测试：比较兴趣与真实职业'],
            'path' => '/career/match',
            'privacy' => 'explicit_opt_in',
            'roles' => ['single'],
        ];
    }

    private function fakeLiveCatalog(array $items): void
    {
        Cache::forget(EntertainmentActivity::CATALOG_CACHE);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://fermatmind.com/api/entertainment-catalog' => Http::response([
            'schema' => 'entertainment.catalog.v1',
            'items' => $items,
        ])]);
    }

    private function envelope(string $event = 'fun_start', string $role = 'single', string $slug = 'what-dog-breed-am-i', string $locale = 'en'): array
    {
        return ['requestId' => (string) Str::uuid(), 'eventName' => $event, 'anonymousId' => '', 'payload' => [
            'slug' => $slug, 'form_code' => '1.0.0', 'locale' => $locale, 'entry_surface' => $role,
            'target_action' => $event === 'fun_share' ? 'copy' : 'none', 'consent_state' => 'analytics_granted',
        ]];
    }

    private function send(array $data, string $token = 'activity_test_token')
    {
        config()->set('fap.events.ingest_token', 'activity_test_token');

        return $this->withHeaders(['X-Track-Ingest-Token' => $token])->postJson('/api/v0.5/seo/attribution/events', $data);
    }

    private function authenticateOps(): void
    {
        $admin = AdminUser::query()->create(['name' => 'activity QA', 'email' => 'activity@example.test', 'password' => bcrypt('fixture'), 'is_active' => true]);
        $role = Role::query()->create(['name' => 'activity-owner']);
        $permission = Permission::query()->firstOrCreate(['name' => PermissionNames::ADMIN_OWNER]);
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
    }

    public function test_ingest_is_authenticated_idempotent_and_has_no_personal_identity(): void
    {
        $data = $this->envelope();
        $this->send($data, 'wrong')->assertUnauthorized();
        $this->send($data)->assertStatus(202);
        $this->send($data)->assertStatus(202);
        $this->assertSame(1, DB::table('events')->where('event_code', 'fun_start')->count());
        $event = DB::table('events')->where('event_code', 'fun_start')->first();
        foreach (['anon_id', 'user_id', 'session_id', 'attempt_id', 'analytics_ip_hash', 'referrer'] as $field) {
            $this->assertEmpty($event->{$field} ?? null);
        }
        $keys = array_keys(json_decode($event->meta_json, true, flags: JSON_THROW_ON_ERROR));
        sort($keys, SORT_STRING);
        $this->assertSame(['excluded', 'method', 'role', 'schema', 'slug', 'version'], $keys);
    }

    public function test_private_answers_unknown_tests_roles_and_missing_opt_in_are_rejected(): void
    {
        $data = $this->envelope();
        $data['payload']['answers'] = ['private'];
        $this->send($data)->assertUnprocessable();
        $this->send($this->envelope(slug: 'unknown-quiz'))->assertUnprocessable();
        $this->send($this->envelope(role: 'friend'))->assertUnprocessable();
        foreach (['habit-patterns-test', 'four-axes-test', 'conflict-boundary-behaviors-test'] as $slug) {
            $data = $this->envelope(slug: $slug);
            $this->send($data)->assertUnprocessable();
            $data['payload']['consent_state'] = 'activity_opt_in';
            $this->send($data)->assertStatus(202);
        }
        $this->assertSame(3, DB::table('events')->where('event_code', 'fun_start')->count());
    }

    public function test_cms_filters_languages_roles_dates_and_excludes_qa_and_other_orgs(): void
    {
        $this->authenticateOps();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28T04:00:00Z'));
        try {
            foreach (['fun_start', 'fun_complete', 'fun_result_view', 'fun_share'] as $event) {
                $this->send($this->envelope($event, 'creator', 'friendship-quiz', 'zh'))->assertStatus(202);
            }
            $this->send($this->envelope('fun_start', 'friend', 'friendship-quiz', 'en'))->assertStatus(202);
            $qa = $this->envelope('fun_start', 'creator', 'friendship-quiz', 'zh');
            $qa['payload']['is_qa'] = true;
            $this->send($qa)->assertStatus(202);
            $other = (array) DB::table('events')->where('event_code', 'fun_complete')->first();
            $other['id'] = (string) Str::uuid();
            $other['org_id'] = 99;
            unset($other['share_style_g'], $other['page_session_id_g']);
            DB::table('events')->insert($other);
            $page = app(EntertainmentStatisticsPage::class);
            $page->fromDate = $page->toDate = '2026-10-28';
            $page->test = 'friendship-quiz';
            $page->locale = 'zh';
            $page->role = 'creator';
            $page->applyFilters();
            $this->assertNull($page->warning);
            $this->assertSame(array_fill_keys(['fun_start', 'fun_complete', 'fun_result_view', 'fun_share'], 1), $page->summary);
            $this->assertCount(1, $page->rows);
            $this->assertSame(['copy' => 1], $page->rows[0]['methods']);
            $widget = app(EntertainmentStatisticsWidget::class);
            $widget->fromDate = $widget->toDate = '2026-10-28';
            $widget->test = 'friendship-quiz';
            $widget->locale = 'zh';
            $widget->role = 'creator';
            $widget->applyFilters();
            $this->assertSame($page->summary, $widget->summary);
            $this->assertSame($page->rows, $widget->rows);
            \Filament\Facades\Filament::setCurrentPanel(app(\Filament\PanelRegistry::class)->get('ops'));
            \Livewire\Livewire::test(EntertainmentStatisticsPage::class)
                ->set('test', 'friendship-quiz')->set('locale', 'zh')->set('role', 'creator')
                ->call('applyFilters')->assertSee('friendship-quiz');
            $page->fromDate = '2026-01-01';
            $page->applyFilters();
            $this->assertNotNull($page->warning);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_dashboard_widget_defaults_to_beijing_today_and_keeps_every_registered_test_accessible(): void
    {
        $this->authenticateOps();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-27T17:00:00Z'));
        try {
            $this->send($this->envelope('fun_start', 'solo', 'friendship-quiz', 'en'))->assertStatus(202);
            $catalog = app(EntertainmentActivity::class)->catalog();
            $totalRows = array_sum(array_map(fn (array $test): int => count($test['roles']) * 2, $catalog));
            \Filament\Facades\Filament::setCurrentPanel(app(\Filament\PanelRegistry::class)->get('ops'));
            $component = \Livewire\Livewire::test(EntertainmentStatisticsWidget::class)
                ->assertSet('fromDate', '2026-10-28')->assertSet('toDate', '2026-10-28')
                ->assertSet('totalRows', $totalRows)->assertCount('rows', 50)
                ->assertSet('summary.fun_start', 1)
                ->call('movePage', 1)->assertSet('offset', 50)->assertCount('rows', 50);
            $component->set('offset', intdiv($totalRows - 1, 50) * 50)->call('movePage', 1)
                ->assertSee($catalog[array_key_last($catalog)]['slug'])
                ->set('test', 'friendship-quiz')->set('locale', 'en')->set('role', 'solo')
                ->call('applyFilters')->assertSet('offset', 0)->assertCount('rows', 1)
                ->assertSet('summary.fun_start', 1)->assertSet('summary.fun_complete', 0)
                ->assertSee('friendship-quiz');
            $component->set('fromDate', '2026-10-27')->set('toDate', '2026-10-27')
                ->call('applyFilters')->assertSet('summary.fun_start', 0);
            app()->setLocale('en');
            $component->set('toDate', '2026-10-28')->call('applyFilters')
                ->assertSee('Fun quiz activity details')->assertSee('Friendship solo');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_unauthorized_widget_read_is_denied(): void
    {
        $this->assertFalse(EntertainmentStatisticsWidget::canView());
        \Livewire\Livewire::test(EntertainmentStatisticsWidget::class)->assertForbidden();
    }

    public function test_unauthorized_page_read_is_denied(): void
    {
        $this->assertFalse(EntertainmentStatisticsPage::canAccess());
    }
}
