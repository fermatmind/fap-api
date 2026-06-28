<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use Illuminate\Support\Facades\DB;

final class Mbti64CmsProjectionDraftWriter
{
    private const SNAPSHOT_KEY = 'mbti64_agent_projection_draft_v1';

    private const PACKAGE_ARTIFACT = 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-01';

    private const QA_ARTIFACT = 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-QA-01';

    private const NEXT_BATCH_6_PACKAGE_ARTIFACT = 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-6-HANDOFF-01';

    private const NEXT_BATCH_6_QA_ARTIFACT = 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-6-HANDOFF-QA-01';

    private const NEXT_BATCH_6_V2_PACKAGE_ARTIFACT = 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01';

    private const NEXT_BATCH_6_V2_QA_ARTIFACT = 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-QA-01';

    private const REMAINING_58_V2_PACKAGE_ARTIFACT = 'MBTI64-REMAINING-58-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01';

    private const REMAINING_58_V2_QA_ARTIFACT = 'MBTI64-REMAINING-58-COMPETITOR-GAP-CONTENT-EXPANSION-V2-QA-01';

    private const VISIBLE_QUERY_BACKED_3_URLS = [
        'https://fermatmind.com/en/personality/enfj-a',
        'https://fermatmind.com/zh/personality/intp-a',
        'https://fermatmind.com/zh/personality/esfp-a',
    ];

    private const FRESH_QUERY_BACKED_3_URLS = [
        'https://fermatmind.com/zh/personality/istp-a',
        'https://fermatmind.com/zh/personality/intp-a',
        'https://fermatmind.com/zh/personality/esfj-a',
    ];

    private const FRESH_QUERY_BACKED_5_URLS = [
        'https://fermatmind.com/en/personality/enfp-a',
        'https://fermatmind.com/zh/personality/istp-a',
        'https://fermatmind.com/en/personality/esfj-a',
        'https://fermatmind.com/zh/personality/esfj-a',
        'https://fermatmind.com/en/personality/intp-a',
    ];

    private const NEXT_BATCH_6_URLS = [
        'https://fermatmind.com/zh/personality/intp-a',
        'https://fermatmind.com/en/personality/intp-a',
        'https://fermatmind.com/zh/personality/esfp-a',
        'https://fermatmind.com/en/personality/esfp-a',
        'https://fermatmind.com/en/personality/enfj-a',
        'https://fermatmind.com/zh/personality/enfj-a',
    ];

    private const AGENT_BATCH_ALLOWED_SIZES = [5, 10];

