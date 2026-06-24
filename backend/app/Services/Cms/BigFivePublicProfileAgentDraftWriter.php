<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Support\Facades\DB;

final class BigFivePublicProfileAgentDraftWriter
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
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    public function plan(array $package, array $qa, string $sourceSha256, string $qaSha256): array
    {
        return $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, false);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    public function write(array $package, array $qa, string $sourceSha256, string $qaSha256): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, true));
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    private function buildSummary(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $write): array
    {
        $qaRows = $this->qaRowsByUrl($qa);
        $approvedRows = $write ? $this->approvedRowsByUrl($sourceSha256, $qaSha256) : [];
        $errors = [];
        $rows = [];

        foreach ($this->recommendations($package) as $position => $recommendation) {
            $identity = $this->identityForRecommendation($recommendation);
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            $qaRow = $qaRows[$targetUrl] ?? [];
            $recommendationJson = $this->jsonString($recommendation);
            $recommendationSha256 = hash('sha256', $recommendationJson);

            if ($identity === null) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'unsupported_big_five_target_url',
                    'message' => 'Only Big Five hub, facet hub, domain, and high/low polarity public URLs are supported.',
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

            if ($qaRow === [] || ! $this->qaDecisionPasses((string) ($qaRow['decision'] ?? $qaRow['status'] ?? $qaRow['qa_status'] ?? ''))) {
                $errors[] = [
                    'field' => 'qa.'.((string) $targetUrl),
                    'code' => 'qa_pass_required',
                    'message' => 'Every Big Five draft row requires a matching PASS QA row.',
                ];
            }

            if ((array) ($qaRow['blockers'] ?? []) !== []) {
                $errors[] = [
                    'field' => 'qa.'.((string) $targetUrl).'.blockers',
                    'code' => 'qa_blockers_present',
                    'message' => 'QA blockers prevent CMS draft planning.',
                ];
            }

            if ($write && ! $this->approvedRowPasses($approvedRows[$targetUrl] ?? null, $recommendationSha256)) {
                $errors[] = [
                    'field' => 'approval_queue.'.((string) $targetUrl),
                    'code' => 'approved_approval_queue_item_required',
                    'message' => 'Big Five CMS draft writes require an approved approval queue item matching target, package hash, QA hash, and recommendation hash.',
                ];
            }

            $existing = $this->existingAsset($identity);
            if ($existing instanceof PersonalityPublicContentAsset && ! $this->existingAssetIsWritableDraft($existing)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'existing_live_or_foreign_asset_blocks_draft_write',
                    'message' => 'Existing public/content-ready/published/indexable asset blocks draft-only writes.',
                ];
            }

            $sameSourceDraft = $existing instanceof PersonalityPublicContentAsset
                && $this->existingAssetMatchesHashes($existing, $sourceSha256, $qaSha256, $recommendationSha256);

            $rows[] = [
                'position' => $position + 1,
                'url' => $targetUrl,
                'path' => $identity['path'],
                'locale' => $identity['locale'],
                'entity_type' => $identity['entity_type'],
                'entity_key' => $identity['entity_key'],
                'slug' => $identity['slug'],
                'source_sha256' => $sourceSha256,
                'qa_source_sha256' => $qaSha256,
                'recommendation_sha256' => $recommendationSha256,
                'existing_asset_id' => $existing?->id !== null ? (int) $existing->id : null,
                'action' => $existing instanceof PersonalityPublicContentAsset
                    ? ($sameSourceDraft ? 'skip_existing_same_source_draft' : 'update_existing_draft_revision_overlay')
                    : 'create_draft_revision_overlay',
                'asset_preview' => $this->assetPayload($recommendation, $identity, $sourceSha256, $qaSha256, $recommendationSha256),
            ];
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write), [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($rows),
                'logical_entity_count' => $this->logicalEntityCount($rows),
                'locale_counts' => $this->localeCounts($rows),
                'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
                'domain_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_DOMAIN),
                'polarity_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_POLARITY),
                'facet_hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_FACET_HUB),
                'would_create_revision_count' => 0,
                'would_update_revision_count' => 0,
                'created_revision_count' => 0,
                'updated_revision_count' => 0,
                'skipped_existing_count' => 0,
                'missing_target_count' => $this->countErrorCode($errors, 'unsupported_big_five_target_url'),
                'forbidden_route_count' => $this->countErrorCode($errors, 'forbidden_private_route_pattern_present'),
                'rows' => $rows,
                'errors' => $errors,
                'warnings' => [],
            ]);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        if ($write) {
            foreach ($rows as &$row) {
                $existingId = $row['existing_asset_id'] ?? null;
                if ($existingId !== null && $row['action'] === 'skip_existing_same_source_draft') {
                    $row['action'] = 'skipped_existing';
                    $skipped++;

                    continue;
                }

                if ($existingId !== null) {
                    PersonalityPublicContentAsset::query()
                        ->withoutGlobalScopes()
                        ->whereKey((int) $existingId)
                        ->update((array) $row['asset_preview']);
                    $row['action'] = 'updated_draft_revision_overlay';
                    $updated++;

                    continue;
                }

                PersonalityPublicContentAsset::query()->create((array) $row['asset_preview']);
                $row['action'] = 'created_draft_revision_overlay';
                $created++;
            }
            unset($row);
        }

        return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write), [
            'ok' => true,
            'status' => 'pass',
            'row_count' => count($rows),
            'logical_entity_count' => $this->logicalEntityCount($rows),
            'locale_counts' => $this->localeCounts($rows),
            'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
            'domain_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_DOMAIN),
            'polarity_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_POLARITY),
            'facet_hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_FACET_HUB),
            'would_create_revision_count' => $write ? 0 : count(array_filter($rows, static fn (array $row): bool => ($row['existing_asset_id'] ?? null) === null)),
            'would_update_revision_count' => $write ? 0 : count(array_filter($rows, static fn (array $row): bool => ($row['existing_asset_id'] ?? null) !== null && $row['action'] !== 'skip_existing_same_source_draft')),
            'created_revision_count' => $created,
            'updated_revision_count' => $updated,
            'created_asset_count' => $created,
            'skipped_existing_count' => $skipped,
            'missing_target_count' => 0,
            'forbidden_route_count' => 0,
            'writes_committed' => $write && ($created + $updated) > 0,
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
     * @param  array<string,mixed>  $qa
     * @return array<string,array<string,mixed>>
     */
    private function qaRowsByUrl(array $qa): array
    {
        $rows = [];
        foreach ([$qa['evaluations'] ?? null, $qa['page_results'] ?? null, $qa['results'] ?? null, $qa['items'] ?? null] as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($source as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $url = (string) ($item['target_url'] ?? $item['url'] ?? '');
                if ($url !== '') {
                    $rows[$url] = $item;
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function approvedRowsByUrl(string $sourceSha256, string $qaSha256): array
    {
        $rows = [];
        $items = DB::table('personality_agent_approval_items as items')
            ->join('personality_agent_approval_batches as batches', 'batches.id', '=', 'items.batch_id')
            ->where('batches.framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('batches.source_package_sha256', $sourceSha256)
            ->where('batches.qa_sha256', $qaSha256)
            ->where('items.framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('items.approval_state', 'approved')
            ->whereNotNull('items.approved_at')
            ->whereNull('items.rejected_at')
            ->whereNull('items.blocked_reason')
            ->select(['items.target_url', 'items.recommendation_sha256'])
            ->get();

        foreach ($items as $item) {
            $rows[(string) $item->target_url] = [
                'target_url' => (string) $item->target_url,
                'recommendation_sha256' => (string) $item->recommendation_sha256,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>|null  $row
     */
    private function approvedRowPasses(?array $row, string $recommendationSha256): bool
    {
        return $row !== null && (string) ($row['recommendation_sha256'] ?? '') === $recommendationSha256;
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
            'locale' => $this->localeFromPrefix($prefix),
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

    private function existingAssetIsWritableDraft(PersonalityPublicContentAsset $asset): bool
    {
        return (bool) $asset->is_public === false
            && (bool) $asset->index_eligible === false
            && (bool) $asset->sitemap_eligible === false
            && (bool) $asset->llms_eligible === false
            && in_array((string) $asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_DRAFT,
                PersonalityPublicContentAsset::LAUNCH_REVIEW,
            ], true);
    }

    private function existingAssetMatchesHashes(PersonalityPublicContentAsset $asset, string $sourceSha256, string $qaSha256, string $recommendationSha256): bool
    {
        if ((string) $asset->source_hash !== $sourceSha256) {
            return false;
        }

        $notes = is_array($asset->evidence_notes_json) ? $asset->evidence_notes_json : [];
        foreach ($notes as $note) {
            if (! is_array($note)) {
                continue;
            }

            if (
                (string) ($note['package_sha256'] ?? '') === $sourceSha256
                && (string) ($note['qa_sha256'] ?? '') === $qaSha256
                && (string) ($note['recommendation_sha256'] ?? '') === $recommendationSha256
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @param  array{path:string,locale:string,entity_type:string,entity_key:string,slug:string}  $identity
     * @return array<string,mixed>
     */
    private function assetPayload(array $recommendation, array $identity, string $sourceSha256, string $qaSha256, string $recommendationSha256): array
    {
        $recommendations = is_array($recommendation['recommendations'] ?? null) ? $recommendation['recommendations'] : [];
        $title = trim((string) ($recommendations['h1'] ?? $recommendations['title'] ?? 'Big Five public profile draft'));
        $seoTitle = trim((string) ($recommendations['title'] ?? $title));
        $description = trim((string) ($recommendations['description'] ?? ''));
        $quickAnswer = trim((string) ($recommendations['quick_answer'] ?? ''));

        return [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $identity['entity_type'],
            'entity_key' => $identity['entity_key'],
            'slug' => $identity['slug'],
            'locale' => $identity['locale'],
            'title' => $title,
            'summary' => $quickAnswer !== '' ? $quickAnswer : $description,
            'content_sections_json' => $this->contentSections($quickAnswer, $recommendations),
            'seo_json' => [
                'title' => $seoTitle,
                'description' => $description,
            ],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => [
                'path' => $identity['path'],
            ],
            'hreflang_json' => [],
            'faq_json' => array_values(is_array($recommendations['faq'] ?? null) ? $recommendations['faq'] : []),
            'media_json' => [],
            'schema_json' => [],
            'method_boundary_json' => [
                'summary' => 'Big Five public profile drafts are reflective educational content only; they are not clinical diagnosis, employment screening, official affiliation, or deterministic guidance.',
                'not_for' => ['clinical diagnosis', 'employment screening', 'deterministic decisions'],
            ],
            'evidence_notes_json' => [
                [
                    'source_type' => 'agent_recommendation',
                    'source' => self::SNAPSHOT_SOURCE,
                    'package_sha256' => $sourceSha256,
                    'qa_sha256' => $qaSha256,
                    'recommendation_sha256' => $recommendationSha256,
                ],
            ],
            'internal_links_json' => array_values(is_array($recommendations['internal_links'] ?? null) ? $recommendations['internal_links'] : []),
            'is_public' => false,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_REVIEW,
            'review_state' => 'agent_draft_pending_review',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => self::SNAPSHOT_SOURCE,
            'source_hash' => $sourceSha256,
            'last_reviewed_at' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $recommendations
     * @return list<array<string,mixed>>
     */
    private function contentSections(string $quickAnswer, array $recommendations): array
    {
        $sections = [];
        if ($quickAnswer !== '') {
            $sections[] = [
                'key' => 'quick_answer',
                'title' => 'Quick answer',
                'body_md' => $quickAnswer,
            ];
        }

        foreach (array_values(is_array($recommendations['differentiation_notes'] ?? null) ? $recommendations['differentiation_notes'] : []) as $index => $note) {
            if (! is_scalar($note) || trim((string) $note) === '') {
                continue;
            }

            $sections[] = [
                'key' => 'differentiation_'.((string) ($index + 1)),
                'title' => 'Differentiation note',
                'body_md' => trim((string) $note),
            ];
        }

        return $sections;
    }

    private function qaDecisionPasses(string $decision): bool
    {
        return in_array($decision, [
            'pass',
            'PASS',
            'PASS_READY_FOR_CMS_DRAFT',
            'PASS_READY_FOR_APPROVAL_QUEUE',
        ], true);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
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
     * @param  list<array<string,string>>  $errors
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

    private function localeFromPrefix(string $prefix): string
    {
        return $prefix === 'zh' ? 'zh-CN' : 'en';
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $write): array
    {
        return [
            'artifact' => 'BIG-FIVE-CMS-DRAFT-WRITER-CONTRACT-01',
            'status' => 'pending',
            'ok' => false,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'package_artifact' => (string) ($package['artifact'] ?? ''),
            'qa_artifact' => (string) ($qa['artifact'] ?? ''),
            'source_sha256' => $sourceSha256,
            'qa_sha256' => $qaSha256,
            'dry_run' => ! $write,
            'write' => $write,
            'writes_attempted' => $write,
            'writes_committed' => false,
            'cms_write_attempted' => $write,
            'cms_mutation_attempted' => $write,
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
