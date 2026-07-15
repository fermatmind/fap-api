<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\VisibleProvenance;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class BigFiveVisibleProvenanceProjector
{
    public const CONTRACT_VERSION = 'big5-visible-provenance.v1';

    private const AUTHOR_ROLES = [
        'content_author',
        'editorial_author',
        'policy_owner',
        'product_authority_owner',
    ];

    private const REVIEWER_ROLES = [
        'content_reviewer',
        'editorial_reviewer',
        'operator_reviewer',
        'policy_reviewer',
    ];

    private const REVIEW_STATES = [
        'approved',
        'human_reviewed',
        'published',
        'reviewed',
    ];

    private const SOURCE_CATEGORY_MAP = [
        'academic_evidence' => 'academic_evidence',
        'internal_policy' => 'internal_policy',
        'internal_repository_evidence' => 'internal_policy',
        'official_product_evidence' => 'product_authority',
        'product_authority' => 'product_authority',
    ];

    /** @return array<string, mixed> */
    public function forArticle(Article $article, ?ArticleTranslationRevision $revision = null): array
    {
        $revisionMatches = $revision instanceof ArticleTranslationRevision
            && (int) $revision->article_id === (int) $article->id
            && (int) $revision->org_id === (int) $article->org_id
            && (string) $revision->locale === (string) $article->locale
            && $revision->revision_status === ArticleTranslationRevision::STATUS_PUBLISHED;
        $public = $article->status === 'published' && (bool) $article->is_public && $revisionMatches;
        $metadata = $revisionMatches && is_array($revision->authority_metadata_json)
            ? $revision->authority_metadata_json
            : [];
        $author = $public ? $this->actor(data_get($metadata, 'visible_provenance.author'), self::AUTHOR_ROLES) : null;
        if ($author !== null) {
            if ($revision->created_by !== null && $author['identity'] !== 'admin_user:'.(int) $revision->created_by) {
                $author = null;
            } elseif (filled($article->author_name) && $author['label'] !== trim((string) $article->author_name)) {
                $author = null;
            }
        }
        $reviewer = $public ? $this->reviewer(data_get($metadata, 'visible_provenance.reviewer')) : null;
        if ($reviewer !== null && (
            $revision->reviewed_by === null
            || $reviewer['identity'] !== 'admin_user:'.(int) $revision->reviewed_by
            || $reviewer['review_state'] !== (string) $revision->revision_status
            || $reviewer['reviewed_at'] !== $this->normalizeDate($revision->reviewed_at)
            || (filled($article->reviewer_name) && $reviewer['label'] !== trim((string) $article->reviewer_name))
        )) {
            $reviewer = null;
        }

        return $this->project(
            'Article',
            'article:'.(int) $article->id.':'.(string) $article->locale.':'.(string) $article->slug,
            $author,
            $reviewer,
            $public ? $this->sources(data_get($metadata, 'visible_provenance.sources')) : [],
            (bool) $article->is_public,
        );
    }

    /** @return array<string, mixed> */
    public function forPersonalityAsset(PersonalityPublicContentAsset $asset): array
    {
        $public = $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            && (bool) $asset->is_public;
        $metadata = is_array($asset->authority_json) ? $asset->authority_json : [];
        $reviewer = $public ? $this->reviewer(data_get($metadata, 'visible_provenance.reviewer')) : null;
        if ($reviewer !== null && (
            $reviewer['review_state'] !== strtolower(trim((string) $asset->review_state))
            || $reviewer['reviewed_at'] !== $this->normalizeDate($asset->last_reviewed_at)
        )) {
            $reviewer = null;
        }

        return $this->project(
            'PersonalityPublicContentAsset',
            'personality_public_content_asset:'.(int) $asset->id.':'.(string) $asset->locale.':'.(string) $asset->slug,
            $public ? $this->actor(data_get($metadata, 'visible_provenance.author'), self::AUTHOR_ROLES) : null,
            $reviewer,
            $public ? $this->sources(data_get($metadata, 'visible_provenance.sources')) : [],
            (bool) $asset->is_public,
        );
    }

    /** @return array<string, mixed> */
    public function forTopic(TopicProfile $topic, ?TopicProfileRevision $revision = null): array
    {
        $revisionMatches = $revision instanceof TopicProfileRevision
            && (int) $revision->profile_id === (int) $topic->id;
        $public = $topic->status === TopicProfile::STATUS_PUBLISHED && (bool) $topic->is_public && $revisionMatches;
        $metadata = $revisionMatches && is_array($revision->snapshot_json) ? $revision->snapshot_json : [];

        return $this->project(
            'Topic',
            'topic:'.(int) $topic->id.':'.(string) $topic->locale.':'.(string) $topic->slug,
            $public ? $this->actor(data_get($metadata, 'visible_provenance.author'), self::AUTHOR_ROLES) : null,
            $public ? $this->reviewer(data_get($metadata, 'visible_provenance.reviewer')) : null,
            $public ? $this->sources(data_get($metadata, 'visible_provenance.sources')) : [],
            (bool) $topic->is_public,
        );
    }

    /** @return array<string, mixed> */
    public function forLandingSurface(LandingSurface $surface): array
    {
        $public = $surface->status === LandingSurface::STATUS_PUBLISHED && (bool) $surface->is_public;
        $metadata = is_array($surface->payload_json) ? $surface->payload_json : [];

        return $this->project(
            'LandingSurface',
            'landing_surface:'.(int) $surface->id.':'.(string) $surface->locale.':'.(string) $surface->surface_key,
            $public ? $this->actor(data_get($metadata, 'visible_provenance.author'), self::AUTHOR_ROLES) : null,
            $public ? $this->reviewer(data_get($metadata, 'visible_provenance.reviewer')) : null,
            $public ? $this->sources(data_get($metadata, 'visible_provenance.sources')) : [],
            (bool) $surface->is_public,
        );
    }

    /**
     * @param  array<string, string>|null  $author
     * @param  array<string, string>|null  $reviewer
     * @param  list<array<string, string>>  $sources
     * @return array<string, mixed>
     */
    private function project(
        string $surface,
        string $identity,
        ?array $author,
        ?array $reviewer,
        array $sources,
        bool $existingPublicContent,
    ): array {
        $blocked = [];
        if ($author === null) {
            $blocked[] = 'visible_author_authority_missing';
        }
        if ($reviewer === null) {
            $blocked[] = 'visible_reviewer_authority_missing';
            $blocked[] = 'promotion_reviewer_gate_blocked';
        }
        if ($sources === []) {
            $blocked[] = 'visible_source_authority_missing';
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'authority_surface' => $surface,
            'identity' => $identity,
            'visible_provenance' => [
                'author' => $author,
                'reviewer' => $reviewer,
                'sources' => $sources,
            ],
            'eligibility' => [
                'visible_author_eligible' => $author !== null,
                'visible_reviewer_eligible' => $reviewer !== null,
                'visible_source_eligible' => $sources !== [],
                'promotion_eligible' => $author !== null && $reviewer !== null && $sources !== [],
                'blocked_reasons' => $blocked,
            ],
            'preservation' => [
                'read_only_projection' => true,
                'existing_public_content_preserved' => $existingPublicContent,
                'missing_reviewer_overwrites_existing_content' => false,
            ],
            'claim_boundaries' => [
                'institutional_certification_claimed' => false,
                'expert_endorsement_claimed' => false,
                'clinical_review_claimed' => false,
            ],
        ];
    }

    /** @param mixed $value @param list<string> $roles @return array<string, string>|null */
    private function actor(mixed $value, array $roles): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $actor = [
            'identity' => trim((string) ($value['identity'] ?? '')),
            'label' => trim((string) ($value['label'] ?? '')),
            'role' => strtolower(trim((string) ($value['role'] ?? ''))),
            'authority_ref' => trim((string) ($value['authority_ref'] ?? '')),
        ];
        if (in_array('', $actor, true)
            || ! in_array($actor['role'], $roles, true)
            || $this->hasEndorsementClaim($actor['label'])) {
            return null;
        }

        return $actor;
    }

    /** @return array<string, string>|null */
    private function reviewer(mixed $value): ?array
    {
        $actor = $this->actor($value, self::REVIEWER_ROLES);
        if ($actor === null || ! is_array($value)) {
            return null;
        }
        if (preg_match('/\Aadmin_user:[1-9][0-9]*\z/', $actor['identity']) !== 1
            || ! str_starts_with($actor['authority_ref'], 'review-ledger:')) {
            return null;
        }
        $reviewedAt = $this->normalizeDate($value['reviewed_at'] ?? null);
        $reviewState = strtolower(trim((string) ($value['review_state'] ?? '')));
        if ($reviewedAt === null || ! in_array($reviewState, self::REVIEW_STATES, true)) {
            return null;
        }

        return [...$actor, 'reviewed_at' => $reviewedAt, 'review_state' => $reviewState];
    }

    /** @return list<array<string, string>> */
    private function sources(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $sources = [];
        foreach ($value as $source) {
            if (! is_array($source)) {
                return [];
            }
            $inputCategory = strtolower(trim((string) ($source['category'] ?? '')));
            $entry = [
                'source_id' => trim((string) ($source['source_id'] ?? '')),
                'label' => trim((string) ($source['label'] ?? '')),
                'category' => self::SOURCE_CATEGORY_MAP[$inputCategory] ?? '',
                'authority_ref' => trim((string) ($source['authority_ref'] ?? '')),
            ];
            if (in_array('', $entry, true) || $this->hasEndorsementClaim($entry['label'])) {
                return [];
            }
            $sources[] = $entry;
        }

        return $sources;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse((string) $value);

            return $date->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function hasEndorsementClaim(string $label): bool
    {
        return preg_match('/\b(certified|accredited|clinical|medical expert|official partner)\b|官方合作|认证专家|临床审核|医疗专家|权威背书/iu', $label) === 1;
    }
}
