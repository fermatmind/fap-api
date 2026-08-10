<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PublicTopicEdge;
use Illuminate\Support\Facades\Cache;

/** @review-surface public_topic_edge */
final class PublicTopicEdgeReadModel
{
    public const SCHEMA_VERSION = 'public-topic-edges.v1';

    public const AUTHORITY_VERSION = 'cms-public-topic-edge-authority.v1';

    public const CAREER_GATE = 'CLOSED';

    public function __construct(private readonly PublicTopicEdgeAuthorityResolver $resolver) {}

    /**
     * @return array<string,mixed>
     */
    public function read(string $sourceType, int $sourceId, string $sourceLocale): array
    {
        $base = $this->emptyProjection($sourceType, $sourceId, $sourceLocale);

        if (PublicTopicEdge::isCareerType($sourceType)) {
            $base['authority']['reason'] = 'CAREER_LINK_PUBLICATION_GATE_CLOSED';

            return $base;
        }

        $source = $this->resolver->resolve($sourceType, $sourceId, $sourceLocale);
        if ($source === null) {
            $base['authority']['reason'] = 'SOURCE_NOT_PUBLICLY_ELIGIBLE';

            return $base;
        }

        $base['authority']['source_publication_eligible'] = true;
        $base['authority']['source_canonical'] = $source['canonical'];
        $base['authority']['reason'] = 'OK';

        $loadCandidates = static fn (): array => PublicTopicEdge::query()
            ->where('org_id', 0)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_locale', $sourceLocale)
            ->where('active', true)
            ->where('publication_allowed', true)
            ->where('review_state', PublicTopicEdge::REVIEW_APPROVED)
            ->where('target_publication_eligible', true)
            ->where(static fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(static fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->orderBy('position')
            ->orderBy('relation_type')
            ->orderBy('target_type')
            ->orderBy('target_id')
            ->orderBy('target_locale')
            ->orderBy('id')
            ->get()
            ->map(static fn (PublicTopicEdge $edge): array => $edge->toArray())
            ->all();

        try {
            $candidates = Cache::remember(
                PublicTopicEdge::candidateCacheKey(0, $sourceType, $sourceId, $sourceLocale),
                PublicTopicEdge::CACHE_TTL_SECONDS,
                $loadCandidates,
            );
        } catch (\Throwable) {
            $candidates = $loadCandidates();
        }

        usort($candidates, static function (array $left, array $right): int {
            foreach (['position', 'relation_type', 'target_type', 'target_id', 'target_locale', 'id'] as $field) {
                $comparison = ($left[$field] ?? null) <=> ($right[$field] ?? null);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        $seen = [];
        foreach ($candidates as $candidate) {
            $edge = new PublicTopicEdge;
            $edge->forceFill($candidate);
            $edge->exists = true;

            $item = $this->projectEligibleEdge($edge);
            if ($item === null || isset($seen[$item['identity']])) {
                continue;
            }

            $seen[$item['identity']] = true;
            $base['items'][] = $item;
        }

        $base['authority']['eligible_item_count'] = count($base['items']);

        return $base;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function projectEligibleEdge(PublicTopicEdge $edge): ?array
    {
        $evidenceRefs = is_array($edge->evidence_refs)
            ? array_values(array_filter(
                $edge->evidence_refs,
                static fn (mixed $reference): bool => is_string($reference)
                    && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/', $reference) === 1,
            ))
            : [];
        $now = now();

        if (! (bool) $edge->active
            || ! (bool) $edge->publication_allowed
            || ! (bool) $edge->target_publication_eligible
            || (string) $edge->review_state !== PublicTopicEdge::REVIEW_APPROVED
            || filled($edge->blocker)
            || ($edge->valid_from !== null && $edge->valid_from->isAfter($now))
            || ($edge->valid_until !== null && ! $edge->valid_until->isAfter($now))
            || ! in_array((string) $edge->relation_type, PublicTopicEdge::RELATION_TYPES, true)
            || PublicTopicEdge::isCareerType((string) $edge->target_type)
            || ! in_array((string) $edge->target_type, PublicTopicEdge::PUBLIC_ENTITY_TYPES, true)
            || trim((string) $edge->visible_label) === ''
            || trim((string) $edge->version) === ''
            || (int) $edge->created_by_admin_user_id <= 0
            || (int) $edge->updated_by_admin_user_id <= 0
            || $evidenceRefs === []) {
            return null;
        }

        $isAlternateLocale = (string) $edge->relation_type === 'alternate_locale';
        if (! $isAlternateLocale && (string) $edge->source_locale !== (string) $edge->target_locale) {
            return null;
        }

        if ($isAlternateLocale && ((string) $edge->source_type !== (string) $edge->target_type
            || (string) $edge->source_locale === (string) $edge->target_locale)) {
            return null;
        }

        $target = $this->resolver->resolve(
            (string) $edge->target_type,
            (int) $edge->target_id,
            (string) $edge->target_locale,
            (int) $edge->org_id,
        );
        if ($target === null || ! hash_equals($target['canonical'], rtrim(trim((string) $edge->target_canonical), '/'))) {
            return null;
        }

        $identityFields = [
            (string) $edge->source_type,
            (string) $edge->source_id,
            (string) $edge->source_locale,
            (string) $edge->relation_type,
            (string) $edge->target_type,
            (string) $edge->target_id,
            (string) $edge->target_locale,
        ];

        return [
            'identity' => hash('sha256', implode('|', $identityFields)),
            'source_type' => (string) $edge->source_type,
            'source_id' => (int) $edge->source_id,
            'source_locale' => (string) $edge->source_locale,
            'relation_type' => (string) $edge->relation_type,
            'target_type' => (string) $edge->target_type,
            'target_id' => (int) $edge->target_id,
            'target_locale' => (string) $edge->target_locale,
            'visible_label' => (string) $edge->visible_label,
            'context' => filled($edge->context) ? (string) $edge->context : null,
            'position' => (int) $edge->position,
            'active' => true,
            'proposed_active_state' => (bool) $edge->proposed_active_state,
            'publication_allowed' => true,
            'blocker' => filled($edge->blocker) ? (string) $edge->blocker : null,
            'review_state' => (string) $edge->review_state,
            'evidence_refs' => $evidenceRefs,
            'version' => (string) $edge->version,
            'valid_from' => $edge->valid_from?->toIso8601String(),
            'valid_until' => $edge->valid_until?->toIso8601String(),
            'target_publication_eligible' => true,
            'target_canonical' => $target['canonical'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyProjection(string $sourceType, int $sourceId, string $sourceLocale): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'authority' => [
                'owner' => 'fap-api/cms',
                'authority_version' => self::AUTHORITY_VERSION,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_locale' => $sourceLocale,
                'source_publication_eligible' => false,
                'source_canonical' => null,
                'eligible_item_count' => 0,
                'frontend_fallback_allowed' => false,
                'candidate_cache_ttl_seconds' => PublicTopicEdge::CACHE_TTL_SECONDS,
                'target_truth_readback' => 'live',
                'career_link_publication_gate' => self::CAREER_GATE,
                'reason' => 'NO_ELIGIBLE_EDGES',
            ],
            'items' => [],
        ];
    }
}
