<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\ReviewAttestationTargetEvidence;
use App\Support\ArticleBrandByline;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use RuntimeException;

/**
 * Bind existing published editorial records; never create a historical review or a text revision.
 *
 * @review-surface article
 * @review-surface article_translation_revision
 */
final class ArticlePublishedProvenanceBinder
{
    public function plan(Article $article, array $input, bool $lock): array
    {
        if (array_diff(array_keys($input), ['body_sha256', 'editorial_review_audit_id', 'citations', 'preview_qa_acknowledged']) !== []
            || ($input['preview_qa_acknowledged'] ?? null) !== true
            || ! str_starts_with((string) $article->slug, 'big-five-')
            || ! $article->is_indexable || $article->working_revision_id !== $article->published_revision_id
            || $article->author_name !== ArticleBrandByline::editorialName((string) $article->locale)) {
            throw new RuntimeException('published_provenance_scope_invalid');
        }
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->whereKey($article->published_revision_id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $revision instanceof ArticleTranslationRevision || $revision->article_id !== $article->id
            || $revision->org_id !== $article->org_id || $revision->locale !== $article->locale
            || $revision->translation_group_id !== $article->translation_group_id
            || $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
            || ! $revision->created_by || ! $revision->reviewed_by || ! $revision->reviewed_at
            || ! $revision->approved_at || ! $revision->published_at || $revision->published_at->isFuture()
            || $revision->reviewed_at->gt($revision->published_at)
            || $revision->approved_at->gt($revision->published_at)
            || ! hash_equals(hash('sha256', (string) $revision->content_md), (string) ($input['body_sha256'] ?? ''))) {
            throw new RuntimeException('published_provenance_revision_invalid');
        }
        foreach ([$revision->created_by, $revision->reviewed_by] as $id) {
            if (! AdminUser::query()->whereKey($id)->exists()) {
                throw new RuntimeException('published_provenance_actor_missing');
            }
        }
        $audit = AuditLog::withoutGlobalScopes()->whereKey($input['editorial_review_audit_id'] ?? 0)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $audit instanceof AuditLog || $audit->target_type !== 'article'
            || (string) $audit->target_id !== (string) $article->id || $audit->action !== 'editorial_review_approved'
            || $audit->reason !== 'cms_editorial_review' || data_get($audit->meta_json, 'locale') !== $article->locale
            || $audit->result !== 'success' || (int) $audit->actor_admin_id !== $revision->reviewed_by
            || ! $audit->created_at || $audit->created_at->lt($revision->reviewed_at)) {
            throw new RuntimeException('published_provenance_review_record_missing');
        }
        // Audit org_id is the Ops session context, not the global Article tenant.
        // Resource org/locale/body are bound by the original exact content target below.
        // Preserve the original exact content approval. This is a derived metadata binding,
        // not a claim that its new metadata was present in that historical approval.
        $reviewRevision = clone $revision;
        $reviewMetadata = $revision->authority_metadata_json ?? [];
        unset($reviewMetadata['visible_provenance'], $reviewMetadata['published_provenance_binding_v1']);
        $reviewRevision->authority_metadata_json = $reviewMetadata;
        $target = app(CmsEditorialReviewAttestationService::class)->targets([
            ['surface_id' => 'article_translation_revision', 'record' => $reviewRevision],
        ])[0];
        $evidence = ReviewAttestationTargetEvidence::query()->with('attestation')
            ->where('target_identity', $target['target_identity'])->where('target_sha256', $target['target_sha256'])
            ->where('target_decision', 'approved')
            ->whereHas('attestation', function ($q) use ($article, $revision, $audit): void {
                $q->where('scope_type', 'cms_editorial_review')->where('scope_identity', 'like', 'article:'.$article->id.':workflow:%')
                    ->where('review_mode', 'solo_owner')->where('decision', 'approved_all')
                    ->where('review_source', (string) config('review_governance.attestation.review_source'))
                    ->where('attested_by_admin_user_id', $revision->reviewed_by)
                    ->where('attested_at', $audit->created_at->utc()->format('Y-m-d H:i:s'));
            })->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $evidence instanceof ReviewAttestationTargetEvidence) {
            throw new RuntimeException('published_provenance_exact_review_evidence_missing');
        }
        $citations = $input['citations'] ?? null;
        if (! is_array($citations) || ! array_is_list($citations) || count($citations) < 1 || count($citations) > 20) {
            throw new RuntimeException('published_provenance_citations_invalid');
        }
        $sources = [];
        $ledger = [];
        foreach ($citations as $citation) {
            if (! is_array($citation) || array_diff(array_keys($citation), ['url', 'label', 'category', 'body_quote']) !== []) {
                throw new RuntimeException('published_provenance_citation_invalid');
            }
            $url = $citation['url'] ?? '';
            $label = $citation['label'] ?? '';
            $quote = $citation['body_quote'] ?? '';
            $category = $citation['category'] ?? '';
            $prefix = match ($category) {
                'academic_evidence' => 'source-ledger:academic:',
                'official_guidance' => 'source-ledger:official-guidance:',
                'product_authority' => 'product-contract:',
                default => null,
            };
            if (! is_string($url) || ! is_string($label) || ! is_string($quote) || $prefix === null
                || trim($label) === '' || mb_strlen($label) > 1000 || strlen($quote) > 15000
                || ! $this->publicCitationUrl($url) || ! str_contains($quote, ']('.$url.')')
                || ! str_contains($quote, $label) || ! str_contains((string) $revision->content_md, $quote)) {
                throw new RuntimeException('published_provenance_citation_not_in_body');
            }
            $id = hash('sha256', $url);
            if (isset($ledger[$id])) {
                throw new RuntimeException('published_provenance_duplicate_citation');
            }
            $sources[] = ['source_id' => $id, 'label' => $label, 'category' => $category,
                'authority_ref' => $prefix.'article-revision:'.$revision->id.':'.$id];
            $ledger[$id] = [...$citation, 'quote_sha256' => hash('sha256', $quote)];
        }
        $reviewerLabel = filled($article->reviewer_name)
            ? trim((string) $article->reviewer_name) : ArticleBrandByline::editorialName((string) $article->locale);
        $provenance = [
            'author' => ['identity' => 'admin_user:'.$revision->created_by, 'label' => $article->author_name,
                'role' => 'editorial_author', 'authority_ref' => 'revision-author:'.$revision->id],
            'reviewer' => ['identity' => 'admin_user:'.$revision->reviewed_by, 'label' => $reviewerLabel,
                'role' => 'editorial_reviewer', 'authority_ref' => 'review-ledger:attestation:'.$evidence->review_attestation_id.':target:'.$evidence->id,
                'review_state' => $revision->revision_status, 'reviewed_at' => $revision->reviewed_at->toIso8601String()],
            'sources' => $sources,
        ];
        $beforeMetadata = $revision->authority_metadata_json;
        $metadata = $beforeMetadata ?? [];
        $record = ['revision_id' => $revision->id, 'body_sha256' => $input['body_sha256'],
            'editorial_review_audit_id' => $audit->id, 'editorial_review_audit_org_id' => (int) $audit->org_id,
            'original_approved_target' => $target,
            'review_attestation_id' => $evidence->review_attestation_id, 'review_target_evidence_id' => $evidence->id,
            'citations' => $ledger];
        foreach (['visible_provenance' => $provenance, 'published_provenance_binding_v1' => $record] as $key => $value) {
            if (array_key_exists($key, $metadata) && ! Hash::sameValue($metadata[$key], $value)) {
                throw new RuntimeException('published_provenance_existing_binding_conflict');
            }
            $metadata[$key] = $value;
        }
        $candidate = clone $article;
        $candidateRevision = clone $revision;
        $candidateRevision->authority_metadata_json = $metadata;
        $candidate->setRelation('publishedRevision', $candidateRevision);
        $jsonld = app(ArticleSeoService::class)->generateJsonLdForGateRollout($candidate, $candidateRevision,
            ['article_schema_enabled' => true, 'breadcrumb_schema_enabled' => true, 'faq_schema_enabled' => false]);
        $fragments = $jsonld['@graph'] ?? ($jsonld ? [$jsonld] : []);
        $types = array_column($fragments, '@type');
        $articleFragment = collect($fragments)->firstWhere('@type', 'Article');
        if (! in_array('Article', $types, true) || ! in_array('BreadcrumbList', $types, true)
            || in_array('FAQPage', $types, true) || data_get($articleFragment, 'author.@type') !== 'Organization'
            || data_get($articleFragment, 'author.name') !== $article->author_name) {
            throw new RuntimeException('published_provenance_schema_preview_failed');
        }

        return ['revision' => $revision, 'metadata' => $metadata, 'before_metadata' => $beforeMetadata,
            'jsonld' => $jsonld, 'record' => $record];
    }

    public function apply(array $plan): void
    {
        $revision = $plan['revision'];
        $before = $revision->getAttributes();
        $revision->timestamps = false;
        $revision->authority_metadata_json = $plan['metadata'];
        $revision->saveQuietly();
        $revision->refresh();
        $after = $revision->getAttributes();
        unset($before['authority_metadata_json'], $after['authority_metadata_json']);
        if (! Hash::sameValue($before, $after) || ! Hash::sameValue($plan['metadata'], $revision->authority_metadata_json)) {
            throw new RuntimeException('published_provenance_readback_failed');
        }
    }

    private function publicCitationUrl(string $url): bool
    {
        if (preg_match('~\A/(en|zh)/(tests|personality|topics)/[a-z0-9/-]+\z~D', $url) === 1) {
            return true;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port'])) {
            return false;
        }
        $host = $parts['host'] ?? '';

        return filter_var($url, FILTER_VALIDATE_URL) !== false && str_contains($host, '.')
            && ! filter_var($host, FILTER_VALIDATE_IP) && ! str_ends_with($host, '.local')
            && ! str_ends_with($host, '.internal');
    }
}
