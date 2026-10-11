<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ScaleRegistryResource\Pages\EditScaleRegistry;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ContentPromotion\IqPublicEntryPackage;
use App\Services\Scale\PublicScaleCatalogCache;
use App\Services\Scale\ScaleRegistry;
use App\Services\Scale\ScaleRegistryWriter;
use App\Support\CacheKeys;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class Eq60IntroUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fap.scales_registry.use_v2' => true, 'content_packs.public_scale_cache_store' => 'array']);
        $content = json_encode([
            'en' => ['why_choose' => ['intro' => 'Original public EQ intro.', 'items' => [['id' => 'evidence', 'text' => 'Keep evidence.']]],
                'faq' => ['scores' => 'Keep score and norm limits.'], 'free' => 'Keep existing free claim.'],
            'zh' => ['why_choose' => ['intro' => '保留中文正文', 'items' => []], 'number' => 1.0, 'boolean' => true, 'null' => null, 'empty_object' => (object) [], 'empty_list' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        DB::table('scales_registry_v2')->where('id', 3)->delete();
        DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'EQ_60')->delete();
        DB::table('scales_registry')->where('org_id', 0)->where('code', 'EQ_60')->delete();
        $row = [
            'org_id' => 0, 'code' => 'EQ_60', 'primary_slug' => ScaleRegistryWriter::EQ_INTRO_TARGET['primary_slug'],
            'slugs_json' => json_encode([ScaleRegistryWriter::EQ_INTRO_TARGET['primary_slug'], 'eq-test', 'emotional-intelligence-test']),
            'driver_type' => 'eq', 'assessment_driver' => 'eq_60', 'default_pack_id' => 'EQ_60',
            'default_dir_version' => 'v1', 'default_region' => 'GLOBAL', 'default_locale' => 'en',
            'capabilities_json' => '{"scoring":"KEEP","norm":"PROVISIONAL"}',
            'view_policy_json' => '{"public":true,"indexable":true}', 'commercial_json' => '{"mode":"free_only"}',
            'seo_schema_json' => '{"robots":"index,follow"}', 'seo_i18n_json' => '{"en":{"title":"Keep title"},"zh":{"title":"保留"}}',
            'report_summary_i18n_json' => '{"en":{"summary":"KEEP"}}', 'content_i18n_json' => $content,
            'is_public' => true, 'is_active' => true, 'is_indexable' => true,
            'created_at' => '2026-08-09 07:16:56', 'updated_at' => '2026-10-07 09:24:12',
        ];
        DB::table('scales_registry')->insert($row);
        DB::table('scales_registry_v2')->insert(['id' => 3, ...$row]);
        DB::table('scale_slugs')->where('org_id', 0)->where('scale_code', 'EQ_60')->delete();
        foreach (json_decode($row['slugs_json'], true) as $i => $slug) {
            DB::table('scale_slugs')->insert(['org_id' => 0, 'scale_code' => 'EQ_60', 'slug' => $slug,
                'is_primary' => $i === 0, 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
        }
    }

    public function test_plan_is_deterministic_and_write_free(): void
    {
        $request = $this->request();
        $before = $this->state();
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $first = app(ScaleRegistryWriter::class)->updateEqIntro($request);
        self::assertSame('PLAN_READY', $first['status']);
        self::assertSame($first, app(ScaleRegistryWriter::class)->updateEqIntro($request));
        self::assertSame($before, $this->state());
        foreach ($queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $sql);
        }
    }

    public function test_invalid_contract_rejects_in_plan_and_execute_without_writes(): void
    {
        $valid = $this->request();
        $requests = [];
        foreach (['org_id' => 1, 'id' => 4, 'code' => 'BIG5_OCEAN', 'primary_slug' => 'eq-test', 'field' => 'content_i18n_json.zh.why_choose.intro'] as $key => $value) {
            $bad = $valid;
            $bad['target'][$key] = $value;
            $requests[] = $bad;
        }
        $bad = $valid;
        $bad['target']['extra'] = true;
        $requests[] = $bad;
        $bad = $valid;
        $bad['is_indexable'] = false;
        $requests[] = $bad;
        $bad = $valid;
        $bad['baseline']['v2'] = 'invalid';
        $requests[] = $bad;
        foreach (['replacement', $valid['before']."\n\n ", ['invalid'], $valid['before']."\n\n".str_repeat('x', 8192)] as $after) {
            $bad = $valid;
            $bad['after'] = $after;
            $requests[] = $bad;
        }
        foreach ($requests as $request) {
            $before = $this->state();
            foreach ([false, true] as $execute) {
                $this->reject(fn () => app(ScaleRegistryWriter::class)->updateEqIntro($request, $execute));
                self::assertSame($before, $this->state());
            }
        }
    }

    public function test_stale_baseline_rechecked_in_execute_and_plan(): void
    {
        $request = $this->request();
        app(ScaleRegistryWriter::class)->updateEqIntro($request);
        $this->leaveTestTransaction();
        DB::table('scales_registry_v2')->where('id', 3)->update(['seo_i18n_json' => '{"en":{"title":"concurrent change"}}']);
        $before = $this->state();
        foreach ([false, true] as $execute) {
            $this->reject(fn () => app(ScaleRegistryWriter::class)->updateEqIntro($request, $execute), 'Stale');
            self::assertSame($before, $this->state());
        }
    }

    public static function identityTables(): array
    {
        return [['scales_registry_v2'], ['scales_registry']];
    }

    #[DataProvider('identityTables')]
    public function test_case_insensitive_lookup_rejects_actual_code_drift_without_writes(string $table): void
    {
        $request = $this->request();
        $this->leaveTestTransaction();
        Schema::table($table, function (Blueprint $schema): void {
            $schema->string('code', 64)->collation(DB::connection()->getDriverName() === 'sqlite'
                ? 'NOCASE' : 'utf8mb4_unicode_ci')->change();
        });
        DB::table($table)->where('org_id', 0)->where('code', 'EQ_60')->update(['code' => 'eq_60']);
        $found = DB::table($table)->where('org_id', 0)->where('code', 'EQ_60')->get();
        self::assertCount(1, $found);
        self::assertSame('eq_60', $found->first()->code);
        $keys = [CacheKeys::scaleRegistryActive(0), CacheKeys::scaleRegistryByCode(0, 'EQ_60'),
            CacheKeys::scaleRegistryByCode(0, 'EQ_EMOTIONAL_INTELLIGENCE'), CacheKeys::publicScaleRegistryGeneration(0)];
        foreach ([ScaleRegistryWriter::EQ_INTRO_TARGET['primary_slug'], 'eq-test', 'emotional-intelligence-test'] as $slug) {
            foreach ([$slug, 'compat:'.$slug, 'canonical:'.$slug] as $lookup) {
                $keys[] = CacheKeys::scaleRegistryBySlug(0, $lookup);
            }
        }
        foreach ($keys as $key) {
            Cache::put($key, 7, 600);
        }
        $cacheBefore = Cache::store('array')->getStore()->all();
        $before = $this->state();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $cache = Mockery::mock(PublicScaleCatalogCache::class)->makePartial();
        $cache->shouldReceive('bumpGeneration')->never();
        $writer = new ScaleRegistryWriter($cache);
        $this->reject(fn () => $writer->eqIntroBaseline(ScaleRegistryWriter::EQ_INTRO_TARGET), 'identity');
        self::assertSame($before, $this->state());
        self::assertSame($cacheBefore, Cache::store('array')->getStore()->all());
        foreach ([false, true] as $execute) {
            $this->reject(fn () => $writer->updateEqIntro($request, $execute), 'identity');
            self::assertSame($before, $this->state());
            self::assertSame($cacheBefore, Cache::store('array')->getStore()->all());
        }
        foreach ($queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $sql);
        }
    }

    public function test_divergent_missing_and_malformed_authority_is_zero_write(): void
    {
        $request = $this->request();
        DB::table('scales_registry')->where('code', 'EQ_60')->update(['content_i18n_json' => '{}']);
        $before = $this->state();
        $this->reject(fn () => app(ScaleRegistryWriter::class)->updateEqIntro($request), 'differs');
        self::assertSame($before, $this->state());
        foreach (['{}', '{"en":{"why_choose":{"intro":[]}}}'] as $body) {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                DB::table($table)->where('org_id', 0)->where('code', 'EQ_60')->update(['content_i18n_json' => $body]);
            }
            $before = $this->state();
            $this->reject(fn () => app(ScaleRegistryWriter::class)->eqIntroBaseline(ScaleRegistryWriter::EQ_INTRO_TARGET), 'string');
            self::assertSame($before, $this->state());
        }
        DB::table('scales_registry')->where('code', 'EQ_60')->delete();
        $before = $this->state();
        $this->reject(fn () => app(ScaleRegistryWriter::class)->updateEqIntro($request), 'exactly one');
        self::assertSame($before, $this->state());
    }

    public function test_execute_preserves_every_other_value_and_column_and_repeat_is_zero_write(): void
    {
        $request = $this->request();
        $this->leaveTestTransaction();
        $before = $this->state();
        $reader = app(ScaleRegistry::class);
        self::assertSame($request['before'], $reader->getByCode('EQ_EMOTIONAL_INTELLIGENCE', 0)['content_i18n_json']['en']['why_choose']['intro']);
        self::assertSame($request['before'], $reader->getByCode('EQ_60', 0)['content_i18n_json']['en']['why_choose']['intro']);
        foreach (['eq-test', 'emotional-intelligence-test'] as $slug) {
            self::assertSame($request['before'], $reader->lookupBySlug($slug, 0, true)['content_i18n_json']['en']['why_choose']['intro']);
        }
        self::assertSame('APPLIED', app(ScaleRegistryWriter::class)->updateEqIntro($request, true)['status']);
        $after = $this->state();
        self::assertSame($before['slugs'], $after['slugs']);
        self::assertSame($before['generation'] + 1, $after['generation']);
        foreach (['v2', 'legacy'] as $key) {
            $old = $before[$key];
            $new = $after[$key];
            $expected = json_decode($old['content_i18n_json'], true);
            $expected['en']['why_choose']['intro'] = $request['after'];
            self::assertJsonValueSame($expected, json_decode($new['content_i18n_json'], true));
            unset($old['content_i18n_json'], $new['content_i18n_json']);
            self::assertSame($old, $new);
        }
        self::assertSame($request['after'], $reader->getByCode('EQ_EMOTIONAL_INTELLIGENCE', 0)['content_i18n_json']['en']['why_choose']['intro']);
        self::assertSame($request['after'], $reader->getByCode('EQ_60', 0)['content_i18n_json']['en']['why_choose']['intro']);
        foreach (['eq-test', 'emotional-intelligence-test'] as $slug) {
            self::assertSame($request['after'], $reader->lookupBySlug($slug, 0, true)['content_i18n_json']['en']['why_choose']['intro']);
        }
        foreach ([false, true] as $execute) {
            $this->reject(fn () => app(ScaleRegistryWriter::class)->updateEqIntro($request, $execute), 'Stale');
            self::assertSame($after, $this->state());
        }
    }

    public function test_second_table_failure_rolls_back_both_and_does_not_invalidate_cache(): void
    {
        $request = $this->request();
        $this->leaveTestTransaction();
        $before = $this->state();
        DB::statement(DB::connection()->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_eq_intro BEFORE UPDATE ON scales_registry FOR EACH ROW BEGIN IF NEW.code = 'EQ_60' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'second table rejected'; END IF; END"
            : "CREATE TRIGGER reject_eq_intro BEFORE UPDATE ON scales_registry WHEN NEW.code = 'EQ_60' BEGIN SELECT RAISE(ABORT, 'second table rejected'); END");
        try {
            app(ScaleRegistryWriter::class)->updateEqIntro($request, true);
            self::fail('Expected legacy failure.');
        } catch (QueryException $e) {
            self::assertStringContainsString('second table rejected', $e->getMessage());
        } finally {
            DB::statement('DROP TRIGGER reject_eq_intro');
        }
        self::assertSame($before, $this->state());
    }

    public function test_execute_in_outer_transaction_is_rejected_without_writes(): void
    {
        $before = $this->state();
        $this->reject(fn () => app(ScaleRegistryWriter::class)->updateEqIntro($this->request(), true), 'top-level');
        self::assertSame($before, $this->state());
    }

    public function test_cache_failure_reports_committed_partial_closeout_and_body_is_not_reapplied(): void
    {
        $request = $this->request();
        $this->leaveTestTransaction();
        $cache = Mockery::mock(PublicScaleCatalogCache::class)->makePartial();
        $cache->shouldReceive('bumpGeneration')->once()->with(0)->andThrow(new \RuntimeException('cache unavailable'));
        $writer = new ScaleRegistryWriter($cache);
        $result = $writer->updateEqIntro($request, true);
        self::assertSame('PARTIAL_CACHE_CLOSEOUT', $result['status']);
        self::assertSame($result['post_baseline'], $writer->eqIntroBaseline(ScaleRegistryWriter::EQ_INTRO_TARGET)['baseline']);
        $after = $this->state();
        $this->reject(fn () => $writer->updateEqIntro($request, true), 'Stale');
        self::assertSame($after, $this->state());
    }

    public function test_stale_wide_writer_is_rejected_and_other_scale_remains_compatible(): void
    {
        $request = $this->request();
        $oldPayload = $this->state()['v2'];
        unset($oldPayload['id']);
        $this->leaveTestTransaction();
        app(ScaleRegistryWriter::class)->updateEqIntro($request, true);
        $before = $this->state();
        $this->reject(fn () => app(ScaleRegistryWriter::class)->upsertScale($oldPayload), 'restricted');
        self::assertSame($before, $this->state());
        app(ScaleRegistryWriter::class)->upsertScale(['org_id' => 0, 'code' => 'UNRELATED', 'primary_slug' => 'unrelated', 'driver_type' => 'mbti']);
        self::assertDatabaseHas('scales_registry_v2', ['org_id' => 0, 'code' => 'UNRELATED']);
        self::assertSame($before['v2'], $this->state()['v2']);
        self::assertSame($before['legacy'], $this->state()['legacy']);
    }

    public function test_seed_preserves_the_updated_public_intro_before_its_wide_write(): void
    {
        $request = $this->request();
        $this->leaveTestTransaction();
        app(ScaleRegistryWriter::class)->updateEqIntro($request, true);
        $this->artisan('fap:scales:seed-default')->assertExitCode(0);
        foreach (['scales_registry_v2', 'scales_registry'] as $table) {
            $content = json_decode(DB::table($table)->where('org_id', 0)->where('code', 'EQ_60')->value('content_i18n_json'), true);
            self::assertSame($request['after'], $content['en']['why_choose']['intro']);
        }
    }

    public function test_seed_default_preserves_the_reviewed_iq_entry_modules_in_both_tables(): void
    {
        self::assertSame(0, Artisan::call('fap:scales:seed-default', ['--no-interaction' => true]));
        $rows = app(IqPublicEntryPackage::class)->read(base_path(), IqPublicEntryPackage::SHA256);
        $before = [];
        foreach (['scales_registry_v2', 'scales_registry'] as $table) {
            $content = json_decode(DB::table($table)->where('org_id', 0)->where('code', 'IQ_RAVEN')->value('content_i18n_json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($rows as $row) {
                $content[$row['key']] = array_replace($content[$row['key']] ?? [], $row['patch']);
            }
            DB::table($table)->where('org_id', 0)->where('code', 'IQ_RAVEN')->update(['content_i18n_json' => json_encode($content, JSON_THROW_ON_ERROR)]);
            $before[$table] = $content;
        }
        self::assertSame(0, Artisan::call('fap:scales:seed-default', ['--no-interaction' => true]));
        foreach (['scales_registry_v2', 'scales_registry'] as $table) {
            $after = json_decode(DB::table($table)->where('org_id', 0)->where('code', 'IQ_RAVEN')->value('content_i18n_json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($rows as $row) {
                foreach (['landing_copy', 'why_choose', 'faq'] as $leaf) {
                    self::assertJsonValueSame($before[$table][$row['key']][$leaf], $after[$row['key']][$leaf]);
                }
            }
        }
    }

    public function test_actual_row_identity_drift_and_execute_dual_difference_are_zero_write(): void
    {
        $request = $this->request();
        $this->leaveTestTransaction();
        DB::table('scales_registry_v2')->where('id', 3)->update(['primary_slug' => 'wrong-authority']);
        $before = $this->state();
        foreach ([false, true] as $execute) {
            $this->reject(fn () => app(ScaleRegistryWriter::class)->updateEqIntro($request, $execute), 'identity');
            self::assertSame($before, $this->state());
        }
        DB::table('scales_registry_v2')->where('id', 3)->update(['primary_slug' => ScaleRegistryWriter::EQ_INTRO_TARGET['primary_slug']]);
        DB::table('scales_registry')->where('code', 'EQ_60')->update(['content_i18n_json' => '{}']);
        $before = $this->state();
        $this->reject(fn () => app(ScaleRegistryWriter::class)->updateEqIntro($request, true), 'differs');
        self::assertSame($before, $this->state());
    }

    public function test_existing_authenticated_cms_action_plans_without_writing(): void
    {
        $this->authenticate([PermissionNames::ADMIN_CONTENT_READ, PermissionNames::ADMIN_CONTENT_WRITE]);
        $before = $this->state();
        Livewire::test(EditScaleRegistry::class, ['record' => 3])->assertActionVisible('updateEqIntro')
            ->callAction('updateEqIntro', data: ['after' => $this->request()['after']], arguments: ['plan' => true])
            ->assertHasNoActionErrors();
        self::assertSame($before, $this->state());
    }

    public function test_cms_invalid_append_is_visible_validation_error_and_zero_write(): void
    {
        $this->authenticate([PermissionNames::ADMIN_CONTENT_READ, PermissionNames::ADMIN_CONTENT_WRITE]);
        $before = $this->state();
        Livewire::test(EditScaleRegistry::class, ['record' => 3])
            ->callAction('updateEqIntro', data: ['after' => 'Illegal full replacement'])
            ->assertHasActionErrors(['after']);
        self::assertSame($before, $this->state());
    }

    public function test_authenticated_cms_execute_uses_the_same_restricted_contract(): void
    {
        $this->authenticate([PermissionNames::ADMIN_CONTENT_READ, PermissionNames::ADMIN_CONTENT_WRITE]);
        $request = $this->request();
        $this->leaveTestTransaction();
        $before = $this->state();
        Livewire::test(EditScaleRegistry::class, ['record' => 3])
            ->callAction('updateEqIntro', data: ['after' => $request['after']])
            ->assertHasNoActionErrors();
        $after = $this->state();
        self::assertSame($request['after'], json_decode($after['v2']['content_i18n_json'], true)['en']['why_choose']['intro']);
        self::assertSame($before['slugs'], $after['slugs']);
        self::assertSame($before['generation'] + 1, $after['generation']);
    }

    public function test_content_writer_in_other_org_cannot_edit_global_target(): void
    {
        $this->authenticate([PermissionNames::ADMIN_CONTENT_READ, PermissionNames::ADMIN_CONTENT_WRITE]);
        app(OrgContext::class)->set(1, (int) auth('admin')->id(), 'admin');
        $before = $this->state();
        Livewire::test(EditScaleRegistry::class, ['record' => 3])->assertForbidden();
        self::assertSame($before, $this->state());
    }

    public function test_read_only_admin_cannot_access_update(): void
    {
        $this->authenticate([PermissionNames::ADMIN_CONTENT_READ]);
        Livewire::test(EditScaleRegistry::class, ['record' => 3])->assertForbidden();
    }

    private function request(): array
    {
        $baseline = app(ScaleRegistryWriter::class)->eqIntroBaseline(ScaleRegistryWriter::EQ_INTRO_TARGET);

        return [...$baseline, 'after' => $baseline['before']."\n\nSynthetic instrument identity clarification for test only."];
    }

    private function state(): array
    {
        return [
            'v2' => (array) DB::table('scales_registry_v2')->where('id', 3)->first(),
            'legacy' => (array) DB::table('scales_registry')->where('org_id', 0)->where('code', 'EQ_60')->first(),
            'slugs' => DB::table('scale_slugs')->where('org_id', 0)->where('scale_code', 'EQ_60')->orderBy('slug')->get()->map(fn ($row) => (array) $row)->all(),
            'generation' => app(PublicScaleCatalogCache::class)->generation(0),
        ];
    }

    private function leaveTestTransaction(): void
    {
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
        RefreshDatabaseState::$migrated = false;
    }

    private function reject(callable $call, ?string $message = null): void
    {
        try {
            $call();
            self::fail('Expected fail-closed rejection.');
        } catch (\DomainException $e) {
            if ($message !== null) {
                self::assertStringContainsString($message, $e->getMessage());
            } else {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }

    private function authenticate(array $permissions): void
    {
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        $admin = AdminUser::query()->create(['name' => 'Q02 test admin', 'email' => 'q02@example.test', 'password' => bcrypt('test-only'), 'is_active' => 1]);
        $role = Role::query()->create(['name' => 'q02-test-role', 'guard_name' => 'admin']);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name], ['guard_name' => 'admin']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/scale-registries/3/edit', 'GET'));
        app(OrgContext::class)->set(0, (int) $admin->id, 'admin');
    }
}
