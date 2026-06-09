<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\ContentPage;
use Illuminate\Validation\ValidationException;

final class ContentPagePublishGate
{
    /**
     * @return array<string, list<string>>
     */
    public function errorsFor(ContentPage $page): array
    {
        return $this->errorsForState([
            'slug' => (string) $page->slug,
            'page_type' => (string) $page->page_type,
            'locale' => (string) $page->locale,
            'status' => (string) $page->status,
            'is_public' => (bool) $page->is_public,
            'is_indexable' => (bool) $page->is_indexable,
            'review_state' => (string) $page->review_state,
            'legal_review_required' => (bool) $page->legal_review_required,
            'science_review_required' => (bool) $page->science_review_required,
            'schema_enabled' => (bool) $page->schema_enabled,
            'publish_allowed' => (bool) $page->publish_allowed,
            'operator_approval_required' => (bool) $page->operator_approval_required,
            'operator_approved_at' => $page->operator_approved_at,
            'claim_gate_status' => (string) ($page->claim_gate_status ?: 'not_reviewed'),
            'forbidden_claims' => is_array($page->forbidden_claims) ? array_values($page->forbidden_claims) : [],
            'faq_schema_eligible' => (bool) $page->faq_schema_eligible,
            'schema_eligibility_reviewed_at' => $page->schema_eligibility_reviewed_at,
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'meta_description' => $page->meta_description,
        ]);
    }

    public function assertPasses(ContentPage $page): void
    {
        $errors = $this->errorsFor($page);
        if ($errors === []) {
            return;
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, list<string>>
     */
    public function errorsForState(array $state): array
    {
        $errors = [];
        $locale = $this->normalizeLocale((string) ($state['locale'] ?? ''));
        $isIndexable = (bool) ($state['is_indexable'] ?? false);

        if ($locale === 'en' && $isIndexable) {
            if (trim((string) ($state['seo_title'] ?? '')) === '') {
                $errors['seo_title'][] = 'seo_title is required before an English ContentPage can be indexable.';
            }

            if (
                trim((string) ($state['meta_description'] ?? '')) === ''
                && trim((string) ($state['seo_description'] ?? '')) === ''
            ) {
                $errors['meta_description'][] = 'meta_description or seo_description is required before an English ContentPage can be indexable.';
            }
        }

        $slug = $this->normalizeSlug((string) ($state['slug'] ?? ''));
        $pageType = (string) ($state['page_type'] ?? '');
        $scienceReviewRequired = (bool) ($state['science_review_required'] ?? false);
        $status = (string) ($state['status'] ?? '');
        $isPublic = (bool) ($state['is_public'] ?? false);

        if (
            $status !== ContentPage::STATUS_PUBLISHED
            || ! $isPublic
            || ! $this->isScienceControlledPage($slug, $pageType, $scienceReviewRequired)
        ) {
            return $errors;
        }

        if (! (bool) ($state['publish_allowed'] ?? false)) {
            $errors['publish_allowed'][] = 'publish_allowed must be true before publishing a Science ContentPage.';
        }
        if ((bool) ($state['operator_approval_required'] ?? true) && ($state['operator_approved_at'] ?? null) === null) {
            $errors['operator_approved_at'][] = 'operator_approved_at is required while operator_approval_required is true.';
        }
        if ((string) ($state['review_state'] ?? '') !== 'approved') {
            $errors['review_state'][] = 'review_state must be approved before publishing a Science ContentPage.';
        }
        if ((bool) ($state['legal_review_required'] ?? false)) {
            $errors['legal_review_required'][] = 'legal_review_required must be resolved before publishing a Science ContentPage.';
        }
        if ($scienceReviewRequired) {
            $errors['science_review_required'][] = 'science_review_required must be resolved before publishing a Science ContentPage.';
        }
        if ((string) ($state['claim_gate_status'] ?? 'not_reviewed') !== 'passed') {
            $errors['claim_gate_status'][] = 'claim_gate_status must be passed before publishing a Science ContentPage.';
        }
        if (array_values(array_filter((array) ($state['forbidden_claims'] ?? []))) !== []) {
            $errors['forbidden_claims'][] = 'forbidden_claims must be empty before publishing a Science ContentPage.';
        }
        if (
            (bool) ($state['schema_enabled'] ?? false)
            && (! (bool) ($state['faq_schema_eligible'] ?? false) || ($state['schema_eligibility_reviewed_at'] ?? null) === null)
        ) {
            $errors['faq_schema_eligible'][] = 'faq_schema_eligible and schema_eligibility_reviewed_at are required when schema_enabled is true.';
        }

        return $errors;
    }

    private function isScienceControlledPage(string $slug, string $pageType, bool $scienceReviewRequired): bool
    {
        return $scienceReviewRequired
            || in_array($pageType, ContentPage::SCIENCE_CONTROLLED_PAGE_TYPES, true)
            || in_array($slug, ContentPage::SCIENCE_CONTROLLED_SLUGS, true);
    }

    private function normalizeSlug(string $slug): string
    {
        return trim(strtolower($slug));
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        return str_starts_with($normalized, 'zh') ? 'zh-CN' : $normalized;
    }
}
