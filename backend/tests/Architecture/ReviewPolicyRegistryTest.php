<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Services\ReviewGovernance\ReviewPolicyRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
            $this->assertNotSame('', $row['public_projection']);
            $this->assertNotSame('', $row['migration_pr']);
            if ($row['risk_tier'] === 'R3') {
                $this->assertTrue($row['step_up_required']);
                $this->assertTrue($row['production_execution_separate']);
            }
            if ($row['external_evidence_required']) {
                $this->assertSame('R4', $row['risk_tier']);
                $this->assertTrue($row['production_execution_separate']);
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
    }

    public function test_new_or_modified_manual_review_gates_declare_a_registered_surface(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $baseRef = $this->mergeBase($repoRoot);
        $command = sprintf(
            'git -C %s diff --name-only --diff-filter=ACMR %s...HEAD -- backend/app backend/database/migrations',
            escapeshellarg($repoRoot),
            escapeshellarg($baseRef),
        );
        exec($command, $files, $exitCode);
        $this->assertSame(0, $exitCode, 'Unable to inspect branch diff for review gates.');

        $registered = array_fill_keys(array_column(ReviewPolicyRegistry::all(), 'surface_id'), true);
        $missing = [];
        foreach (array_values(array_unique($files)) as $file) {
            if ($this->isReviewGovernanceFoundationPath($file)) {
                continue;
            }
            $diffCommand = sprintf(
                'git -C %s diff --unified=0 %s...HEAD -- %s',
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

    public function test_foundation_exemption_does_not_cover_future_review_governance_files(): void
    {
        $this->assertTrue($this->isReviewGovernanceFoundationPath(
            'backend/app/Services/ReviewGovernance/ReviewAttestationValidator.php',
        ));
        $this->assertFalse($this->isReviewGovernanceFoundationPath(
            'backend/app/Services/ReviewGovernance/FutureApprovalAdapter.php',
        ));
        $this->assertFalse($this->isReviewGovernanceFoundationPath(
            'backend/app/DTO/ReviewGovernance/FutureReviewRequest.php',
        ));
    }

    public function test_review_gate_marker_detection_covers_existing_and_removed_markers(): void
    {
        $this->assertTrue($this->containsReviewGateMarker('if ($record->approval_state === \'approved\') {}'));
        $this->assertTrue($this->containsReviewGateMarker('-    $record->reviewed_at = now();'));
        $this->assertTrue($this->containsReviewGateMarker('$this->reviewAttestationService->bind($attestation);'));
        $this->assertTrue($this->containsReviewGateMarker('use App\\Services\\ReviewGovernance\\ReviewAttestationValidator;'));
        $this->assertTrue($this->containsReviewGateMarker('$payload[\'review_attestation\'] = $attestation;'));
        $this->assertFalse($this->containsReviewGateMarker('+    $record->title = $title;'));
    }

    private function mergeBase(string $repoRoot): string
    {
        $command = sprintf('git -C %s merge-base HEAD origin/main', escapeshellarg($repoRoot));
        exec($command, $output, $exitCode);
        $this->assertSame(0, $exitCode, 'Unable to resolve merge base with origin/main.');
        $baseRef = trim((string) ($output[0] ?? ''));
        $this->assertNotSame('', $baseRef, 'Merge base with origin/main is empty.');

        return $baseRef;
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

    private function containsReviewGateMarker(string $source): bool
    {
        return preg_match(
            '/manual[ _-]?review|human[ _-]?review|reviewer_|review_state|review_status|reviewed_by|reviewed_at|approval_state|approved_by|approved_at|operator_approval|review[ _-]?attestation|ReviewAttestation/i',
            $source,
        ) === 1;
    }
}
