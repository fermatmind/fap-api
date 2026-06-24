<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Support\Facades\DB;

final class EnneagramCmsDraftWriter
{
    private const SNAPSHOT_SOURCE = 'enneagram_agent_projection_draft_v1';

    private const ALLOWED_ENTITY_TYPES = [
        PersonalityPublicContentAsset::ENTITY_HUB,
        PersonalityPublicContentAsset::ENTITY_CENTER,
        PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
    ];

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
        $errors = [];
        $rows = [];

        foreach ($this->recommendations($package) as $position => $recommendation) {
            $identity = $this->identityForRecommendation($recommendation);
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            $qaRow = $qaRows[$targetUrl] ?? [];
            $recommendationJson = $this->jsonString($recommendation);

            if ($identity === null) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'unsupported_enneagram_target_url',
                    'message' => 'Only Enneagram hub, center, and core type public URLs are supported.',
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
                    'message' => 'Every Enneagram draft row requires a matching PASS QA row.',
                ];
            }

            if ((array) ($qaRow['blockers'] ?? []) !== []) {
                $errors[] = [
                    'field' => 'qa.'.((string) $targetUrl).'.blockers',
                    'code' => 'qa_blockers_present',
                    'message' => 'QA blockers prevent CMS draft planning.',
                ];
            }

            $existing = $this->existingAsset($identity);
            if ($existing instanceof PersonalityPublicContentAsset && ! $this->existingAssetIsWritableDraft($existing, $sourceSha256)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'existing_live_or_foreign_asset_blocks_draft_write',
                    'message' => 'Existing public/content-ready/published/indexable or foreign-source asset blocks draft-only writes.',
                ];
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
                'qa_source_sha256' => $qaSha256,
                'recommendation_sha256' => hash('sha256', $recommendationJson),
                'existing_asset_id' => $existing?->id !== null ? (int) $existing->id : null,
                'action' => $existing instanceof PersonalityPublicContentAsset ? 'skip_existing_same_source_draft' : 'create_draft_asset',
                'asset_preview' => $this->assetPayload($recommendation, $identity, $sourceSha256, $qaSha256),
            ];
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write), [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($rows),
                'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
                'center_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CENTER),
                'core_type_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CORE_TYPE),
                'would_create_asset_count' => 0,
                'created_asset_count' => 0,
                'skipped_existing_count' => 0,
                'rows' => $rows,
                'errors' => $errors,
                'warnings' => [],
            ]);
        }

        $created = 0;
        $skipped = 0;
        if ($write) {
            foreach ($rows as &$row) {
                if (($row['existing_asset_id'] ?? null) !== null) {
                    $row['action'] = 'skipped_existing';
                    $skipped++;

                    continue;
                }

                PersonalityPublicContentAsset::query()->create((array) $row['asset_preview']);
                $row['action'] = 'created_draft_asset';
                $created++;
            }
            unset($row);
        }

        return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write), [
            'ok' => true,
            'status' => 'pass',
            'row_count' => count($rows),
            'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
            'center_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CENTER),
            'core_type_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CORE_TYPE),
            'would_create_asset_count' => $write ? 0 : count(array_filter($rows, static fn (array $row): bool => ($row['existing_asset_id'] ?? null) === null)),
            'created_asset_count' => $created,
            'skipped_existing_count' => $skipped,
            'writes_committed' => $write && $created > 0,
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
        foreach ([$qa['page_results'] ?? null, $qa['results'] ?? null, $qa['items'] ?? null] as $source) {
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
     * @param  array<string,mixed>  $recommendation
     * @return array{path:string,locale:string,entity_type:string,entity_key:string,slug:string}|null
     */
    private function identityForRecommendation(array $recommendation): ?array
    {
        $framework = (string) ($recommendation['framework'] ?? '');
        if ($framework !== PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM) {
            return null;
        }

        $path = (string) parse_url((string) ($recommendation['target_url'] ?? ''), PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram$#i', $path, $matches) === 1) {
            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
                'entity_key' => 'enneagram',
                'slug' => 'enneagram',
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/centers/(?<code>gut|heart|head)$#i', $path, $matches) === 1) {
            $code = strtolower((string) $matches['code']);

            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => PersonalityPublicContentAsset::ENTITY_CENTER,
                'entity_key' => $code,
                'slug' => 'enneagram/centers/'.$code,
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/type-(?<type>[1-9])$#i', $path, $matches) === 1) {
            $code = 'type-'.((string) $matches['type']);

            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
                'entity_key' => $code,
                'slug' => 'enneagram/'.$code,
            ];
        }

        return null;
    }

    /**
     * @param  array{locale:string,entity_type:string,entity_key:string}  $identity
     */
    private function existingAsset(array $identity): ?PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->where('entity_type', $identity['entity_type'])
            ->where('entity_key', $identity['entity_key'])
            ->where('locale', $identity['locale'])
            ->first();
    }

    private function existingAssetIsWritableDraft(PersonalityPublicContentAsset $asset, string $sourceSha256): bool
    {
        return (string) $asset->source_hash === $sourceSha256
            && (bool) $asset->is_public === false
            && (bool) $asset->index_eligible === false
            && (bool) $asset->sitemap_eligible === false
            && (bool) $asset->llms_eligible === false
            && in_array((string) $asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_DRAFT,
                PersonalityPublicContentAsset::LAUNCH_REVIEW,
            ], true);
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @param  array{path:string,locale:string,entity_type:string,entity_key:string,slug:string}  $identity
     * @return array<string,mixed>
     */
    private function assetPayload(array $recommendation, array $identity, string $sourceSha256, string $qaSha256): array
    {
        $recommendations = is_array($recommendation['recommendations'] ?? null) ? $recommendation['recommendations'] : [];
        $title = trim((string) ($recommendations['h1'] ?? $recommendations['title'] ?? 'Enneagram public profile draft'));
        $seoTitle = trim((string) ($recommendations['title'] ?? $title));
        $description = trim((string) ($recommendations['description'] ?? ''));
        $quickAnswer = trim((string) ($recommendations['quick_answer'] ?? ''));

        return [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
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
                'summary' => 'Enneagram public profile drafts are reflective educational content only; they are not clinical diagnosis, hiring screening, official affiliation, or deterministic guidance.',
                'not_for' => ['clinical diagnosis', 'employment screening', 'deterministic decisions'],
            ],
            'evidence_notes_json' => [
                [
                    'source_type' => 'agent_recommendation',
                    'source' => self::SNAPSHOT_SOURCE,
                    'package_sha256' => $sourceSha256,
                    'qa_sha256' => $qaSha256,
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

    private function countRows(array $rows, string $entityType): int
    {
        return count(array_filter($rows, static fn (array $row): bool => ($row['entity_type'] ?? null) === $entityType));
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
            'artifact' => 'ENNEAGRAM-CMS-DRAFT-WRITER-CONTRACT-01',
            'status' => 'pending',
            'ok' => false,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
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
