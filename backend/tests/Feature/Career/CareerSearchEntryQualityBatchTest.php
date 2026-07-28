<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionCoverageSnapshot;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Http\Controllers\API\V0_5\Career\CareerJobDetailController;
use App\Models\CareerSearchEntryQualityBatchOperation;
use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Career\Review\CareerJobDetailReaderSafeReviewProjector;
use App\Services\Career\Review\CareerPilotReviewEvidenceBridge;
use App\Services\Career\Review\CareerSearchEntryQualityBatchControlService;
use App\Services\Career\Review\CareerSearchEntryQualityBatchManifestReader;
use App\Services\Career\Review\CareerSearchEntryQualityBatchPlanner;
use App\Services\Career\Review\CareerSearchEntryQualityEvaluator;
use App\Services\Career\Review\CareerSearchEntryTierResolver;
use App\Services\ReviewGovernance\CareerSeoReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerSearchEntryQualityBatchTest extends TestCase
{
    use RefreshDatabase;

    private CareerSearchEntryQualityBatchManifestReader $manifestReader;

    private PublicCareerAuthorityResponseCache $responseCache;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(defaultItemPublished: true),
        );
        $this->manifestReader = app(CareerSearchEntryQualityBatchManifestReader::class);
        $this->responseCache = app(PublicCareerAuthorityResponseCache::class);
        $this->publishCompleteBatch();
    }

    public function test_manifest_locks_exact_fifty_non_held_candidates(): void
    {
        $manifest = $this->manifestReader->read();

        $this->assertSame(50, $manifest['expected_candidate_count']);
        $this->assertSame(50, $manifest['max_candidate_count']);
        $this->assertCount(50, $manifest['candidates']);
        $this->assertSame(range(1, 50), array_column($manifest['candidates'], 'pool_rank'));
        $this->assertSame(
            CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE,
            $manifest['content_quality_tier'],
        );
        $this->assertSame(
            [],
            array_values(array_intersect(
                array_column($manifest['candidates'], 'canonical_slug'),
                CareerDirectoryAuthorityService::excludedSlugs(),
            )),
        );
    }

    public function test_manifest_rejects_more_than_fifty_candidates(): void
    {
        $manifest = $this->manifestReader->read();
        $manifest['expected_candidate_count'] = 51;
        $manifest['candidates'][] = [
            'pool_rank' => 51,
            'canonical_slug' => 'unbounded-candidate',
            'expected_publish_track' => 'candidate',
        ];
        $path = storage_path('framework/testing/career-search-entry-quality-batch-overflow.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('count boundary');
            $this->manifestReader->read($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_manifest_rejects_a_synchronously_truncated_cohort(): void
    {
        $manifest = $this->manifestReader->read();
        $manifest['expected_candidate_count'] = 49;
        array_pop($manifest['candidates']);
        $path = storage_path('framework/testing/career-search-entry-quality-batch-truncated.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('count boundary');
            $this->manifestReader->read($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_dry_run_is_deterministic_bilingual_bounded_and_zero_write(): void
    {
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $before = $this->cacheEvidenceSha();
        $planner = app(CareerSearchEntryQualityBatchPlanner::class);

        $first = $planner->build();
        $second = $planner->build();
        $verified = $planner->verify($first);

        $this->assertSame($first, $second);
        $this->assertSame($first, $verified);
        $this->assertSame(50, $first['candidate_count']);
        $this->assertSame(100, $first['bilingual_url_count']);
        $this->assertSame(300, $first['target_count']);
        $this->assertCount(50, $first['slugs']);
        $this->assertCount(100, $first['canonical_urls']);
        $this->assertSame(64, strlen($first['package_sha256']));
        $this->assertSame(64, strlen($first['target_set_sha256']));
        $this->assertSame(64, strlen($first['quality_package_sha256']));
        $this->assertSame(range(1, 50), array_column($first['candidates'], 'selection_rank'));
        $this->assertSame(
            ['stable', 'stable', 'stable', 'stable'],
            array_slice(array_column($first['candidates'], 'publish_track'), 0, 4),
        );
        $this->assertNotContains('stable', array_slice(array_column($first['candidates'], 'publish_track'), 4));

        foreach ($first['candidates'] as $candidate) {
            $this->assertSame([], $candidate['blockers']);
            $this->assertSame(
                CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE,
                $candidate['content_quality_tier'],
            );
            $this->assertSame('ineligible', $candidate['search_entry_tier']);
            $this->assertContains($candidate['target_search_entry_tier'], ['stable', 'approved_candidate']);
            $this->assertSame('awaiting_exact_approved_all_binding', $candidate['review_state']);
            $this->assertCount(6, $candidate['review_targets']);
            $this->assertCount(6, $candidate['review_target_sha256_by_identity']);
            foreach (['en', 'zh-CN'] as $locale) {
                $evidence = $candidate['locales'][$locale];
                $this->assertGreaterThanOrEqual(500, $evidence['visible_character_count']);
                $this->assertNotEmpty($evidence['source_references']);
                $this->assertNotEmpty($evidence['claim_boundary']);
                $this->assertGreaterThan(0, $evidence['faq_count']);
                $this->assertNotEmpty($evidence['internal_links']);
                $this->assertMatchesRegularExpression(
                    '/^[a-f0-9]{64}$/',
                    $candidate['current_content_sha256_by_locale'][$locale],
                );
                $this->assertMatchesRegularExpression(
                    '/^[a-f0-9]{64}$/',
                    $candidate['current_seo_sha256_by_locale'][$locale],
                );
                $this->assertSame(
                    $candidate['review_target_sha256_by_identity'][
                        "career-job:{$candidate['canonical_slug']}:{$locale}:content"
                    ],
                    $candidate['current_content_sha256_by_locale'][$locale],
                );
                $this->assertSame(
                    $candidate['review_target_sha256_by_identity'][
                        "career-job:{$candidate['canonical_slug']}:{$locale}:seo"
                    ],
                    $candidate['current_seo_sha256_by_locale'][$locale],
                );
            }
        }

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->cacheEvidenceSha());
        $this->assertSame(array_fill_keys(array_keys($first['negative_guarantees']), 0), $first['negative_guarantees']);
        Queue::assertNothingPushed();
    }

    public function test_command_build_and_exact_verification_are_idempotent(): void
    {
        $this->assertSame(0, Artisan::call('career:build-search-entry-quality-batch', ['--json' => true]));
        $first = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS_CAREER_SEARCH_ENTRY_QUALITY_BATCH', $first['status']);

        $path = storage_path('framework/testing/career-search-entry-quality-batch.json');
        file_put_contents($path, json_encode($first, JSON_THROW_ON_ERROR));
        try {
            $this->assertSame(0, Artisan::call('career:build-search-entry-quality-batch', [
                '--expected-package' => $path,
                '--json' => true,
            ]));
            $verified = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($verified['expected_package_verified']);
            $this->assertSame($first['package_sha256'], $verified['package_sha256']);
            $this->assertSame($first['target_set_sha256'], $verified['target_set_sha256']);
            $this->assertSame($first['quality_package_sha256'], $verified['quality_package_sha256']);

            $tampered = $first;
            $tampered['candidates'][0]['locales']['en']['visible_character_count']++;
            file_put_contents($path, json_encode($tampered, JSON_THROW_ON_ERROR));
            $this->assertSame(1, Artisan::call('career:build-search-entry-quality-batch', [
                '--expected-package' => $path,
                '--json' => true,
            ]));
            $rejected = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('HOLD_CAREER_SEARCH_ENTRY_QUALITY_BATCH', $rejected['status']);
        } finally {
            @unlink($path);
        }
    }

    public function test_command_human_readable_hold_includes_actionable_error(): void
    {
        $path = storage_path('framework/testing/missing-career-search-entry-quality-batch.json');
        @unlink($path);

        $this->assertSame(1, Artisan::call('career:build-search-entry-quality-batch', [
            '--expected-package' => $path,
        ]));
        $output = Artisan::output();

        $this->assertStringContainsString('status=HOLD_CAREER_SEARCH_ENTRY_QUALITY_BATCH', $output);
        $this->assertStringContainsString(
            'error=Expected Career quality package path is invalid.',
            $output,
        );
    }

    public function test_control_commands_keep_review_and_apply_as_separate_exact_transitions(): void
    {
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        $path = storage_path('framework/testing/career-search-entry-quality-command-control.json');
        file_put_contents(
            $path,
            json_encode(app(CareerSearchEntryQualityBatchPlanner::class)->build(), JSON_THROW_ON_ERROR),
        );

        try {
            $reviewBase = [
                '--expected-package' => $path,
                '--actor-admin-user-id' => 1,
                '--json' => true,
            ];
            $this->assertSame(0, Artisan::call(
                'career:review-search-entry-quality-batch',
                $reviewBase,
            ));
            $reviewPreflight = json_decode(
                trim(Artisan::output()),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $this->assertSame('PASS_REVIEW_PREFLIGHT', $reviewPreflight['status']);
            $this->assertDatabaseCount('career_search_entry_quality_batch_operations', 0);

            $this->assertSame(0, Artisan::call(
                'career:review-search-entry-quality-batch',
                [...$reviewBase, '--bind' => true],
            ));
            $review = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('PASS_REVIEW_BOUND', $review['status']);

            $operationBase = [
                '--expected-package' => $path,
                '--active-release-sha' => str_repeat('d', 40),
                '--active-release-name' => 'career-search-entry-batch-command',
                '--operation-id' => 'CAREER-SEARCH-ENTRY-BATCH-01-APPLY:command',
                '--rollback-identifier' => 'career-search-entry-batch-01:command',
                '--actor-admin-user-id' => 1,
                '--expected-review-evidence-sha256' => $review['review_evidence_sha256'],
                '--json' => true,
            ];
            foreach ([
                'preflight' => 'PASS_APPLY_PREFLIGHT',
                'apply' => 'PASS_APPLY_COMMITTED',
                'readback' => 'PASS_APPLY_READBACK',
            ] as $mode => $status) {
                $this->assertSame(0, Artisan::call(
                    'career:control-search-entry-quality-batch',
                    [...$operationBase, '--mode' => $mode],
                ));
                $receipt = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($status, $receipt['status']);
            }
            $this->assertDatabaseCount('career_search_entry_quality_batch_operations', 1);
        } finally {
            @unlink($path);
        }
    }

    public function test_review_targets_bind_reader_safe_payload_and_controller_contract(): void
    {
        $projector = app(CareerJobDetailReaderSafeReviewProjector::class);
        $controllerReflection = new \ReflectionClass(CareerJobDetailController::class);
        $internalKeys = $controllerReflection->getConstant('INTERNAL_READER_PAYLOAD_KEYS');
        $replacements = $controllerReflection->getConstant('RAW_READER_PAYLOAD_VALUE_REPLACEMENTS');
        $this->assertIsArray($internalKeys);
        $this->assertIsArray($replacements);
        $payload = [
            ...array_fill_keys($internalKeys, 'private'),
            'summary' => implode(' | ', array_keys($replacements)),
            'nested' => ['row_hash' => 'private', 'label' => 'raw enum'],
        ];
        $projected = $projector->project($payload);
        $controllerProjection = new \ReflectionMethod(CareerJobDetailController::class, 'projectReaderSafePayload');
        $expected = $controllerProjection->invoke(app(CareerJobDetailController::class), $payload);

        $this->assertArrayNotHasKey('source_id', $projected);
        $this->assertArrayNotHasKey('row_hash', $projected['nested']);
        $this->assertSame($expected, $projected);
        $expectedContractSha = hash_file(
            'sha256',
            app_path('Http/Controllers/API/V0_5/Career/CareerJobDetailController.php'),
        );
        $this->assertSame($expectedContractSha, $projector->contractSha256());
        $this->assertSame($expectedContractSha, $projector->contractSha256());
        $memoizedSha = new \ReflectionProperty($projector, 'contractSha256');
        $this->assertSame($expectedContractSha, $memoizedSha->getValue($projector));
    }

    public function test_batch_build_does_not_repeat_runtime_projection_lookups(): void
    {
        $snapshot = [];
        foreach ($this->manifestReader->read()['candidates'] as $candidate) {
            foreach (['en', 'zh-CN'] as $locale) {
                $snapshot[$candidate['canonical_slug'].'|'.$locale] = [
                    'slug' => $candidate['canonical_slug'],
                    'locale' => $locale,
                    'runtime_publish_state' => 'published',
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                ];
            }
        }
        $runtimeProjection = \Mockery::mock(
            CareerRuntimePublishProjectionVisibility::class
                .', '.CareerRuntimePublishProjectionCoverageSnapshot::class,
        );
        $runtimeProjection->shouldReceive('jobDetailCoverageItems')
            ->once()
            ->with(['en', 'zh-CN'])
            ->andReturn($snapshot);
        $runtimeProjection->shouldNotReceive('itemForSlug');
        $this->app->instance(CareerRuntimePublishProjectionVisibility::class, $runtimeProjection);
        foreach ([
            PublicCareerAuthorityResponseCache::class,
            CareerSearchEntryQualityEvaluator::class,
            CareerPilotReviewEvidenceBridge::class,
            CareerSearchEntryQualityBatchPlanner::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->assertSame(50, app(CareerSearchEntryQualityBatchPlanner::class)->build()['candidate_count']);
    }

    public function test_bulk_publication_snapshot_preserves_same_version_active_exposure_authority(): void
    {
        $slug = $this->manifestReader->read()['candidates'][0]['canonical_slug'];
        $materializedCandidate = [
            $slug.'|en' => [
                'slug' => $slug,
                'locale' => 'en',
                'runtime_publish_state' => 'candidate',
                'detail_route_enabled' => false,
                'robots_indexable' => false,
                'release_gate_pass' => false,
            ],
        ];
        $runtimeProjection = \Mockery::mock(
            CareerRuntimePublishProjectionVisibility::class
                .', '.CareerRuntimePublishProjectionCoverageSnapshot::class,
        );
        $runtimeProjection->shouldReceive('jobDetailCoverageItems')
            ->twice()
            ->with(['en'])
            ->andReturn($materializedCandidate);
        $runtimeProjection->shouldNotReceive('itemForSlug');
        $this->app->instance(CareerRuntimePublishProjectionVisibility::class, $runtimeProjection);
        $this->app->forgetInstance(PublicCareerAuthorityResponseCache::class);
        $responseCache = app(PublicCareerAuthorityResponseCache::class);
        $responseCache->publishJobDetailReadModel(
            $slug,
            'en',
            $this->detailPayload($slug, 'en'),
            [
                'slug' => $slug,
                'locale' => 'en',
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'robots_indexable' => true,
                'release_gate_pass' => true,
            ],
        );

        $this->assertSame(
            [$slug => ['en' => true]],
            $responseCache->jobDetailPublishedSnapshot([$slug], ['en']),
        );
        $publication = $responseCache->jobDetailPublicationSnapshot([$slug], ['en']);
        $this->assertTrue($publication[$slug]['en']['published']);
        $this->assertSame('ready_active', $publication[$slug]['en']['classification']);
        $this->assertIsString($publication[$slug]['en']['version']);
        $this->assertSame($slug, data_get($publication[$slug]['en']['payload'], 'identity.canonical_slug'));
    }

    public function test_content_seo_or_review_target_drift_rejects_exact_package(): void
    {
        $planner = app(CareerSearchEntryQualityBatchPlanner::class);
        $package = $planner->build();
        $slug = $package['slugs'][0];
        $this->responseCache->publishJobDetailReadModel(
            $slug,
            'en',
            $this->detailPayload($slug, 'en', ['drift_marker' => 'changed']),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('drift detected');
        $planner->verify($package);
    }

    public function test_exact_review_binding_remains_ineligible_without_controlled_apply_evidence(): void
    {
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        $rows = $this->manifestReader->read()['candidates'];
        $slugs = [$rows[0]['canonical_slug'], $rows[4]['canonical_slug']];
        $bridge = app(CareerPilotReviewEvidenceBridge::class);
        $package = $bridge->buildPackage($slugs);
        app(CareerSeoReviewAttestationService::class)->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: $package['scope_identity'],
            decision: 'approved_all',
            authoritativeTargets: $package['targets'],
            actorAdminUserId: 1,
            packageSha256: $package['package_sha256'],
        );

        $stable = $bridge->projectDetailPayload(
            $slugs[0],
            $this->responseCache->jobDetailPayload($slugs[0], 'en'),
        );
        $candidate = $bridge->projectDetailPayload(
            $slugs[1],
            $this->responseCache->jobDetailPayload($slugs[1], 'en'),
        );

        $this->assertSame('ineligible', $stable['search_entry_tier']);
        $this->assertSame('ineligible', $candidate['search_entry_tier']);
        foreach ([$stable, $candidate] as $payload) {
            $this->assertFalse($payload['search_entry_authority']['search_entry_eligible']);
            $this->assertSame('approved', $payload['search_entry_authority']['review_state']);
            $this->assertSame('unknown', $payload['search_entry_authority']['content_quality_tier']);
            $this->assertContains(
                'content_quality_tier_unknown',
                $payload['search_entry_authority']['reason_codes'],
            );
        }
    }

    public function test_review_apply_readback_and_append_only_rollback_are_exact_and_separate(): void
    {
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        $planner = app(CareerSearchEntryQualityBatchPlanner::class);
        $package = $planner->build();
        $path = storage_path('framework/testing/career-search-entry-quality-control.json');
        file_put_contents($path, json_encode($package, JSON_THROW_ON_ERROR));
        $control = app(CareerSearchEntryQualityBatchControlService::class);

        try {
            $preflight = $control->reviewPreflight($path, 1);
            $this->assertSame('PASS_REVIEW_PREFLIGHT', $preflight['status']);
            $this->assertSame('awaiting_exact_approved_all_binding', $preflight['review_state']);
            $this->assertSame(0, $preflight['review_write_count']);
            $this->assertDatabaseCount('review_attestations', 0);
            $this->assertDatabaseCount('career_search_entry_quality_batch_operations', 0);

            $review = $control->bindReview($path, 1);
            $this->assertSame('PASS_REVIEW_BOUND', $review['status']);
            $this->assertSame(300, $review['review_target_evidence_count']);
            $this->assertSame(301, $review['review_write_count']);
            $this->assertDatabaseCount('review_attestations', 1);
            $this->assertDatabaseCount('review_attestation_target_evidences', 300);

            $slug = $package['slugs'][0];
            $reviewOnly = app(CareerPilotReviewEvidenceBridge::class)->projectDetailPayload(
                $slug,
                $this->responseCache->jobDetailPayload($slug, 'en'),
            );
            $this->assertSame('approved', data_get($reviewOnly, 'trust_manifest.review_state'));
            $this->assertSame('ineligible', $reviewOnly['search_entry_tier']);

            $options = [
                'active_release_sha' => str_repeat('a', 40),
                'active_release_name' => 'career-search-entry-batch-control-a',
                'operation_id' => 'CAREER-SEARCH-ENTRY-BATCH-01-APPLY:test',
                'rollback_identifier' => 'career-search-entry-batch-01:test',
                'actor_admin_user_id' => 1,
                'expected_review_evidence_sha256' => $review['review_evidence_sha256'],
            ];
            $applyPreflight = $control->operationPreflight($path, $options);
            $this->assertSame('PASS_APPLY_PREFLIGHT', $applyPreflight['status']);
            $this->assertSame(0, $applyPreflight['operation_write_count']);
            $this->assertSame('ineligible', $applyPreflight['search_entry_tier_before']);

            $apply = $control->apply($path, $options);
            $this->assertSame('PASS_APPLY_COMMITTED', $apply['status']);
            $this->assertSame(1, $apply['operation_write_count']);
            $this->assertDatabaseCount('career_search_entry_quality_batch_operations', 1);
            $repeat = $control->apply($path, $options);
            $this->assertSame('PASS_APPLY_ALREADY_COMMITTED', $repeat['status']);
            $this->assertSame(0, $repeat['operation_write_count']);
            $this->assertSame($apply['operation_receipt_sha256'], $repeat['operation_receipt_sha256']);
            $this->assertDatabaseCount('career_search_entry_quality_batch_operations', 1);

            $this->app->forgetInstance(CareerPilotReviewEvidenceBridge::class);
            $eligible = app(CareerPilotReviewEvidenceBridge::class)->projectDetailPayload(
                $slug,
                $this->responseCache->jobDetailPayload($slug, 'en'),
            );
            $this->assertSame('stable', $eligible['search_entry_tier']);
            $this->assertTrue($eligible['search_entry_authority']['search_entry_eligible']);
            $this->assertSame(
                CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE,
                $eligible['search_entry_authority']['content_quality_tier'],
            );

            $readback = $control->readback($path, $options);
            $this->assertSame('PASS_APPLY_READBACK', $readback['status']);
            $this->assertSame('exact_50_eligible', $readback['search_entry_tier_readback']);

            $rollbackOptions = [
                ...$options,
                'operation_id' => 'CAREER-SEARCH-ENTRY-BATCH-01-ROLLBACK:test',
                'expected_apply_receipt_sha256' => $apply['operation_receipt_sha256'],
                'expected_rollback_authorization_sha256' => $apply['rollback_authorization_sha256'],
            ];
            $rollback = $control->rollback($path, $rollbackOptions);
            $this->assertSame('PASS_ROLLBACK_COMMITTED', $rollback['status']);
            $this->assertSame('ineligible', $rollback['search_entry_tier_readback']);
            $this->assertDatabaseCount('career_search_entry_quality_batch_operations', 2);

            $this->app->forgetInstance(CareerPilotReviewEvidenceBridge::class);
            $rolledBack = app(CareerPilotReviewEvidenceBridge::class)->projectDetailPayload(
                $slug,
                $this->responseCache->jobDetailPayload($slug, 'en'),
            );
            $this->assertSame('ineligible', $rolledBack['search_entry_tier']);
            $this->assertFalse($rolledBack['search_entry_authority']['search_entry_eligible']);

            $operation = CareerSearchEntryQualityBatchOperation::query()->firstOrFail();
            $this->expectException(\LogicException::class);
            $operation->update(['active_release_name' => 'forbidden']);
        } finally {
            @unlink($path);
        }
    }

    public function test_apply_rejects_missing_review_wrong_actor_and_package_drift_without_writes(): void
    {
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        $planner = app(CareerSearchEntryQualityBatchPlanner::class);
        $package = $planner->build();
        $path = storage_path('framework/testing/career-search-entry-quality-control-hold.json');
        file_put_contents($path, json_encode($package, JSON_THROW_ON_ERROR));
        $options = [
            'active_release_sha' => str_repeat('b', 40),
            'active_release_name' => 'career-search-entry-batch-control-b',
            'operation_id' => 'CAREER-SEARCH-ENTRY-BATCH-01-APPLY:hold',
            'rollback_identifier' => 'career-search-entry-batch-01:hold',
            'actor_admin_user_id' => 2,
            'expected_review_evidence_sha256' => str_repeat('c', 64),
        ];

        try {
            $control = app(CareerSearchEntryQualityBatchControlService::class);
            try {
                $control->operationPreflight($path, $options);
                $this->fail('Wrong actor must fail closed.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('actor', strtolower($exception->getMessage()));
            }

            $options['actor_admin_user_id'] = 1;
            try {
                $control->operationPreflight($path, $options);
                $this->fail('Missing review evidence must fail closed.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('review evidence', strtolower($exception->getMessage()));
            }

            $review = $control->bindReview($path, 1);
            $options['expected_review_evidence_sha256'] = $review['review_evidence_sha256'];
            $tampered = $package;
            $tampered['candidates'][0]['locales']['en']['visible_character_count']++;
            file_put_contents($path, json_encode($tampered, JSON_THROW_ON_ERROR));
            try {
                $control->apply($path, $options);
                $this->fail('Package drift must fail closed.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'authentication failed',
                    strtolower($exception->getMessage()),
                );
            }
        } finally {
            @unlink($path);
            $this->assertDatabaseCount('review_attestations', 1);
            $this->assertDatabaseCount('career_search_entry_quality_batch_operations', 0);
        }
    }

    public function test_review_package_reuses_the_index_snapshot_qualified_by_evaluator(): void
    {
        $slug = $this->manifestReader->read()['candidates'][0]['canonical_slug'];
        $evaluator = app(CareerSearchEntryQualityEvaluator::class);
        $this->assertSame([], $evaluator->evaluate($slug)['blockers']);
        $indexSnapshot = $evaluator->indexSnapshot([$slug]);
        $originalItem = $indexSnapshot['en'][$slug];

        $en = $this->responseCache->jobIndexPayload('en');
        $zh = $this->responseCache->jobIndexPayload('zh-CN');
        foreach ($en['items'] as &$item) {
            if (data_get($item, 'identity.canonical_slug') === $slug) {
                $item['seo_contract']['robots_policy'] = 'noindex,follow';
            }
        }
        unset($item);
        $this->responseCache->publishJobIndexReadModelsAtomically(['en' => $en, 'zh-CN' => $zh]);

        $package = app(CareerPilotReviewEvidenceBridge::class)->buildPackage(
            [$slug],
            $evaluator->publicationSnapshot([$slug]),
            $indexSnapshot,
        );

        $this->assertSame(
            hash('sha256', app(ReviewAttestationCanonicalizer::class)->encode($originalItem)),
            $package['index_item_sha256_by_slug'][$slug]['en'],
        );
    }

    public function test_detail_projection_rejects_payload_that_drifted_after_approved_projection_was_cached(): void
    {
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        $slug = $this->manifestReader->read()['candidates'][0]['canonical_slug'];
        $bridge = app(CareerPilotReviewEvidenceBridge::class);
        $package = $bridge->buildPackage([$slug]);
        app(CareerSeoReviewAttestationService::class)->createAndBindReview(
            surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
            scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            scopeIdentity: $package['scope_identity'],
            decision: 'approved_all',
            authoritativeTargets: $package['targets'],
            actorAdminUserId: 1,
            packageSha256: $package['package_sha256'],
        );
        $bridge->projectJobIndexPayload($this->responseCache->jobIndexPayload('en'), 'en');

        $this->responseCache->publishJobDetailReadModel(
            $slug,
            'en',
            $this->detailPayload($slug, 'en', ['identity' => ['public_alias' => 'drifted-after-review']]),
        );
        $projected = $bridge->projectDetailPayload(
            $slug,
            $this->responseCache->jobDetailPayload($slug, 'en'),
        );

        $this->assertSame('unknown', $projected['trust_manifest']['review_state']);
        $this->assertSame('ineligible', $projected['search_entry_tier']);
        $this->assertContains(
            'reviewer_evidence_not_current',
            $projected['search_entry_authority']['reason_codes'],
        );
    }

    public function test_quality_gaps_are_independently_rejected_without_backfill(): void
    {
        $rows = $this->manifestReader->read()['candidates'];
        $cases = [
            [$rows[0]['canonical_slug'], 'thin', 'visible_content_too_thin'],
            [$rows[1]['canonical_slug'], 'sources', 'source_references_missing'],
            [$rows[2]['canonical_slug'], 'claims', 'claim_boundary_missing'],
            [$rows[3]['canonical_slug'], 'faq', 'faq_visible_content_mismatch'],
            [$rows[4]['canonical_slug'], 'links', 'internal_links_missing_or_cross_locale'],
            [$rows[5]['canonical_slug'], 'robots', 'robots_not_indexable'],
        ];
        foreach ($cases as [$slug, $gap, $expectedBlocker]) {
            $this->responseCache->publishJobDetailReadModel(
                $slug,
                'en',
                $this->detailPayload($slug, 'en', $this->qualityGapOverrides($gap)),
            );
        }

        $evaluator = app(CareerSearchEntryQualityEvaluator::class);
        foreach ($cases as [$slug, , $expectedBlocker]) {
            $this->assertContains('en:'.$expectedBlocker, $evaluator->evaluate($slug)['blockers'], $slug);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('insufficient qualified candidates');
        app(CareerSearchEntryQualityBatchPlanner::class)->build();
    }

    public function test_placeholder_and_contract_metadata_cannot_satisfy_visible_prose_thickness(): void
    {
        $slug = $this->manifestReader->read()['candidates'][0]['canonical_slug'];
        $payload = $this->detailPayload($slug, 'en');
        $placeholders = [];
        foreach (range(1, 24) as $index) {
            $placeholders['placeholder_'.$index] = [
                'module_key' => str_repeat('not-visible-module-key-', 4),
                'module_state' => str_repeat('pending_reviewed_locale_content-', 4),
                'content_available' => false,
                'source' => str_repeat('component_order_contract-', 4),
                'placeholder_policy' => str_repeat('no_cross_locale_editorial_copy_generated-', 4),
                'href' => '/en/'.str_repeat('not-visible-path-', 4),
            ];
        }
        $payload['display_surface_v1']['page']['content'] = array_merge([
            'hero' => ['title' => 'Thin', 'quick_answer' => 'Thin'],
            'primary_cta' => ['href' => '/en/tests/holland-career-interest-test-riasec'],
            'faq_block' => ['items' => [[
                'question' => 'Is this a guaranteed outcome?',
                'answer' => 'No. It is bounded evidence for exploration.',
            ]]],
            'boundary_notice' => ['body' => 'No guarantees.'],
            'final_cta' => ['href' => '/en/career'],
        ], $placeholders);
        $this->responseCache->publishJobDetailReadModel($slug, 'en', $payload);

        $locale = app(CareerSearchEntryQualityEvaluator::class)->evaluate($slug)['locales']['en'];

        $this->assertLessThan(500, $locale['visible_character_count']);
        $this->assertContains('visible_content_too_thin', $locale['blockers']);
    }

    public function test_source_contract_metadata_cannot_replace_empty_reference_entries(): void
    {
        $slug = $this->manifestReader->read()['candidates'][0]['canonical_slug'];
        $payload = $this->detailPayload($slug, 'en');
        $payload['display_surface_v1']['sources'] = [
            'references' => [],
            'source_refs_contract' => 'claim_level_source_refs_normalized_from_workbook',
        ];
        $this->responseCache->publishJobDetailReadModel($slug, 'en', $payload);

        $locale = app(CareerSearchEntryQualityEvaluator::class)->evaluate($slug)['locales']['en'];

        $this->assertSame([], $locale['source_references']);
        $this->assertContains('source_references_missing', $locale['blockers']);
    }

    public function test_source_usage_metadata_without_identity_is_not_evidence(): void
    {
        $slug = $this->manifestReader->read()['candidates'][0]['canonical_slug'];
        $payload = $this->detailPayload($slug, 'en');
        $payload['display_surface_v1']['sources'] = [
            'references' => [[
                'key' => 'internal-only-key',
                'usage' => 'Career evidence validation.',
                'url' => 'not-a-public-url',
            ]],
        ];
        $this->responseCache->publishJobDetailReadModel($slug, 'en', $payload);

        $locale = app(CareerSearchEntryQualityEvaluator::class)->evaluate($slug)['locales']['en'];

        $this->assertSame([], $locale['source_references']);
        $this->assertContains('source_references_missing', $locale['blockers']);
    }

    public function test_every_populated_detail_canonical_field_must_match(): void
    {
        $slug = $this->manifestReader->read()['candidates'][0]['canonical_slug'];
        $payload = $this->detailPayload($slug, 'en');
        $payload['seo_contract']['canonical_url'] = '/en/career/jobs/'.$slug;
        $payload['seo_contract']['canonical_path'] = '/zh/career/jobs/'.$slug;
        $this->responseCache->publishJobDetailReadModel($slug, 'en', $payload);

        $locale = app(CareerSearchEntryQualityEvaluator::class)->evaluate($slug)['locales']['en'];

        $this->assertContains('canonical_url_mismatch', $locale['blockers']);
    }

    public function test_index_item_canonical_must_match_exact_locale_and_slug(): void
    {
        $slug = $this->manifestReader->read()['candidates'][0]['canonical_slug'];
        $en = $this->responseCache->jobIndexPayload('en');
        $zh = $this->responseCache->jobIndexPayload('zh-CN');
        foreach ($en['items'] as &$item) {
            if (data_get($item, 'identity.canonical_slug') === $slug) {
                $item['seo_contract']['canonical_url'] = '/en/career/jobs/'.$slug;
                $item['seo_contract']['canonical_path'] = '/zh/career/jobs/'.$slug;
            }
        }
        unset($item);
        $this->responseCache->publishJobIndexReadModelsAtomically([
            'en' => $en,
            'zh-CN' => $zh,
        ]);

        $evaluation = app(CareerSearchEntryQualityEvaluator::class)->evaluate($slug);

        $this->assertContains('en:index_item_canonical_mismatch', $evaluation['blockers']);
        $this->assertSame('ineligible', $evaluation['content_quality_tier']);
    }

    public function test_missing_bilingual_authority_fails_closed(): void
    {
        Cache::flush();
        $evaluation = app(CareerSearchEntryQualityEvaluator::class)
            ->evaluate($this->manifestReader->read()['candidates'][0]['canonical_slug']);

        $this->assertContains('en:bilingual_detail_not_ready', $evaluation['blockers']);
        $this->assertContains('zh-CN:bilingual_detail_not_ready', $evaluation['blockers']);
        $this->assertSame('ineligible', $evaluation['content_quality_tier']);
    }

    private function publishCompleteBatch(): void
    {
        $indexItems = ['en' => [], 'zh-CN' => []];
        foreach ($this->manifestReader->read()['candidates'] as $candidate) {
            $slug = $candidate['canonical_slug'];
            foreach (['en', 'zh-CN'] as $locale) {
                $this->responseCache->publishJobDetailReadModel($slug, $locale, $this->detailPayload($slug, $locale));
                $indexItems[$locale][] = [
                    'identity' => ['canonical_slug' => $slug],
                    'titles' => ['canonical_en' => str($slug)->replace('-', ' ')->title()->toString()],
                    'trust_summary' => ['review_state' => 'unknown', 'last_reviewed_at' => null],
                    'seo_contract' => [
                        'canonical_path' => ($locale === 'en' ? '/en' : '/zh').'/career/jobs/'.$slug,
                        'robots_policy' => 'index,follow',
                        'index_eligible' => true,
                    ],
                ];
            }
        }
        $this->responseCache->publishJobIndexReadModelsAtomically([
            'en' => ['bundle_kind' => 'career_job_index', 'items' => $indexItems['en']],
            'zh-CN' => ['bundle_kind' => 'career_job_index', 'items' => $indexItems['zh-CN']],
        ]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function detailPayload(string $slug, string $locale, array $overrides = []): array
    {
        $prefix = $locale === 'en' ? '/en' : '/zh';
        $title = str($slug)->replace('-', ' ')->title()->toString();
        $faqQuestion = $locale === 'en' ? 'Is this a guaranteed outcome?' : '这是否保证职业结果？';
        $faqAnswer = $locale === 'en'
            ? 'No. It is bounded evidence for exploration.'
            : '不是。这是用于职业探索的边界证据。';
        $body = str_repeat(
            $locale === 'en'
                ? 'Visible source-bounded career evidence supports careful exploration and comparison. '
                : '可见且有来源边界的职业证据用于审慎探索、比较与复盘。',
            20,
        );
        $payload = [
            'bundle_kind' => 'career_job_detail',
            'identity' => ['canonical_slug' => $slug],
            'locale_policy' => ['locale' => $locale],
            'titles' => ['canonical_en' => $title, 'canonical_zh' => $title.' 中文'],
            'truth_layer' => ['summary' => 'Source-bounded public fact.'],
            'content_sections' => [['key' => 'overview', 'body_md' => $body]],
            'content_body_md' => $body,
            'trust_manifest' => [
                'reviewer_status' => 'pending_exact_batch_review',
                'review_state' => 'unknown',
                'last_reviewed_at' => null,
            ],
            'warnings' => [],
            'claim_permissions' => [
                'allow_strong_claim' => false,
                'allow_salary_comparison' => false,
                'allow_ai_strategy' => false,
                'reason_codes' => ['bounded_quality_review_required'],
            ],
            'seo_contract' => [
                'canonical_url' => $prefix.'/career/jobs/'.$slug,
                'canonical_path' => $prefix.'/career/jobs/'.$slug,
                'canonical_target' => $prefix.'/career/jobs/'.$slug,
                'robots_policy' => 'index,follow',
                'index_eligible' => true,
            ],
            'structured_data' => ['occupation' => ['@type' => 'Occupation']],
            'display_surface_v1' => [
                'page' => [
                    'locale' => $locale,
                    'content' => [
                        'hero' => ['title' => $title, 'quick_answer' => $body],
                        'primary_cta' => ['href' => $prefix.'/tests/holland-career-interest-test-riasec'],
                        'faq_block' => ['items' => [[
                            'question' => $faqQuestion,
                            'answer' => $faqAnswer,
                        ]]],
                        'boundary_notice' => ['body' => 'No hiring, salary, or outcome guarantee.'],
                        'final_cta' => ['href' => $prefix.'/career'],
                    ],
                ],
                'sources' => [
                    'references' => [[
                        'key' => 'bounded_fixture_source',
                        'label' => 'Bounded public career source',
                        'usage' => 'Career evidence validation.',
                    ]],
                    'source_refs_contract' => 'claim_level_source_refs_normalized_from_workbook',
                ],
                'claim_permissions' => [
                    'allow_strong_claim' => false,
                    'allow_salary_comparison' => false,
                    'allow_ai_strategy' => false,
                    'blocked_claims' => ['guaranteed_outcomes'],
                ],
                'structured_data_from_visible_content' => [
                    'faq_page' => [
                        $locale === 'en' ? 'en' : 'zh' => [
                            '@context' => 'https://schema.org',
                            '@type' => 'FAQPage',
                            'mainEntity' => [[
                                '@type' => 'Question',
                                'name' => $faqQuestion,
                                'acceptedAnswer' => [
                                    '@type' => 'Answer',
                                    'text' => $faqAnswer,
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    /** @return array<string,mixed> */
    private function qualityGapOverrides(string $gap): array
    {
        return match ($gap) {
            'thin' => ['display_surface_v1' => ['page' => ['content' => [
                'hero' => ['title' => 'thin', 'quick_answer' => 'thin'],
            ]]]],
            'sources' => ['display_surface_v1' => ['sources' => [
                'references' => [null],
                'source_refs_contract' => 'claim_level_source_refs_normalized_from_workbook',
            ]]],
            'claims' => [
                'display_surface_v1' => ['claim_permissions' => [
                    'allow_strong_claim' => 'unknown',
                    'allow_salary_comparison' => 'unknown',
                    'allow_ai_strategy' => 'unknown',
                ]],
                'claim_permissions' => [
                    'allow_strong_claim' => 'unknown',
                    'allow_salary_comparison' => 'unknown',
                    'allow_ai_strategy' => 'unknown',
                ],
            ],
            'faq' => ['display_surface_v1' => ['structured_data_from_visible_content' => [
                'faq_page' => ['en' => ['mainEntity' => [[
                    'acceptedAnswer' => ['text' => 'Drifted hidden FAQ answer.'],
                ]]]],
            ]]],
            'links' => ['display_surface_v1' => ['page' => ['content' => [
                'primary_cta' => ['href' => null],
                'final_cta' => ['href' => null],
            ]]]],
            'robots' => ['seo_contract' => [
                'robots_policy' => 'noindex,follow',
                'index_eligible' => false,
            ]],
            default => [],
        };
    }

    private function cacheEvidenceSha(): string
    {
        $rows = $this->manifestReader->read()['candidates'];
        $slugs = [$rows[0]['canonical_slug'], $rows[49]['canonical_slug']];
        $evidence = [
            'index_en' => $this->responseCache->jobIndexPayload('en'),
            'index_zh' => $this->responseCache->jobIndexPayload('zh-CN'),
        ];
        foreach ($slugs as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $evidence[$slug.':'.$locale] = $this->responseCache->jobDetailCacheReadiness($slug, $locale);
            }
        }

        return hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
    }
}
