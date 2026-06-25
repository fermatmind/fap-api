<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Support\Facades\DB;

final class BigFivePublicProfileAgentPromotionService
{
    private const SNAPSHOT_SOURCE = 'big_five_agent_public_profile_draft_v1';

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

    private const DOMAIN_SLUGS = [
        'agreeableness',
        'conscientiousness',
        'extraversion',
        'neuroticism',
        'openness',
    ];

    private const POLARITY_DOMAIN_SLUGS = [
        'agreeableness',
        'conscientiousness',
        'extraversion',
        'neuroticism',
        'openness',
    ];

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    public function plan(array $package, string $sourceSha256): array
    {
        return $this->buildSummary($package, $sourceSha256, false);
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    public function promote(array $package, string $sourceSha256): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $sourceSha256, true));
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    private function buildSummary(array $package, string $sourceSha256, bool $write): array
    {
        $rows = [];
        $errors = [];

        foreach ($this->recommendations($package) as $position => $recommendation) {
            $identity = $this->identityForRecommendation($recommendation);
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            $recommendationJson = $this->jsonString($recommendation);
            $recommendationSha256 = hash('sha256', $recommendationJson);

            if ($identity === null) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'unsupported_big_five_target_url',
                    'message' => 'Only Big Five hub, facet hub, domain, and polarity public URLs are supported.',
                ];

                continue;
            }

            if ($this->containsForbiddenRoutePattern($targetUrl) || $this->containsForbiddenRoutePattern($recommendationJson)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position),
                    'code' => 'forbidden_private_route_pattern_present',
                    'message' => 'Recommendation contains a private/result/order/payment/account/share route or sensitive query key.',
                ];
            }

            $asset = $this->existingAsset($identity);
            $action = 'promote_to_content_ready';
            if (! $asset instanceof PersonalityPublicContentAsset) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'missing_draft_asset',
                    'message' => 'A matching Big Five draft asset is required before promotion.',
                ];
                $action = 'missing_draft_asset';
            } elseif (! $this->assetMatchesSource($asset, $sourceSha256, $recommendationSha256)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'source_hash_mismatch',
                    'message' => 'The existing asset source hash or recommendation hash does not match the promotion package.',
                ];
                $action = 'blocked_source_hash_mismatch';
            } elseif ($this->assetIsLiveContent($asset)) {
                $action = 'skip_existing_live_match';
            } elseif (! $this->assetIsPromotableDraft($asset, $sourceSha256, $recommendationSha256)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'asset_not_promotable_draft',
                    'message' => 'Only non-public noindex draft/review Big Five assets from the matching source can be promoted.',
                ];
                $action = 'blocked_not_promotable';
            }

            $rows[] = [
                'position' => $position + 1,
                'url' => $targetUrl,
                'path' => $identity['path'],
                'locale' => $identity['locale'],
                'entity_type' => $identity['entity_type'],
                'entity_key' => $identity['entity_key'],
                'slug' => $identity['slug'],
                'source_sha256' => $sourceSha256,
                'recommendation_sha256' => $recommendationSha256,
                'existing_asset_id' => $asset?->id !== null ? (int) $asset->id : null,
                'action' => $action,
            ];
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary($package, $sourceSha256, $write), [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($rows),
                'logical_entity_count' => $this->logicalEntityCount($rows),
                'locale_counts' => $this->localeCounts($rows),
                'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
                'domain_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_DOMAIN),
                'polarity_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_POLARITY),
                'facet_hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_FACET_HUB),
                'would_promote_count' => 0,
                'promoted_count' => 0,
                'skipped_existing_count' => 0,
                'missing_target_count' => $this->countErrorCode($errors, 'missing_draft_asset') + $this->countErrorCode($errors, 'unsupported_big_five_target_url'),
                'forbidden_route_count' => $this->countErrorCode($errors, 'forbidden_private_route_pattern_present'),
                'stale_or_invalid_asset_count' => $this->countErrorCode($errors, 'source_hash_mismatch') + $this->countErrorCode($errors, 'asset_not_promotable_draft'),
                'rows' => $rows,
                'errors' => $errors,
                'warnings' => [],
            ]);
        }

        $promoted = 0;
        $skipped = 0;
        if ($write) {
            foreach ($rows as &$row) {
                if ($row['action'] === 'skip_existing_live_match') {
                    $skipped++;

                    continue;
                }

                PersonalityPublicContentAsset::query()
                    ->withoutGlobalScopes()
                    ->whereKey((int) $row['existing_asset_id'])
                    ->update([
                        'is_public' => true,
                        'index_eligible' => false,
                        'sitemap_eligible' => false,
                        'llms_eligible' => false,
                        'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
                        'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
                        'review_state' => 'agent_promoted_content_ready',
                        'last_reviewed_at' => now(),
                        'updated_at' => now(),
                    ]);
                $row['action'] = 'promoted_to_content_ready';
                $promoted++;
            }
            unset($row);
        }

        return array_merge($this->baseSummary($package, $sourceSha256, $write), [
            'ok' => true,
            'status' => 'pass',
            'row_count' => count($rows),
            'logical_entity_count' => $this->logicalEntityCount($rows),
            'locale_counts' => $this->localeCounts($rows),
            'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
            'domain_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_DOMAIN),
            'polarity_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_POLARITY),
            'facet_hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_FACET_HUB),
            'would_promote_count' => $write ? 0 : count(array_filter($rows, static fn (array $row): bool => ($row['action'] ?? null) === 'promote_to_content_ready')),
            'promoted_count' => $promoted,
            'skipped_existing_count' => $write ? $skipped : count(array_filter($rows, static fn (array $row): bool => ($row['action'] ?? null) === 'skip_existing_live_match')),
            'missing_target_count' => 0,
            'forbidden_route_count' => 0,
            'stale_or_invalid_asset_count' => 0,
            'content_promotion_attempted' => $write,
            'writes_committed' => $write && $promoted > 0,
            'rows' => $rows,
            'errors' => [],
            'warnings' => [],
        ]);
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
     * @param  array<string,mixed>  $recommendation
     * @return array{path:string,locale:string,entity_type:string,entity_key:string,slug:string}|null
     */
    private function identityForRecommendation(array $recommendation): ?array
    {
        if ((string) ($recommendation['framework'] ?? '') !== PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE) {
            return null;
        }

        $path = (string) parse_url((string) ($recommendation['target_url'] ?? ''), PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/big-five$#i', $path, $matches) === 1) {
            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_HUB, 'big-five', 'big-five');
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/big-five/facets$#i', $path, $matches) === 1) {
            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_FACET_HUB, 'facets', 'big-five/facets');
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/big-five/(?<slug>[a-z-]+)$#i', $path, $matches) !== 1) {
            return null;
        }

        $slug = strtolower((string) $matches['slug']);
        if ($slug === 'emotional-stability') {
            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_POLARITY, $slug, 'big-five/'.$slug);
        }

        if (in_array($slug, self::DOMAIN_SLUGS, true)) {
            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_DOMAIN, $slug, 'big-five/'.$slug);
        }

        if (preg_match('#^(?<polarity>high|low)-(?<domain>[a-z-]+)$#i', $slug, $polarityMatches) === 1
            && in_array(strtolower((string) $polarityMatches['domain']), self::POLARITY_DOMAIN_SLUGS, true)) {
            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_POLARITY, $slug, 'big-five/'.$slug);
        }

        return null;
    }

    /**
     * @return array{path:string,locale:string,entity_type:string,entity_key:string,slug:string}
     */
    private function identity(string $path, string $prefix, string $entityType, string $entityKey, string $slug): array
    {
        return [
            'path' => $path,
            'locale' => $prefix === 'zh' ? 'zh-CN' : 'en',
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'slug' => $slug,
        ];
    }

    /**
     * @param  array{locale:string,entity_type:string,entity_key:string}  $identity
     */
    private function existingAsset(array $identity): ?PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('entity_type', $identity['entity_type'])
            ->where('entity_key', $identity['entity_key'])
            ->where('locale', $identity['locale'])
            ->first();
    }

    private function assetMatchesSource(PersonalityPublicContentAsset $asset, string $sourceSha256, string $recommendationSha256): bool
    {
        return (string) $asset->source_package === self::SNAPSHOT_SOURCE
            && (string) $asset->source_hash === $sourceSha256
            && $this->assetEvidenceMatches($asset, $sourceSha256, $recommendationSha256);
    }

    private function assetIsPromotableDraft(PersonalityPublicContentAsset $asset, string $sourceSha256, string $recommendationSha256): bool
    {
        return $this->assetMatchesSource($asset, $sourceSha256, $recommendationSha256)
            && (bool) $asset->is_public === false
            && (bool) $asset->index_eligible === false
            && (bool) $asset->sitemap_eligible === false
            && (bool) $asset->llms_eligible === false
            && $asset->robots === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW
            && in_array((string) $asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_DRAFT,
                PersonalityPublicContentAsset::LAUNCH_REVIEW,
            ], true);
    }

    private function assetIsLiveContent(PersonalityPublicContentAsset $asset): bool
    {
        return (bool) $asset->is_public === true
            && (bool) $asset->index_eligible === false
            && (bool) $asset->sitemap_eligible === false
            && (bool) $asset->llms_eligible === false
            && $asset->robots === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW
            && $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_CONTENT_READY;
    }

    private function assetEvidenceMatches(PersonalityPublicContentAsset $asset, string $sourceSha256, string $recommendationSha256): bool
    {
        $notes = is_array($asset->evidence_notes_json) ? $asset->evidence_notes_json : [];
        foreach ($notes as $note) {
            if (! is_array($note)) {
                continue;
            }

            if (
                (string) ($note['package_sha256'] ?? '') === $sourceSha256
                && (string) ($note['recommendation_sha256'] ?? '') === $recommendationSha256
            ) {
                return true;
            }
        }

        return false;
    }

    private function countRows(array $rows, string $entityType): int
    {
        return count(array_filter($rows, static fn (array $row): bool => ($row['entity_type'] ?? null) === $entityType));
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function localeCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $locale = (string) ($row['locale'] ?? '');
            $counts[$locale] = ($counts[$locale] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function logicalEntityCount(array $rows): int
    {
        return count(array_unique(array_map(
            static fn (array $row): string => (string) ($row['entity_type'] ?? '').':'.(string) ($row['entity_key'] ?? ''),
            $rows
        )));
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function countErrorCode(array $errors, string $code): int
    {
        return count(array_filter($errors, static fn (array $error): bool => ($error['code'] ?? null) === $code));
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
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, string $sourceSha256, bool $write): array
    {
        return [
            'artifact' => 'BIG-FIVE-CMS-PROMOTION-CONTRACT-01',
            'status' => 'pending',
            'ok' => false,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'package_artifact' => (string) ($package['artifact'] ?? ''),
            'source_sha256' => $sourceSha256,
            'dry_run' => ! $write,
            'write' => $write,
            'writes_attempted' => $write,
            'writes_committed' => false,
            'content_promotion_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'enqueue_attempted' => false,
            'external_calls_attempted' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function jsonString(array $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
