<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Filament\Ops\Pages\EntertainmentStatisticsPage;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EntertainmentActivityTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame(['schema', 'slug', 'version', 'role', 'method', 'excluded'], array_keys(json_decode($event->meta_json, true)));
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

    public function test_unauthorized_page_read_is_denied(): void
    {
        $this->assertFalse(EntertainmentStatisticsPage::canAccess());
    }
}
