<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Support\Facades\DB;

final class EnneagramCmsPublishGateService
{
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
}
