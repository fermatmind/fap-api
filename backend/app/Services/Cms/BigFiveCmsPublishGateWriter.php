<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveCmsPublishGateWriter
{
    public const SOURCE_PACKAGE = 'big-five-cms-import-draft-polished.v2';

    public const REVIEW_STATE = 'operator_approved_content_ready';

    public const AUTHORIZED_ZH_CN_SLUGS = [
        'openness',
        'conscientiousness',
        'extraversion',
        'agreeableness',
        'neuroticism',
        'openness-high',
        'openness-mid',
        'openness-low',
        'conscientiousness-high',
        'conscientiousness-mid',
        'conscientiousness-low',
        'extraversion-high',
        'extraversion-mid',
        'extraversion-low',
        'agreeableness-high',
        'agreeableness-mid',
        'agreeableness-low',
        'neuroticism-high',
        'neuroticism-mid',
        'neuroticism-low',
    ];

    private const AUTHORIZED_CONTENT_TYPES = [
        'trait_page',
        'trait_range_page',
    ];

    /**
     * @param  array<mixed>  $package
     * @param  array<string,mixed>  $plan
     * @param  list<string>  $authorizedSlugs
     * @return array<string,mixed>
     */
    public function plan(
        array $package,
        array $plan,
        string $sourceSha256,
        string $packagePath,
        string $targetEnvironment,
        array $authorizedSlugs
    ): array {
        return $this->buildSummary($package, $plan, $sourceSha256, $packagePath, $targetEnvironment, $authorizedSlugs, false);
    }

    /**
     * @param  array<mixed>  $package
     * @param  array<string,mixed>  $plan
     * @param  list<string>  $authorizedSlugs
     * @return array<string,mixed>
     */
    public function write(
        array $package,
        array $plan,
        string $sourceSha256,
        string $packagePath,
        string $targetEnvironment,
        array $authorizedSlugs
    ): array {
        return DB::transaction(fn (): array => $this->buildSummary(
            $package,
            $plan,
            $sourceSha256,
            $packagePath,
            $targetEnvironment,
            $authorizedSlugs,
            true
        ));
    }

    /**
     * @param  array<mixed>  $package
     * @param  array<string,mixed>  $plan
     * @param  list<string>  $authorizedSlugs
     * @return array<string,mixed>
     */
    private function buildSummary(
        array $package,
        array $plan,
        string $sourceSha256,
        string $packagePath,
        string $targetEnvironment,
        array $authorizedSlugs,
        bool $write
    ): array {
        if (! Schema::hasTable((new PersonalityPublicContentAsset)->getTable())) {
            throw new RuntimeException('personality_public_content_assets table is missing; run migrations before Big Five CMS publish gate.');
        }

        $this->guardAuthorizedSlugList($authorizedSlugs);

        $rows = $this->rows($package);
        $rowPlans = array_values(is_array($plan['rows'] ?? null) ? $plan['rows'] : []);
        if (count($rows) !== 42 || count($rowPlans) !== 42) {
            throw new RuntimeException('Expected exactly 42 package rows and 42 dry-run row plans.');
        }

        $selected = $this->selectedRows($rows, $rowPlans);
        if (count($selected) !== 20) {
            throw new RuntimeException('Expected exactly 20 authorized zh-CN trait/range rows for Big Five publish gate.');
        }

        $selectedSlugs = array_map(static fn (array $row): string => (string) ($row['row']['slug'] ?? ''), $selected);
        $this->guardAuthorizedSlugList($selectedSlugs);

        $planned = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $preImportSnapshot = [];

        foreach ($selected as $selectedRow) {
            $row = $selectedRow['row'];
            $rowPlan = $selectedRow['row_plan'];
            $identity = is_array($rowPlan['identity'] ?? null) ? $rowPlan['identity'] : [];
            $attributes = $this->attributesForRow($row, $rowPlan, $sourceSha256, $packagePath, $targetEnvironment);
            $existing = $this->existingAsset($identity);
            $preImportSnapshot[] = $this->snapshotRow($existing);
            $action = 'create_content_ready_asset';

            if ($existing instanceof PersonalityPublicContentAsset) {
                if (! $this->existingAssetIsWritableContentGateTarget($existing)) {
                    $errors[] = [
                        'field' => 'rows.'.((string) ($selectedRow['position'] - 1)),
                        'code' => 'existing_asset_blocks_content_ready_gate',
                        'message' => 'Existing published/indexable/search-released or foreign-source Big Five asset blocks PR12 publish gate.',
                    ];
                }

                $action = $this->attributesMatch($existing, $attributes)
                    ? 'skip_existing_same_source_content_ready'
                    : 'update_existing_content_ready_asset';
            }

            $planned[] = [
                'position' => $selectedRow['position'],
                'slug' => (string) ($row['slug'] ?? ''),
                'content_type' => (string) ($row['content_type'] ?? ''),
                'canonical_path' => (string) ($rowPlan['canonical_path'] ?? $row['canonical_path'] ?? ''),
                'identity' => $identity,
                'existing_asset_id' => $existing?->id !== null ? (int) $existing->id : null,
                'action' => $action,
            ];
        }

        if ($errors !== []) {
            return $this->summary($sourceSha256, $packagePath, $targetEnvironment, $write, $planned, $preImportSnapshot, 0, 0, 0, $errors);
        }

        if ($write) {
            foreach ($planned as $index => &$plannedRow) {
                $selectedRow = $selected[$index];
                $identity = is_array($plannedRow['identity'] ?? null) ? $plannedRow['identity'] : [];
                $existing = $this->existingAsset($identity);
                $attributes = $this->attributesForRow($selectedRow['row'], $selectedRow['row_plan'], $sourceSha256, $packagePath, $targetEnvironment);

                if ($existing instanceof PersonalityPublicContentAsset && $this->attributesMatch($existing, $attributes)) {
                    $plannedRow['action'] = 'skipped_existing_same_source_content_ready';
                    $skipped++;

                    continue;
                }

                if ($existing instanceof PersonalityPublicContentAsset) {
                    $existing->fill($attributes);
                    $existing->save();
                    $plannedRow['action'] = 'updated_content_ready_asset';
                    $updated++;

                    continue;
                }

                $createdAsset = PersonalityPublicContentAsset::query()->create($attributes);
                $plannedRow['existing_asset_id'] = (int) $createdAsset->id;
                $plannedRow['action'] = 'created_content_ready_asset';
                $created++;
            }
            unset($plannedRow);
        }

        return $this->summary($sourceSha256, $packagePath, $targetEnvironment, $write, $planned, $preImportSnapshot, $created, $updated, $skipped, []);
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

        $expected = self::AUTHORIZED_ZH_CN_SLUGS;
        sort($expected);

        if ($normalized !== $expected) {
            throw new RuntimeException('Authorized slug list must match the exact 20 zh-CN Big Five trait/range slugs.');
        }
    }

    /**
     * @param  array<mixed>  $package
     * @return list<mixed>
     */
    private function rows(array $package): array
    {
        if (array_is_list($package)) {
            return array_values($package);
        }

        return array_values(is_array($package['rows'] ?? null) ? $package['rows'] : (is_array($package['pages'] ?? null) ? $package['pages'] : []));
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<mixed>  $rowPlans
     * @return list<array{position:int,row:array<string,mixed>,row_plan:array<string,mixed>}>
     */
    private function selectedRows(array $rows, array $rowPlans): array
    {
        $selected = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Package row must be an object.');
            }

            $rowPlan = is_array($rowPlans[$index] ?? null) ? $rowPlans[$index] : [];
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            $locale = (string) ($row['locale'] ?? '');
            $contentType = (string) ($row['content_type'] ?? '');

            if ($locale !== 'zh-CN' || ! in_array($contentType, self::AUTHORIZED_CONTENT_TYPES, true)) {
                continue;
            }

            if (! in_array($slug, self::AUTHORIZED_ZH_CN_SLUGS, true)) {
                continue;
            }

            $this->guardSelectedRow($row, $rowPlan, $index);

            $selected[] = [
                'position' => $index + 1,
                'row' => $row,
                'row_plan' => $rowPlan,
            ];
        }

        return $selected;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $rowPlan
     */
    private function guardSelectedRow(array $row, array $rowPlan, int $index): void
    {
        $identity = is_array($rowPlan['identity'] ?? null) ? $rowPlan['identity'] : [];
        $slug = (string) ($row['slug'] ?? '');
        $canonicalPath = (string) ($rowPlan['canonical_path'] ?? $row['canonical_path'] ?? '');

        if ((string) ($identity['entity_type'] ?? '') !== (
            (string) ($row['content_type'] ?? '') === 'trait_page'
                ? PersonalityPublicContentAsset::ENTITY_DOMAIN
                : PersonalityPublicContentAsset::ENTITY_POLARITY
        )) {
            throw new RuntimeException('Selected row identity entity_type mismatch at row '.((string) $index).'.');
        }

        if ((string) ($identity['entity_key'] ?? '') !== $slug || (string) ($identity['slug'] ?? '') !== 'big-five/'.$slug) {
            throw new RuntimeException('Selected row identity key/slug mismatch at row '.((string) $index).'.');
        }

        if (! str_starts_with($canonicalPath, '/zh/personality/big-five/')) {
            throw new RuntimeException('Selected row canonical path must stay under /zh/personality/big-five at row '.((string) $index).'.');
        }

        if ($canonicalPath !== '/zh/personality/big-five/'.$slug) {
            throw new RuntimeException('Selected row canonical path must use v2 trait-first slug format at row '.((string) $index).'.');
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $rowPlan
     * @return array<string,mixed>
     */
    private function attributesForRow(array $row, array $rowPlan, string $sourceSha256, string $packagePath, string $targetEnvironment): array
    {
        $identity = is_array($rowPlan['identity'] ?? null) ? $rowPlan['identity'] : [];
        $canonicalPath = (string) ($rowPlan['canonical_path'] ?? $row['canonical_path'] ?? '');
        $seo = is_array($row['seo'] ?? null) ? $row['seo'] : [];
        $faq = array_values(is_array($row['faq'] ?? null) ? $row['faq'] : []);
        $claimBoundaries = array_values(is_array($row['claim_boundaries'] ?? null) ? $row['claim_boundaries'] : []);
        $sections = $this->contentSections($row);

        if ($sections === []) {
            throw new RuntimeException('Selected Big Five content-ready rows must have non-empty non-FAQ body sections.');
        }

        if (count($faq) < 5) {
            throw new RuntimeException('Selected Big Five content-ready rows must retain at least 5 structured FAQ entries.');
        }

        return [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => (string) ($identity['entity_type'] ?? ''),
            'entity_key' => (string) ($identity['entity_key'] ?? ''),
            'slug' => (string) ($identity['slug'] ?? ''),
            'locale' => (string) ($identity['locale'] ?? ''),
            'title' => trim((string) ($row['title'] ?? '')),
            'summary' => trim((string) ($seo['description'] ?? '')) ?: null,
            'content_sections_json' => $sections,
            'seo_json' => $seo,
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => $canonicalPath],
            'hreflang_json' => [],
            'faq_json' => $faq,
            'schema_json' => [
                'recommendation' => (string) ($row['schema_recommendation'] ?? ''),
                'draft_only' => false,
                'runtime_jsonld_enabled' => false,
                'runtime_release' => false,
            ],
            'method_boundary_json' => [
                'claim_boundaries' => $claimBoundaries,
                'method_boundary' => 'Big Five public CMS content-ready rows remain non-diagnostic, non-predictive, and not for hiring or high-stakes decisions.',
                'indexability_gate' => (string) ($row['indexability_gate'] ?? ''),
                'operator_publish_gate' => 'BIG5-CMS-PUBLISH-GATE-12',
            ],
            'evidence_notes_json' => [
                [
                    'source_type' => 'cms_import_draft',
                    'source' => self::SOURCE_PACKAGE,
                    'package_path' => $packagePath,
                    'package_sha256' => $sourceSha256,
                    'target_environment' => $targetEnvironment,
                    'authorized_slug_count' => 20,
                    'schema_runtime_release' => false,
                    'sitemap_release' => false,
                    'llms_release' => false,
                    'indexability_release' => false,
                ],
            ],
            'internal_links_json' => array_values(is_array($row['internal_links'] ?? null) ? $row['internal_links'] : []),
            'is_public' => true,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'review_state' => self::REVIEW_STATE,
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => self::SOURCE_PACKAGE,
            'source_hash' => $sourceSha256,
            'published_at' => null,
            'last_reviewed_at' => now(),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return list<array<string,string>>
     */
    private function contentSections(array $row): array
    {
        $sections = [];
        foreach (array_values(is_array($row['body_sections'] ?? null) ? $row['body_sections'] : []) as $index => $section) {
            if (! is_array($section) || $this->isFaqBodySection($section)) {
                continue;
            }

            $heading = trim((string) ($section['heading'] ?? $section['title'] ?? 'Section '.((string) ($index + 1))));
            $body = trim((string) ($section['body_md'] ?? $section['body'] ?? ''));
            if ($body === '') {
                continue;
            }

            $sections[] = [
                'key' => $this->sectionKey($heading, $index),
                'title' => $heading,
                'body_md' => $body,
            ];
        }

        return $sections;
    }

    /**
     * @param  array<mixed>  $section
     */
    private function isFaqBodySection(array $section): bool
    {
        $heading = trim((string) ($section['heading'] ?? $section['title'] ?? ''));

        return preg_match('/^(faq|常见问题|问答|问题)$/iu', $heading) === 1;
    }

    private function sectionKey(string $heading, int $index): string
    {
        $ascii = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $heading));
        $ascii = trim($ascii, '_');

        return $ascii !== '' ? $ascii : 'section_'.((string) ($index + 1));
    }

    /**
     * @param  array<string,mixed>  $identity
     */
    private function existingAsset(array $identity): ?PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('entity_type', (string) ($identity['entity_type'] ?? ''))
            ->where('entity_key', (string) ($identity['entity_key'] ?? ''))
            ->where('locale', (string) ($identity['locale'] ?? ''))
            ->first();
    }

    private function existingAssetIsWritableContentGateTarget(PersonalityPublicContentAsset $asset): bool
    {
        return (bool) $asset->index_eligible === false
            && (bool) $asset->sitemap_eligible === false
            && (bool) $asset->llms_eligible === false
            && $asset->published_at === null
            && $asset->robots === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW
            && in_array((string) $asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_DRAFT,
                PersonalityPublicContentAsset::LAUNCH_REVIEW,
                PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            ], true)
            && in_array((string) $asset->source_package, ['', self::SOURCE_PACKAGE, 'big-five-v1-content-editorial-repair-02'], true);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function attributesMatch(PersonalityPublicContentAsset $existing, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if ($key === 'last_reviewed_at') {
                continue;
            }

            if ($this->comparable($existing->{$key}) !== $this->comparable($value)) {
                return false;
            }
        }

        return true;
    }

    private function comparable(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        if (is_array($value)) {
            $this->sortAssociativeRecursive($value);

            return $value;
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function sortAssociativeRecursive(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortAssociativeRecursive($child);
            }
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
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
            'published_at' => $asset->published_at?->toAtomString(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>|null>  $preImportSnapshot
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function summary(
        string $sourceSha256,
        string $packagePath,
        string $targetEnvironment,
        bool $write,
        array $rows,
        array $preImportSnapshot,
        int $created,
        int $updated,
        int $skipped,
        array $errors
    ): array {
        $importBatchId = 'big5-cms-publish-gate-'.substr($sourceSha256, 0, 12).'-'.gmdate('YmdHis');

        return [
            'artifact' => 'BIG5-CMS-PUBLISH-GATE-12',
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
            'write' => $write,
            'writes_committed' => $write && ($created + $updated) > 0,
            'cms_write_attempted' => $write,
            'publish_attempted' => false,
            'content_ready_attempted' => $write,
            'index_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'jsonld_runtime_release_attempted' => false,
            'created_asset_count' => $created,
            'updated_asset_count' => $updated,
            'skipped_existing_count' => $skipped,
            'rollback_handle' => [
                'import_batch_id' => $importBatchId,
                'source_package' => self::SOURCE_PACKAGE,
                'source_hash' => $sourceSha256,
                'deterministic_revert_criteria' => [
                    'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                    'source_package' => self::SOURCE_PACKAGE,
                    'source_hash' => $sourceSha256,
                    'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
                    'review_state' => self::REVIEW_STATE,
                    'index_eligible' => false,
                    'sitemap_eligible' => false,
                    'llms_eligible' => false,
                ],
                'pre_import_snapshot' => $preImportSnapshot,
                'restore_note' => 'Restore pre_import_snapshot rows or revert rows matching deterministic_revert_criteria with the same exact package hash. Do not release sitemap, llms, JSON-LD, indexability, or production deploy from this handle.',
            ],
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => [],
        ];
    }
}
