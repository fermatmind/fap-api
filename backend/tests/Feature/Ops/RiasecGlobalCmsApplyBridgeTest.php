<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\RiasecGlobalCmsApplyPage;
use App\Models\AdminUser;
use App\Models\LandingSurface;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Ops\RiasecGlobalCmsApplyBridge;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

final class RiasecGlobalCmsApplyBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_DEPLOYED_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TEST_RELEASE_ID = 'standard-test-release';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('admin.totp.enabled', false);
        config()->set('app.riasec_global_cms_test_runtime_revision', self::TEST_DEPLOYED_SHA);
        config()->set('app.riasec_global_cms_test_release_id', self::TEST_RELEASE_ID);
        CarbonImmutable::setTestNow('2026-08-11T04:00:00Z');
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_owner_can_open_bridge_without_an_organization_and_non_owner_cannot(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);

        $this->actingAs($owner, (string) config('admin.guard', 'admin'))
            ->get(route('filament.ops.pages.riasec-global-cms-apply'))
            ->assertOk()
            ->assertSee('RIASEC Global CMS Apply Bridge')
            ->assertSee('Fresh exact operator approval phrase')
            ->assertSee(RiasecGlobalCmsApplyBridge::TARGET_PACKAGE_SHA256);

        $writer = $this->adminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);

        $this->actingAs($writer, (string) config('admin.guard', 'admin'))
            ->get(route('filament.ops.pages.riasec-global-cms-apply'))
            ->assertRedirect('/ops/login');
    }

    public function test_exact_package_preflight_apply_idempotency_and_rollback_are_audited(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();
        $this->actingAs($owner, (string) config('admin.guard', 'admin'));
        $evidence = $this->baselineEvidence();

        $component = Livewire::test(RiasecGlobalCmsApplyPage::class)
            ->set('beforeSnapshotJson', $this->fixture('current_public_readback.json'))
            ->set('targetPackageJson', $this->fixture('target_internal_update.json'))
            ->set('baselineReceiptJson', $evidence['receipt'])
            ->set('landingAndProductFunnelJson', $evidence['landing'])
            ->set('attemptResultFunnelJson', $evidence['funnel'])
            ->set('failureCohortsJson', $evidence['failure'])
            ->set('expectedDeployedSha', self::TEST_DEPLOYED_SHA)
            ->set('expectedReleaseId', self::TEST_RELEASE_ID)
            ->call('preflightExactPackage')
            ->assertSet('receipt.status', 'ready_to_apply');

        $applyPhrase = (string) $component->get('receipt.operator_approval_phrase');
        $component
            ->set('operatorApprovalPhrase', 'not authorized')
            ->call('applyExactPackage')
            ->assertSet('receipt', [])
            ->set('operatorApprovalPhrase', $applyPhrase)
            ->call('applyExactPackage')
            ->assertSet('receipt.status', 'applied');

        $surface = $this->surface();
        $this->assertSame('Free Holland Career Interest Test (RIASEC) | FermatMind', $surface->title);
        $this->assertSame('Free Holland Career Interest Test (RIASEC)', data_get($surface->payload_json, 'h1_or_hero_title'));
        $this->assertTrue((bool) $surface->is_indexable);

        $this->assertDatabaseHas('audit_logs', [
            'org_id' => 0,
            'actor_admin_id' => (int) $owner->id,
            'action' => 'riasec_global_cms_apply',
            'target_type' => 'landing_surface',
            'target_id' => RiasecGlobalCmsApplyBridge::SURFACE_KEY,
            'result' => 'applied',
        ]);
        $applyAudit = DB::table('audit_logs')->where('action', 'riasec_global_cms_apply')->firstOrFail();
        $applyMeta = json_decode((string) $applyAudit->meta_json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::TEST_DEPLOYED_SHA, $applyMeta['deployed_sha'] ?? null);
        $this->assertSame(self::TEST_RELEASE_ID, $applyMeta['release_id'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $applyMeta['preflight_fingerprint'] ?? '');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $applyMeta['operator_approval_phrase_sha256'] ?? '');
        $this->assertSame(hash('sha256', $evidence['receipt']), data_get($applyMeta, 'production_baseline.receipt_sha256'));
        $this->assertSame(self::TEST_DEPLOYED_SHA, data_get($applyMeta, 'production_baseline.active_revision'));
        $this->assertStringNotContainsString('I explicitly approve', (string) $applyAudit->meta_json);

        $component
            ->call('preflightExactPackage')
            ->assertSet('receipt.status', 'already_applied');
        $idempotentApplyPhrase = (string) $component->get('receipt.operator_approval_phrase');
        $component
            ->set('operatorApprovalPhrase', $idempotentApplyPhrase)
            ->call('applyExactPackage')
            ->assertSet('receipt.status', 'already_applied')
            ->call('preflightExactRollback')
            ->assertSet('receipt.status', 'ready_to_rollback');
        $rollbackPhrase = (string) $component->get('receipt.operator_approval_phrase');
        $component
            ->set('operatorApprovalPhrase', $rollbackPhrase)
            ->call('rollbackExactPackage')
            ->assertSet('receipt.status', 'rolled_back');

        $component
            ->call('preflightExactRollback')
            ->assertSet('receipt.status', 'already_rolled_back');
        $idempotentRollbackPhrase = (string) $component->get('receipt.operator_approval_phrase');
        $component
            ->set('operatorApprovalPhrase', $idempotentRollbackPhrase)
            ->call('rollbackExactPackage')
            ->assertSet('receipt.status', 'already_rolled_back');

        $this->assertSame(
            'Free Holland Career Interest Test | RIASEC Full Report',
            $this->surface()->title,
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'riasec_global_cms_rollback',
            'target_id' => RiasecGlobalCmsApplyBridge::SURFACE_KEY,
            'result' => 'rolled_back',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'riasec_global_cms_apply_idempotent',
            'result' => 'already_applied',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'riasec_global_cms_rollback_idempotent',
            'result' => 'already_rolled_back',
        ]);
    }

    public function test_hash_mismatch_and_surface_drift_fail_closed_without_an_audit_write(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();
        $this->setPublicOrgContext($owner);
        $bridge = app(RiasecGlobalCmsApplyBridge::class);
        $evidence = $this->baselineEvidence();

        try {
            $bridge->apply(
                $this->fixture('current_public_readback.json'),
                $this->fixture('target_internal_update.json')."\n",
                $evidence['receipt'],
                $evidence['landing'],
                $evidence['funnel'],
                $evidence['failure'],
                (int) $owner->id,
                self::TEST_DEPLOYED_SHA,
                self::TEST_RELEASE_ID,
                str_repeat('a', 64),
                'not authorized',
            );
            $this->fail('Expected the target package hash mismatch to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Target package SHA-256 mismatch.', $exception->getMessage());
        }

        $preflight = $bridge->preflight(
            $this->fixture('current_public_readback.json'),
            $this->fixture('target_internal_update.json'),
            $evidence['receipt'],
            $evidence['landing'],
            $evidence['funnel'],
            $evidence['failure'],
            (int) $owner->id,
            self::TEST_DEPLOYED_SHA,
            self::TEST_RELEASE_ID,
        );

        $surface = $this->surface();
        $surface->title = 'Unexpected external edit';
        $surface->save();

        try {
            $bridge->apply(
                $this->fixture('current_public_readback.json'),
                $this->fixture('target_internal_update.json'),
                $evidence['receipt'],
                $evidence['landing'],
                $evidence['funnel'],
                $evidence['failure'],
                (int) $owner->id,
                self::TEST_DEPLOYED_SHA,
                self::TEST_RELEASE_ID,
                (string) $preflight['preflight_fingerprint'],
                (string) $preflight['operator_approval_phrase'],
            );
            $this->fail('Expected current surface drift to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Pre-apply surface drift detected. No write was performed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('Unexpected external edit', $this->surface()->title);
    }

    public function test_direct_apply_without_fresh_preflight_and_runtime_drift_fail_closed(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();
        $this->setPublicOrgContext($owner);
        $bridge = app(RiasecGlobalCmsApplyBridge::class);
        $evidence = $this->baselineEvidence();

        try {
            $bridge->apply(
                $this->fixture('current_public_readback.json'),
                $this->fixture('target_internal_update.json'),
                $evidence['receipt'],
                $evidence['landing'],
                $evidence['funnel'],
                $evidence['failure'],
                (int) $owner->id,
                self::TEST_DEPLOYED_SHA,
                self::TEST_RELEASE_ID,
                str_repeat('a', 64),
                'not authorized',
            );
            $this->fail('Expected direct apply without a fresh preflight to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A fresh exact preflight is required before this mutation.', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Active backend REVISION or release identity does not match the authorization.');
        $driftEvidence = $this->baselineEvidence(str_repeat('b', 40));
        $bridge->preflight(
            $this->fixture('current_public_readback.json'),
            $this->fixture('target_internal_update.json'),
            $driftEvidence['receipt'],
            $driftEvidence['landing'],
            $driftEvidence['funnel'],
            $driftEvidence['failure'],
            (int) $owner->id,
            str_repeat('b', 40),
            self::TEST_RELEASE_ID,
        );
    }

    public function test_preflight_authorization_expires_without_a_write(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();
        $this->setPublicOrgContext($owner);
        $bridge = app(RiasecGlobalCmsApplyBridge::class);
        $evidence = $this->baselineEvidence();
        $preflight = $bridge->preflight(
            $this->fixture('current_public_readback.json'),
            $this->fixture('target_internal_update.json'),
            $evidence['receipt'],
            $evidence['landing'],
            $evidence['funnel'],
            $evidence['failure'],
            (int) $owner->id,
            self::TEST_DEPLOYED_SHA,
            self::TEST_RELEASE_ID,
        );

        $this->travel(16)->minutes();

        try {
            $bridge->apply(
                $this->fixture('current_public_readback.json'),
                $this->fixture('target_internal_update.json'),
                $evidence['receipt'],
                $evidence['landing'],
                $evidence['funnel'],
                $evidence['failure'],
                (int) $owner->id,
                self::TEST_DEPLOYED_SHA,
                self::TEST_RELEASE_ID,
                (string) $preflight['preflight_fingerprint'],
                (string) $preflight['operator_approval_phrase'],
            );
            $this->fail('Expected the expired preflight authorization to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fresh exact preflight authorization does not match.', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('Free Holland Career Interest Test | RIASEC Full Report', $this->surface()->title);
    }

    public function test_apply_fails_closed_when_the_baseline_receipt_changes_after_preflight(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();
        $this->setPublicOrgContext($owner);
        $bridge = app(RiasecGlobalCmsApplyBridge::class);
        $evidence = $this->baselineEvidence();
        $preflight = $bridge->preflight(
            $this->fixture('current_public_readback.json'),
            $this->fixture('target_internal_update.json'),
            $evidence['receipt'],
            $evidence['landing'],
            $evidence['funnel'],
            $evidence['failure'],
            (int) $owner->id,
            self::TEST_DEPLOYED_SHA,
            self::TEST_RELEASE_ID,
        );
        $differentEvidence = $this->baselineEvidence(checkedAt: '2026-08-11T03:31:00Z');

        try {
            $bridge->apply(
                $this->fixture('current_public_readback.json'),
                $this->fixture('target_internal_update.json'),
                $differentEvidence['receipt'],
                $differentEvidence['landing'],
                $differentEvidence['funnel'],
                $differentEvidence['failure'],
                (int) $owner->id,
                self::TEST_DEPLOYED_SHA,
                self::TEST_RELEASE_ID,
                (string) $preflight['preflight_fingerprint'],
                (string) $preflight['operator_approval_phrase'],
            );
            $this->fail('Expected baseline receipt drift to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fresh exact preflight authorization does not match.', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('Free Holland Career Interest Test | RIASEC Full Report', $this->surface()->title);
    }

    public function test_tampered_or_stale_baseline_evidence_fails_closed_without_a_write(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();
        $this->setPublicOrgContext($owner);
        $bridge = app(RiasecGlobalCmsApplyBridge::class);
        $evidence = $this->baselineEvidence();

        foreach ([
            array_replace($evidence, ['landing' => $evidence['landing']."\n"]),
            $this->baselineEvidence(checkedAt: '2026-08-11T01:59:59Z'),
        ] as $invalidEvidence) {
            try {
                $bridge->preflight(
                    $this->fixture('current_public_readback.json'),
                    $this->fixture('target_internal_update.json'),
                    $invalidEvidence['receipt'],
                    $invalidEvidence['landing'],
                    $invalidEvidence['funnel'],
                    $invalidEvidence['failure'],
                    (int) $owner->id,
                    self::TEST_DEPLOYED_SHA,
                    self::TEST_RELEASE_ID,
                );
                $this->fail('Expected invalid production baseline evidence to fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Production baseline receipt contract mismatch.', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('Free Holland Career Interest Test | RIASEC Full Report', $this->surface()->title);
    }

    public function test_positive_tenant_context_cannot_use_the_org_zero_bridge(): void
    {
        $owner = $this->adminWithPermissions([PermissionNames::ADMIN_OWNER]);
        $this->seedBeforeSurface();

        $context = app(OrgContext::class);
        $context->set(77, (int) $owner->id, 'admin', null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);
        $evidence = $this->baselineEvidence();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The bridge requires the unselected org-0 Ops authority context.');

        app(RiasecGlobalCmsApplyBridge::class)->preflight(
            $this->fixture('current_public_readback.json'),
            $this->fixture('target_internal_update.json'),
            $evidence['receipt'],
            $evidence['landing'],
            $evidence['funnel'],
            $evidence['failure'],
            (int) $owner->id,
            self::TEST_DEPLOYED_SHA,
            self::TEST_RELEASE_ID,
        );
    }

    private function seedBeforeSurface(): LandingSurface
    {
        $before = json_decode($this->fixture('current_public_readback.json'), true, 512, JSON_THROW_ON_ERROR);
        $surface = $before['surface'];

        return LandingSurface::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'surface_key' => $surface['surface_key'],
            'locale' => $surface['locale'],
            'title' => $surface['title'],
            'description' => $surface['description'],
            'schema_version' => $surface['schema_version'],
            'payload_json' => $surface['payload_json'],
            'status' => $surface['status'],
            'is_public' => $surface['is_public'],
            'is_indexable' => $surface['is_indexable'],
            'published_at' => $surface['published_at'],
            'scheduled_at' => $surface['scheduled_at'],
        ]);
    }

    private function surface(): LandingSurface
    {
        return LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('surface_key', RiasecGlobalCmsApplyBridge::SURFACE_KEY)
            ->where('locale', RiasecGlobalCmsApplyBridge::LOCALE)
            ->firstOrFail();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Seo/RiasecLandingCmsExperiment01/'.$name));
    }

    private function setPublicOrgContext(AdminUser $admin): void
    {
        $context = app(OrgContext::class);
        $context->set(0, (int) $admin->id, 'admin', null, OrgContext::KIND_PUBLIC);
        app()->instance(OrgContext::class, $context);
    }

    /**
     * @return array{receipt:string,landing:string,funnel:string,failure:string}
     */
    private function baselineEvidence(
        string $activeRevision = self::TEST_DEPLOYED_SHA,
        string $releaseId = self::TEST_RELEASE_ID,
        string $checkedAt = '2026-08-11T03:30:00Z',
    ): array {
        $landing = [
            'schema_version' => 'fermatmind.riasec-landing-product-funnel.v1',
            'ok' => true,
            'status' => 'pass',
            'from' => '2026-07-13',
            'to' => '2026-08-09',
            'reporting_timezone' => 'Asia/Shanghai',
            'storage_timezone' => 'UTC',
            'window_utc_start' => '2026-07-12T16:00:00+00:00',
            'window_utc_end_exclusive' => '2026-08-09T16:00:00+00:00',
            'org_id' => 0,
            'authority' => [
                'source_table' => 'events',
                'source_builder' => 'App\\Services\\Analytics\\SeoConversionDailyBuilder',
                'materialized_table_used' => false,
                'unscoped_builder_skipped_rows' => 0,
                'scoped_source_event_count' => 9,
                'scoped_projected_event_count' => 9,
                'scoped_source_reconciliation' => 'exact',
                'matched_source_rows' => 0,
            ],
            'filters' => [
                'reporting_timezone' => 'Asia/Shanghai',
                'storage_timezone' => 'UTC',
                'window_utc_start' => '2026-07-12T16:00:00+00:00',
                'window_utc_end_exclusive' => '2026-08-09T16:00:00+00:00',
                'canonical_path' => '/en/tests/holland-career-interest-test-riasec',
                'take_path' => '/en/tests/holland-career-interest-test-riasec/take',
                'url_identity_policy' => 'root_relative_or_exact_https_fermatmind_origin_then_normalized_path',
                'approved_absolute_origins' => ['https://fermatmind.com'],
                'event_path_attribution' => [
                    'landing_pv' => 'url canonical_path; form_id is not required',
                    'start_test' => 'url take_path or source_url canonical_path',
                    'complete_test' => 'url take_path or source_url canonical_path',
                    'view_result' => 'url canonical_path, url take_path, or source_url canonical_path',
                ],
                'lang' => 'en',
                'scale_id' => 'RIASEC',
                'form_ids' => ['riasec_60', 'riasec_140'],
            ],
            'totals' => ['landing_view' => 3],
            'by_form_code' => [
                'riasec_60' => ['test_start' => 2, 'test_complete' => 1, 'riasec_result_view' => 1],
                'riasec_140' => ['test_start' => 1, 'test_complete' => 1, 'riasec_result_view' => 0],
            ],
            'issues' => [],
            'read_only' => true,
        ];
        $funnel = [
            'schema_version' => 'fermatmind.measurement-funnel.v2',
            'ok' => true,
            'status' => 'pass',
            'issues' => [],
            'from' => '2026-07-13',
            'to' => '2026-08-09',
            'reporting_timezone' => 'Asia/Shanghai',
            'storage_timezone' => 'UTC',
            'window_utc_start' => '2026-07-12T16:00:00+00:00',
            'window_utc_end_exclusive' => '2026-08-09T16:00:00+00:00',
            'org_id' => 0,
            'filters' => [
                'scale_codes' => ['RIASEC'],
                'locales' => ['en'],
                'form_codes' => ['riasec_60', 'riasec_140'],
            ],
            'row_count' => 1,
            'rows' => [[
                'dimensions' => [
                    'scale_code' => 'RIASEC',
                    'form_code' => 'riasec_60',
                    'locale' => 'en',
                ],
                'metrics' => [
                    'attempt_started_count' => 2,
                    'test_completed_count' => 1,
                    'result_ready_count' => 1,
                    'result_ready_event_count' => 1,
                    'result_ready_duplicate_event_count' => 0,
                    'result_ready_event_coverage_status' => 'complete',
                ],
            ]],
            'totals' => [
                'attempt_started_count' => 2,
                'test_completed_count' => 1,
                'result_ready_count' => 1,
                'result_ready_event_count' => 1,
                'result_ready_duplicate_event_count' => 0,
                'result_ready_event_coverage_status' => 'complete',
            ],
            'read_only' => true,
        ];
        $failure = [
            'schema_version' => 'fermatmind.measurement-failure-cohorts.v2',
            'ok' => true,
            'status' => 'pass',
            'issues' => [],
            'from' => '2026-07-13',
            'to' => '2026-08-09',
            'reporting_timezone' => 'Asia/Shanghai',
            'storage_timezone' => 'UTC',
            'window_utc_start' => '2026-07-12T16:00:00+00:00',
            'window_utc_end_exclusive' => '2026-08-09T16:00:00+00:00',
            'org_id' => 0,
            'filters' => [
                'scale_code' => ['RIASEC'],
                'form_code' => ['riasec_60', 'riasec_140'],
                'locale' => ['en'],
                'device_class' => [],
                'browser_class' => [],
                'endpoint_class' => [],
                'status_group' => [],
                'error_class' => [],
            ],
            'cohorts' => [
                'questions_load_failure' => ['failed_attempt_count' => 1],
                'submit_failure' => ['failed_attempt_count' => 2],
            ],
            'read_only' => true,
        ];
        $landingJson = $this->encodeJson($landing);
        $funnelJson = $this->encodeJson($funnel);
        $failureJson = $this->encodeJson($failure);
        $receipt = [
            'schema_version' => 'fermatmind.production-riasec-product-baseline.v1',
            'status' => 'PASS_PRODUCTION_RIASEC_PRODUCT_BASELINE',
            'control_plane_sha' => 'cccccccccccccccccccccccccccccccccccccccc',
            'active_revision' => $activeRevision,
            'release_id' => $releaseId,
            'checked_at' => $checkedAt,
            'failed_check' => null,
            'source_report_sha256' => [
                'landing_and_product_funnel' => hash('sha256', $landingJson),
                'attempt_result_funnel' => hash('sha256', $funnelJson),
                'failure_cohorts' => hash('sha256', $failureJson),
            ],
            'source_health' => [
                'landing_and_product_funnel' => ['ok' => true, 'status' => 'pass', 'issues' => []],
                'attempt_result_funnel' => ['ok' => true, 'status' => 'pass', 'issues' => []],
                'failure_cohorts' => ['ok' => true, 'status' => 'pass', 'issues' => []],
            ],
            'totals' => [
                'landing_view' => 3,
                'test_start_by_form_code' => ['riasec_60' => 2, 'riasec_140' => 1],
                'test_complete_by_form_code' => ['riasec_60' => 1, 'riasec_140' => 1],
                'riasec_result_view_by_form_code' => ['riasec_60' => 1, 'riasec_140' => 0],
                'questions_load_failure' => 1,
                'submit_failure' => 2,
            ],
            'negative_guarantees' => [
                'deploy' => false,
                'migration' => false,
                'database_write' => false,
                'cms_write' => false,
                'cache_write' => false,
                'publication' => false,
                'discoverability_change' => false,
                'queue_action' => false,
                'process_restart' => false,
                'remote_file_write' => false,
                'raw_log_read' => false,
                'search_submit' => false,
            ],
            'writes_committed' => false,
        ];

        return [
            'receipt' => $this->encodeJson($receipt),
            'landing' => $landingJson,
            'funnel' => $funnelJson,
            'failure' => $failureJson,
        ];
    }

    /** @param array<string,mixed> $value */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function adminWithPermissions(array $permissionNames): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'riasec_bridge_'.Str::lower(Str::random(6)),
            'email' => 'riasec_bridge_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'riasec_bridge_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissionNames as $permissionName) {
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
