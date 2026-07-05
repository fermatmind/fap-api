<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveCmsImportDraftStagingWriter
{
    private const SOURCE_PACKAGE = 'big-five-cms-import-draft-polished.v2';

    /**
     * @param  array<mixed>  $package
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function plan(array $package, array $plan, string $sourceSha256, string $packagePath, string $targetEnvironment): array
    {
        return $this->buildSummary($package, $plan, $sourceSha256, $packagePath, $targetEnvironment, false);
    }

    /**
     * @param  array<mixed>  $package
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function write(array $package, array $plan, string $sourceSha256, string $packagePath, string $targetEnvironment): array
    {
        return DB::transaction(fn (): array => $this->buildSummary(
            $package,
            $plan,
            $sourceSha256,
            $packagePath,
            $targetEnvironment,
            true
        ));
    }

    /**
     * @param  array<mixed>  $package
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function buildSummary(array $package, array $plan, string $sourceSha256, string $packagePath, string $targetEnvironment, bool $write): array
    {
        if (! Schema::hasTable((new PersonalityPublicContentAsset)->getTable())) {
            throw new RuntimeException('personality_public_content_assets table is missing; run migrations before staging/dev write.');
        }

        $rows = $this->rows($package);
        $rowPlans = array_values(is_array($plan['rows'] ?? null) ? $plan['rows'] : []);
        if (count($rows) !== 42 || count($rowPlans) !== 42) {
            throw new RuntimeException('Expected exactly 42 package rows and 42 dry-run row plans.');
        }

        $planned = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $preImportSnapshot = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Package row must be an object.');
            }

            $rowPlan = is_array($rowPlans[$index] ?? null) ? $rowPlans[$index] : [];
            $identity = is_array($rowPlan['identity'] ?? null) ? $rowPlan['identity'] : [];
            $attributes = $this->attributesForRow($row, $rowPlan, $sourceSha256, $packagePath, $targetEnvironment);
            $existing = $this->existingAsset($identity);
            $preImportSnapshot[] = $this->snapshotRow($existing);
            $action = 'create_draft_asset';

            if ($existing instanceof PersonalityPublicContentAsset) {
                if (! $this->existingAssetIsWritableControlledDraft($existing)) {
                    $errors[] = [
                        'field' => 'rows.'.((string) $index),
                        'code' => 'existing_live_or_foreign_asset_blocks_staging_write',
                        'message' => 'Existing public/content-ready/published/indexable or foreign-source asset blocks staging/dev draft writes.',
                    ];
                }

                $action = $this->attributesMatch($existing, $attributes)
                    ? 'skip_existing_same_source_draft'
                    : 'update_existing_controlled_draft';
            }

            $planned[] = [
                'position' => $index + 1,
                'canonical_path' => (string) ($row['canonical_path'] ?? ''),
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
                $identity = is_array($plannedRow['identity'] ?? null) ? $plannedRow['identity'] : [];
                $existing = $this->existingAsset($identity);
                $attributes = $this->attributesForRow($rows[$index], $rowPlans[$index], $sourceSha256, $packagePath, $targetEnvironment);

                if ($existing instanceof PersonalityPublicContentAsset && $this->attributesMatch($existing, $attributes)) {
                    $plannedRow['action'] = 'skipped_existing_same_source_draft';
                    $skipped++;

                    continue;
                }

                if ($existing instanceof PersonalityPublicContentAsset) {
                    $existing->fill($attributes);
                    $existing->save();
                    $plannedRow['action'] = 'updated_controlled_draft';
                    $updated++;

                    continue;
                }

                $createdAsset = PersonalityPublicContentAsset::query()->create($attributes);
                $plannedRow['existing_asset_id'] = (int) $createdAsset->id;
                $plannedRow['action'] = 'created_controlled_draft';
                $created++;
            }
            unset($plannedRow);
        }

        return $this->summary($sourceSha256, $packagePath, $targetEnvironment, $write, $planned, $preImportSnapshot, $created, $updated, $skipped, []);
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

        return [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => (string) ($identity['entity_type'] ?? ''),
            'entity_key' => (string) ($identity['entity_key'] ?? ''),
            'slug' => (string) ($identity['slug'] ?? ''),
            'locale' => (string) ($identity['locale'] ?? ''),
            'title' => trim((string) ($row['title'] ?? '')),
            'summary' => trim((string) ($seo['description'] ?? '')) ?: null,
            'content_sections_json' => $this->contentSections($row),
            'seo_json' => $seo,
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => $canonicalPath],
            'hreflang_json' => [],
            'faq_json' => $faq,
            'media_json' => [],
            'schema_json' => [
                'recommendation' => (string) ($row['schema_recommendation'] ?? ''),
                'draft_only' => true,
                'runtime_jsonld_enabled' => false,
            ],
            'method_boundary_json' => [
                'claim_boundaries' => $claimBoundaries,
                'method_boundary' => 'Big Five public CMS draft content is non-diagnostic, non-predictive, and not for hiring or high-stakes decisions.',
                'indexability_gate' => (string) ($row['indexability_gate'] ?? ''),
            ],
            'evidence_notes_json' => [
                [
                    'source_type' => 'cms_import_draft',
                    'source' => self::SOURCE_PACKAGE,
                    'package_path' => $packagePath,
                    'package_sha256' => $sourceSha256,
                    'target_environment' => $targetEnvironment,
                    'schema_runtime_release' => false,
                    'sitemap_release' => false,
                    'llms_release' => false,
                ],
            ],
            'internal_links_json' => array_values(is_array($row['internal_links'] ?? null) ? $row['internal_links'] : []),
            'is_public' => false,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_REVIEW,
            'review_state' => 'cms_import_draft_pending_review',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => self::SOURCE_PACKAGE,
            'source_hash' => $sourceSha256,
            'published_at' => null,
            'last_reviewed_at' => null,
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

    private function existingAssetIsWritableControlledDraft(PersonalityPublicContentAsset $asset): bool
    {
        return (bool) $asset->is_public === false
            && (bool) $asset->index_eligible === false
            && (bool) $asset->sitemap_eligible === false
            && (bool) $asset->llms_eligible === false
            && $asset->robots === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW
            && in_array((string) $asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_DRAFT,
                PersonalityPublicContentAsset::LAUNCH_REVIEW,
            ], true)
            && in_array((string) $asset->source_package, ['', self::SOURCE_PACKAGE], true);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function attributesMatch(PersonalityPublicContentAsset $existing, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
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
        $importBatchId = 'big5-cms-staging-'.substr($sourceSha256, 0, 12).'-'.gmdate('YmdHis');

        return [
            'artifact' => 'BIG5-CMS-STAGING-WRITE-IMPORT-10',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'target_environment' => $targetEnvironment,
            'package_path' => $packagePath,
            'source_sha256' => $sourceSha256,
            'row_count' => count($rows),
            'expected_row_count' => 42,
            'row_count_matches_expected' => count($rows) === 42,
            'dry_run' => ! $write,
            'write' => $write,
            'writes_committed' => $write && ($created + $updated) > 0,
            'cms_write_attempted' => $write,
            'publish_attempted' => false,
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
                'deterministic_delete_criteria' => [
                    'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                    'source_package' => self::SOURCE_PACKAGE,
                    'source_hash' => $sourceSha256,
                    'is_public' => false,
                    'index_eligible' => false,
                    'sitemap_eligible' => false,
                    'llms_eligible' => false,
                ],
                'pre_import_snapshot' => $preImportSnapshot,
                'restore_note' => 'Restore pre_import_snapshot rows or delete rows matching deterministic_delete_criteria in staging/dev only. Do not touch production.',
            ],
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => [],
        ];
    }
}
