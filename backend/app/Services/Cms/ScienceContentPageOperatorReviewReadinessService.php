<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Resources\ContentPageResource;
use App\Models\ContentPage;
use ReflectionClass;

final class ScienceContentPageOperatorReviewReadinessService
{
    /**
     * @return array<string, mixed>
     */
    public function review(?string $packagePath = null): array
    {
        $draftDryRun = null;
        if ($packagePath !== null && trim($packagePath) !== '') {
            $draftDryRun = app(ScienceContentPageDraftDryRunService::class)->dryRun($packagePath);
        }

        $model = new ContentPage;
        $fillable = $model->getFillable();
        $casts = $model->getCasts();
        $resourceSource = $this->sourceFor(ContentPageResource::class);
        $controllerSource = $this->sourceFor(\App\Http\Controllers\API\V0_5\Cms\ContentPageController::class);
        $editPageSource = $this->sourceFor(\App\Filament\Ops\Resources\ContentPageResource\Pages\EditContentPage::class);

        $coreFields = $this->fieldStatus([
            'slug',
            'path',
            'locale',
            'kind',
            'page_type',
            'title',
            'content_md',
            'content_html',
            'seo_title',
            'meta_description',
            'seo_description',
            'canonical_path',
            'status',
            'review_state',
            'owner',
            'legal_review_required',
            'science_review_required',
            'last_reviewed_at',
            'published_at',
            'is_public',
            'is_indexable',
            'source_doc',
        ], $fillable);

        $reviewStates = $this->fieldStatus([
            ContentPage::STATUS_DRAFT,
            ContentPage::STATUS_PUBLISHED,
            'owner_review',
            'legal_review',
            'science_review',
            'approved',
            'changes_requested',
        ], array_merge(
            [ContentPage::STATUS_DRAFT, ContentPage::STATUS_SCHEDULED, ContentPage::STATUS_PUBLISHED, ContentPage::STATUS_ARCHIVED],
            ContentPage::REVIEW_STATES,
        ));

        $booleanCasts = $this->fieldStatus([
            'legal_review_required',
            'science_review_required',
            'is_public',
            'is_indexable',
        ], array_keys(array_filter(
            $casts,
            static fn (string $cast): bool => $cast === 'boolean',
        )));

        $resourceFields = $this->sourceTokenStatus([
            'status',
            'review_state',
            'is_public',
            'is_indexable',
            'legal_review_required',
            'science_review_required',
            'last_reviewed_at',
            'published_at',
            'owner',
            'source_doc',
            'content_md',
            'seo_title',
            'meta_description',
            'seo_description',
            'canonical_path',
            'ops_publish_readiness',
        ], $resourceSource);

        $editPersistence = $this->sourceTokenStatus([
            'status',
            'review_state',
            'is_public',
            'is_indexable',
            'legal_review_required',
            'science_review_required',
            'last_reviewed_at',
            'published_at',
            'owner',
            'source_doc',
            'content_md',
            'seo_title',
            'meta_description',
            'seo_description',
            'canonical_path',
        ], $editPageSource);

        $internalApiFields = $this->sourceTokenStatus([
            'status',
            'review_state',
            'is_public',
            'is_indexable',
            'legal_review_required',
            'science_review_required',
            'last_reviewed_at',
            'published_at',
            'owner',
            'content_md',
            'seo_title',
            'meta_description',
            'seo_description',
            'canonical_path',
        ], $controllerSource);

        $missingFirstClass = [
            'publish_allowed',
            'operator_approval_required',
            'operator_approved_at',
            'claim_gate_status',
            'forbidden_claims',
            'faq_schema_eligible',
            'schema_eligibility_reviewed_at',
        ];

        $draftPagesReviewable = $draftDryRun === null
            ? 'Unknown'
            : (int) ($draftDryRun['pages_ready_for_non_public_draft_import'] ?? 0);
        $reconciliationPages = $draftDryRun === null
            ? 'Unknown'
            : (int) ($draftDryRun['pages_blocked'] ?? 0);
        $reconciledAuthorityPages = $draftDryRun === null
            ? 'Unknown'
            : (int) ($draftDryRun['pages_reconciled_existing_authority'] ?? 0);

        $coreReady = $this->allPresent($coreFields)
            && $this->allPresent($reviewStates)
            && $this->allPresent($booleanCasts)
            && $this->allPresent($resourceFields)
            && $this->allPresent($editPersistence)
            && $this->allPresent($internalApiFields);

        return [
            'task' => 'SCIENCE-CONTENTPAGE-OPERATOR-REVIEW-01',
            'mode' => 'read_only_operator_review_gate',
            'cms_mutation_performed' => false,
            'database_writes_allowed' => false,
            'content_import_performed' => false,
            'publish_performed' => false,
            'package_path' => $packagePath !== null && trim($packagePath) !== '' ? rtrim($packagePath, DIRECTORY_SEPARATOR) : 'Unknown',
            'draft_package' => [
                'status' => $draftDryRun['status'] ?? 'Unknown',
                'pages_seen' => $draftDryRun['pages_seen'] ?? 'Unknown',
                'pages_reviewable_as_non_public_draft' => $draftPagesReviewable,
                'pages_requiring_authority_reconciliation' => $reconciliationPages,
                'pages_reconciled_existing_authority' => $reconciledAuthorityPages,
                'would_write' => $draftDryRun['would_write'] ?? false,
            ],
            'operator_review_ready_for_non_public_draft' => $coreReady,
            'operator_publish_decision_ready' => false,
            'publish_allowed_default' => false,
            'natural_distribution_allowed' => false,
            'decision' => $coreReady ? 'CONDITIONAL' : 'NO-GO',
            'reason' => $coreReady
                ? 'Core ContentPage review fields and CMS/API editing surfaces exist, but publish/claim/schema approval remains metadata or external QA rather than first-class CMS fields.'
                : 'Core ContentPage review fields or editing surfaces are missing.',
            'capabilities' => [
                'content_page_core_fields' => $coreFields,
                'review_states' => $reviewStates,
                'boolean_review_casts' => $booleanCasts,
                'filament_operator_fields' => $resourceFields,
                'filament_edit_persistence' => $editPersistence,
                'internal_api_fields' => $internalApiFields,
            ],
            'missing_first_class_publish_safety_fields' => $missingFirstClass,
            'operator_must_check_before_publish' => [
                'review_state_is_approved',
                'science_review_required_resolved',
                'legal_review_required_resolved',
                'claim_gate_passed',
                'faq_schema_visible_only',
                'private_url_absent',
                'cta_public_canonical_only',
                'sitemap_llms_footer_remain_false_until_final_gate',
            ],
            'hard_no_go' => [
                'no_cms_mutation',
                'no_real_import',
                'no_publish',
                'no_private_url',
                'no_schema_eligibility_without_visible_faq',
                'no_claim_gate_bypass',
            ],
        ];
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $available
     * @return array<string, array{present: bool}>
     */
    private function fieldStatus(array $fields, array $available): array
    {
        $status = [];
        foreach ($fields as $field) {
            $status[$field] = ['present' => in_array($field, $available, true)];
        }

        return $status;
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, array{present: bool}>
     */
    private function sourceTokenStatus(array $tokens, string $source): array
    {
        $status = [];
        foreach ($tokens as $token) {
            $status[$token] = ['present' => $source !== '' && str_contains($source, $token)];
        }

        return $status;
    }

    /**
     * @param  array<string, array{present: bool}>  $status
     */
    private function allPresent(array $status): bool
    {
        foreach ($status as $field) {
            if (($field['present'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    private function sourceFor(string $class): string
    {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();
        if (! is_string($file) || ! is_file($file)) {
            return '';
        }

        return file_get_contents($file) ?: '';
    }
}
