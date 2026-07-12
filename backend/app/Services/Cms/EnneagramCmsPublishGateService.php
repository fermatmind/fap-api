<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueEligibilityEvaluator;
use Illuminate\Support\Facades\DB;

final class EnneagramCmsPublishGateService
{
    private const LLMS_EXPECTED_COUNT = 116;

    private const LLMS_EXPECTED_ENTITY_COUNTS = [
        PersonalityPublicContentAsset::ENTITY_HUB => 2,
        PersonalityPublicContentAsset::ENTITY_CENTER => 6,
        PersonalityPublicContentAsset::ENTITY_CORE_TYPE => 18,
        PersonalityPublicContentAsset::ENTITY_WING => 36,
        PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE => 54,
    ];

    private const SUPPORTED_LOCALES = ['zh-CN', 'en'];

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
    public function publish(array $package, string $sourceSha256): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $sourceSha256, true));
    }

    /**
     * Validate and optionally release the exact 116-asset Enneagram llms.txt cohort.
     * The write path changes only llms_eligible and remains independently SHA/cohort gated.
     *
     * @return array<string,mixed>
     */
    public function llmsTxtRelease(
        string $deployedSha,
        bool $write = false,
        ?string $confirmedCohortSha256 = null,
        ?string $operatorToken = null,
    ): array {
        $deployedSha = strtolower(trim($deployedSha));
        $assets = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->orderBy('locale')
            ->orderBy('entity_type')
            ->orderBy('entity_key')
            ->get();
        [$rows, $issues] = $this->llmsInspect($assets->all());
        if (preg_match('/^[0-9a-f]{40}$/', $deployedSha) !== 1) {
            $issues[] = 'deployed_sha_invalid';
        }
        $cohortSha = hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $llmsTrue = $assets->where('llms_eligible', true)->count();
        $llmsFalse = $assets->where('llms_eligible', false)->count();
        if (! (($llmsTrue === 0 && $llmsFalse === self::LLMS_EXPECTED_COUNT)
            || ($llmsTrue === self::LLMS_EXPECTED_COUNT && $llmsFalse === 0))) {
            $issues[] = 'partial_llms_release_state';
        }
        if ($write) {
            if (! (bool) config('personality.enneagram_llms_txt_write_enabled', false)) {
                $issues[] = 'process_write_gate_disabled';
            }
            if (! is_string($confirmedCohortSha256) || ! hash_equals($cohortSha, strtolower(trim($confirmedCohortSha256)))) {
                $issues[] = 'cohort_sha256_confirmation_mismatch';
            }
            $expectedToken = 'ENNEAGRAM-LLMS-TXT-RELEASE-01:'.$deployedSha.':'.$cohortSha;
            if (! is_string($operatorToken) || ! hash_equals($expectedToken, trim($operatorToken))) {
                $issues[] = 'operator_approval_mismatch';
            }
        }
        $issues = array_values(array_unique($issues));
        sort($issues);
        if ($issues !== []) {
            return $this->llmsPayload('blocked', $write, $deployedSha, $cohortSha, $rows, $issues, 0);
        }
        if (! $write) {
            return $this->llmsPayload(
                $llmsTrue === self::LLMS_EXPECTED_COUNT ? 'already_released' : 'dry_run_ready',
                false,
                $deployedSha,
                $cohortSha,
                $rows,
                [],
                0,
            );
        }
        if ($llmsTrue === self::LLMS_EXPECTED_COUNT) {
            return $this->llmsPayload('already_released', true, $deployedSha, $cohortSha, $rows, [], 0);
        }

        $updated = DB::transaction(function () use ($assets): int {
            $ids = $assets->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $updated = PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->where('llms_eligible', false)
                ->update(['llms_eligible' => true]);
            $readback = PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->where('llms_eligible', true)
                ->count();
            if ($updated !== self::LLMS_EXPECTED_COUNT || $readback !== self::LLMS_EXPECTED_COUNT) {
                throw new \RuntimeException('llms_write_or_readback_count_mismatch');
            }

            return $updated;
        });

        return $this->llmsPayload('released', true, $deployedSha, $cohortSha, $rows, [], $updated);
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

            if ($identity === null) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'unsupported_enneagram_target_url',
                    'message' => 'Only Enneagram hub, center, and core type public URLs are supported.',
                ];

                continue;
            }

            if (! in_array($identity['locale'], self::SUPPORTED_LOCALES, true)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'locale_not_supported_for_publish',
                    'message' => 'Only zh-CN and en locales are supported for the Enneagram publish gate.',
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
            $action = 'publish_to_live';

            if (! $asset instanceof PersonalityPublicContentAsset) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'missing_cms_asset',
                    'message' => 'A matching Enneagram CMS asset is required before publish.',
                ];
                $action = 'missing_cms_asset';
            } elseif ($this->assetIsAlreadyPublished($asset)) {
                $action = 'skip_already_published';
            } elseif (! $this->assetIsContentReady($asset)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'asset_not_content_ready',
                    'message' => 'Asset must be content_ready with is_public=true and noindex before publish.',
                ];
                $action = 'blocked_not_content_ready';
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
                'recommendation_sha256' => hash('sha256', $recommendationJson),
                'existing_asset_id' => $asset?->id !== null ? (int) $asset->id : null,
                'action' => $action,
            ];
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary($package, $sourceSha256, $write), [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($rows),
                'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
                'center_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CENTER),
                'core_type_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CORE_TYPE),
                'wing_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_WING),
                'instinctual_subtype_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE),
                'would_publish_count' => 0,
                'published_count' => 0,
                'skipped_existing_count' => 0,
                'rows' => $rows,
                'errors' => $errors,
                'warnings' => [],
            ]);
        }

        $published = 0;
        $skipped = 0;

        if ($write) {
            foreach ($rows as &$row) {
                if ($row['action'] === 'skip_already_published') {
                    $skipped++;

                    continue;
                }

                PersonalityPublicContentAsset::query()
                    ->withoutGlobalScopes()
                    ->whereKey((int) $row['existing_asset_id'])
                    ->update([
                        'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
                        'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
                        'index_eligible' => true,
                        'sitemap_eligible' => true,
                        'llms_eligible' => false,
                        'is_public' => true,
                        'published_at' => $this->bumpPublishedAt((int) $row['existing_asset_id']),
                        'updated_at' => now(),
                    ]);
                $row['action'] = 'published_to_live';
                $published++;
            }
            unset($row);
        }

        $wouldPublish = $write ? 0 : count(array_filter($rows, static fn (array $row): bool => ($row['action'] ?? null) === 'publish_to_live'));

        return array_merge($this->baseSummary($package, $sourceSha256, $write), [
            'ok' => true,
            'status' => 'pass',
            'writes_committed' => $write && $published > 0,
            'row_count' => count($rows),
            'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
            'center_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CENTER),
            'core_type_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CORE_TYPE),
            'wing_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_WING),
            'instinctual_subtype_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE),
            'would_publish_count' => $wouldPublish,
            'published_count' => $published,
            'skipped_existing_count' => $write ? $skipped : count(array_filter($rows, static fn (array $row): bool => ($row['action'] ?? null) === 'skip_already_published')),
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
        if ((string) ($recommendation['framework'] ?? '') !== PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM) {
            return null;
        }

        $path = (string) parse_url((string) ($recommendation['target_url'] ?? ''), PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram$#i', $path, $matches) === 1) {
            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_HUB, 'enneagram', 'enneagram');
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/centers/(?<code>gut|heart|head)$#i', $path, $matches) === 1) {
            $code = strtolower((string) $matches['code']);

            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_CENTER, $code, 'enneagram/centers/'.$code);
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/type-(?<type>[1-9])$#i', $path, $matches) === 1) {
            $code = 'type-'.((string) $matches['type']);

            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_CORE_TYPE, $code, 'enneagram/'.$code);
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/wings/(?<code>[1-9]w[1-9])$#i', $path, $matches) === 1) {
            $code = strtolower((string) $matches['code']);

            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_WING, $code, 'enneagram/wings/'.$code);
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/type-(?<type>[1-9])/instincts/(?<subtype>self-preservation|social|one-to-one)$#i', $path, $matches) === 1) {
            $type = 'type-'.((string) $matches['type']);
            $subtype = strtolower((string) $matches['subtype']);

            return $this->identity($path, (string) $matches['prefix'], PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE, $type.'/'.$subtype, 'enneagram/'.$type.'/instincts/'.$subtype);
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
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->where('entity_type', $identity['entity_type'])
            ->where('entity_key', $identity['entity_key'])
            ->where('locale', $identity['locale'])
            ->first();
    }

    private function assetIsContentReady(PersonalityPublicContentAsset $asset): bool
    {
        return (bool) $asset->is_public === true
            && (bool) $asset->index_eligible === false
            && (bool) $asset->sitemap_eligible === false
            && (bool) $asset->llms_eligible === false
            && $asset->robots === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW
            && $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_CONTENT_READY;
    }

    private function assetIsAlreadyPublished(PersonalityPublicContentAsset $asset): bool
    {
        return (bool) $asset->is_public === true
            && (bool) $asset->index_eligible === true
            && (bool) $asset->sitemap_eligible === true
            && (bool) $asset->llms_eligible === false
            && $asset->robots === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW
            && $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED;
    }

    private function bumpPublishedAt(int $assetId): mixed
    {
        $existing = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->whereKey($assetId)
            ->value('published_at');

        return $existing ?? now();
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

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, string $sourceSha256, bool $write): array
    {
        return [
            'artifact' => 'ENNEAGRAM-CMS-PUBLISH-GATE-CONTRACT-01',
            'status' => 'pending',
            'ok' => false,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'package_artifact' => (string) ($package['artifact'] ?? ''),
            'source_sha256' => $sourceSha256,
            'dry_run' => ! $write,
            'write' => $write,
            'writes_attempted' => $write,
            'writes_committed' => false,
            'publish_attempted' => $write,
            'publish_performed' => false,
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

    /** @param list<PersonalityPublicContentAsset> $assets @return array{list<array<string,string>>,list<string>} */
    private function llmsInspect(array $assets): array
    {
        $issues = [];
        if (count($assets) !== self::LLMS_EXPECTED_COUNT) {
            $issues[] = 'asset_count_mismatch';
        }
        if (collect($assets)->countBy('locale')->sortKeys()->all() !== ['en' => 58, 'zh-CN' => 58]) {
            $issues[] = 'locale_count_mismatch';
        }
        $expectedEntityCounts = self::LLMS_EXPECTED_ENTITY_COUNTS;
        ksort($expectedEntityCounts);
        if (collect($assets)->countBy('entity_type')->sortKeys()->all() !== $expectedEntityCounts) {
            $issues[] = 'entity_count_mismatch';
        }

        $rows = [];
        $identityPaths = [];
        foreach ($assets as $asset) {
            $path = SearchChannelQueueEligibilityEvaluator::normalizePublicPath(
                (string) data_get($asset->canonical_json, 'path', '')
            );
            $identity = $asset->entity_type.'|'.$asset->entity_key;
            if ($path !== null) {
                $identityPaths[$identity][(string) $asset->locale] = $path;
            }
            foreach ($this->llmsAssetIssues($asset, $path) as $issue) {
                $issues[] = $asset->locale.'|'.$identity.':'.$issue;
            }
            $rows[] = [
                'locale' => (string) $asset->locale,
                'entity_type' => (string) $asset->entity_type,
                'entity_key' => (string) $asset->entity_key,
                'canonical_path_hash' => hash('sha256', $path ?? '/invalid'),
                'source_hash' => (string) $asset->source_hash,
            ];
        }
        if (count(array_unique(array_column($rows, 'canonical_path_hash'))) !== self::LLMS_EXPECTED_COUNT) {
            $issues[] = 'canonical_set_not_unique';
        }
        foreach ($identityPaths as $identity => $localized) {
            if (! isset($localized['en'], $localized['zh-CN'])) {
                $issues[] = $identity.':hreflang_pair_missing';

                continue;
            }
            $enSuffix = preg_replace('#^/en/#', '/', $localized['en']) ?: '';
            $zhSuffix = preg_replace('#^/zh/#', '/', $localized['zh-CN']) ?: '';
            if ($enSuffix !== $zhSuffix) {
                $issues[] = $identity.':hreflang_pair_mismatch';
            }
        }
        if (count($identityPaths) !== 58) {
            $issues[] = 'hreflang_identity_count_mismatch';
        }

        return [$rows, $issues];
    }

    /** @return list<string> */
    private function llmsAssetIssues(PersonalityPublicContentAsset $asset, ?string $path): array
    {
        $issues = [];
        $prefix = $asset->locale === 'en' ? '/en/personality/enneagram' : '/zh/personality/enneagram';
        if ($path === null || ! str_starts_with($path, $prefix)) {
            $issues[] = 'canonical_invalid_or_private';
        }
        if (! $asset->is_public || $asset->launch_state !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED) {
            $issues[] = 'not_published_public';
        }
        if ($asset->robots !== PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW || ! $asset->index_eligible || ! $asset->sitemap_eligible) {
            $issues[] = 'not_index_sitemap_eligible';
        }
        if (preg_match('/^[0-9a-f]{64}$/', (string) $asset->source_hash) !== 1 || trim((string) $asset->source_package) === '') {
            $issues[] = 'source_provenance_invalid';
        }
        if (trim((string) $asset->title) === '' || trim((string) $asset->summary) === '' || $this->llmsVisibleTextLength($asset->content_sections_json) < 80) {
            $issues[] = 'visible_content_incomplete';
        }
        if ($this->llmsVisibleTextLength($asset->method_boundary_json) < 10 || ! $this->llmsHasSourceId($asset->evidence_notes_json)) {
            $issues[] = 'evidence_or_claim_boundary_incomplete';
        }
        foreach ((array) $asset->internal_links_json as $link) {
            $href = is_array($link) ? ($link['href'] ?? $link['url'] ?? null) : null;
            if ($href !== null && ! $this->llmsSafeInternalHref((string) $href)) {
                $issues[] = 'internal_link_invalid_or_private';
                break;
            }
        }

        return $issues;
    }

    private function llmsSafeInternalHref(string $href): bool
    {
        $href = trim($href);
        if (preg_match('/^#[A-Za-z0-9][\w:.-]{0,127}$/', $href) === 1) {
            return true;
        }

        return SearchChannelQueueEligibilityEvaluator::normalizePublicPath($href) !== null
            || SearchChannelQueueEligibilityEvaluator::publicPathFromCanonicalUrl($href) !== null;
    }

    private function llmsVisibleTextLength(mixed $value): int
    {
        if (is_string($value) || is_numeric($value)) {
            return mb_strlen(trim(strip_tags((string) $value)));
        }
        if (! is_array($value)) {
            return 0;
        }

        return array_sum(array_map(fn (mixed $item): int => $this->llmsVisibleTextLength($item), $value));
    }

    private function llmsHasSourceId(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['source_id', 'source_ids'], true) && $this->llmsVisibleTextLength($item) > 0) {
                return true;
            }
            if (is_array($item) && $this->llmsHasSourceId($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string,string>> $rows @param list<string> $issues @return array<string,mixed> */
    private function llmsPayload(string $status, bool $write, string $deployedSha, string $cohortSha, array $rows, array $issues, int $updated): array
    {
        return [
            'schema_version' => 'enneagram-llms-txt-release-gate.v1',
            'status' => $status,
            'ok' => in_array($status, ['dry_run_ready', 'released', 'already_released'], true),
            'dry_run' => ! $write,
            'write_requested' => $write,
            'deployed_sha' => preg_match('/^[0-9a-f]{40}$/', $deployedSha) === 1 ? $deployedSha : null,
            'cohort_sha256' => $cohortSha,
            'target_count' => count($rows),
            'updated_count' => $updated,
            'issues' => $issues,
            'negative_guarantees' => [
                'llms_full_release' => false,
                'search_queue_write_or_submit' => false,
                'sitemap_index_or_robots_write' => false,
                'cache_warm' => false,
                'deploy' => false,
                'private_payload_exposed' => false,
            ],
        ];
    }
}
