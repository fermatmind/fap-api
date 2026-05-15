<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPlanCanonical80RuntimeCandidatePoolCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-80-runtime-candidate-pool', Artisan::all());
    }

    public function test_missing_audit_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--audit' => sys_get_temp_dir().'/missing-career-audit.json',
            '--projection' => $this->writeProjection([]),
            '--truth' => $this->writeTruth([]),
            '--ledger' => $this->writeLedger([]),
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['pool_pass']);
        $this->assertSame('audit_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_invalid_projection_json_blocks(): void
    {
        $audit = $this->writeAudit(['alpha-career']);
        $projection = $this->tempPath('projection');
        file_put_contents($projection, '{not json');

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--projection' => $projection,
            '--truth' => $this->writeTruth([]),
            '--ledger' => $this->writeLedger([]),
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('projection_artifact_json_invalid', $payload['blockers'][0]['reason']);
    }

    public function test_passes_with_target_override_and_writes_output(): void
    {
        $slugs = ['zeta-career', 'alpha-career', 'beta-career'];
        $audit = $this->writeCandidateAwareBlockedAudit($slugs);
        $output = $this->tempPath('runtime-pool-output');

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--projection' => $this->writeProjection($slugs),
            '--truth' => $this->writeTruth($slugs),
            '--ledger' => $this->writeLedger($slugs),
            '--target' => 2,
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['pool_pass']);
        $this->assertSame(3, $payload['eligible_count']);
        $this->assertSame(2, $payload['selected_count']);
        $this->assertSame(['alpha-career', 'beta-career'], $payload['selection']['slugs']);
        $this->assertSame('promotion_candidate', $payload['selection']['rows'][0]['runtime_state_evidence']['candidate_aware_overlay']['ledger_index_state']);
        $this->assertTrue($payload['selection']['rows'][0]['runtime_state_evidence']['candidate_aware_overlay']['candidate_prep_apply_write_verified']);
        $this->assertTrue($payload['rollout']['manifest_generation_allowed']);
        $this->assertTrue($payload['rollout']['dry_run_allowed']);
        $this->assertFalse($payload['rollout']['apply_allowed']);
        $this->assertFileExists($output);
    }

    public function test_requires_candidate_aware_overlay_source_for_delta_planning(): void
    {
        $audit = $this->writeCandidateAwareBlockedAudit(['alpha-career']);

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--projection' => $this->writeProjection(['alpha-career'], candidateAware: false),
            '--truth' => $this->writeTruth(['alpha-career'], candidateAware: false),
            '--ledger' => $this->writeLedger(['alpha-career'], candidateAware: false),
            '--target' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['pool_pass']);
        $this->assertSame(0, $payload['selected_count']);
        $this->assertContains('candidate_aware_overlay_missing', $payload['runtime_candidate_gate']['excluded_rows'][0]['exclusion_reasons']);
        $this->assertContains('candidate_prep_apply_not_verified', $payload['runtime_candidate_gate']['excluded_rows'][0]['exclusion_reasons']);
    }

    public function test_stale_full_publication_audit_reasons_do_not_veto_verified_candidate_aware_overlay(): void
    {
        $slugs = ['delta-001', 'delta-002'];

        $exitCode = $this->callCommand([
            '--audit' => $this->writeCandidateAwareBlockedAudit($slugs),
            '--projection' => $this->writeProjection($slugs),
            '--truth' => $this->writeTruth($slugs),
            '--ledger' => $this->writeLedger($slugs),
            '--target' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['pool_pass']);
        $this->assertSame($slugs, $payload['selection']['slugs']);
        $this->assertSame(2, $payload['eligible_count']);
        $this->assertSame(0, $payload['excluded_count']);
    }

    public function test_explicit_progressive_delta_slugs_drive_pool_selection_instead_of_legacy_80_candidates(): void
    {
        $legacySlugs = ['legacy-near-eligible-001', 'legacy-near-eligible-002'];
        $progressiveSlugs = ['progressive-delta-001', 'progressive-delta-002', 'progressive-delta-003'];

        $exitCode = $this->callCommand([
            '--audit' => $this->writeProgressiveStaleAudit($progressiveSlugs, $legacySlugs),
            '--projection' => $this->writeProjection($progressiveSlugs),
            '--truth' => $this->writeTruth($progressiveSlugs),
            '--ledger' => $this->writeLedger($progressiveSlugs),
            '--target' => 3,
            '--target-total' => 300,
            '--cohort' => 'career_80_to_300_delta',
            '--delta-slugs' => $this->writeSlugArtifact($progressiveSlugs),
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['pool_pass']);
        $this->assertSame('progressive_explicit_delta_runtime_candidate_pool', $payload['selection']['strategy']);
        $this->assertSame($progressiveSlugs, $payload['selection']['slugs']);
        $this->assertSame(3, $payload['base_candidate_count']);
        $this->assertSame(3, $payload['eligible_count']);
        $this->assertSame('progressive_delta_slug_artifact', $payload['source_artifacts']['explicit_delta_selection']['strategy']);
        $this->assertSame(3, $payload['source_artifacts']['explicit_delta_selection']['slug_count']);
        $this->assertSame('PROGRESSIVE_ROLLOUT_MANIFEST', $payload['next_required_action']);
        $this->assertTrue($payload['selection']['rows'][0]['runtime_state_evidence']['candidate_aware_overlay']['explicit_progressive_delta']);
        $this->assertFalse($payload['selection']['rows'][0]['runtime_state_evidence']['candidate_aware_overlay']['audit_index_evidence_required']);
    }

    public function test_readiness_plan_selected_slugs_can_feed_progressive_pool_selection(): void
    {
        $slugs = ['progressive-plan-001', 'progressive-plan-002'];

        $exitCode = $this->callCommand([
            '--audit' => $this->writeProgressiveStaleAudit($slugs),
            '--projection' => $this->writeProjection($slugs),
            '--truth' => $this->writeTruth($slugs),
            '--ledger' => $this->writeLedger($slugs),
            '--target' => 2,
            '--target-total' => 300,
            '--readiness-plan' => $this->writeJson('readiness-plan', [
                'status' => 'pass',
                'readiness_pass' => true,
                'target_public_total' => 300,
                'delta_slug_count' => 2,
                'selected_slugs' => $slugs,
                'blockers' => [],
            ]),
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame($slugs, $payload['selection']['slugs']);
        $this->assertSame('progressive_readiness_plan_selected_slugs', $payload['source_artifacts']['explicit_delta_selection']['strategy']);
        $this->assertSame(2, $payload['selected_count']);
    }

    public function test_explicit_progressive_delta_selection_still_requires_verified_candidate_overlay(): void
    {
        $slugs = ['progressive-missing-overlay'];

        $exitCode = $this->callCommand([
            '--audit' => $this->writeProgressiveStaleAudit($slugs),
            '--projection' => $this->writeProjection($slugs, candidateAware: false),
            '--truth' => $this->writeTruth($slugs, candidateAware: false),
            '--ledger' => $this->writeLedger($slugs, candidateAware: false),
            '--target' => 1,
            '--target-total' => 300,
            '--delta-slugs' => $this->writeSlugArtifact($slugs),
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['pool_pass']);
        $this->assertSame(0, $payload['selected_count']);
        $this->assertContains('candidate_aware_overlay_missing', $payload['runtime_candidate_gate']['excluded_rows'][0]['exclusion_reasons']);
        $this->assertContains('candidate_prep_apply_not_verified', $payload['runtime_candidate_gate']['excluded_rows'][0]['exclusion_reasons']);
    }

    public function test_explicit_progressive_delta_slug_count_mismatch_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--audit' => $this->writeProgressiveStaleAudit(['progressive-delta-001']),
            '--projection' => $this->writeProjection(['progressive-delta-001']),
            '--truth' => $this->writeTruth(['progressive-delta-001']),
            '--ledger' => $this->writeLedger(['progressive-delta-001']),
            '--target' => 2,
            '--delta-slugs' => $this->writeSlugArtifact(['progressive-delta-001']),
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('explicit_delta_slug_count_mismatch', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_excludes_runtime_invalid_candidates(): void
    {
        $slugs = [
            'valid-candidate',
            'already-published',
            'missing-ledger',
            'missing-projection',
            'missing-truth',
            'blocked-runtime',
            'route-exposed',
        ];
        $audit = $this->writeAudit($slugs);
        $projection = $this->writeProjection([
            'valid-candidate',
            'already-published' => 'published',
            'missing-ledger',
            'missing-truth',
            'blocked-runtime' => 'blocked',
            'route-exposed' => 'published_candidate',
        ], routeExposedSlugs: ['route-exposed']);
        $truth = $this->writeTruth([
            'valid-candidate',
            'already-published' => 'published',
            'missing-ledger',
            'missing-projection',
            'blocked-runtime' => 'blocked',
            'route-exposed',
        ], routeExposedSlugs: ['route-exposed']);
        $ledger = $this->writeLedger([
            'valid-candidate',
            'already-published',
            'missing-projection',
            'missing-truth',
            'blocked-runtime',
            'route-exposed',
        ], blockedSlugs: ['blocked-runtime']);

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--projection' => $projection,
            '--truth' => $truth,
            '--ledger' => $ledger,
            '--target' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(['valid-candidate'], $payload['selection']['slugs']);
        $this->assertSame(1, $payload['eligible_count']);
        $this->assertSame(6, $payload['excluded_count']);
        $this->assertSame(1, $payload['exclusions_by_reason']['already_published']);
        $this->assertSame(1, $payload['exclusions_by_reason']['ledger_member_missing']);
        $this->assertSame(1, $payload['exclusions_by_reason']['projection_row_missing']);
        $this->assertSame(1, $payload['exclusions_by_reason']['truth_row_missing']);
        $this->assertSame(1, $payload['exclusions_by_reason']['runtime_state_blocked']);
        $this->assertSame(1, $payload['exclusions_by_reason']['ledger_not_candidate_ready']);
        $this->assertSame(1, $payload['exclusions_by_reason']['unexpected_route_exposure']);
    }

    public function test_blocks_when_fewer_than_target_runtime_candidates_exist(): void
    {
        $audit = $this->writeAudit(['valid-candidate', 'already-published']);

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--projection' => $this->writeProjection(['valid-candidate', 'already-published' => 'published']),
            '--truth' => $this->writeTruth(['valid-candidate', 'already-published' => 'published']),
            '--ledger' => $this->writeLedger(['valid-candidate', 'already-published']),
            '--target' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['pool_pass']);
        $this->assertSame(1, $payload['selected_count']);
        $this->assertSame('insufficient_runtime_candidate_pool', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['rollout']['manifest_generation_allowed']);
        $this->assertFalse($payload['rollout']['dry_run_allowed']);
    }

    public function test_schema_is_stable_and_never_allows_apply(): void
    {
        $audit = $this->writeAudit(['alpha-career']);

        $exitCode = $this->callCommand([
            '--audit' => $audit,
            '--projection' => $this->writeProjection(['alpha-career']),
            '--truth' => $this->writeTruth(['alpha-career']),
            '--ledger' => $this->writeLedger(['alpha-career']),
            '--target' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame([
            'schema_version',
            'status',
            'pool_pass',
            'read_only',
            'writes_database',
            'target',
            'locales',
            'base_candidate_count',
            'eligible_count',
            'selected_count',
            'excluded_count',
            'exclusions_by_reason',
            'source_artifacts',
            'runtime_candidate_gate',
            'selection',
            'recovery_plan',
            'blockers',
            'rollout',
            'next_required_action',
        ], array_keys($payload));
        $this->assertSame('career_80_runtime_candidate_pool_plan.v1', $payload['schema_version']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['rollout']['apply_allowed']);
        $this->assertFalse($payload['recovery_plan']['approval_gated_apply_ready']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:plan-canonical-80-runtime-candidate-pool', array_merge([
            '--json' => true,
        ], $options));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $nearEligibleSlugs
     */
    private function writeAudit(array $nearEligibleSlugs): string
    {
        $rows = [];
        foreach (array_values(array_unique($nearEligibleSlugs)) as $slug) {
            $rows[] = $this->row($slug, 'en');
            $rows[] = $this->row($slug, 'zh');
        }

        $path = $this->tempPath('audit');
        file_put_contents($path, json_encode([
            'status' => 'blocked',
            'scope' => 'all',
            'expected_occupations' => 2786,
            'audited_occupations' => 2786,
            'eligible_count' => 0,
            'blocked_count' => count($rows),
            'by_reason' => [
                'llms_expected_not_ready' => count($rows),
                'surface_unverified' => count($rows),
            ],
            'context_summary' => ['surface_context' => 'supplied'],
            'policy_summary' => ['near_eligible_count' => count($nearEligibleSlugs)],
            'rows' => $rows,
            'sidecars' => [],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writeCandidateAwareBlockedAudit(array $slugs): string
    {
        $rows = [];
        foreach (array_values(array_unique($slugs)) as $slug) {
            $rows[] = $this->candidateAwareBlockedRow($slug, 'en');
            $rows[] = $this->candidateAwareBlockedRow($slug, 'zh');
        }

        return $this->writeJson('audit', [
            'status' => 'blocked',
            'scope' => 'all',
            'expected_occupations' => 2786,
            'audited_occupations' => 2786,
            'eligible_count' => 0,
            'blocked_count' => count($rows),
            'by_reason' => [
                'index_state_not_indexed_like' => count($rows),
                'runtime_publish_state_not_published' => count($rows),
                'truth_state_not_published' => count($rows),
                'surface_unverified' => count($rows),
            ],
            'context_summary' => ['surface_context' => 'supplied'],
            'policy_summary' => ['near_eligible_count' => 0],
            'rows' => $rows,
            'sidecars' => [],
        ]);
    }

    /**
     * @param  list<string>  $progressiveSlugs
     * @param  list<string>  $legacyNearEligibleSlugs
     */
    private function writeProgressiveStaleAudit(array $progressiveSlugs, array $legacyNearEligibleSlugs = []): string
    {
        $rows = [];
        foreach (array_values(array_unique($legacyNearEligibleSlugs)) as $slug) {
            $rows[] = $this->row($slug, 'en');
            $rows[] = $this->row($slug, 'zh');
        }

        foreach (array_values(array_unique($progressiveSlugs)) as $slug) {
            $rows[] = $this->progressiveStaleRow($slug, 'en');
            $rows[] = $this->progressiveStaleRow($slug, 'zh');
        }

        return $this->writeJson('audit', [
            'status' => 'blocked',
            'scope' => 'all',
            'expected_occupations' => 2786,
            'audited_occupations' => 2786,
            'eligible_count' => 0,
            'blocked_count' => count($rows),
            'by_reason' => [
                'index_state_missing' => count($progressiveSlugs) * 2,
                'runtime_publish_state_not_published' => count($progressiveSlugs) * 2,
                'truth_state_not_published' => count($progressiveSlugs) * 2,
                'surface_unverified' => count($rows),
            ],
            'context_summary' => ['surface_context' => 'supplied'],
            'policy_summary' => ['near_eligible_count' => count($legacyNearEligibleSlugs)],
            'rows' => $rows,
            'sidecars' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $slug, string $locale): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'source_scope' => 'all',
            'entity_status' => $this->layer('entity', 'pass'),
            'baseline_status' => $this->layer('baseline', 'pass'),
            'index_status' => $this->layer('index', 'pass'),
            'runtime_status' => $this->layer('runtime', 'pass', [
                ['runtime_publish_state' => 'published_candidate'],
                ['truth_state' => 'published_candidate'],
                ['canonical_public_type' => 'public_canonical_job'],
                ['candidate_pre_route_expected' => true],
            ]),
            'seo_geo_status' => $this->layer('seo_geo', 'blocked', [], ['llms_expected_not_ready']),
            'surface_status' => $this->layer('surface', 'blocked', [], ['surface_unverified']),
            'safety_status' => $this->layer('safety', 'pass'),
            'overall_status' => 'blocked',
            'severity' => 'high',
            'reasons' => ['llms_expected_not_ready', 'surface_unverified'],
            'evidence' => [['slug' => $slug]],
            'sidecars' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateAwareBlockedRow(string $slug, string $locale): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'source_scope' => 'all',
            'entity_status' => $this->layer('entity', 'pass'),
            'baseline_status' => $this->layer('baseline', 'pass'),
            'index_status' => $this->layer('index', 'blocked', [
                [
                    'latest_index_state' => 'promotion_candidate',
                    'reason_codes' => [
                        'prepare_published_candidate_runtime_rows',
                        'target_runtime_state:published_candidate',
                        'target_index_state:promotion_candidate',
                    ],
                ],
            ], ['index_state_not_indexed_like']),
            'runtime_status' => $this->layer('runtime', 'blocked', [
                ['runtime_publish_state' => 'published_candidate'],
                ['truth_state' => 'published_candidate'],
                ['canonical_public_type' => 'public_canonical_job'],
                ['candidate_pre_route_expected' => true],
            ], ['runtime_publish_state_not_published', 'truth_state_not_published']),
            'seo_geo_status' => $this->layer('seo_geo', 'blocked', [], ['sitemap_missing', 'llms_missing', 'llms_full_missing']),
            'surface_status' => $this->layer('surface', 'blocked', [], ['surface_unverified']),
            'safety_status' => $this->layer('safety', 'pass'),
            'overall_status' => 'blocked',
            'severity' => 'high',
            'reasons' => [
                'index_state_not_indexed_like',
                'runtime_publish_state_not_published',
                'truth_state_not_published',
                'sitemap_missing',
                'llms_missing',
                'llms_full_missing',
                'surface_unverified',
            ],
            'evidence' => [['slug' => $slug]],
            'sidecars' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function progressiveStaleRow(string $slug, string $locale): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'source_scope' => 'all',
            'entity_status' => $this->layer('entity', 'pass'),
            'baseline_status' => $this->layer('baseline', 'pass'),
            'index_status' => $this->layer('index', 'blocked', [], ['index_state_missing']),
            'runtime_status' => $this->layer('runtime', 'blocked', [
                ['runtime_publish_state' => 'published_candidate'],
                ['truth_state' => 'published_candidate'],
                ['canonical_public_type' => 'public_canonical_job'],
                ['candidate_pre_route_expected' => true],
            ], ['runtime_publish_state_not_published', 'truth_state_not_published']),
            'seo_geo_status' => $this->layer('seo_geo', 'blocked', [], ['sitemap_missing', 'llms_missing', 'llms_full_missing']),
            'surface_status' => $this->layer('surface', 'blocked', [], ['surface_unverified']),
            'safety_status' => $this->layer('safety', 'pass'),
            'overall_status' => 'blocked',
            'severity' => 'high',
            'reasons' => [
                'index_state_missing',
                'runtime_publish_state_not_published',
                'truth_state_not_published',
                'sitemap_missing',
                'llms_missing',
                'llms_full_missing',
                'surface_unverified',
            ],
            'evidence' => [['slug' => $slug]],
            'sidecars' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function layer(string $layer, string $status, array $evidence = [], array $reasons = []): array
    {
        return [
            'layer' => $layer,
            'status' => $status,
            'reasons' => $status === 'pass' ? [] : $reasons,
            'evidence' => $evidence,
            'source' => 'synthetic_test_fixture',
        ];
    }

    /**
     * @param  list<string>|array<string, string>  $slugs
     * @param  list<string>  $routeExposedSlugs
     */
    private function writeProjection(array $slugs, array $routeExposedSlugs = [], bool $candidateAware = true): string
    {
        $rows = [];
        foreach ($slugs as $key => $value) {
            $slug = is_string($key) ? $key : $value;
            $state = is_string($key) ? $value : 'published_candidate';
            foreach (['en', 'zh'] as $locale) {
                $row = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'public_resolution_type' => $state === 'blocked' ? 'blocked_until_governance_approval' : 'public_canonical_job',
                    'runtime_publish_state' => $state,
                    'detail_route_enabled' => in_array($slug, $routeExposedSlugs, true),
                    'dataset_visible' => false,
                    'search_visible' => false,
                ];
                if ($candidateAware) {
                    $row['overlay_source'] = 'candidate_prep_apply_overlay';
                    $row['source_artifact_sha256'] = str_repeat('a', 64);
                }
                $rows[] = $row;
            }
        }

        return $this->writeJson('projection', ['items' => $rows]);
    }

    /**
     * @param  list<string>|array<string, string>  $slugs
     * @param  list<string>  $routeExposedSlugs
     */
    private function writeTruth(array $slugs, array $routeExposedSlugs = [], bool $candidateAware = true): string
    {
        $rows = [];
        foreach ($slugs as $key => $value) {
            $slug = is_string($key) ? $key : $value;
            $state = is_string($key) ? $value : 'published_candidate';
            foreach (['en', 'zh'] as $locale) {
                $row = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'public_resolution_type' => $state === 'blocked' ? 'blocked_until_governance_approval' : 'public_canonical_job',
                    'projection_state' => $state,
                    'route_exists' => in_array($slug, $routeExposedSlugs, true),
                    'final_200' => false,
                    'dataset_visible' => false,
                    'search_visible' => false,
                    'candidate_pre_route_expected' => $state === 'published_candidate',
                    'candidate_unexpected_exposures' => in_array($slug, $routeExposedSlugs, true) ? ['route'] : [],
                ];
                if ($candidateAware) {
                    $row['overlay_source'] = 'candidate_prep_apply_overlay';
                    $row['source_artifact_sha256'] = str_repeat('a', 64);
                }
                $rows[] = $row;
            }
        }

        return $this->writeJson('truth', ['items' => $rows]);
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $blockedSlugs
     */
    private function writeLedger(array $slugs, array $blockedSlugs = [], bool $candidateAware = true): string
    {
        $members = [];
        foreach ($slugs as $slug) {
            $member = [
                'canonical_slug' => $slug,
                'release_cohort' => in_array($slug, $blockedSlugs, true) ? 'review_needed' : 'public_detail_conservative',
                'public_index_state' => in_array($slug, $blockedSlugs, true) ? 'noindex' : 'indexable',
                'current_index_state' => 'promotion_candidate',
                'runtime_publish_state' => 'published_candidate',
            ];
            if ($candidateAware) {
                $member['overlay_source'] = 'candidate_prep_apply_overlay';
                $member['source_artifact_sha256'] = str_repeat('a', 64);
                $member['canonical_ledger_authority_claimed'] = false;
                $member['evidence_refs'] = [
                    'candidate_prep_apply' => [
                        'kind' => 'candidate_prep_apply_overlay',
                        'write_verified' => true,
                        'artifact_sha256' => str_repeat('a', 64),
                    ],
                ];
            }
            $members[] = $member;
        }

        return $this->writeJson('ledger', ['members' => $members]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $name, array $payload): string
    {
        $path = $this->tempPath($name);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writeSlugArtifact(array $slugs): string
    {
        $path = $this->tempPath('delta-slugs');
        file_put_contents($path, implode(PHP_EOL, $slugs).PHP_EOL);

        return $path;
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().'/career-80-runtime-pool-'.Str::uuid().'-'.$name.'.json';
    }
}
