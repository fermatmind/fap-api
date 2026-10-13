<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ScaleRegistryResource\Pages\EditScaleRegistry;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Services\Scale\PublicScaleCatalogCache;
use App\Services\Scale\ScaleRegistryWriter;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ScaleRegistryFaqEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fap.scales_registry.use_v2', true);
        Config::set('content_packs.public_scale_cache_store', 'array');
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        $admin = AdminUser::query()->create(['name' => 'FAQ editor', 'email' => 'faq@example.test', 'password' => bcrypt('test-only'), 'is_active' => 1]);
        $role = Role::query()->create(['name' => 'faq-editor-test', 'guard_name' => 'admin']);
        foreach ([PermissionNames::ADMIN_CONTENT_READ, PermissionNames::ADMIN_CONTENT_WRITE] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name], ['guard_name' => 'admin']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/scale-registries/10/edit', 'GET'));
        app(OrgContext::class)->set(0, (int) $admin->id, 'admin');
        foreach (['ENNEAGRAM' => 10, 'BIG5_OCEAN' => 1] as $code => $id) {
            $content = json_encode([
                'zh' => ['landing_copy' => 'KEEP', 'faq' => [
                    ['id' => 'faq-one', 'q' => 'Question one', 'a' => 'Answer one'],
                    ['id' => 'faq-target', 'q' => 'Question two', 'a' => 'Before', 'extra' => (object) [], 'weight' => 1.0],
                ]],
                'en' => ['faq' => [['id' => 'faq-en', 'q' => 'English question', 'a' => 'KEEP', 'weight' => 1.0]]],
                'unknown' => ['empty_object' => (object) [], 'empty_list' => [], 'float' => 1.0, 'null' => null],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $slug = str_replace('_', '-', strtolower($code)).'-test';
            $row = ['org_id' => 0, 'code' => $code, 'primary_slug' => $slug, 'slugs_json' => json_encode([$slug]),
                'driver_type' => 'mbti', 'assessment_driver' => 'KEEP', 'default_pack_id' => 'KEEP', 'default_dir_version' => 'v1', 'default_region' => 'GLOBAL', 'default_locale' => 'zh-CN',
                'capabilities_json' => '{}', 'view_policy_json' => '{"public":true}', 'commercial_json' => '{"mode":"KEEP"}',
                'seo_schema_json' => '{}', 'seo_i18n_json' => '{"zh":{"title":"KEEP"}}', 'report_summary_i18n_json' => '{}', 'content_i18n_json' => $content,
                'is_public' => true, 'is_active' => true, 'is_indexable' => true, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00'];
            DB::table('scales_registry')->where('code', $code)->delete();
            DB::table('scales_registry_v2')->where('id', $id)->delete();
            DB::table('scales_registry')->insert($row);
            DB::table('scales_registry_v2')->insert(['id' => $id, ...$row]);
            DB::table('scale_slugs')->where('scale_code', $code)->delete();
            DB::table('scale_slugs')->insert(['org_id' => 0, 'scale_code' => $code, 'slug' => $row['primary_slug'], 'is_primary' => true, 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
        }
        // Exercise the service's real top-level transaction, as in Eq60IntroUpdateTest.
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
        RefreshDatabaseState::$migrated = false;
    }

    public static function targets(): array
    {
        return [[10, 'ENNEAGRAM'], [1, 'BIG5_OCEAN']];
    }

    #[DataProvider('targets')]
    public function test_real_fill_save_preserves_other_json_values_columns_and_slugs(int $id, string $code): void
    {
        $before = $this->state($code);
        $page = Livewire::test(EditScaleRegistry::class, ['record' => $id]);
        $text = $page->get('data.content_i18n_json.zh.faq');
        self::assertIsString($text);
        self::assertStringNotContainsString('[object Object]', $text);
        $faq = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        self::assertIsObject($faq[1]->extra);
        $faq[1]->a = 'After';
        if ($code === 'BIG5_OCEAN') {
            $faq[1]->q = 'Revised question';
        }
        $page->set('data.content_i18n_json.zh.faq', json_encode($faq, JSON_PRESERVE_ZERO_FRACTION))->call('save', false)->assertHasNoFormErrors();
        self::assertSame('APPLIED', $page->get('faqSaveReceipt.status'));
        $after = $this->state($code);
        self::assertSame($before['slugs'], $after['slugs']);
        foreach (['v2', 'legacy'] as $table) {
            $old = $before[$table];
            $new = $after[$table];
            $expected = json_decode($old['content_i18n_json']);
            $expected->zh->faq = $faq;
            $actual = json_decode($new['content_i18n_json']);
            self::assertEquals($expected, $actual);
            self::assertIsObject($actual->unknown->empty_object);
            self::assertIsArray($actual->unknown->empty_list);
            self::assertIsFloat($actual->unknown->float);
            self::assertIsObject($actual->zh->faq[1]->extra);
            self::assertIsFloat($actual->zh->faq[1]->weight);
            self::assertIsFloat($actual->en->faq[0]->weight);
            unset($old['content_i18n_json'], $new['content_i18n_json']);
            self::assertSame($old, $new);
        }
        $audit = DB::table('audit_logs')->where('id', $page->get('faqSaveReceipt.audit_id'))->first();
        self::assertSame('content_applied', $audit->result);
        $meta = json_decode($audit->meta_json, true);
        self::assertSame($page->get('faqSaveReceipt.candidate_sha256'), $meta['candidate_sha256']);
        self::assertTrue($meta['protected_fields_preserved']);
        $auditCount = DB::table('audit_logs')->where('action', 'scale_registry.faq.updated')->count();
        $page->call('save', false)->assertHasNoFormErrors();
        self::assertSame('UNCHANGED', $page->get('faqSaveReceipt.status'));
        self::assertSame($after, $this->state($code));
        self::assertSame($auditCount, DB::table('audit_logs')->where('action', 'scale_registry.faq.updated')->count());
    }

    public static function invalidFaqs(): array
    {
        return [['{bad'], ['{}'], ['[1]'], ['[{"id":"x","q":"q","a":"a"},{"id":"x","q":"q","a":"b"}]']];
    }

    #[DataProvider('invalidFaqs')]
    public function test_invalid_json_or_duplicate_ids_are_visible_zero_write_errors(string $text): void
    {
        $before = $this->state();
        Livewire::test(EditScaleRegistry::class, ['record' => 10])->set('data.content_i18n_json.zh.faq', $text)->call('save', false)
            ->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame($before, $this->state());
    }

    public function test_combined_form_changes_do_not_fall_back_to_wide_upsert(): void
    {
        $page = $this->changedPage();
        $before = $this->state();
        $page->set('data.content_i18n_json.zh.landing_copy', 'Forbidden simultaneous change')->call('save', false)
            ->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame($before, $this->state());
    }

    public function test_stale_baseline_is_not_overwritten(): void
    {
        $page = $this->changedPage();
        DB::table('scales_registry_v2')->where('id', 10)->update(['updated_at' => '2026-10-02 00:00:00']);
        $before = $this->state();
        $page->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame($before, $this->state());
    }

    public function test_baseline_cannot_be_changed_by_the_client(): void
    {
        $page = Livewire::test(EditScaleRegistry::class, ['record' => 10]);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $page->set('faqEditBaseline.baseline.v2', str_repeat('0', 64));
    }

    public function test_more_than_one_changed_faq_is_rejected(): void
    {
        $page = $this->changedPage();
        $faq = json_decode($page->get('data.content_i18n_json.zh.faq'));
        $faq[0]->a = 'Second edit';
        $before = $this->state();
        $page->set('data.content_i18n_json.zh.faq', json_encode($faq))->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame($before, $this->state());
    }

    public function test_current_org_and_permission_are_rechecked(): void
    {
        $page = $this->changedPage();
        $context = [...$page->get('faqEditBaseline'), 'form_before_json' => $page->get('faqFormBaselineJson')];
        $payload = $this->baselineForm($page);
        $payload['content_i18n_json']['zh']['faq'][1]->a = 'After';
        app(OrgContext::class)->set(1, (int) auth('admin')->id(), 'admin');
        $before = $this->state();
        try {
            app(ScaleRegistryWriter::class)->upsertScale($payload, $context);
            self::fail('Expected scope rejection.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('write scope', $e->getMessage());
        }
        self::assertSame($before, $this->state());
    }

    public function test_audit_silently_not_written_rolls_back_content(): void
    {
        $page = $this->changedPage();
        $before = $this->state();
        app()->instance(AuditLogger::class, Mockery::mock(AuditLogger::class)->shouldReceive('log')->once()->andReturnNull()->getMock());
        $page->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame($before, $this->state());
    }

    public function test_old_form_without_trusted_baseline_cannot_fall_back_to_wide_save(): void
    {
        $before = $this->state();
        $page = Livewire::test(LegacyFaqEditor::class, ['record' => 10]);
        $faq = json_decode($page->get('data.content_i18n_json.zh.faq'));
        $faq[1]->a = 'After';
        $page->set('data.content_i18n_json.zh.faq', json_encode($faq))->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame($before, $this->state());
    }

    public function test_read_only_admin_cannot_use_the_precision_branch(): void
    {
        $page = $this->changedPage();
        $context = [...$page->get('faqEditBaseline'), 'form_before_json' => $page->get('faqFormBaselineJson')];
        $payload = $this->baselineForm($page);
        $payload['content_i18n_json']['zh']['faq'][1]->a = 'After';
        $reader = AdminUser::query()->create(['name' => 'FAQ reader', 'email' => 'reader@example.test', 'password' => bcrypt('test-only'), 'is_active' => 1]);
        $readRole = Role::query()->create(['name' => 'faq-reader-test', 'guard_name' => 'admin']);
        $readRole->permissions()->attach(Permission::where('name', PermissionNames::ADMIN_CONTENT_READ)->firstOrFail()->id);
        $reader->roles()->attach($readRole->id);
        $this->actingAs($reader, 'admin');
        app(OrgContext::class)->set(0, (int) $reader->id, 'admin');
        $before = $this->state();
        Livewire::test(EditScaleRegistry::class, ['record' => 10])->assertForbidden();
        try {
            app(ScaleRegistryWriter::class)->upsertScale($payload, $context);
            self::fail('Expected permission rejection.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('write scope', $e->getMessage());
        }
        self::assertSame($before, $this->state());
    }

    public function test_deleted_authority_row_is_not_recreated(): void
    {
        $page = $this->changedPage();
        DB::table('scales_registry')->where('org_id', 0)->where('code', 'ENNEAGRAM')->delete();
        $before = $this->state();
        $page->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame($before, $this->state());
    }

    public function test_faq_ids_order_and_count_cannot_change(): void
    {
        foreach (['id', 'order', 'count'] as $change) {
            $page = $this->changedPage();
            $faq = json_decode($page->get('data.content_i18n_json.zh.faq'));
            if ($change === 'id') {
                $faq[1]->id = 'new-id';
            } elseif ($change === 'order') {
                $faq = array_reverse($faq);
            } else {
                array_pop($faq);
            }
            $before = $this->state();
            $page->set('data.content_i18n_json.zh.faq', json_encode($faq))->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
            self::assertSame($before, $this->state());
        }
    }

    public function test_page_owns_commit_even_when_panel_enables_outer_transactions(): void
    {
        Filament::getCurrentPanel()->databaseTransactions();
        $page = $this->changedPage();
        $page->call('save', false)->assertHasNoFormErrors();
        self::assertSame('APPLIED', $page->get('faqSaveReceipt.status'));
        self::assertSame(0, DB::transactionLevel());
    }

    public function test_audit_insert_exception_rolls_back_content(): void
    {
        $page = $this->changedPage();
        DB::statement(DB::connection()->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_faq_audit BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit rejected'; END"
            : "CREATE TRIGGER reject_faq_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit rejected'); END");
        $before = $this->state();
        try {
            $page->call('save', false);
            self::fail('Expected audit failure.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame($before, $this->state());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_faq_audit');
        }
    }

    public function test_protected_column_readback_failure_rolls_back_content(): void
    {
        $page = $this->changedPage();
        DB::statement(DB::connection()->getDriverName() === 'mysql'
            ? "CREATE TRIGGER mutate_faq_flag BEFORE UPDATE ON scales_registry_v2 FOR EACH ROW BEGIN IF NEW.code = 'ENNEAGRAM' THEN SET NEW.is_indexable = 0; END IF; END"
            : "CREATE TRIGGER mutate_faq_flag AFTER UPDATE ON scales_registry_v2 WHEN NEW.code = 'ENNEAGRAM' BEGIN UPDATE scales_registry_v2 SET is_indexable = 0 WHERE id = NEW.id; END");
        $before = $this->state();
        try {
            $page->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
            self::assertSame($before, $this->state());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS mutate_faq_flag');
        }
    }

    public function test_second_table_failure_rolls_back_both_rows_and_audit(): void
    {
        $page = $this->changedPage();
        DB::statement(DB::connection()->getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_faq_legacy BEFORE UPDATE ON scales_registry FOR EACH ROW BEGIN IF NEW.code = 'ENNEAGRAM' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'second table rejected'; END IF; END"
            : "CREATE TRIGGER reject_faq_legacy BEFORE UPDATE ON scales_registry WHEN NEW.code = 'ENNEAGRAM' BEGIN SELECT RAISE(ABORT, 'second table rejected'); END");
        $before = $this->state();
        try {
            $page->call('save', false);
            self::fail('Expected second-table failure.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame($before, $this->state());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'scale_registry.faq.updated')->count());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_faq_legacy');
        }
    }

    public function test_cache_failure_keeps_committed_body_and_repeat_retries_only_cache(): void
    {
        $page = $this->changedPage();
        $cache = Mockery::mock(PublicScaleCatalogCache::class)->makePartial();
        $cache->shouldReceive('bumpGeneration')->once()->andThrow(new \RuntimeException('cache unavailable'));
        app()->instance(ScaleRegistryWriter::class, new ScaleRegistryWriter($cache));
        $page->call('save', false)->assertHasNoFormErrors();
        self::assertSame('PARTIAL_CACHE_CLOSEOUT', $page->get('faqSaveReceipt.status'));
        $after = $this->state();
        $auditId = $page->get('faqSaveReceipt.audit_id');
        self::assertSame('After', json_decode($after['v2']['content_i18n_json'])->zh->faq[1]->a);
        app()->forgetInstance(ScaleRegistryWriter::class);
        $page->call('save', false)->assertHasNoFormErrors();
        self::assertSame('APPLIED', $page->get('faqSaveReceipt.status'));
        self::assertSame($auditId, $page->get('faqSaveReceipt.audit_id'));
        self::assertSame($after, $this->state());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'scale_registry.faq.updated')->count());
    }

    public function test_no_change_save_preserves_faq_floats_across_livewire_snapshot(): void
    {
        $before = $this->state();
        $page = Livewire::test(EditScaleRegistry::class, ['record' => 10]);
        $page->call('save', false)->assertHasNoFormErrors();
        self::assertSame('UNCHANGED', $page->get('faqSaveReceipt.status'));
        self::assertSame($before, $this->state());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'scale_registry.faq.updated')->count());
    }

    public function test_cache_retry_rejects_concurrent_authority_change_without_body_write(): void
    {
        $page = $this->cacheFailedPage();
        DB::table('scales_registry_v2')->where('id', 10)->update(['updated_at' => '2026-10-03 00:00:00']);
        $before = $this->state();
        $page->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame('PARTIAL_CACHE_CLOSEOUT', $page->get('faqSaveReceipt.status'));
        self::assertSame($before, $this->state());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'scale_registry.faq.updated')->count());
    }

    public function test_cache_retry_rejects_changed_audit_binding(): void
    {
        $page = $this->cacheFailedPage();
        $id = $page->get('faqSaveReceipt.audit_id');
        $audit = DB::table('audit_logs')->where('id', $id)->first();
        $meta = json_decode($audit->meta_json, true);
        $meta['candidate_sha256'] = str_repeat('0', 64);
        DB::table('audit_logs')->where('id', $id)->update(['meta_json' => json_encode($meta)]);
        $before = $this->state();
        $page->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame('PARTIAL_CACHE_CLOSEOUT', $page->get('faqSaveReceipt.status'));
        self::assertSame($before, $this->state());
    }

    public function test_faq_then_ordinary_edit_refreshes_baseline_and_clears_receipt(): void
    {
        $page = $this->changedPage();
        $page->call('save', false)->assertHasNoFormErrors();
        $firstAudit = $page->get('faqSaveReceipt.audit_id');
        $page->set('data.seo_i18n_json.zh.title', 'Ordinary title')->call('save', false)->assertHasNoFormErrors();
        self::assertSame([], $page->get('faqSaveReceipt'));
        $faq = json_decode($page->get('data.content_i18n_json.zh.faq'));
        $faq[1]->a = 'After ordinary edit';
        $page->set('data.content_i18n_json.zh.faq', json_encode($faq, JSON_PRESERVE_ZERO_FRACTION))->call('save', false)->assertHasNoFormErrors();
        self::assertSame('APPLIED', $page->get('faqSaveReceipt.status'));
        self::assertNotSame($firstAudit, $page->get('faqSaveReceipt.audit_id'));
        self::assertSame('Ordinary title', json_decode($this->state()['v2']['seo_i18n_json'])->zh->title);
    }

    public function test_cache_failure_then_ordinary_save_does_not_reuse_old_receipt(): void
    {
        $page = $this->cacheFailedPage();
        $page->set('data.seo_i18n_json.zh.title', 'Ordinary title')->call('save', false)->assertHasNoFormErrors();
        self::assertSame([], $page->get('faqSaveReceipt'));
        self::assertNotEmpty($page->get('faqEditBaseline'));
    }

    public function test_reloaded_page_does_not_claim_previous_cache_operation_completed(): void
    {
        $this->cacheFailedPage();
        $before = $this->state();
        $page = Livewire::test(EditScaleRegistry::class, ['record' => 10]);
        $page->call('save', false)->assertHasNoFormErrors();
        self::assertSame('UNCHANGED', $page->get('faqSaveReceipt.status'));
        self::assertArrayNotHasKey('audit_id', $page->get('faqSaveReceipt'));
        self::assertSame($before, $this->state());
    }

    public function test_org_local_v2_only_ordinary_edit_succeeds_but_faq_edit_is_rejected(): void
    {
        $globalBefore = $this->state();
        $row = $globalBefore['v2'];
        $row['id'] = 20;
        $row['org_id'] = 1;
        DB::table('scales_registry_v2')->insert($row);
        app(OrgContext::class)->set(1, (int) auth('admin')->id(), 'admin');
        $page = Livewire::test(EditScaleRegistry::class, ['record' => 20]);
        self::assertSame([], $page->get('faqEditBaseline'));
        $page->set('data.seo_i18n_json.zh.title', 'Org-local title')->call('save', false)->assertHasNoFormErrors();
        self::assertSame('Org-local title', json_decode(DB::table('scales_registry_v2')->where('id', 20)->value('seo_i18n_json'))->zh->title);
        self::assertSame($globalBefore, $this->state());
        self::assertSame(0, DB::table('scales_registry')->where('org_id', 1)->where('code', 'ENNEAGRAM')->count());
        $before = (array) DB::table('scales_registry_v2')->where('id', 20)->first();
        $faq = json_decode($page->get('data.content_i18n_json.zh.faq'));
        $faq[1]->a = 'Rejected local FAQ';
        $page->set('data.content_i18n_json.zh.faq', json_encode($faq, JSON_PRESERVE_ZERO_FRACTION))->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame($before, (array) DB::table('scales_registry_v2')->where('id', 20)->first());
    }

    public function test_missing_legacy_table_keeps_ordinary_edit_but_rejects_faq_edit(): void
    {
        Schema::drop('scales_registry');
        $page = Livewire::test(EditScaleRegistry::class, ['record' => 10]);
        self::assertSame([], $page->get('faqEditBaseline'));
        $page->set('data.seo_i18n_json.zh.title', 'V2-only title')->call('save', false)->assertHasNoFormErrors();
        self::assertSame('V2-only title', json_decode(DB::table('scales_registry_v2')->where('id', 10)->value('seo_i18n_json'))->zh->title);
        $before = (array) DB::table('scales_registry_v2')->where('id', 10)->first();
        $faq = json_decode($page->get('data.content_i18n_json.zh.faq'));
        $faq[1]->a = 'Rejected without legacy table';
        $page->set('data.content_i18n_json.zh.faq', json_encode($faq, JSON_PRESERVE_ZERO_FRACTION))->call('save', false)->assertHasFormErrors(['content_i18n_json.zh.faq']);
        self::assertSame($before, (array) DB::table('scales_registry_v2')->where('id', 10)->first());
    }

    public function test_legacy_query_dependency_error_is_not_treated_as_missing_legacy_table(): void
    {
        Schema::drop('scales_registry');
        DB::unprepared('CREATE VIEW scales_registry AS SELECT * FROM missing_registry_dependency');
        try {
            Livewire::test(EditScaleRegistry::class, ['record' => 10]);
            self::fail('A missing view dependency must not be swallowed');
        } catch (ViewException $exception) {
            $cause = $exception;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
                if ($cause instanceof QueryException) {
                    self::assertSame('no such table: main.missing_registry_dependency', $cause->errorInfo[2]);

                    return;
                }
            }
            self::fail('The rendering exception must retain its database cause');
        }
    }

    public static function mysqlLegacyErrors(): array
    {
        return [
            'exact prefixed legacy table' => ['42S02', 1146, "Table 'faq_fixture.prefix_scales_registry' doesn't exist", true],
            'other missing table' => ['42S02', 1146, "Table 'faq_fixture.missing_dependency' doesn't exist", false],
            'invalid view' => ['HY000', 1356, "View 'faq_fixture.prefix_scales_registry' references invalid table(s)", false],
        ];
    }

    #[DataProvider('mysqlLegacyErrors')]
    public function test_mysql_legacy_error_boundary(string $state, int $code, string $message, bool $missingLegacy): void
    {
        $pdoError = new \PDOException($message);
        $pdoError->errorInfo = [$state, $code, $message];
        $error = new QueryException('mysql-legacy-fixture', 'select * from scales_registry', [], $pdoError);
        $connection = new MySqlConnection(null, 'faq_fixture', 'prefix_', ['driver' => 'mysql']);
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('where')->with('org_id', 0)->once()->andReturnSelf();
        $builder->shouldReceive('where')->with('code', 'ENNEAGRAM')->once()->andReturnSelf();
        $builder->shouldReceive('get')->once()->andThrow($error);
        $realManager = DB::getFacadeRoot();
        $manager = Mockery::mock($realManager);
        $manager->shouldReceive('table')->withAnyArgs()->andReturnUsing(fn ($table) => $realManager->table($table))->byDefault();
        $manager->shouldReceive('connection')->withAnyArgs()->andReturnUsing(fn ($name = null) => $realManager->connection($name))->byDefault();
        DB::swap($manager);
        $manager->shouldReceive('table')->with('scales_registry')->andReturn($builder);
        $manager->shouldReceive('connection')->with('mysql-legacy-fixture')->andReturn($connection);
        try {
            $page = Livewire::test(EditScaleRegistry::class, ['record' => 10]);
            self::assertTrue($missingLegacy, 'Other database errors must reject editor mount');
            self::assertSame([], $page->get('faqEditBaseline'));
        } catch (ViewException $outer) {
            self::assertFalse($missingLegacy, $outer->getMessage());
            $cause = $outer;
            while ($cause instanceof ViewException) {
                $cause = $cause->getPrevious();
            }
            self::assertSame($error, $cause);
            self::assertSame($pdoError, $cause->getPrevious());
            self::assertSame([$state, $code, $message], $cause->errorInfo);
        } finally {
            DB::swap($realManager);
        }
    }

    private function cacheFailedPage(): \Livewire\Features\SupportTesting\Testable
    {
        $page = $this->changedPage();
        $cache = Mockery::mock(PublicScaleCatalogCache::class)->makePartial();
        $cache->shouldReceive('bumpGeneration')->once()->andThrow(new \RuntimeException('cache unavailable'));
        app()->instance(ScaleRegistryWriter::class, new ScaleRegistryWriter($cache));
        $page->call('save', false)->assertHasNoFormErrors();
        self::assertSame('PARTIAL_CACHE_CLOSEOUT', $page->get('faqSaveReceipt.status'));
        app()->forgetInstance(ScaleRegistryWriter::class);

        return $page;
    }

    private function baselineForm(\Livewire\Features\SupportTesting\Testable $page): array
    {
        $json = $page->get('faqFormBaselineJson');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $objects = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        foreach (['en', 'zh'] as $locale) {
            $data['content_i18n_json'][$locale]['faq'] = $objects->content_i18n_json->{$locale}->faq;
        }

        return $data;
    }

    private function changedPage(): \Livewire\Features\SupportTesting\Testable
    {
        $page = Livewire::test(EditScaleRegistry::class, ['record' => 10]);
        $faq = json_decode($page->get('data.content_i18n_json.zh.faq'));
        $faq[1]->a = 'After';

        return $page->set('data.content_i18n_json.zh.faq', json_encode($faq, JSON_PRESERVE_ZERO_FRACTION));
    }

    private function state(string $code = 'ENNEAGRAM'): array
    {
        return ['v2' => (array) DB::table('scales_registry_v2')->where('org_id', 0)->where('code', $code)->first(),
            'legacy' => (array) DB::table('scales_registry')->where('org_id', 0)->where('code', $code)->first(),
            'slugs' => DB::table('scale_slugs')->where('org_id', 0)->where('scale_code', $code)->orderBy('id')->get()->map(fn ($x) => (array) $x)->all()];
    }
}

// Simulates an already-open pre-deployment form that has no new Locked snapshot.
class LegacyFaqEditor extends EditScaleRegistry
{
    protected function afterFill(): void {}
}
