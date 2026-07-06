<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveSeoDiscoverabilityReleaseWriter
{
    public const REVIEW_STATE = 'operator_approved_seo_discoverability_released';

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<string>  $authorizedSlugs
     * @return array<string,mixed>
     */
    public function plan(
        array $plan,
        string $sourceSha256,
        string $packagePath,
        string $targetEnvironment,
        array $authorizedSlugs
    ): array {
        return $this->buildSummary($plan, $sourceSha256, $packagePath, $targetEnvironment, $authorizedSlugs, false);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<string>  $authorizedSlugs
     * @return array<string,mixed>
     */
    public function release(
        array $plan,
        string $sourceSha256,
        string $packagePath,
        string $targetEnvironment,
        array $authorizedSlugs,
        SeoDiscoverabilityCacheInvalidator $cacheInvalidator
    ): array {
        return DB::transaction(function () use ($plan, $sourceSha256, $packagePath, $targetEnvironment, $authorizedSlugs, $cacheInvalidator): array {
            $summary = $this->buildSummary($plan, $sourceSha256, $packagePath, $targetEnvironment, $authorizedSlugs, true);

            if (($summary['ok'] ?? false) === true && ($summary['writes_committed'] ?? false) === true) {
                $summary['cache_keys_flushed'] = $cacheInvalidator->flushPersonalityPublicContentDiscoverabilityCaches();
            }

            return $summary;
        });
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<string>  $authorizedSlugs
     * @return array<string,mixed>
     */
    private function buildSummary(
        array $plan,
        string $sourceSha256,
        string $packagePath,
        string $targetEnvironment,
        array $authorizedSlugs,
        bool $write
    ): array {
        if (! Schema::hasTable((new PersonalityPublicContentAsset)->getTable())) {
            throw new RuntimeException('personality_public_content_assets table is missing; run migrations before Big Five SEO discoverability release.');
        }

        $this->guardAuthorizedSlugList($authorizedSlugs);

        $rowPlans = array_values(is_array($plan['rows'] ?? null) ? $plan['rows'] : []);
        if (count($rowPlans) !== 42) {
            throw new RuntimeException('Expected exactly 42 dry-run row plans before Big Five SEO discoverability release.');
        }

        $selected = $this->selectedRows($rowPlans);
        if (count($selected) !== 20) {
            throw new RuntimeException('Expected exactly 20 authorized zh-CN trait/range rows for Big Five SEO discoverability release.');
        }

        $selectedSlugs = array_map(static fn (array $row): string => (string) ($row['identity']['entity_key'] ?? ''), $selected);
        $this->guardAuthorizedSlugList($selectedSlugs);

        $rows = [];
        $preReleaseSnapshot = [];
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($selected as $selectedRow) {
            $identity = is_array($selectedRow['identity'] ?? null) ? $selectedRow['identity'] : [];
            $asset = $this->assetForIdentity($identity, $sourceSha256, $write);
            $preReleaseSnapshot[] = $this->snapshotRow($asset);

            if (! $asset instanceof PersonalityPublicContentAsset) {
                $errors[] = [
                    'field' => 'rows.'.$selectedRow['position'],
                    'code' => 'content_ready_asset_missing',
                    'message' => 'Authorized Big Five content-ready asset is missing or source hash does not match the locked package.',
                ];

                continue;
            }

            foreach ($this->preflightErrors($asset, (string) ($selectedRow['canonical_path'] ?? '')) as $error) {
                $errors[] = $error;
            }

            $alreadyReleased = $this->isReleased($asset);
            $rows[] = [
                'position' => $selectedRow['position'],
                'asset_id' => (int) $asset->id,
                'slug' => (string) $asset->entity_key,
                'entity_type' => (string) $asset->entity_type,
                'canonical_path' => (string) ($asset->canonical_json['path'] ?? ''),
                'action' => $alreadyReleased ? 'skip_existing_discoverability_released' : 'release_discoverability',
                'before' => $this->snapshotRow($asset),
                'after' => $this->releasedSnapshot($asset),
            ];
        }

        if ($errors !== []) {
            return $this->summary($sourceSha256, $packagePath, $targetEnvironment, $write, $rows, $preReleaseSnapshot, 0, 0, [], $errors);
        }

        if ($write) {
            foreach ($rows as &$row) {
                $asset = PersonalityPublicContentAsset::query()
                    ->withoutGlobalScopes()
                    ->lockForUpdate()
                    ->find((int) $row['asset_id']);

                if (! $asset instanceof PersonalityPublicContentAsset) {
                    throw new RuntimeException('Planned Big Five asset disappeared before SEO discoverability release.');
                }

                foreach ($this->preflightErrors($asset, (string) ($row['canonical_path'] ?? '')) as $error) {
                    throw new RuntimeException('Big Five SEO discoverability release preflight failed before write: '.$error['code']);
                }

                if ($this->isReleased($asset)) {
                    $row['action'] = 'skipped_existing_discoverability_released';
                    $skipped++;

                    continue;
                }

                $this->releaseAsset($asset, $sourceSha256, $packagePath, $targetEnvironment);
                $row['action'] = 'released_discoverability';
                $row['after'] = $this->snapshotRow($asset->fresh());
                $updated++;
            }
            unset($row);
        }

        return $this->summary($sourceSha256, $packagePath, $targetEnvironment, $write, $rows, $preReleaseSnapshot, $updated, $skipped, [], []);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function guardAuthorizedSlugList(array $slugs): void
    {
        $normalized = array_values(array_unique(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            $slugs
        )));
        sort($normalized);

        $expected = BigFiveCmsPublishGateWriter::AUTHORIZED_ZH_CN_SLUGS;
        sort($expected);

        if ($normalized !== $expected) {
            throw new RuntimeException('Authorized slug list must match the exact 20 zh-CN Big Five trait/range slugs.');
        }
    }

    /**
     * @param  list<mixed>  $rowPlans
     * @return list<array<string,mixed>>
     */
    private function selectedRows(array $rowPlans): array
    {
        $selected = [];
        foreach ($rowPlans as $index => $rowPlan) {
            if (! is_array($rowPlan)) {
                continue;
            }

            $identity = is_array($rowPlan['identity'] ?? null) ? $rowPlan['identity'] : [];
            $locale = (string) ($identity['locale'] ?? '');
            $entityType = (string) ($identity['entity_type'] ?? '');
            $entityKey = (string) ($identity['entity_key'] ?? '');

            if ($locale !== 'zh-CN') {
                continue;
            }

            if (! in_array($entityType, [PersonalityPublicContentAsset::ENTITY_DOMAIN, PersonalityPublicContentAsset::ENTITY_POLARITY], true)) {
                continue;
            }

            if (! in_array($entityKey, BigFiveCmsPublishGateWriter::AUTHORIZED_ZH_CN_SLUGS, true)) {
                continue;
            }

            $canonicalPath = (string) ($rowPlan['canonical_path'] ?? '');
            if ($canonicalPath !== '/zh/personality/big-five/'.$entityKey) {
                throw new RuntimeException('Selected row canonical path must use v2 trait-first slug format at row '.((string) $index).'.');
            }

            $selected[] = [
                'position' => $index + 1,
                'identity' => $identity,
                'canonical_path' => $canonicalPath,
            ];
        }

        return $selected;
    }

    /**
     * @param  array<string,mixed>  $identity
     */
    private function assetForIdentity(array $identity, string $sourceSha256, bool $lockForUpdate): ?PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('entity_type', (string) ($identity['entity_type'] ?? ''))
            ->where('entity_key', (string) ($identity['entity_key'] ?? ''))
            ->where('locale', 'zh-CN')
            ->where('source_package', BigFiveCmsPublishGateWriter::SOURCE_PACKAGE)
            ->where('source_hash', $sourceSha256)
            ->when($lockForUpdate, static fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * @return list<array<string,string>>
     */
    private function preflightErrors(PersonalityPublicContentAsset $asset, string $expectedCanonicalPath): array
    {
        $errors = [];

        if ((string) $asset->framework !== PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE) {
            $errors[] = $this->issue('framework', 'framework_mismatch', 'Only Big Five assets can be released.');
        }
        if ((string) $asset->locale !== 'zh-CN') {
            $errors[] = $this->issue('locale', 'locale_not_authorized', 'Only zh-CN Big Five assets are authorized for this release.');
        }
        if (! in_array((string) $asset->entity_type, [PersonalityPublicContentAsset::ENTITY_DOMAIN, PersonalityPublicContentAsset::ENTITY_POLARITY], true)) {
            $errors[] = $this->issue('entity_type', 'entity_type_not_authorized', 'Only trait and range assets are authorized for this release.');
        }
        if (! in_array((string) $asset->entity_key, BigFiveCmsPublishGateWriter::AUTHORIZED_ZH_CN_SLUGS, true)) {
            $errors[] = $this->issue('entity_key', 'slug_not_authorized', 'Asset slug is outside the authorized 20-page allowlist.');
        }
        if ((string) $asset->source_package !== BigFiveCmsPublishGateWriter::SOURCE_PACKAGE) {
            $errors[] = $this->issue('source_package', 'source_package_mismatch', 'Asset source package must match the PR12 content-ready source.');
        }
        if ((string) $asset->launch_state !== PersonalityPublicContentAsset::LAUNCH_CONTENT_READY && ! $this->isReleased($asset)) {
            $errors[] = $this->issue('launch_state', 'asset_not_content_ready', 'Asset must be content_ready or already released by this command.');
        }
        if (! in_array((string) $asset->review_state, [BigFiveCmsPublishGateWriter::REVIEW_STATE, self::REVIEW_STATE], true)) {
            $errors[] = $this->issue('review_state', 'review_state_not_authorized', 'Asset must carry the operator-approved Big Five review state.');
        }
        if (! (bool) $asset->is_public) {
            $errors[] = $this->issue('is_public', 'asset_not_public', 'Asset must already be public-readable content before SEO release.');
        }
        if (! is_array($asset->content_sections_json) || count($asset->content_sections_json) < 1) {
            $errors[] = $this->issue('content_sections_json', 'visible_content_missing', 'Asset must have visible CMS body sections.');
        }
        if (! is_array($asset->faq_json) || count($asset->faq_json) < 5) {
            $errors[] = $this->issue('faq_json', 'structured_faq_missing', 'Asset must retain at least five visible structured FAQ entries.');
        }
        if ((string) ($asset->canonical_json['path'] ?? '') !== $expectedCanonicalPath) {
            $errors[] = $this->issue('canonical_json.path', 'canonical_path_mismatch', 'Asset canonical path must match the v2 authorized path.');
        }
        if (str_contains((string) ($asset->canonical_json['path'] ?? ''), '/zh/big-five')) {
            $errors[] = $this->issue('canonical_json.path', 'old_short_route_residue', 'Old /zh/big-five route residue blocks release.');
        }

        return $errors;
    }

    private function isReleased(PersonalityPublicContentAsset $asset): bool
    {
        return (string) $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            && (string) $asset->review_state === self::REVIEW_STATE
            && (string) $asset->robots === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW
            && (bool) $asset->index_eligible
            && (bool) $asset->sitemap_eligible
            && (bool) $asset->llms_eligible
            && (bool) (($asset->schema_json['runtime_jsonld_enabled'] ?? false) === true)
            && (bool) (($asset->schema_json['runtime_release'] ?? false) === true);
    }

    private function releaseAsset(PersonalityPublicContentAsset $asset, string $sourceSha256, string $packagePath, string $targetEnvironment): void
    {
        $schema = is_array($asset->schema_json) ? $asset->schema_json : [];
        $schema['draft_only'] = false;
        $schema['runtime_jsonld_enabled'] = true;
        $schema['runtime_release'] = true;
        $schema['runtime_release_gate'] = 'BIG5-SEO-DISCOVERABILITY-RELEASE-13';

        $evidenceNotes = is_array($asset->evidence_notes_json) ? $asset->evidence_notes_json : [];
        $evidenceNotes[] = [
            'source_type' => 'controlled_seo_discoverability_release',
            'source' => 'BIG5-SEO-DISCOVERABILITY-RELEASE-13',
            'package_path' => $packagePath,
            'package_sha256' => $sourceSha256,
            'target_environment' => $targetEnvironment,
            'authorized_slug_count' => 20,
            'schema_runtime_release' => true,
            'sitemap_release' => true,
            'llms_release' => true,
            'indexability_release' => true,
            'search_submission' => false,
            'frontend_revalidation' => false,
        ];

        $asset->fill([
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => self::REVIEW_STATE,
            'schema_json' => $schema,
            'evidence_notes_json' => $evidenceNotes,
            'published_at' => $asset->published_at ?? now(),
            'last_reviewed_at' => now(),
        ]);
        $asset->save();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function snapshotRow(?PersonalityPublicContentAsset $asset): ?array
    {
        if (! $asset instanceof PersonalityPublicContentAsset) {
            return null;
        }

        return [
            'id' => (int) $asset->id,
            'framework' => (string) $asset->framework,
            'entity_type' => (string) $asset->entity_type,
            'entity_key' => (string) $asset->entity_key,
            'slug' => (string) $asset->slug,
            'locale' => (string) $asset->locale,
            'source_package' => (string) $asset->source_package,
            'source_hash' => (string) $asset->source_hash,
            'launch_state' => (string) $asset->launch_state,
            'review_state' => (string) $asset->review_state,
            'robots' => (string) $asset->robots,
            'is_public' => (bool) $asset->is_public,
            'index_eligible' => (bool) $asset->index_eligible,
            'sitemap_eligible' => (bool) $asset->sitemap_eligible,
            'llms_eligible' => (bool) $asset->llms_eligible,
            'schema_runtime_jsonld_enabled' => (bool) ($asset->schema_json['runtime_jsonld_enabled'] ?? false),
            'schema_runtime_release' => (bool) ($asset->schema_json['runtime_release'] ?? false),
            'canonical_path' => (string) ($asset->canonical_json['path'] ?? ''),
            'published_at' => $asset->published_at?->toAtomString(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function releasedSnapshot(PersonalityPublicContentAsset $asset): array
    {
        $snapshot = $this->snapshotRow($asset) ?? [];
        $snapshot['launch_state'] = PersonalityPublicContentAsset::LAUNCH_PUBLISHED;
        $snapshot['review_state'] = self::REVIEW_STATE;
        $snapshot['robots'] = PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW;
        $snapshot['index_eligible'] = true;
        $snapshot['sitemap_eligible'] = true;
        $snapshot['llms_eligible'] = true;
        $snapshot['schema_runtime_jsonld_enabled'] = true;
        $snapshot['schema_runtime_release'] = true;

        return $snapshot;
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>|null>  $preReleaseSnapshot
     * @param  list<string>  $cacheKeysFlushed
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function summary(
        string $sourceSha256,
        string $packagePath,
        string $targetEnvironment,
        bool $write,
        array $rows,
        array $preReleaseSnapshot,
        int $updated,
        int $skipped,
        array $cacheKeysFlushed,
        array $errors
    ): array {
        $releaseBatchId = 'big5-seo-discoverability-release-'.substr($sourceSha256, 0, 12).'-'.gmdate('YmdHis');

        return [
            'artifact' => 'BIG5-SEO-DISCOVERABILITY-RELEASE-13',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'target_environment' => $targetEnvironment,
            'package_path' => $packagePath,
            'source_sha256' => $sourceSha256,
            'row_count' => count($rows),
            'expected_row_count' => 20,
            'row_count_matches_expected' => count($rows) === 20,
            'authorized_slug_count' => 20,
            'dry_run' => ! $write,
            'release' => $write,
            'writes_committed' => $write && $updated > 0,
            'cms_write_attempted' => $write,
            'publish_attempted' => $write,
            'content_ready_attempted' => false,
            'index_attempted' => $write,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => $write,
            'jsonld_runtime_release_attempted' => $write,
            'frontend_revalidation_attempted' => false,
            'updated_asset_count' => $updated,
            'skipped_existing_count' => $skipped,
            'cache_keys_flushed' => $cacheKeysFlushed,
            'rollback_handle' => [
                'release_batch_id' => $releaseBatchId,
                'source_package' => BigFiveCmsPublishGateWriter::SOURCE_PACKAGE,
                'source_hash' => $sourceSha256,
                'deterministic_revert_criteria' => [
                    'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                    'source_package' => BigFiveCmsPublishGateWriter::SOURCE_PACKAGE,
                    'source_hash' => $sourceSha256,
                    'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
                    'review_state' => self::REVIEW_STATE,
                    'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
                    'index_eligible' => true,
                    'sitemap_eligible' => true,
                    'llms_eligible' => true,
                ],
                'pre_release_snapshot' => $preReleaseSnapshot,
                'restore_note' => 'Restore pre_release_snapshot rows or revert rows matching deterministic_revert_criteria with the same exact package hash. This handle does not authorize content edits, English release, Search Console submission, frontend deploy, or schema content invention.',
            ],
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => [],
        ];
    }
}