    private const FORBIDDEN_ROUTE_PATTERNS = [
        '#/results?(?:/|$)#i',
        '#/orders?(?:/|$)#i',
        '#/share(?:/|$)#i',
        '#/pay(?:/|$)#i',
        '#/payment(?:/|$)#i',
        '#/history(?:/|$)#i',
        '#/private(?:/|$)#i',
        '#/account(?:/|$)#i',
        '#[?&](?:token|session|user|result_id|report_id|order_no)=#i',
    ];

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(array $package, array $qa, string $sourceSha256, string $qaSha256, array $options = []): array
    {
        return $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, false, $options);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function write(array $package, array $qa, string $sourceSha256, string $qaSha256, array $options = []): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, true, $options));
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildSummary(
        array $package,
        array $qa,
        string $sourceSha256,
        string $qaSha256,
        bool $write,
        array $options,
    ): array {
        $errors = $this->validatePackageAndQa($package, $qa, $options);
        $warnings = array_values(array_filter((array) ($qa['warnings'] ?? []), static fn (mixed $warning): bool => is_string($warning)));
        $qaResultsByUrl = $this->qaResultsByUrl($qa);
        $recommendations = $this->recommendationsForOptions($package, $options, $write, $errors);

        $preparedRows = [];
        foreach ($recommendations as $position => $recommendation) {
            $identity = $this->identityForRecommendation($recommendation);
            if ($identity === null) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'unsupported_mbti64_target_url',
                    'message' => 'Unsupported MBTI64 public profile URL: '.((string) ($recommendation['target_url'] ?? '')),
                ];

                continue;
            }

            $target = $this->targetRecord($identity);
            $targetId = $target['id'] ?? null;
            $pageType = (string) $identity['page_type'];
            $targetField = $pageType === 'comparison' ? 'profile_id' : 'personality_profile_variant_id';

            if (! is_int($targetId)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'target_not_found',
                    'message' => 'CMS target record was not found for MBTI64 agent projection '.$identity['path'],
                ];
            }

            if ($this->containsForbiddenRoutePattern(json_encode($recommendation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position),
                    'code' => 'forbidden_public_route_pattern_present',
                    'message' => 'Recommendation contains a forbidden private route or sensitive query pattern.',
                ];
            }

            $existingRevision = is_int($targetId)
                ? $this->existingRevision($pageType, $targetField, $targetId, $sourceSha256)
                : null;
            $nextRevisionNo = is_int($targetId)
                ? $this->nextRevisionNo($pageType, $targetField, $targetId)
                : null;
            $qaResult = $qaResultsByUrl[(string) $recommendation['target_url']] ?? [];
            $recommendationSha256 = hash('sha256', $this->jsonString($recommendation));

            $preparedRows[] = [
                'position' => $position + 1,
                'url' => (string) $recommendation['target_url'],
                'path' => $identity['path'],
                'locale' => $identity['locale'],
                'page_type' => $pageType,
                'identity' => $identity,
                'target_table' => $pageType === 'comparison'
                    ? 'personality_profile_revisions'
                    : 'personality_profile_variant_revisions',
                'target_id' => $targetId,
                'snapshot_key' => self::SNAPSHOT_KEY,
                'source_sha256' => $sourceSha256,
                'qa_source_sha256' => $qaSha256,
                'recommendation_sha256' => $recommendationSha256,
                'existing_revision_id' => $existingRevision?->id !== null ? (int) $existingRevision->id : null,
                'existing_revision_no' => $existingRevision?->revision_no !== null ? (int) $existingRevision->revision_no : null,
                'next_revision_no' => $nextRevisionNo,
                'write_mode' => $write ? 'write_draft_revision' : 'dry_run',
                'action' => 'pending',
                'snapshot_preview' => $this->snapshotPayload($package, $qa, $recommendation, $identity, $sourceSha256, $qaSha256, $qaResult),
            ];
        }

        $approvalGate = $this->agentBatchApprovalGate($preparedRows, $sourceSha256, $qaSha256, $options);
        if ((bool) ($approvalGate['required_for_write'] ?? false)) {
            $approvalRowsByUrl = is_array($approvalGate['rows_by_url'] ?? null) ? $approvalGate['rows_by_url'] : [];
            foreach ($preparedRows as &$preparedRow) {
                $preparedRow['approval_queue'] = $approvalRowsByUrl[(string) ($preparedRow['url'] ?? '')] ?? [
                    'ready' => false,
                    'blocker' => 'approval_row_missing',
                ];
            }
            unset($preparedRow);
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write, $options), [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($preparedRows),
                'variant_row_count' => $this->countRows($preparedRows, 'variant'),
                'comparison_row_count' => $this->countRows($preparedRows, 'comparison'),
                'subset' => $this->subsetSummary($options, $preparedRows),
                'approval_queue' => $this->approvalGateForOutput($approvalGate),
                'rows' => $preparedRows,
                'errors' => $errors,
                'warnings' => $warnings,
            ]);
        }

        if ($write && (bool) ($approvalGate['required_for_write'] ?? false) && ! (bool) ($approvalGate['ready_for_write'] ?? false)) {
            return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write, $options), [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($preparedRows),
                'variant_row_count' => $this->countRows($preparedRows, 'variant'),
                'comparison_row_count' => $this->countRows($preparedRows, 'comparison'),
                'created_revision_count' => 0,
                'skipped_existing_count' => 0,
                'would_create_revision_count' => 0,
                'writes_committed' => false,
                'subset' => $this->subsetSummary($options, $preparedRows),
                'approval_queue' => $this->approvalGateForOutput($approvalGate),
                'rows' => $preparedRows,
                'errors' => [[
                    'field' => 'approval_queue',
                    'code' => 'agent_batch_approval_required',
                    'message' => 'Agent batch writes require every selected recommendation to have a matching approved personality agent approval queue item.',
                ]],
                'warnings' => $warnings,
            ]);
        }

        $created = 0;
        $skippedExisting = 0;
        if ($write) {
            foreach ($preparedRows as &$preparedRow) {
                if (($preparedRow['existing_revision_id'] ?? null) !== null) {
                    $preparedRow['action'] = 'skipped_existing';
                    $skippedExisting++;

                    continue;
                }

                $revision = $this->createRevision($preparedRow);
                $preparedRow['action'] = 'created';
                $preparedRow['created_revision_id'] = (int) $revision->id;
                $preparedRow['created_revision_no'] = (int) $revision->revision_no;
                $created++;
            }
            unset($preparedRow);
        } else {
            foreach ($preparedRows as &$preparedRow) {
                if (($preparedRow['existing_revision_id'] ?? null) !== null) {
                    $preparedRow['action'] = 'would_skip_existing';
                    $skippedExisting++;

                    continue;
                }

                $preparedRow['action'] = 'would_create';
            }
            unset($preparedRow);
        }

        return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write, $options), [
            'ok' => true,
            'status' => 'pass',
            'row_count' => count($preparedRows),
            'variant_row_count' => $this->countRows($preparedRows, 'variant'),
            'comparison_row_count' => $this->countRows($preparedRows, 'comparison'),
            'created_revision_count' => $created,
            'skipped_existing_count' => $skippedExisting,
            'would_create_revision_count' => $write ? 0 : count($preparedRows) - $skippedExisting,
            'writes_committed' => $write && $created > 0,
            'subset' => $this->subsetSummary($options, $preparedRows),
            'approval_queue' => $this->approvalGateForOutput($approvalGate),
            'rows' => $preparedRows,
            'errors' => [],
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return list<array<string,string>>
     */
    private function validatePackageAndQa(array $package, array $qa, array $options): array
    {
        if ($this->nextBatch6Requested($options)) {
            return $this->validateNextBatch6PackageAndQa($package, $qa);
        }

        if ($this->remaining58Requested($options)) {
            return $this->validateRemaining58PackageAndQa($package, $qa);
        }

        $errors = [];
        $summary = is_array($package['summary'] ?? null) ? $package['summary'] : [];
        $qaSummary = is_array($qa['summary'] ?? null) ? $qa['summary'] : [];

        if ((string) ($package['artifact'] ?? '') !== self::PACKAGE_ARTIFACT) {
            $errors[] = ['field' => 'artifact', 'code' => 'unsupported_package_artifact', 'message' => 'Unexpected package artifact.'];
        }
        if ((string) ($package['version'] ?? '') !== 'mbti64.agent_expansion_88_recommendations.v1') {
            $errors[] = ['field' => 'version', 'code' => 'unsupported_package_version', 'message' => 'Unexpected package version.'];
        }
        if ((string) ($package['status'] ?? '') !== 'pass_ready_for_qa_gates') {
            $errors[] = ['field' => 'status', 'code' => 'package_status_not_ready_for_qa', 'message' => 'Package must be ready for QA gates.'];
        }
        if (count($this->recommendations($package)) !== 88 || (int) ($summary['recommendation_count'] ?? -1) !== 88) {
            $errors[] = ['field' => 'recommendations', 'code' => 'unexpected_recommendation_count', 'message' => 'Expected exactly 88 expansion recommendations.'];
        }
        if ((int) ($summary['variant_pages'] ?? -1) !== 58 || (int) ($summary['comparison_pages'] ?? -1) !== 30) {
            $errors[] = ['field' => 'summary', 'code' => 'unexpected_page_type_counts', 'message' => 'Expected 58 variant and 30 comparison recommendations.'];
        }
        if ((string) ($qa['artifact'] ?? '') !== self::QA_ARTIFACT) {
            $errors[] = ['field' => 'qa.artifact', 'code' => 'unsupported_qa_artifact', 'message' => 'Unexpected QA artifact.'];
        }
        if ((string) ($qa['final_decision'] ?? '') !== 'PASS_READY_FOR_CMS_DRAFT') {
            $errors[] = ['field' => 'qa.final_decision', 'code' => 'qa_not_ready_for_cms_draft', 'message' => 'QA final decision must pass before draft write.'];
        }
        if ((int) ($qaSummary['checked_recommendation_count'] ?? -1) !== 88
            || (int) ($qaSummary['pass_ready_for_cms_draft_count'] ?? -1) !== 88
            || (int) ($qaSummary['blocked_count'] ?? -1) !== 0) {
            $errors[] = ['field' => 'qa.summary', 'code' => 'qa_summary_not_all_pass', 'message' => 'QA summary must show 88 pass and 0 blocked.'];
        }
        if ((array) ($qa['blockers'] ?? []) !== []) {
            $errors[] = ['field' => 'qa.blockers', 'code' => 'qa_blockers_present', 'message' => 'QA blockers must be empty.'];
        }

        $recommendationUrls = array_map(static fn (array $item): string => (string) ($item['target_url'] ?? ''), $this->recommendations($package));
        $qaUrls = array_map(
            static fn (array $item): string => (string) ($item['target_url'] ?? ''),
            array_values(array_filter(
                is_array($qa['page_results'] ?? null) ? $qa['page_results'] : [],
                static fn (mixed $item): bool => is_array($item)
            ))
        );
        sort($recommendationUrls);
        sort($qaUrls);
        if ($recommendationUrls !== $qaUrls) {
            $errors[] = ['field' => 'qa.page_results', 'code' => 'qa_url_set_mismatch', 'message' => 'QA page result URLs must match recommendation URLs.'];
        }

        foreach ($this->qaResultsByUrl($qa) as $url => $result) {
            if ((string) ($result['decision'] ?? '') !== 'PASS_READY_FOR_CMS_DRAFT' || (array) ($result['blockers'] ?? []) !== []) {
                $errors[] = ['field' => 'qa.page_results.'.$url, 'code' => 'qa_page_not_pass', 'message' => 'Every QA page result must pass with no blockers.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return list<array<string,string>>
     */
    private function validateNextBatch6PackageAndQa(array $package, array $qa): array
    {
        $errors = [];
        $summary = is_array($package['summary'] ?? null) ? $package['summary'] : [];
        $qaSummary = is_array($qa['summary'] ?? null) ? $qa['summary'] : [];
        $recommendationUrls = array_map(
            static fn (array $item): string => (string) ($item['target_url'] ?? ''),
            $this->recommendations($package)
        );
        $expectedUrls = self::NEXT_BATCH_6_URLS;
        sort($recommendationUrls);
        sort($expectedUrls);

        $packageArtifact = (string) ($package['artifact'] ?? '');
        $qaArtifact = (string) ($qa['artifact'] ?? '');
        $v2Package = $packageArtifact === self::NEXT_BATCH_6_V2_PACKAGE_ARTIFACT;
        $v2Qa = $qaArtifact === self::NEXT_BATCH_6_V2_QA_ARTIFACT;

        if (! in_array($packageArtifact, [self::NEXT_BATCH_6_PACKAGE_ARTIFACT, self::NEXT_BATCH_6_V2_PACKAGE_ARTIFACT], true)) {
            $errors[] = ['field' => 'artifact', 'code' => 'unsupported_package_artifact', 'message' => 'Unexpected next-batch-6 package artifact.'];
        }
        if ((string) ($package['status'] ?? '') !== 'pass') {
            $errors[] = ['field' => 'status', 'code' => 'package_status_not_ready_for_approval_handoff', 'message' => 'Next-batch-6 package must have pass status.'];
        }
        $packageRecommendationCount = $v2Package
            ? (int) ($package['target_count'] ?? -1)
            : (int) ($summary['recommendation_count'] ?? -1);
        if (count($this->recommendations($package)) !== 6 || $packageRecommendationCount !== 6) {
            $errors[] = ['field' => 'recommendations', 'code' => 'unexpected_recommendation_count', 'message' => 'Expected exactly 6 next-batch recommendations.'];
        }
        $variantCount = $v2Package ? $this->countUrlsByPageType($recommendationUrls, 'variant') : (int) ($summary['variant_pages'] ?? -1);
        $comparisonCount = $v2Package ? $this->countUrlsByPageType($recommendationUrls, 'comparison') : (int) ($summary['comparison_pages'] ?? -1);
        if ($variantCount !== 6 || $comparisonCount !== 0) {
            $errors[] = ['field' => 'summary', 'code' => 'unexpected_page_type_counts', 'message' => 'Expected 6 variant and 0 comparison recommendations.'];
        }
        if ($recommendationUrls !== $expectedUrls) {
            $errors[] = ['field' => 'recommendations', 'code' => 'next_batch_6_url_set_mismatch', 'message' => 'Next-batch-6 package must contain exactly the fixed approved URL set.'];
        }

        if (! in_array($qaArtifact, [self::NEXT_BATCH_6_QA_ARTIFACT, self::NEXT_BATCH_6_V2_QA_ARTIFACT], true)) {
            $errors[] = ['field' => 'qa.artifact', 'code' => 'unsupported_qa_artifact', 'message' => 'Unexpected next-batch-6 QA artifact.'];
        }
        if (! $this->nextBatch6QaFinalDecisionPasses((string) ($qa['final_decision'] ?? ''))) {
            $errors[] = ['field' => 'qa.final_decision', 'code' => 'qa_not_ready_for_approval_review', 'message' => 'Next-batch-6 QA must be ready for approval review.'];
        }
        $qaCheckedCount = $v2Qa ? (int) ($qaSummary['target_count'] ?? -1) : (int) ($qaSummary['checked_recommendation_count'] ?? -1);
        $qaPassCount = $v2Qa ? (int) ($qaSummary['pass_count'] ?? -1) : (int) ($qaSummary['pass_ready_for_approval_review_count'] ?? -1);
        $qaBlockedCount = $v2Qa ? (int) ($qaSummary['no_go_count'] ?? -1) : (int) ($qaSummary['blocked_count'] ?? -1);
        if ($qaCheckedCount !== 6 || $qaPassCount !== 6 || $qaBlockedCount !== 0) {
            $errors[] = ['field' => 'qa.summary', 'code' => 'qa_summary_not_all_pass', 'message' => 'Next-batch-6 QA summary must show 6 pass and 0 blocked.'];
        }
        if ((array) ($qa['blockers'] ?? []) !== []) {
            $errors[] = ['field' => 'qa.blockers', 'code' => 'qa_blockers_present', 'message' => 'QA blockers must be empty.'];
        }

        $qaUrls = array_map(
            static fn (array $item): string => (string) ($item['target_url'] ?? ''),
            array_values(array_filter(
                is_array($qa['page_results'] ?? null) ? $qa['page_results'] : [],
                static fn (mixed $item): bool => is_array($item)
            ))
        );
        sort($qaUrls);
        if ($recommendationUrls !== $qaUrls) {
            $errors[] = ['field' => 'qa.page_results', 'code' => 'qa_url_set_mismatch', 'message' => 'QA page result URLs must match recommendation URLs.'];
        }

        foreach ($this->qaResultsByUrl($qa) as $url => $result) {
            $pageDecision = (string) ($result['decision'] ?? ($result['qa_decision'] ?? ''));
            $blockedReason = trim((string) ($result['blocked_reason'] ?? ''));
            if (! $this->nextBatch6QaPageDecisionPasses($pageDecision) || (array) ($result['blockers'] ?? []) !== [] || $blockedReason !== '') {
                $errors[] = ['field' => 'qa.page_results.'.$url, 'code' => 'qa_page_not_pass', 'message' => 'Every next-batch-6 QA page result must pass approval review with no blockers.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return list<array<string,string>>
     */
    private function validateRemaining58PackageAndQa(array $package, array $qa): array
    {
        $errors = [];
        $qaSummary = is_array($qa['summary'] ?? null) ? $qa['summary'] : [];
        $recommendationUrls = array_map(
            static fn (array $item): string => (string) ($item['target_url'] ?? ''),
            $this->recommendations($package)
        );
        $expectedUrls = $this->remaining58Urls();
        sort($recommendationUrls);
        sort($expectedUrls);

        if ((string) ($package['artifact'] ?? '') !== self::REMAINING_58_V2_PACKAGE_ARTIFACT) {
            $errors[] = ['field' => 'artifact', 'code' => 'unsupported_package_artifact', 'message' => 'Unexpected remaining-58 package artifact.'];
        }
        if ((string) ($package['status'] ?? '') !== 'pass') {
            $errors[] = ['field' => 'status', 'code' => 'package_status_not_ready_for_approval_review', 'message' => 'Remaining-58 package must have pass status.'];
        }
        if ((string) ($package['final_decision'] ?? '') !== 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW') {
            $errors[] = ['field' => 'final_decision', 'code' => 'package_not_ready_for_content_expansion_review', 'message' => 'Remaining-58 package must be ready for content expansion review.'];
        }
        if (count($this->recommendations($package)) !== 58 || (int) ($package['target_count'] ?? -1) !== 58) {
            $errors[] = ['field' => 'recommendations', 'code' => 'unexpected_recommendation_count', 'message' => 'Expected exactly 58 remaining recommendations.'];
        }
        if ($recommendationUrls !== $expectedUrls) {
            $errors[] = ['field' => 'recommendations', 'code' => 'remaining_58_url_set_mismatch', 'message' => 'Remaining-58 package must contain exactly the fixed approved URL set.'];
        }
        if ($this->countUrlsByPageType($recommendationUrls, 'variant') !== 58
            || $this->countUrlsByPageType($recommendationUrls, 'comparison') !== 0) {
            $errors[] = ['field' => 'recommendations', 'code' => 'unexpected_page_type_counts', 'message' => 'Expected 58 variant and 0 comparison recommendations.'];
        }

        if ((string) ($qa['artifact'] ?? '') !== self::REMAINING_58_V2_QA_ARTIFACT) {
            $errors[] = ['field' => 'qa.artifact', 'code' => 'unsupported_qa_artifact', 'message' => 'Unexpected remaining-58 QA artifact.'];
        }
        if ((string) ($qa['final_decision'] ?? '') !== 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW') {
            $errors[] = ['field' => 'qa.final_decision', 'code' => 'qa_not_ready_for_content_expansion_review', 'message' => 'Remaining-58 QA must be ready for content expansion review.'];
        }
        if ((int) ($qaSummary['target_count'] ?? -1) !== 58
            || (int) ($qaSummary['pass_count'] ?? -1) !== 58
            || (int) ($qaSummary['no_go_count'] ?? -1) !== 0
            || (int) ($qaSummary['variant_pages'] ?? -1) !== 58
            || (int) ($qaSummary['comparison_pages'] ?? -1) !== 0) {
            $errors[] = ['field' => 'qa.summary', 'code' => 'qa_summary_not_all_pass', 'message' => 'Remaining-58 QA summary must show 58 variant pass and 0 blocked.'];
        }
        if ((array) ($qa['blockers'] ?? []) !== []) {
            $errors[] = ['field' => 'qa.blockers', 'code' => 'qa_blockers_present', 'message' => 'QA blockers must be empty.'];
        }

        $qaUrls = array_map(
            static fn (array $item): string => (string) ($item['target_url'] ?? ''),
            array_values(array_filter(
                is_array($qa['page_results'] ?? null) ? $qa['page_results'] : [],
                static fn (mixed $item): bool => is_array($item)
            ))
        );
        sort($qaUrls);
        if ($recommendationUrls !== $qaUrls) {
            $errors[] = ['field' => 'qa.page_results', 'code' => 'qa_url_set_mismatch', 'message' => 'QA page result URLs must match remaining-58 recommendation URLs.'];
        }

        foreach ($this->qaResultsByUrl($qa) as $url => $result) {
            $pageDecision = (string) ($result['decision'] ?? ($result['qa_decision'] ?? ''));
            $blockedReason = trim((string) ($result['blocked_reason'] ?? ''));
            if ($pageDecision !== 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW' || (array) ($result['blockers'] ?? []) !== [] || $blockedReason !== '') {
                $errors[] = ['field' => 'qa.page_results.'.$url, 'code' => 'qa_page_not_pass', 'message' => 'Every remaining-58 QA page result must pass content expansion review with no blockers.'];
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $urls
     */
    private function countUrlsByPageType(array $urls, string $pageType): int
    {
        return count(array_filter($urls, function (string $url) use ($pageType): bool {
            $identity = $this->identityForRecommendation(['target_url' => $url]);

            return $identity !== null && ($identity['page_type'] ?? null) === $pageType;
        }));
    }

    private function nextBatch6QaFinalDecisionPasses(string $decision): bool
    {
        return in_array($decision, [
            'PASS_READY_FOR_APPROVAL_REVIEW',
            'PASS_READY_FOR_EDITORIAL_REVIEW_AND_APPROVAL_QUEUE_REPAIR',
        ], true);
    }

    private function nextBatch6QaPageDecisionPasses(string $decision): bool
    {
        return in_array($decision, [
            'PASS_READY_FOR_APPROVAL_REVIEW',
            'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW',
        ], true);
    }

    /**
     * @param  array<string,mixed>  $package
     * @return list<array<string,mixed>>
     */
    private function recommendations(array $package): array
    {
        return array_values(array_filter(
            is_array($package['recommendations'] ?? null) ? $package['recommendations'] : [],
            static fn (mixed $item): bool => is_array($item)
        ));
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $options
     * @param  list<array<string,string>>  $errors
     * @return list<array<string,mixed>>
     */
    private function recommendationsForOptions(array $package, array $options, bool $write, array &$errors): array
    {
        $recommendations = $this->recommendations($package);
        $visibleQueryBacked3 = (bool) ($options['visible_query_backed_3'] ?? false);
        $freshQueryBacked3 = (bool) ($options['fresh_query_backed_3'] ?? false);
        $freshQueryBacked5 = (bool) ($options['fresh_query_backed_5'] ?? false);
        $nextBatch6 = $this->nextBatch6Requested($options);
        $remaining58 = $this->remaining58Requested($options);
        $agentBatchRequested = $this->agentBatchRequested($options);

        if (($visibleQueryBacked3 ? 1 : 0)
            + ($freshQueryBacked3 ? 1 : 0)
            + ($freshQueryBacked5 ? 1 : 0)
            + ($nextBatch6 ? 1 : 0)
            + ($remaining58 ? 1 : 0)
            + ($agentBatchRequested ? 1 : 0) > 1) {
            $errors[] = [
                'field' => 'options',
                'code' => 'exclusive_subset_modes_required',
                'message' => 'Only one subset mode can be used: --visible-query-backed-3, --fresh-query-backed-3, --fresh-query-backed-5, --next-batch-6, --remaining-58, or agent batch options.',
            ];

            return [];
        }

        if (! $visibleQueryBacked3 && ! $freshQueryBacked3 && ! $freshQueryBacked5 && ! $nextBatch6 && ! $remaining58 && ! $agentBatchRequested) {
            return $recommendations;
        }

        if ($agentBatchRequested) {
            $batchOptions = $this->agentBatchOptions($options, count($recommendations), $errors);
            if ($batchOptions === null) {
                return [];
            }

            return array_values(array_slice($recommendations, $batchOptions['offset'], $batchOptions['size']));
        }

        [$expectedUrls, $subsetCode, $subsetLabel] = match (true) {
            $remaining58 => [
                $this->remaining58Urls(),
                'remaining_58_subset_required_urls_missing',
                'remaining 58',
            ],
            $nextBatch6 => [
                self::NEXT_BATCH_6_URLS,
                'next_batch_6_subset_required_urls_missing',
                'next batch 6',
            ],
            $freshQueryBacked5 => [
                self::FRESH_QUERY_BACKED_5_URLS,
                'fresh_query_backed_5_subset_required_urls_missing',
                'fresh query-backed 5',
            ],
            $freshQueryBacked3 => [
                self::FRESH_QUERY_BACKED_3_URLS,
                'fresh_query_backed_subset_required_urls_missing',
                'fresh query-backed 3',
            ],
            default => [
                self::VISIBLE_QUERY_BACKED_3_URLS,
                'visible_query_backed_subset_required_urls_missing',
                'visible query-backed 3',
            ],
        };
        $allowed = array_fill_keys($expectedUrls, true);
        $subset = array_values(array_filter(
            $recommendations,
            static fn (array $item): bool => isset($allowed[(string) ($item['target_url'] ?? '')])
        ));
        $subsetUrls = array_map(static fn (array $item): string => (string) ($item['target_url'] ?? ''), $subset);
        sort($subsetUrls);
        sort($expectedUrls);

        if ($subsetUrls !== $expectedUrls) {
            $errors[] = [
                'field' => 'recommendations',
                'code' => $subsetCode,
                'message' => 'The '.$subsetLabel.' subset must resolve exactly the '.count($expectedUrls).' approved URLs.',
            ];
        }

        return $subset;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function agentBatchRequested(array $options): bool
    {
        return trim((string) ($options['agent_batch_size'] ?? '')) !== ''
            || trim((string) ($options['agent_batch_offset'] ?? '')) !== '';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function nextBatch6Requested(array $options): bool
    {
        return (bool) ($options['next_batch_6'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function remaining58Requested(array $options): bool
    {
        return (bool) ($options['remaining_58'] ?? false);
    }

    /**
     * @return list<string>
     */
    private function remaining58Urls(): array
    {
        $excluded = array_fill_keys(self::NEXT_BATCH_6_URLS, true);
        $urls = [];
        foreach (['en', 'zh'] as $prefix) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                foreach (['a', 't'] as $variantCode) {
                    $url = 'https://fermatmind.com/'.$prefix.'/personality/'.strtolower($typeCode).'-'.$variantCode;
                    if (! isset($excluded[$url])) {
                        $urls[] = $url;
                    }
                }
            }
        }

        sort($urls);

        return $urls;
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  list<array<string,string>>  $errors
     * @return array{size:int,offset:int}|null
     */
    private function agentBatchOptions(array $options, int $recommendationCount, array &$errors): ?array
    {
        $rawSize = trim((string) ($options['agent_batch_size'] ?? ''));
        $rawOffset = trim((string) ($options['agent_batch_offset'] ?? ''));

        if ($rawSize === '') {
            $errors[] = [
                'field' => 'options.agent_batch_size',
                'code' => 'agent_batch_size_required',
                'message' => '--agent-batch-size is required when using agent batch mode.',
            ];

            return null;
        }

        if (! ctype_digit($rawSize)) {
            $errors[] = [
                'field' => 'options.agent_batch_size',
                'code' => 'agent_batch_size_invalid',
                'message' => '--agent-batch-size must be 5 or 10.',
            ];

            return null;
        }

        $size = (int) $rawSize;
        if (! in_array($size, self::AGENT_BATCH_ALLOWED_SIZES, true)) {
            $errors[] = [
                'field' => 'options.agent_batch_size',
                'code' => 'agent_batch_size_not_allowed',
                'message' => '--agent-batch-size must be one of: '.implode(', ', self::AGENT_BATCH_ALLOWED_SIZES).'.',
            ];

            return null;
        }

        if ($rawOffset === '') {
            $offset = 0;
        } elseif (! ctype_digit($rawOffset)) {
            $errors[] = [
                'field' => 'options.agent_batch_offset',
                'code' => 'agent_batch_offset_invalid',
                'message' => '--agent-batch-offset must be a non-negative integer.',
            ];

            return null;
        } else {
            $offset = (int) $rawOffset;
        }

        if ($offset + $size > $recommendationCount) {
            $errors[] = [
                'field' => 'options.agent_batch_offset',
                'code' => 'agent_batch_window_out_of_range',
                'message' => 'The requested agent batch window must resolve exactly '.$size.' recommendations.',
            ];

            return null;
        }

        return [
            'size' => $size,
            'offset' => $offset,
        ];
    }

    /**
     * @param  array<string,mixed>  $qa
     * @return array<string,array<string,mixed>>
     */
    private function qaResultsByUrl(array $qa): array
    {
        $results = [];
        foreach (is_array($qa['page_results'] ?? null) ? $qa['page_results'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = (string) ($item['target_url'] ?? '');
            if ($url !== '') {
                $results[$url] = $item;
            }
        }

        return $results;
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @return array<string,string>|null
     */
    private function identityForRecommendation(array $recommendation): ?array
    {
        $targetUrl = (string) ($recommendation['target_url'] ?? '');
        $path = (string) (parse_url($targetUrl, PHP_URL_PATH) ?: '');
        if (preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-(?<variant>a|t)$#i', $path, $matches) === 1) {
            $locale = $this->localeFromPrefix((string) $matches['prefix']);
            $canonicalType = strtoupper((string) $matches['type']);
            $variantCode = strtoupper((string) $matches['variant']);

            return [
                'url' => $targetUrl,
                'path' => $path,
                'locale' => $locale,
                'page_type' => 'variant',
                'canonical_type_code' => $canonicalType,
                'variant_code' => $variantCode,
                'runtime_type_code' => $canonicalType.'-'.$variantCode,
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-a-vs-\k<type>-t$#i', $path, $matches) === 1) {
            $locale = $this->localeFromPrefix((string) $matches['prefix']);
            $canonicalType = strtoupper((string) $matches['type']);

            return [
                'url' => $targetUrl,
                'path' => $path,
                'locale' => $locale,
                'page_type' => 'comparison',
                'canonical_type_code' => $canonicalType,
            ];
        }

        return null;
    }

    /**
     * @param  array<string,string>  $identity
     * @return array{id?:int}
     */
    private function targetRecord(array $identity): array
    {
        $profile = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', (string) $identity['locale'])
            ->where('canonical_type_code', (string) $identity['canonical_type_code'])
            ->first();

        if (! $profile instanceof PersonalityProfile) {
            return [];
        }

        if (($identity['page_type'] ?? null) === 'comparison') {
            return ['id' => (int) $profile->id];
        }

        $variant = PersonalityProfileVariant::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', (string) ($identity['runtime_type_code'] ?? ''))
            ->first();

        return $variant instanceof PersonalityProfileVariant ? ['id' => (int) $variant->id] : [];
    }

    private function existingRevision(
        string $pageType,
        string $targetField,
        int $targetId,
        string $sourceSha256,
    ): PersonalityProfileRevision|PersonalityProfileVariantRevision|null {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        foreach ($query->orderByDesc('revision_no')->get() as $revision) {
            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            $storedSha = (string) ($snapshot[self::SNAPSHOT_KEY]['source']['source_sha256'] ?? '');
            if ($storedSha === $sourceSha256) {
                return $revision;
            }
        }

        return null;
    }

    private function nextRevisionNo(string $pageType, string $targetField, int $targetId): int
    {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        return ((int) $query->max('revision_no')) + 1;
    }

    /**
     * @param  list<array<string,mixed>>  $preparedRows
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function agentBatchApprovalGate(array $preparedRows, string $sourceSha256, string $qaSha256, array $options): array
    {
        if (! $this->agentBatchRequested($options) && ! $this->nextBatch6Requested($options) && ! $this->remaining58Requested($options)) {
            return [
                'required_for_write' => false,
                'ready_for_write' => true,
                'write_blocked_until_approved' => false,
            ];
        }

        $batch = DB::table('personality_agent_approval_batches')
            ->where('framework', 'mbti64')
            ->where('source_package_sha256', $sourceSha256)
            ->where('qa_sha256', $qaSha256)
            ->first();

        $rowsByUrl = [];
        $counts = [
            'approved_count' => 0,
            'missing_count' => 0,
            'pending_count' => 0,
            'rejected_count' => 0,
            'blocked_count' => 0,
            'recommendation_hash_mismatch_count' => 0,
            'qa_not_pass_count' => 0,
            'approved_without_timestamp_count' => 0,
        ];

        foreach ($preparedRows as $row) {
            $url = (string) ($row['url'] ?? '');
            $expectedRecommendationSha = (string) ($row['recommendation_sha256'] ?? '');
            $item = $batch === null
                ? null
                : DB::table('personality_agent_approval_items')
                    ->where('batch_id', (int) $batch->id)
                    ->where('target_url', $url)
                    ->first();
            $decision = $this->approvalDecisionForItem($item, $expectedRecommendationSha);
            $blocker = (string) ($decision['blocker'] ?? '');

            if ($blocker === '') {
                $counts['approved_count']++;
            } elseif (array_key_exists($blocker.'_count', $counts)) {
                $counts[$blocker.'_count']++;
            } else {
                $counts['blocked_count']++;
            }

            $rowsByUrl[$url] = [
                'ready' => (bool) $decision['ready'],
                'blocker' => $blocker !== '' ? $blocker : null,
                'approval_batch_id' => $batch?->id !== null ? (int) $batch->id : null,
                'approval_item_id' => $item?->id !== null ? (int) $item->id : null,
                'approval_state' => $item?->approval_state !== null ? (string) $item->approval_state : null,
                'approved_at_present' => $item?->approved_at !== null,
                'qa_decision' => $item?->qa_decision !== null ? (string) $item->qa_decision : null,
                'expected_recommendation_sha256' => $expectedRecommendationSha,
                'actual_recommendation_sha256' => $item?->recommendation_sha256 !== null ? (string) $item->recommendation_sha256 : null,
            ];
        }

        $readyForWrite = count($preparedRows) > 0 && $counts['approved_count'] === count($preparedRows);

        return array_merge([
            'required_for_write' => true,
            'ready_for_write' => $readyForWrite,
            'write_blocked_until_approved' => ! $readyForWrite,
            'approval_batch_id' => $batch?->id !== null ? (int) $batch->id : null,
            'expected_source_package_sha256' => $sourceSha256,
            'expected_qa_sha256' => $qaSha256,
            'required_item_count' => count($preparedRows),
            'rows_by_url' => $rowsByUrl,
        ], $counts);
    }

    /**
     * @return array{ready:bool,blocker:string|null}
     */
    private function approvalDecisionForItem(?object $item, string $expectedRecommendationSha): array
    {
        if ($item === null) {
            return ['ready' => false, 'blocker' => 'missing'];
        }

        $blockedReason = $item->blocked_reason !== null ? trim((string) $item->blocked_reason) : '';
        if ($blockedReason !== '') {
            return ['ready' => false, 'blocker' => 'blocked'];
        }

        $state = (string) ($item->approval_state ?? '');
        if ($state === 'rejected') {
            return ['ready' => false, 'blocker' => 'rejected'];
        }
        if ($state !== 'approved') {
            return ['ready' => false, 'blocker' => 'pending'];
        }

        if ($item->approved_at === null) {
            return ['ready' => false, 'blocker' => 'approved_without_timestamp'];
        }

        if ((string) ($item->recommendation_sha256 ?? '') !== $expectedRecommendationSha) {
            return ['ready' => false, 'blocker' => 'recommendation_hash_mismatch'];
        }

        if (! $this->approvalQaDecisionPasses((string) ($item->qa_decision ?? ''))) {
            return ['ready' => false, 'blocker' => 'qa_not_pass'];
        }

        return ['ready' => true, 'blocker' => null];
    }

    private function approvalQaDecisionPasses(string $decision): bool
    {
        return in_array($decision, [
            'pass',
            'PASS',
            'PASS_READY_FOR_CMS_DRAFT',
            'PASS_READY_FOR_APPROVAL_REVIEW',
            'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW',
            'READY_QUERY_BACKED_LOW_RISK_DRAFT_REVIEW',
        ], true);
    }

    /**
     * @param  array<string,mixed>  $approvalGate
     * @return array<string,mixed>
     */
    private function approvalGateForOutput(array $approvalGate): array
    {
        $output = $approvalGate;
        unset($output['rows_by_url']);

        return $output;
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     */
    private function createRevision(array $preparedRow): PersonalityProfileRevision|PersonalityProfileVariantRevision
    {
        $pageType = (string) ($preparedRow['page_type'] ?? '');
        $targetId = (int) ($preparedRow['target_id'] ?? 0);
        $revisionNo = (int) ($preparedRow['next_revision_no'] ?? 0);
        $snapshot = is_array($preparedRow['snapshot_preview'] ?? null) ? $preparedRow['snapshot_preview'] : [];
        $note = $pageType === 'comparison'
            ? 'mbti64 agent projection comparison draft: '.((string) ($preparedRow['path'] ?? ''))
            : 'mbti64 agent projection variant draft: '.((string) ($preparedRow['path'] ?? ''));

        if ($pageType === 'comparison') {
            return PersonalityProfileRevision::query()->create([
                'profile_id' => $targetId,
                'revision_no' => $revisionNo,
                'snapshot_json' => $snapshot,
                'note' => $note,
                'created_by_admin_user_id' => null,
                'created_at' => now(),
            ]);
        }

        return PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => $targetId,
            'revision_no' => $revisionNo,
            'snapshot_json' => $snapshot,
            'note' => $note,
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $recommendation
     * @param  array<string,string>  $identity
     * @param  array<string,mixed>  $qaResult
     * @return array<string,mixed>
     */
    private function snapshotPayload(
        array $package,
        array $qa,
        array $recommendation,
        array $identity,
        string $sourceSha256,
        string $qaSha256,
        array $qaResult,
    ): array {
        $recommended = is_array($recommendation['recommendations'] ?? null) ? $recommendation['recommendations'] : [];

        return [
            self::SNAPSHOT_KEY => [
                'source' => [
                    'artifact' => (string) ($package['artifact'] ?? ''),
                    'version' => (string) ($package['version'] ?? ''),
                    'status' => (string) ($package['status'] ?? ''),
                    'source_sha256' => $sourceSha256,
                    'qa_artifact' => (string) ($qa['artifact'] ?? ''),
                    'qa_source_sha256' => $qaSha256,
                    'qa_final_decision' => (string) ($qa['final_decision'] ?? ''),
                ],
                'identity' => $identity,
                'first_class_draft_fields' => [
                    'url' => (string) ($recommendation['target_url'] ?? ''),
                    'locale' => (string) ($recommendation['locale'] ?? ''),
                    'page_type' => (string) $identity['page_type'],
                    'seo' => [
                        'title' => $this->recommendedFieldText($recommended, 'title'),
                        'description' => $this->recommendedFieldText($recommended, 'description'),
                        'h1' => $this->recommendedFieldText($recommended, 'h1'),
                    ],
                    'content' => [
                        'quick_answer' => $this->recommendedFieldText($recommended, 'quick_answer'),
                    ],
                    'faq' => is_array($recommended['faq'] ?? null) ? array_values((array) $recommended['faq']) : [],
                    'internal_links' => is_array($recommended['internal_links'] ?? null) ? array_values((array) $recommended['internal_links']) : [],
                    'differentiation_notes' => is_array($recommended['differentiation_notes'] ?? null)
                        ? array_values((array) $recommended['differentiation_notes'])
                        : [],
                ],
                'structured_metadata' => [
                    'current_surface' => is_array($recommendation['current_surface'] ?? null) ? $recommendation['current_surface'] : [],
                    'observed_signal' => is_array($recommendation['observed_signal'] ?? null) ? $recommendation['observed_signal'] : [],
                    'reference_patterns_used' => is_array($recommendation['reference_patterns_used'] ?? null)
                        ? array_values((array) $recommendation['reference_patterns_used'])
                        : [],
                    'source_inputs' => is_array($recommendation['source_inputs'] ?? null) ? $recommendation['source_inputs'] : [],
                    'qa_result' => $qaResult,
                    'qa_summary' => is_array($qa['summary'] ?? null) ? $qa['summary'] : [],
                ],
                'safety_holds' => [
                    'draft_only' => true,
                    'publish_attempted' => false,
                    'index_attempted' => false,
                    'sitemap_llms_release_attempted' => false,
                    'search_release_attempted' => false,
                    'runtime_content_updated' => false,
                ],
                'raw_recommendation' => $recommendation,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $recommended
     */
    private function recommendedFieldText(array $recommended, string $field): string
    {
        $value = $recommended[$field] ?? '';
        if (is_array($value)) {
            return (string) ($value['recommended'] ?? '');
        }

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $write, array $options): array
    {
        return [
            'artifact' => 'MBTI64-CMS-PROJECTION-DRAFT-88-01',
            'source_version' => (string) ($package['version'] ?? ''),
            'source_status' => (string) ($package['status'] ?? ''),
            'source_sha256' => $sourceSha256,
            'qa_artifact' => (string) ($qa['artifact'] ?? ''),
            'qa_source_sha256' => $qaSha256,
            'qa_final_decision' => (string) ($qa['final_decision'] ?? ''),
            'snapshot_key' => self::SNAPSHOT_KEY,
            'dry_run' => ! $write,
            'write' => $write,
            'draft_only' => true,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'writes_committed' => false,
            'subset' => $this->subsetSummary($options),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function subsetSummary(array $options, array $preparedRows = []): array
    {
        $visibleQueryBacked3 = (bool) ($options['visible_query_backed_3'] ?? false);
        $freshQueryBacked3 = (bool) ($options['fresh_query_backed_3'] ?? false);
        $freshQueryBacked5 = (bool) ($options['fresh_query_backed_5'] ?? false);
        $nextBatch6 = $this->nextBatch6Requested($options);
        $remaining58 = $this->remaining58Requested($options);
        $agentBatchRequested = $this->agentBatchRequested($options);
        $selectedUrls = array_values(array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $preparedRows
        ));

        if ($agentBatchRequested) {
            return [
                'mode' => 'agent_batch_safe',
                'enabled' => true,
                'dry_run_only' => false,
                'write_allowed_with_strict_approval' => true,
                'allowed_batch_sizes' => self::AGENT_BATCH_ALLOWED_SIZES,
                'batch_size' => trim((string) ($options['agent_batch_size'] ?? '')),
                'batch_offset' => trim((string) ($options['agent_batch_offset'] ?? '')) !== ''
                    ? trim((string) ($options['agent_batch_offset'] ?? ''))
                    : '0',
                'arbitrary_url_subset_allowed' => false,
                'selected_urls' => $selectedUrls,
            ];
        }

        if ($freshQueryBacked3) {
            return [
                'mode' => 'fresh_query_backed_3',
                'enabled' => true,
                'dry_run_only' => false,
                'write_allowed_with_strict_approval' => true,
                'allowed_urls' => self::FRESH_QUERY_BACKED_3_URLS,
                'selected_urls' => $selectedUrls,
            ];
        }

        if ($freshQueryBacked5) {
            return [
                'mode' => 'fresh_query_backed_5',
                'enabled' => true,
                'dry_run_only' => false,
                'write_allowed_with_strict_approval' => true,
                'allowed_urls' => self::FRESH_QUERY_BACKED_5_URLS,
                'selected_urls' => $selectedUrls,
            ];
        }

        if ($nextBatch6) {
            return [
                'mode' => 'next_batch_6',
                'enabled' => true,
                'dry_run_only' => false,
                'write_allowed_with_strict_approval' => true,
                'approval_queue_required' => true,
                'allowed_urls' => self::NEXT_BATCH_6_URLS,
                'selected_urls' => $selectedUrls,
                'arbitrary_url_subset_allowed' => false,
            ];
        }

        if ($remaining58) {
            return [
                'mode' => 'remaining_58',
                'enabled' => true,
                'dry_run_only' => false,
                'write_allowed_with_strict_approval' => true,
                'approval_queue_required' => true,
                'allowed_urls' => $this->remaining58Urls(),
                'selected_urls' => $selectedUrls,
                'arbitrary_url_subset_allowed' => false,
            ];
        }

        return [
            'mode' => $visibleQueryBacked3 ? 'visible_query_backed_3' : 'full_88',
            'enabled' => $visibleQueryBacked3,
            'dry_run_only' => false,
            'write_allowed_with_strict_approval' => $visibleQueryBacked3,
            'allowed_urls' => $visibleQueryBacked3 ? self::VISIBLE_QUERY_BACKED_3_URLS : [],
            'selected_urls' => $selectedUrls,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function countRows(array $rows, string $pageType): int
    {
        return count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['page_type'] ?? null) === $pageType
        ));
    }

    private function localeFromPrefix(string $prefix): string
    {
        return strtolower($prefix) === 'zh' ? 'zh-CN' : 'en';
    }

    private function containsForbiddenRoutePattern(string $value): bool
    {
        foreach (self::FORBIDDEN_ROUTE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function jsonString(array $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
