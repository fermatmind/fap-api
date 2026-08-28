<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentMaterialDecision;
use App\Services\SeoIntel\MaterialFingerprint\MaterialFingerprintV1;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final class ArticleMaterialDecisionService
{
    public const FAMILY = 'article';

    public const AUTHORITY_REVISION_KIND = 'article_translation_revision';

    public function __construct(
        private readonly MaterialFingerprintV1 $fingerprint,
    ) {}

    public function recordPublished(
        Article $article,
        ArticleTranslationRevision $revision,
        CarbonInterface $effectiveAt,
        string $operation = 'publish',
        ?string $evidenceRef = null,
    ): ContentMaterialDecision {
        $this->assertInsideTransaction();
        $this->assertPublishedAuthority($article, $revision);

        $operation = $this->normalizeOperation($operation, ['publish', 'rollback']);
        $evidenceRef = $this->evidenceRef(
            $evidenceRef ?? self::AUTHORITY_REVISION_KIND.':'.$revision->id,
        );
        $latest = $this->latestForUpdate($article);
        $materialFingerprint = $this->fingerprint->fingerprint(
            $this->materialPayload($article, $revision),
        );

        if ($latest instanceof ContentMaterialDecision
            && (string) $latest->publication_state === 'published'
            && (string) $latest->operation === $operation
            && (string) $latest->authority_revision === (string) $revision->id
            && hash_equals((string) $latest->material_fingerprint, $materialFingerprint)) {
            return $latest;
        }

        $wasPublished = $latest instanceof ContentMaterialDecision
            && (string) $latest->publication_state === 'published';
        $sameFingerprint = $wasPublished
            && is_string($latest->material_fingerprint)
            && hash_equals((string) $latest->material_fingerprint, $materialFingerprint);
        $materialChanged = ! $sameFingerprint;
        $materialChangedAt = $materialChanged
            ? $effectiveAt
            : $latest?->material_changed_at;

        $decisionCode = match (true) {
            $operation === 'rollback' && $sameFingerprint => 'rollback_unchanged',
            $operation === 'rollback' => 'rollback_material_change',
            ! $latest instanceof ContentMaterialDecision => 'initial_publish',
            ! $wasPublished => 'republish_after_unpublish',
            $sameFingerprint => 'unchanged_republish',
            default => 'material_change',
        };

        return ContentMaterialDecision::query()->create([
            ...$this->identity($article),
            'previous_public_identity' => $latest?->public_identity,
            'authority_revision_kind' => self::AUTHORITY_REVISION_KIND,
            'authority_revision' => (string) $revision->id,
            'material_fingerprint' => $materialFingerprint,
            'previous_material_fingerprint' => $latest?->material_fingerprint,
            'publication_state' => 'published',
            'operation' => $operation,
            'decision_code' => $decisionCode,
            'material_changed' => $materialChanged,
            'material_changed_at' => $materialChangedAt,
            'evidence_ref' => $evidenceRef,
            'decision_key' => $this->decisionKey(
                $article,
                $latest?->id,
                (string) $revision->id,
                $materialFingerprint,
                'published',
                $operation,
            ),
        ]);
    }

    public function recordUnpublished(
        Article $article,
        CarbonInterface $effectiveAt,
        ?string $evidenceRef = null,
    ): ContentMaterialDecision {
        $this->assertInsideTransaction();
        if ((string) $article->status === 'published' || (bool) $article->is_public) {
            throw new InvalidArgumentException('Article must be private before recording unpublish material state.');
        }

        $latest = $this->latestForUpdate($article);
        if ($latest instanceof ContentMaterialDecision
            && (string) $latest->publication_state === 'unpublished') {
            return $latest;
        }

        $authorityRevision = (string) ($article->published_revision_id ?? $latest?->authority_revision ?? 'unknown');
        $knownFingerprint = $latest instanceof ContentMaterialDecision
            && (string) $latest->publication_state === 'published'
            && preg_match('/\A[a-f0-9]{64}\z/', (string) $latest->material_fingerprint) === 1;

        return ContentMaterialDecision::query()->create([
            ...$this->identity($article),
            'previous_public_identity' => $latest?->public_identity,
            'authority_revision_kind' => self::AUTHORITY_REVISION_KIND,
            'authority_revision' => $authorityRevision,
            'material_fingerprint' => $knownFingerprint ? $latest->material_fingerprint : null,
            'previous_material_fingerprint' => $latest?->material_fingerprint,
            'publication_state' => 'unpublished',
            'operation' => 'unpublish',
            'decision_code' => $knownFingerprint ? 'unpublish' : 'unpublish_hold_unknown_legacy_fingerprint',
            'material_changed' => $knownFingerprint,
            'material_changed_at' => $knownFingerprint ? $effectiveAt : null,
            'evidence_ref' => $this->evidenceRef($evidenceRef ?? 'article:'.$article->id.':unpublish'),
            'decision_key' => $this->decisionKey(
                $article,
                $latest?->id,
                $authorityRevision,
                $knownFingerprint ? (string) $latest->material_fingerprint : 'unknown',
                'unpublished',
                'unpublish',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function materialPayload(Article $article, ArticleTranslationRevision $revision): array
    {
        $seo = ArticleSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('org_id', $article->org_id)
            ->where('article_id', $article->id)
            ->where('locale', $article->locale)
            ->lockForUpdate()
            ->first();
        $metadata = is_array($revision->authority_metadata_json)
            ? $revision->authority_metadata_json
            : [];

        return [
            'family' => self::FAMILY,
            'locale' => (string) $article->locale,
            'public_identity' => $this->publicIdentity($article),
            'authority_revision_kind' => self::AUTHORITY_REVISION_KIND,
            'visible_content' => [
                'content_md' => $this->normalizeText((string) $revision->content_md),
                'excerpt' => $this->nullableText($revision->excerpt),
                'title' => $this->normalizeText((string) $revision->title),
            ],
            'claims_and_sources' => [
                'declared_claims_and_sources' => $this->sortedSemanticList(
                    data_get($metadata, 'claims_and_sources', []),
                ),
                'visible_sources' => $this->sortedSemanticList(
                    data_get($metadata, 'visible_provenance.sources', []),
                ),
            ],
            'search_surface' => [
                'canonical_url' => $this->nullableString($seo?->canonical_url),
                'is_indexable' => (bool) ($seo?->is_indexable ?? $article->is_indexable),
                'llms_eligible' => (bool) $article->llms_eligible,
                'og_description' => $this->nullableText($seo?->og_description),
                'og_image_url' => $this->nullableString($seo?->og_image_url),
                'og_title' => $this->nullableText($seo?->og_title),
                'robots' => $this->nullableString($seo?->robots),
                'schema_json' => $this->sanitizeSearchSchema($seo?->schema_json),
                'seo_description' => $this->nullableText($seo?->seo_description),
                'seo_title' => $this->nullableText($seo?->seo_title),
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
            ],
            'locale_linkage' => [
                'source_locale' => $this->nullableString($article->source_locale),
                'source_public_identity' => $this->sourcePublicIdentity($article),
            ],
            'public_structure' => [
                'is_public' => (bool) $article->is_public,
                'lifecycle_state' => $this->nullableString($article->lifecycle_state),
                'related_test_slug' => $this->nullableString($article->related_test_slug),
                'slug' => (string) $article->slug,
                'status' => (string) $article->status,
            ],
            'non_material_context' => [
                'article_updated_at' => $article->updated_at?->toIso8601String(),
                'revision_number' => $revision->revision_number,
                'revision_published_at' => $revision->published_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{org_id:int,family:string,locale:string,authority_subject_key:string,public_identity:string}
     */
    private function identity(Article $article): array
    {
        return [
            'org_id' => (int) $article->org_id,
            'family' => self::FAMILY,
            'locale' => (string) $article->locale,
            'authority_subject_key' => 'article:'.$article->id,
            'public_identity' => $this->publicIdentity($article),
        ];
    }

    private function publicIdentity(Article $article): string
    {
        return '/'.trim((string) $article->locale, '/').'/articles/'.ltrim((string) $article->slug, '/');
    }

    private function sourcePublicIdentity(Article $article): ?string
    {
        if ($article->source_article_id === null) {
            return null;
        }

        $source = Article::query()
            ->withoutGlobalScopes()
            ->select(['id', 'locale', 'slug'])
            ->where('org_id', $article->org_id)
            ->find($article->source_article_id);

        if (! $source instanceof Article) {
            throw new InvalidArgumentException('Article material decision source authority is missing or tenant-mismatched.');
        }

        return $this->publicIdentity($source);
    }

    private function latestForUpdate(Article $article): ?ContentMaterialDecision
    {
        return ContentMaterialDecision::query()
            ->where('org_id', $article->org_id)
            ->where('family', self::FAMILY)
            ->where('locale', $article->locale)
            ->where('authority_subject_key', 'article:'.$article->id)
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function assertPublishedAuthority(Article $article, ArticleTranslationRevision $revision): void
    {
        if ((int) $article->id <= 0
            || (int) $article->published_revision_id !== (int) $revision->id
            || (int) $revision->article_id !== (int) $article->id
            || (int) $revision->org_id !== (int) $article->org_id
            || (string) $revision->locale !== (string) $article->locale
            || (string) $article->status !== 'published'
            || ! (bool) $article->is_public
            || (string) $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
            throw new InvalidArgumentException('Article material decision requires the locked published authority revision.');
        }
    }

    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Article material decisions must be recorded inside the publish transaction.');
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeOperation(string $operation, array $allowed): string
    {
        $operation = trim($operation);
        if (! in_array($operation, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported Article material decision operation.');
        }

        return $operation;
    }

    private function evidenceRef(string $evidenceRef): string
    {
        $evidenceRef = trim($evidenceRef);
        if ($evidenceRef === ''
            || mb_strlen($evidenceRef) > 191
            || preg_match('/\A[A-Za-z0-9:._-]+\z/', $evidenceRef) !== 1) {
            throw new InvalidArgumentException('Article material decision evidence_ref is invalid.');
        }

        return $evidenceRef;
    }

    private function decisionKey(
        Article $article,
        ?int $previousDecisionId,
        string $authorityRevision,
        string $materialFingerprint,
        string $publicationState,
        string $operation,
    ): string {
        return hash('sha256', implode('|', [
            (string) $article->org_id,
            self::FAMILY,
            (string) $article->locale,
            $this->publicIdentity($article),
            (string) ($previousDecisionId ?? 'initial'),
            $authorityRevision,
            $materialFingerprint,
            $publicationState,
            $operation,
        ]));
    }

    private function normalizeText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map(
            static fn (string $line): string => rtrim($line),
            explode("\n", $value),
        );

        return trim(implode("\n", $lines));
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = $this->normalizeText($value);

        return $value === '' ? null : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function sortedSemanticList(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        $items = array_is_list($value) ? $value : [$value];
        usort($items, fn (mixed $left, mixed $right): int => $this->sortIdentity($left) <=> $this->sortIdentity($right));

        return $items;
    }

    private function sortIdentity(mixed $value): string
    {
        return (string) json_encode(
            $this->canonicalizeForSort($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function canonicalizeForSort(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeForSort($item);
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function sanitizeSearchSchema(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        $excluded = [
            'created_at',
            'dateModified',
            'datePublished',
            'deploy_at',
            'generated_at',
            'published_at',
            'updated_at',
        ];
        foreach ($excluded as $key) {
            unset($value[$key]);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sanitizeSearchSchema($item);
        }

        return $value;
    }
}
