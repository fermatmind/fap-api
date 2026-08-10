<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Services\ReviewGovernance\PublicReviewContract;
use App\Services\ReviewGovernance\ReviewPolicyRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class ReviewPolicyRegistryTest extends TestCase
{
    private const REQUIRED_SURFACE_IDS = [
        'article',
        'article_translation_revision',
        'cms_translation_revision',
        'content_page',
        'content_page_external_evidence_gate',
        'support_article',
        'interpretation_guide',
        'research_report',
        'editorial_review',
        'public_topic_edge',
        'personality_public_content_asset',
        'personality_public_content_asset_revision_review',
        'big_five_v2_editorial_revision',
        'mbti_approval_batch',
        'mbti_cross_type_comparison_authority',
        'enneagram_review_binder',
        'riasec_content_release_review',
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
        'admin_approval',
        'refund_approval',
        'manual_benefit_grant_approval',
        'benefit_revoke_approval',
        'payment_event_reprocess_approval',
        'rollback_release_approval',
        'data_lifecycle_approval',
        'daily_giving_operator_approval',
        'media_library_operator_approval',
    ];

    public function test_registry_has_complete_unique_schema_and_required_boundaries(): void
    {
        $rows = ReviewPolicyRegistry::all();
        $ids = array_column($rows, 'surface_id');
        $expectedIds = self::REQUIRED_SURFACE_IDS;
        sort($ids, SORT_STRING);
        sort($expectedIds, SORT_STRING);

        $this->assertSame($expectedIds, $ids);
        $this->assertCount(count(array_unique($ids)), $ids);
        foreach ($rows as $row) {
            $this->assertSame('solo_owner', $row['review_mode']);
            $this->assertTrue($row['compact_attestation_supported']);
            $this->assertTrue($row['same_actor_allowed']);
            $this->assertTrue($row['production_execution_separate']);
            $this->assertNotSame('', $row['public_projection']);
            $this->assertNotSame('', $row['migration_pr']);
            if ($row['risk_tier'] === 'R3') {
                $this->assertTrue($row['step_up_required']);
            }
            if ($row['external_evidence_required']) {
                $this->assertSame('R4', $row['risk_tier']);
            }
        }
    }

    public function test_models_with_review_or_approval_fields_are_registered(): void
    {
        $registeredModels = array_fill_keys(
            array_column(ReviewPolicyRegistry::all(), 'current_model_or_service'),
            true,
        );
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app/Models'));
        $unregistered = [];
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/reviewed_by|reviewer_|approval_state|approved_by|review_status|review_state|reviewed_at|approved_at|operator_approval/', $source) !== 1) {
                continue;
            }
            $class = 'App\\Models\\'.$file->getBasename('.php');
            if (! isset($registeredModels[$class])) {
                $unregistered[] = $class;
            }
        }

        $this->assertSame([], $unregistered, 'Manual-review models must be registered before merge.');
    }

    public function test_generated_inventory_matches_the_authoritative_registry(): void
    {
        $path = dirname(__DIR__, 3).'/docs/operations/generated/solo-owner-review-surface-registry.v1.json';
        $artifact = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $inventory = ReviewPolicyRegistry::inventory();

        $this->assertSame($inventory, $artifact);
        $this->assertFalse($artifact['boundaries']['human_review_is_production_authorization']);
        $this->assertFalse($artifact['boundaries']['external_evidence_can_be_created_by_attestation']);
        $this->assertFalse($artifact['boundaries']['public_reviewer_identity_allowed']);
        $this->assertSame(0, $artifact['boundaries']['legacy_internal_reviewer_separation_blocker_count']);
        $this->assertSame(0, $artifact['boundaries']['public_reviewer_identity_exposure_count']);
        $this->assertSame(
            ['review_state', 'last_reviewed_at', 'reviewer'],
            $artifact['public_review_contract']['fields'],
        );
        $this->assertNull($artifact['public_review_contract']['reviewer']);
    }

    public function test_pr6_public_surfaces_use_only_the_normalized_identity_redacted_contract(): void
    {
        $inventory = ReviewPolicyRegistry::inventory();
        $byId = collect($inventory['surfaces'])->keyBy('surface_id');

        foreach ($inventory['public_review_contract']['surface_ids'] as $surfaceId) {
            $row = $byId->get($surfaceId);
            $this->assertIsArray($row);
            $this->assertSame('normalized_review_contract_v1', $row['public_projection']);
        }

        $this->assertSame(
            ['approved', 'pending', 'rejected', 'unknown'],
            $inventory['public_review_contract']['states'],
        );
        $this->assertSame(0, $inventory['boundaries']['legacy_internal_reviewer_separation_blocker_count']);
        $this->assertSame(0, $inventory['boundaries']['public_reviewer_identity_exposure_count']);
    }

    public function test_pr6_public_contract_annotations_cover_every_registered_public_surface(): void
    {
        $reflection = new ReflectionClass(PublicReviewContract::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        preg_match_all('/@review-surface\s+([a-z0-9_]+)/', $source, $matches);

        $annotated = array_values(array_unique($matches[1] ?? []));
        $registered = ReviewPolicyRegistry::inventory()['public_review_contract']['surface_ids'];
        sort($annotated);
        sort($registered);

        $this->assertSame($registered, $annotated);
    }

    public function test_pr2_cms_adapters_are_active_without_weakening_external_evidence(): void
    {
        $byId = collect(ReviewPolicyRegistry::all())->keyBy('surface_id');

        foreach ([
            'article',
            'article_translation_revision',
            'cms_translation_revision',
            'content_page',
            'support_article',
            'interpretation_guide',
            'editorial_review',
        ] as $surfaceId) {
            $this->assertSame('compact_attestation_adapter_active', $byId[$surfaceId]['adapter_status']);
        }

        $this->assertSame(
            'compact_attestation_adapter_active_external_evidence_still_required',
            $byId['research_report']['adapter_status'],
        );
        $this->assertTrue($byId['research_report']['external_evidence_required']);
        $this->assertSame(
            'external_evidence_gate_preserved',
            $byId['content_page_external_evidence_gate']['adapter_status'],
        );
        $this->assertTrue($byId['content_page_external_evidence_gate']['external_evidence_required']);
    }

    public function test_pr4_career_and_seo_adapters_are_active_without_weakening_external_evidence(): void
    {
        $byId = collect(ReviewPolicyRegistry::all())->keyBy('surface_id');

        foreach ([
            'career_trust_manifest',
            'career_editorial_patch',
            'career_occupation_directory_review',
            'career_salary_asset_review',
            'career_ai_impact_asset_review',
            'career_import_publish_readiness',
            'seo_agent_draft_review',
            'seo_canary_approval',
            'search_submission_queue_approval',
            'content_package_approval',
        ] as $surfaceId) {
            $this->assertSame('compact_attestation_adapter_active', $byId[$surfaceId]['adapter_status']);
        }

        foreach ([
            'career_occupation_truth_metric_review',
            'seo_claim_risk_review',
        ] as $surfaceId) {
            $this->assertSame(
                'compact_attestation_adapter_active_external_evidence_still_required',
                $byId[$surfaceId]['adapter_status'],
            );
            $this->assertTrue($byId[$surfaceId]['external_evidence_required']);
        }
    }

    public function test_pr5_ops_adapters_require_step_up_and_separate_execution(): void
    {
        $byId = collect(ReviewPolicyRegistry::all())->keyBy('surface_id');

        foreach ([
            'admin_approval',
            'refund_approval',
            'manual_benefit_grant_approval',
            'benefit_revoke_approval',
            'payment_event_reprocess_approval',
            'rollback_release_approval',
            'data_lifecycle_approval',
        ] as $surfaceId) {
            $row = $byId->get($surfaceId);
            $this->assertIsArray($row);
            $this->assertSame('R3', $row['risk_tier']);
            $this->assertTrue($row['same_actor_allowed']);
            $this->assertTrue($row['step_up_required']);
            $this->assertTrue($row['production_execution_separate']);
            $this->assertSame('step_up_high_risk_approval_adapter_active', $row['adapter_status']);
        }
    }

    public function test_new_or_modified_manual_review_gates_declare_a_registered_surface(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $baseRef = $this->diffBaseRef($repoRoot);
        $command = sprintf(
            'git -C %s diff --name-only --diff-filter=ACMR %s HEAD -- backend/app backend/database/migrations',
            escapeshellarg($repoRoot),
            escapeshellarg($baseRef),
        );
        exec($command, $files, $exitCode);
        $this->assertSame(0, $exitCode, 'Unable to inspect branch diff for review gates.');

        $addedCommand = sprintf(
            'git -C %s diff --name-only --diff-filter=A %s HEAD -- backend/app backend/database/migrations',
            escapeshellarg($repoRoot),
            escapeshellarg($baseRef),
        );
        $addedFiles = [];
        $addedExitCode = 0;
        exec($addedCommand, $addedFiles, $addedExitCode);
        $this->assertSame(0, $addedExitCode, 'Unable to inspect added review-governance foundation files.');
        $addedFileSet = array_fill_keys(array_values(array_unique($addedFiles)), true);

        $registered = array_fill_keys(array_column(ReviewPolicyRegistry::all(), 'surface_id'), true);
        $missing = [];
        foreach (array_values(array_unique($files)) as $file) {
            if ($this->shouldExemptReviewGovernanceFoundationPath($file, $addedFileSet)) {
                continue;
            }
            $diffCommand = sprintf(
                'git -C %s diff --unified=0 %s HEAD -- %s',
                escapeshellarg($repoRoot),
                escapeshellarg($baseRef),
                escapeshellarg($file),
            );
            $diffLines = [];
            $diffExitCode = 0;
            exec($diffCommand, $diffLines, $diffExitCode);
            $this->assertSame(0, $diffExitCode, 'Unable to inspect changed review-gate lines for '.$file.'.');
            $changed = implode("\n", array_filter(
                $diffLines,
                static fn (string $line): bool => (str_starts_with($line, '+') && ! str_starts_with($line, '+++'))
                    || (str_starts_with($line, '-') && ! str_starts_with($line, '---')),
            ));
            $path = $repoRoot.'/'.$file;
            $source = is_file($path) ? (string) file_get_contents($path) : '';
            if (! $this->containsReviewGateMarker($source) && ! $this->containsReviewGateMarker($changed)) {
                continue;
            }
            preg_match_all('/@review-surface\s+([a-z0-9_]+)/', $source, $matches);
            $surfaceIds = array_values(array_unique($matches[1] ?? []));
            if ($surfaceIds === [] || array_filter($surfaceIds, static fn (string $id): bool => ! isset($registered[$id])) !== []) {
                $missing[] = $file;
            }
        }

        $this->assertSame([], $missing, 'New or modified manual-review gates must declare @review-surface with a registered surface ID.');
    }

    public function test_foundation_exemption_only_covers_added_pr1_files(): void
    {
        $foundationFile = 'backend/app/Services/ReviewGovernance/ReviewAttestationValidator.php';

        $this->assertTrue($this->shouldExemptReviewGovernanceFoundationPath(
            $foundationFile,
            [$foundationFile => true],
        ));
        $this->assertFalse($this->shouldExemptReviewGovernanceFoundationPath($foundationFile, []));
        $this->assertFalse($this->shouldExemptReviewGovernanceFoundationPath(
            'backend/app/Services/ReviewGovernance/FutureApprovalAdapter.php',
            ['backend/app/Services/ReviewGovernance/FutureApprovalAdapter.php' => true],
        ));
        $this->assertFalse($this->shouldExemptReviewGovernanceFoundationPath(
            'backend/app/DTO/ReviewGovernance/FutureReviewRequest.php',
            ['backend/app/DTO/ReviewGovernance/FutureReviewRequest.php' => true],
        ));
    }

    public function test_review_gate_marker_detection_covers_existing_and_removed_markers(): void
    {
        $this->assertTrue($this->containsReviewGateMarker('if ($record->approval_state === \'approved\') {}'));
        $this->assertTrue($this->containsReviewGateMarker('-    $record->reviewed_at = now();'));
        $this->assertTrue($this->containsReviewGateMarker('$this->reviewAttestationService->bind($attestation);'));
        $this->assertTrue($this->containsReviewGateMarker('use App\\Services\\ReviewGovernance\\ReviewAttestationValidator;'));
        $this->assertTrue($this->containsReviewGateMarker('$payload[\'review_attestation\'] = $attestation;'));
        $this->assertTrue($this->containsReviewGateMarker('$revision->revision_status === ArticleTranslationRevision::STATUS_APPROVED'));
        $this->assertFalse($this->containsReviewGateMarker('+    $record->title = $title;'));
    }

    private function diffBaseRef(string $repoRoot): string
    {
        $override = trim((string) getenv('REVIEW_POLICY_BASE_REF'));
        if ($override !== '') {
            $resolved = $this->resolveCommit($repoRoot, $override);
            $this->assertNotNull($resolved, 'REVIEW_POLICY_BASE_REF does not resolve to a commit.');

            return $this->resolveMergeBase($repoRoot, $resolved) ?? $resolved;
        }

        $githubBaseRef = trim((string) getenv('GITHUB_BASE_REF'));
        foreach (array_filter([
            $githubBaseRef === '' ? null : 'origin/'.$githubBaseRef,
            'origin/main',
        ]) as $candidate) {
            $resolved = $this->resolveCommit($repoRoot, $candidate);
            if ($resolved !== null) {
                $mergeBase = $this->resolveMergeBase($repoRoot, $resolved);
                if ($mergeBase !== null) {
                    return $mergeBase;
                }
            }
        }

        $githubBaseSha = $this->githubEventBaseSha();
        if ($githubBaseSha !== null) {
            if ($this->resolveCommit($repoRoot, $githubBaseSha) === null) {
                $fetchCommand = sprintf(
                    'git -C %s fetch --no-tags --depth=1 origin %s',
                    escapeshellarg($repoRoot),
                    escapeshellarg($githubBaseSha),
                );
                $fetchOutput = [];
                $fetchExitCode = 0;
                exec($fetchCommand, $fetchOutput, $fetchExitCode);
                $this->assertSame(0, $fetchExitCode, 'Unable to fetch the exact GitHub pull-request base commit.');
            }
            $resolved = $this->resolveCommit($repoRoot, $githubBaseSha);
            $this->assertNotNull($resolved, 'GitHub pull-request base commit is unavailable after fetch.');

            return $resolved;
        }

        $parent = $this->resolveCommit($repoRoot, 'HEAD^1');
        $this->assertNotNull($parent, 'Unable to resolve a review-policy diff base from override, remote base, GitHub event, or HEAD parent.');

        return $parent;
    }

    private function resolveCommit(string $repoRoot, string $ref): ?string
    {
        $command = sprintf(
            'git -C %s rev-parse --verify --quiet %s',
            escapeshellarg($repoRoot),
            escapeshellarg($ref.'^{commit}'),
        );
        $output = [];
        exec($command, $output, $exitCode);
        $resolved = trim((string) ($output[0] ?? ''));

        return $exitCode === 0 && preg_match('/^[0-9a-f]{40}$/', $resolved) === 1 ? $resolved : null;
    }

    private function resolveMergeBase(string $repoRoot, string $ref): ?string
    {
        $command = sprintf(
            'git -C %s merge-base HEAD %s',
            escapeshellarg($repoRoot),
            escapeshellarg($ref),
        );
        $output = [];
        exec($command, $output, $exitCode);
        $resolved = trim((string) ($output[0] ?? ''));

        return $exitCode === 0 && preg_match('/^[0-9a-f]{40}$/', $resolved) === 1 ? $resolved : null;
    }

    private function githubEventBaseSha(): ?string
    {
        $eventPath = trim((string) getenv('GITHUB_EVENT_PATH'));
        if ($eventPath === '' || ! is_file($eventPath)) {
            return null;
        }
        $event = json_decode((string) file_get_contents($eventPath), true);
        $baseSha = is_array($event) ? ($event['pull_request']['base']['sha'] ?? null) : null;

        return is_string($baseSha) && preg_match('/^[0-9a-f]{40}$/', $baseSha) === 1 ? $baseSha : null;
    }

    private function isReviewGovernanceFoundationPath(string $file): bool
    {
        return in_array($file, [
            'backend/app/Console/Commands/ReviewAttestationPreflight.php',
            'backend/app/DTO/ReviewGovernance/ReviewTarget.php',
            'backend/app/DTO/ReviewGovernance/ReviewTargetSet.php',
            'backend/app/DTO/ReviewGovernance/ValidatedReviewAttestation.php',
            'backend/app/Models/ReviewAttestation.php',
            'backend/app/Models/ReviewAttestationTargetEvidence.php',
            'backend/app/Services/ReviewGovernance/ReviewAttestationCanonicalizer.php',
            'backend/app/Services/ReviewGovernance/ReviewAttestationFactory.php',
            'backend/app/Services/ReviewGovernance/ReviewAttestationFingerprintBuilder.php',
            'backend/app/Services/ReviewGovernance/ReviewAttestationSchema.php',
            'backend/app/Services/ReviewGovernance/ReviewAttestationService.php',
            'backend/app/Services/ReviewGovernance/ReviewAttestationValidationException.php',
            'backend/app/Services/ReviewGovernance/ReviewAttestationValidator.php',
            'backend/app/Services/ReviewGovernance/ReviewPolicyRegistry.php',
            'backend/database/migrations/2026_07_17_150000_create_review_attestations_and_target_evidence_tables.php',
        ], true);
    }

    /**
     * @param  array<string, bool>  $addedFileSet
     */
    private function shouldExemptReviewGovernanceFoundationPath(string $file, array $addedFileSet): bool
    {
        return isset($addedFileSet[$file]) && $this->isReviewGovernanceFoundationPath($file);
    }

    private function containsReviewGateMarker(string $source): bool
    {
        return preg_match(
            '/manual[ _-]?review|human[ _-]?review|reviewer_|review_state|review_status|revision_status|STATUS_APPROVED|reviewed_by|reviewed_at|approval_state|approved_by|approved_at|operator_approval|review[ _-]?attestation|ReviewAttestation/i',
            $source,
        ) === 1;
    }
}
