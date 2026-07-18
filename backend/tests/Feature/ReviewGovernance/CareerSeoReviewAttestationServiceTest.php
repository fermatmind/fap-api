<?php

declare(strict_types=1);

namespace Tests\Feature\ReviewGovernance;

use App\Models\ReviewAttestation;
use App\Services\ReviewGovernance\CareerSeoReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationFactory;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerSeoReviewAttestationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SURFACES = [
        'career_trust_manifest',
        'career_occupation_truth_metric_review',
        'career_editorial_patch',
        'career_occupation_directory_review',
        'career_salary_asset_review',
        'career_ai_impact_asset_review',
        'career_import_publish_readiness',
        'seo_agent_draft_review',
        'seo_canary_approval',
        'search_submission_queue_approval',
        'seo_claim_risk_review',
        'content_package_approval',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
    }

    public function test_all_registered_career_and_seo_surfaces_build_deterministic_private_targets(): void
    {
        $service = app(CareerSeoReviewAttestationService::class);
        $authoritative = [
            ['identity' => 'batch-item-b', 'sha256' => hash('sha256', 'payload-b')],
            ['identity' => 'batch-item-a', 'sha256' => hash('sha256', 'payload-a')],
        ];

        foreach (self::SURFACES as $surfaceId) {
            $targets = $service->targets($surfaceId, $authoritative);

            $this->assertSame($surfaceId.':batch-item-b', $targets[0]['target_identity']);
            $this->assertSame(hash('sha256', 'payload-b'), $targets[0]['target_sha256']);
            $this->assertSame($targets, $service->targets($surfaceId, $authoritative));
        }
    }

    public function test_exact_batch_review_expands_approved_and_exception_evidence_without_domain_actions(): void
    {
        $service = app(CareerSeoReviewAttestationService::class);
        $targets = $this->authoritativeTargets();
        $centralTargets = $service->targets('career_salary_asset_review', $targets);
        $attestation = app(ReviewAttestationFactory::class)->make(
            scopeType: 'career_salary_asset_batch',
            scopeIdentity: 'salary:v3.6:batch-1',
            decision: 'approved_with_exceptions',
            targets: array_reverse($centralTargets),
            packageSha256: hash('sha256', 'salary-package'),
            exceptions: [[
                'target_identity' => $centralTargets[1]['target_identity'],
                'reason' => 'private correction remains before approval',
            ]],
            adminUserId: 1,
        );

        $preflight = $service->preflight(
            $attestation,
            'career_salary_asset_review',
            $targets,
            hash('sha256', 'salary-package'),
        );
        $this->assertSame('PASS_SOLO_OWNER_ATTESTATION_PREFLIGHT', $preflight['status']);
        $this->assertSame(0, $preflight['database_writes']);
        $this->assertDatabaseCount('review_attestations', 0);

        $bound = $service->bindReview(
            $attestation,
            'career_salary_asset_review',
            $targets,
            1,
            hash('sha256', 'salary-package'),
        );

        $this->assertSame(2, $bound->targetEvidences->count());
        $this->assertSame(
            ['approved', 'excepted'],
            $bound->targetEvidences->pluck('target_decision')->sort()->values()->all(),
        );
        $this->assertFalse($service->hasApprovedAllEvidence('career_salary_asset_review', $targets));
        $this->assertSame([
            'publishes' => false,
            'imports' => false,
            'changes_indexability' => false,
            'submits_search_urls' => false,
            'changes_discoverability' => false,
            'writes_public_reviewer_identity' => false,
        ], $service->safetyBoundaries());
    }

    public function test_approved_all_requires_one_exact_batch_and_cannot_reuse_separate_single_target_evidence(): void
    {
        $service = app(CareerSeoReviewAttestationService::class);
        $targets = $this->authoritativeTargets();

        foreach ($targets as $index => $singleTarget) {
            $service->createAndBindReview(
                surfaceId: 'search_submission_queue_approval',
                scopeType: 'search_queue_item',
                scopeIdentity: 'queue:'.($index + 1),
                decision: 'approved_all',
                authoritativeTargets: [$singleTarget],
                actorAdminUserId: 1,
            );
        }

        $this->assertFalse($service->hasApprovedAllEvidence('search_submission_queue_approval', $targets));

        $service->createAndBindReview(
            surfaceId: 'search_submission_queue_approval',
            scopeType: 'search_queue_batch',
            scopeIdentity: 'queue:1,2',
            decision: 'approved_all',
            authoritativeTargets: array_reverse($targets),
            actorAdminUserId: 1,
        );

        $this->assertTrue($service->hasApprovedAllEvidence('search_submission_queue_approval', $targets));
        $service->assertApprovedAllEvidence('search_submission_queue_approval', array_reverse($targets));
        $schemaVersion = config('review_governance.attestation.schema_version');
        config()->set('review_governance.attestation.schema_version', 'rotated-schema-version');
        $this->assertFalse($service->hasApprovedAllEvidence('search_submission_queue_approval', $targets));
        config()->set('review_governance.attestation.schema_version', $schemaVersion);
        $statementVersion = config('review_governance.attestation.statement_version');
        config()->set('review_governance.attestation.statement_version', 'rotated-statement-version');
        $this->assertFalse($service->hasApprovedAllEvidence('search_submission_queue_approval', $targets));
        config()->set('review_governance.attestation.statement_version', $statementVersion);
        config()->set('review_governance.solo_owner_admin_user_id', 2);
        $this->assertFalse($service->hasApprovedAllEvidence('search_submission_queue_approval', $targets));
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        config()->set('review_governance.mode', 'team_separated');
        $this->assertFalse($service->hasApprovedAllEvidence('search_submission_queue_approval', $targets));
        $this->assertDatabaseCount('review_attestations', 3);
        $this->assertDatabaseCount('review_attestation_target_evidences', 4);
    }

    public function test_rejected_hash_drift_duplicate_and_extra_targets_fail_closed(): void
    {
        $service = app(CareerSeoReviewAttestationService::class);
        $targets = $this->authoritativeTargets();
        $rejected = $service->createAndBindReview(
            surfaceId: 'seo_claim_risk_review',
            scopeType: 'seo_claim_risk_batch',
            scopeIdentity: 'drafts:1,2',
            decision: 'rejected',
            authoritativeTargets: $targets,
            actorAdminUserId: 1,
        );
        $this->assertSame(['rejected'], $rejected->targetEvidences->pluck('target_decision')->unique()->values()->all());
        $this->assertFalse($service->hasApprovedAllEvidence('seo_claim_risk_review', $targets));

        $approved = app(ReviewAttestationFactory::class)->make(
            'seo_draft_batch',
            'drafts:1,2',
            'approved_all',
            $service->targets('seo_agent_draft_review', $targets),
            adminUserId: 1,
        );
        foreach ([
            'hash drift' => [
                ['identity' => 'draft:1', 'sha256' => hash('sha256', 'changed')],
                $targets[1],
            ],
            'missing target' => [$targets[0]],
            'extra target' => [...$targets, ['identity' => 'draft:3', 'sha256' => hash('sha256', 'draft-3')]],
        ] as $label => $driftedTargets) {
            try {
                $service->bindReview($approved, 'seo_agent_draft_review', $driftedTargets, 1);
                $this->fail('Expected '.$label.' to fail closed.');
            } catch (ReviewAttestationValidationException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        try {
            $service->targets('seo_agent_draft_review', [$targets[0], $targets[0]]);
            $this->fail('Expected a duplicate exact target to fail closed.');
        } catch (ReviewAttestationValidationException $exception) {
            $this->assertStringContainsString('duplicate identity', $exception->getMessage());
        }

        $this->assertDatabaseCount('review_attestations', 1);
        $this->assertDatabaseCount('review_attestation_target_evidences', 2);
    }

    public function test_package_scoped_evidence_requires_the_exact_current_package_sha(): void
    {
        $service = app(CareerSeoReviewAttestationService::class);
        $targets = $this->authoritativeTargets();
        $packageSha256 = hash('sha256', 'content-package-a');

        foreach ([null, 'invalid'] as $invalidPackageSha256) {
            try {
                $service->createAndBindReview(
                    surfaceId: 'content_package_approval',
                    scopeType: 'seo_content_package',
                    scopeIdentity: 'package:invalid',
                    decision: 'approved_all',
                    authoritativeTargets: $targets,
                    actorAdminUserId: 1,
                    packageSha256: $invalidPackageSha256,
                );
                $this->fail('Package-scoped evidence bound without an exact package SHA.');
            } catch (ReviewAttestationValidationException $exception) {
                $this->assertStringContainsString('package', strtolower($exception->getMessage()));
            }
        }
        $this->assertDatabaseCount('review_attestations', 0);

        $service->createAndBindReview(
            surfaceId: 'content_package_approval',
            scopeType: 'seo_content_package',
            scopeIdentity: 'package:a',
            decision: 'approved_all',
            authoritativeTargets: $targets,
            actorAdminUserId: 1,
            packageSha256: $packageSha256,
        );

        $this->assertFalse($service->hasApprovedAllEvidence('content_package_approval', $targets));
        $this->assertFalse($service->hasApprovedAllEvidence(
            'content_package_approval',
            $targets,
            hash('sha256', 'content-package-b'),
        ));
        $this->assertFalse($service->hasApprovedAllEvidence('content_package_approval', $targets, 'invalid'));
        $this->assertTrue($service->hasApprovedAllEvidence('content_package_approval', $targets, $packageSha256));
        $service->assertApprovedAllEvidence('content_package_approval', $targets, $packageSha256);
    }

    public function test_non_owner_and_team_separated_mode_cannot_bind_solo_owner_evidence(): void
    {
        $service = app(CareerSeoReviewAttestationService::class);
        $targets = $this->authoritativeTargets();

        foreach ([2, 999] as $actorId) {
            try {
                $service->createAndBindReview(
                    'career_import_publish_readiness',
                    'career_import_batch',
                    'career:import:1',
                    'approved_all',
                    $targets,
                    $actorId,
                );
                $this->fail('A non-owner bound solo-owner evidence.');
            } catch (ReviewAttestationValidationException $exception) {
                $this->assertStringContainsString('configured owner', $exception->getMessage());
            }
        }

        config()->set('review_governance.mode', 'team_separated');
        try {
            $service->createAndBindReview(
                'career_import_publish_readiness',
                'career_import_batch',
                'career:import:1',
                'approved_all',
                $targets,
                1,
            );
            $this->fail('Team-separated mode accepted solo-owner evidence.');
        } catch (ReviewAttestationValidationException $exception) {
            $this->assertStringContainsString('configured owner', $exception->getMessage());
        }

        $this->assertSame(0, ReviewAttestation::query()->count());
    }

    public function test_review_only_command_preflights_by_default_and_bind_never_executes_domain_actions(): void
    {
        $service = app(CareerSeoReviewAttestationService::class);
        $targets = $this->authoritativeTargets();
        $attestation = app(ReviewAttestationFactory::class)->make(
            'seo_canary_batch',
            'canary:article:1',
            'approved_all',
            $service->targets('seo_canary_approval', $targets),
            packageSha256: hash('sha256', 'seo-canary-package'),
            adminUserId: 1,
        );
        $attestationPath = $this->jsonFixture('career-seo-attestation', $attestation);
        $targetsPath = $this->jsonFixture('career-seo-targets', ['targets' => $targets]);

        $this->assertSame(1, Artisan::call('review:career-seo-attestation', [
            '--surface' => 'seo_canary_approval',
            '--attestation' => $attestationPath,
            '--targets' => $targetsPath,
            '--bind' => true,
            '--actor-admin-user-id' => 1,
            '--json' => true,
        ]));
        $blocked = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('BLOCKED_CAREER_SEO_REVIEW_ATTESTATION', $blocked['status']);
        $this->assertFalse($blocked['review_evidence_bound']);
        $this->assertDatabaseCount('review_attestations', 0);

        $this->assertSame(0, Artisan::call('review:career-seo-attestation', [
            '--surface' => 'seo_canary_approval',
            '--attestation' => $attestationPath,
            '--targets' => $targetsPath,
            '--expected-package-sha256' => hash('sha256', 'seo-canary-package'),
            '--json' => true,
        ]));
        $preflight = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS_SOLO_OWNER_ATTESTATION_PREFLIGHT', $preflight['status']);
        $this->assertFalse($preflight['review_evidence_bound']);
        $this->assertDatabaseCount('review_attestations', 0);

        $this->assertSame(0, Artisan::call('review:career-seo-attestation', [
            '--surface' => 'seo_canary_approval',
            '--attestation' => $attestationPath,
            '--targets' => $targetsPath,
            '--expected-package-sha256' => hash('sha256', 'seo-canary-package'),
            '--actor-admin-user-id' => 1,
            '--bind' => true,
            '--json' => true,
        ]));
        $bound = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS_SOLO_OWNER_REVIEW_EVIDENCE_BOUND', $bound['status']);
        $this->assertTrue($bound['review_evidence_bound']);
        $this->assertFalse($bound['safety_boundaries']['publishes']);
        $this->assertFalse($bound['safety_boundaries']['imports']);
        $this->assertFalse($bound['safety_boundaries']['changes_indexability']);
        $this->assertFalse($bound['safety_boundaries']['submits_search_urls']);
        $this->assertDatabaseCount('review_attestations', 1);
        $this->assertDatabaseCount('review_attestation_target_evidences', 2);
    }

    public function test_review_only_command_json_failure_redacts_private_target_identity(): void
    {
        $privateIdentity = 'private/seo/queue/operator-only-batch';
        $attestationPath = $this->jsonFixture('career-seo-invalid-attestation', []);
        $targetsPath = $this->jsonFixture('career-seo-private-duplicate-targets', ['targets' => [
            ['identity' => $privateIdentity, 'sha256' => hash('sha256', 'private-target-a')],
            ['identity' => $privateIdentity, 'sha256' => hash('sha256', 'private-target-b')],
        ]]);

        $exitCode = Artisan::call('review:career-seo-attestation', [
            '--surface' => 'search_submission_queue_approval',
            '--attestation' => $attestationPath,
            '--targets' => $targetsPath,
            '--json' => true,
        ]);
        $output = trim(Artisan::output());
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('BLOCKED_CAREER_SEO_REVIEW_ATTESTATION', $payload['status']);
        $this->assertSame('INVALID_CAREER_SEO_REVIEW_ATTESTATION', $payload['error_code']);
        $this->assertSame('Career/SEO review attestation validation failed.', $payload['error']);
        $this->assertStringNotContainsString($privateIdentity, $output);
        $this->assertStringNotContainsString('duplicate identity', $output);
        $this->assertFalse($payload['review_evidence_bound']);
        $this->assertDatabaseCount('review_attestations', 0);
        $this->assertDatabaseCount('review_attestation_target_evidences', 0);
    }

    /** @return list<array{identity:string,sha256:string}> */
    private function authoritativeTargets(): array
    {
        return [
            ['identity' => 'draft:1', 'sha256' => hash('sha256', 'draft-1')],
            ['identity' => 'draft:2', 'sha256' => hash('sha256', 'draft-2')],
        ];
    }

    /** @param array<mixed> $payload */
    private function jsonFixture(string $label, array $payload): string
    {
        $path = sys_get_temp_dir().'/'.$label.'-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

        return $path;
    }
}
