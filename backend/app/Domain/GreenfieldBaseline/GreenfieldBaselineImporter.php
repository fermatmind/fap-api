<?php

declare(strict_types=1);

namespace App\Domain\GreenfieldBaseline;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class GreenfieldBaselineImporter
{
    public function __construct(
        private readonly GreenfieldBaselinePackageVerifier $verifier,
        private readonly bool $enforceProductionCounts = true,
    ) {}

    /** @return array<string, mixed> */
    public function run(
        string $packageDirectory,
        bool $apply,
        ?string $confirmation,
        ?string $expectedDatabaseSha256,
    ): array {
        $verified = $this->verifier->verify(
            $packageDirectory,
            enforceProductionCounts: $this->enforceProductionCounts,
        );
        $packageSha = (string) $verified['package_sha256'];
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if ($driver !== 'mysql' && ! app()->runningUnitTests()) {
            throw new RuntimeException('Greenfield baseline import requires MySQL.');
        }
        $databaseName = (string) $connection->getDatabaseName();
        $databaseSha = hash('sha256', $databaseName);
        $manifest = json_decode(
            (string) file_get_contents(rtrim($packageDirectory, '/').'/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (hash_equals((string) data_get($manifest, 'source.database_name_sha256', ''), $databaseSha)) {
            throw new RuntimeException('Greenfield baseline import refuses the source database.');
        }
        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('Greenfield target must be migrated before baseline import.');
        }

        $nonEmptyDatasets = $this->nonEmptyPackageTables($connection);
        $forbiddenRows = $this->forbiddenTableRows($connection);
        $preflight = [
            'status' => $nonEmptyDatasets === [] && $forbiddenRows === [] ? 'ready' : 'blocked',
            'mode' => $apply ? 'apply' : 'dry-run',
            'package_sha256' => $packageSha,
            'target_database_sha256' => $databaseSha,
            'non_empty_dataset_tables' => $nonEmptyDatasets,
            'forbidden_table_rows' => $forbiddenRows,
            'writes_committed' => false,
        ];
        if (! $apply) {
            return $preflight;
        }
        if ($preflight['status'] !== 'ready') {
            throw new RuntimeException('Greenfield target database is not empty.');
        }
        if (config('greenfield-baseline.import_enabled') !== true) {
            throw new RuntimeException('Greenfield baseline apply is disabled by runtime configuration.');
        }
        $expectedConfirmation = 'IMPORT_GREENFIELD_BASELINE:'.$packageSha;
        if (! is_string($confirmation) || ! hash_equals($expectedConfirmation, $confirmation)) {
            throw new RuntimeException('Greenfield baseline apply confirmation is invalid.');
        }
        if (! is_string($expectedDatabaseSha256)
            || preg_match('/^[0-9a-f]{64}$/', $expectedDatabaseSha256) !== 1
            || ! hash_equals($expectedDatabaseSha256, $databaseSha)) {
            throw new RuntimeException('Greenfield target database SHA256 binding is invalid.');
        }

        $deferred = [];
        $foreignKeysDisabled = false;
        try {
            if ($driver === 'mysql') {
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
                $foreignKeysDisabled = true;
            }
            $connection->beginTransaction();
            foreach ($this->importDefinitions() as $definition) {
                $dataset = (string) $definition['name'];
                $table = (string) $definition['table'];
                $rows = $this->readDataset($packageDirectory, $dataset);
                $availableColumns = array_flip(Schema::getColumnListing($table));
                foreach ($rows as &$row) {
                    $unknown = array_diff(array_keys($row), array_keys($availableColumns));
                    if ($unknown !== []) {
                        throw new RuntimeException("Greenfield target schema is missing columns for {$dataset}.");
                    }
                    $deferredValues = [];
                    foreach ((array) ($definition['deferred'] ?? []) as $column) {
                        if (array_key_exists((string) $column, $row)) {
                            $deferredValues[(string) $column] = $row[(string) $column];
                            $row[(string) $column] = null;
                        }
                    }
                    if ($deferredValues !== []) {
                        $deferred[] = [
                            'table' => $table,
                            'key' => $definition['key'] ?? 'id',
                            'key_value' => $row[(string) ($definition['key'] ?? 'id')] ?? null,
                            'values' => $deferredValues,
                        ];
                    }
                }
                unset($row);
                foreach (array_chunk($rows, 200) as $chunk) {
                    if ($chunk !== []) {
                        $connection->table($table)->insert($chunk);
                    }
                }
            }
            foreach ($deferred as $update) {
                if ($update['key_value'] === null) {
                    throw new RuntimeException('Greenfield deferred relation is missing a primary key.');
                }
                $connection->table((string) $update['table'])
                    ->where((string) $update['key'], $update['key_value'])
                    ->update((array) $update['values']);
            }
            $connection->commit();
        } catch (Throwable $throwable) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            throw $throwable;
        } finally {
            if ($foreignKeysDisabled) {
                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return [
            'status' => 'imported',
            'mode' => 'apply',
            'package_sha256' => $packageSha,
            'target_database_sha256' => $databaseSha,
            'dataset_counts' => $verified['dataset_counts'],
            'writes_committed' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function importDefinitions(): array
    {
        $catalog = [];
        foreach (GreenfieldBaselineCatalog::datasets() as $definition) {
            $definition['key'] = $definition['name'] === 'skus' ? 'sku' : 'id';
            $catalog[(string) $definition['name']] = $definition;
        }
        $order = [
            'article_categories', 'article_tags', 'articles', 'article_translation_revisions',
            'article_seo_meta', 'article_tag_map', 'article_test_edges',
            'content_pages', 'cms_translation_revisions', 'landing_surfaces', 'page_blocks',
            'personality_public_content_assets', 'personality_public_content_asset_revisions',
            'personality_public_content_asset_revision_reviews', 'personality_profiles',
            'personality_profile_sections', 'personality_profile_seo_meta',
            'personality_profile_variants', 'personality_profile_variant_sections',
            'personality_profile_variant_seo_meta', 'personality_profile_variant_clone_contents',
            'topic_profiles', 'topic_profile_sections', 'topic_profile_entries',
            'topic_profile_seo_meta', 'mbti_cross_type_comparison_authorities',
            'occupation_families', 'occupations', 'occupation_aliases', 'occupation_crosswalks',
            'occupation_truth_metrics', 'occupation_skill_graphs',
            'career_job_ai_impact_assets', 'career_job_salary_assets',
            'career_job_page_assembly_assets', 'career_job_display_assets',
            'career_jobs', 'career_job_sections', 'career_job_seo_meta',
            'career_guides', 'career_guide_seo_meta', 'career_guide_article_map',
            'career_guide_job_map', 'career_guide_personality_map',
            'media_assets', 'media_variants', 'content_path_aliases', 'skus',
        ];
        $definitions = [];
        foreach ($order as $dataset) {
            if (isset($catalog[$dataset])) {
                $definitions[] = $catalog[$dataset];
                unset($catalog[$dataset]);
            }
        }
        foreach ($catalog as $definition) {
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /** @return list<string> */
    private function nonEmptyPackageTables(ConnectionInterface $connection): array
    {
        $tables = [];
        foreach (GreenfieldBaselineCatalog::datasets() as $definition) {
            $table = (string) $definition['table'];
            if (Schema::hasTable($table) && $connection->table($table)->limit(1)->exists()) {
                $tables[] = $table;
            }
        }
        sort($tables, SORT_STRING);

        return $tables;
    }

    /** @return array<string, int> */
    private function forbiddenTableRows(ConnectionInterface $connection): array
    {
        $counts = [];
        foreach (GreenfieldBaselineCatalog::forbiddenDatasetNames() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $count = (int) $connection->table($table)->count();
            if ($count > 0) {
                $counts[$table] = $count;
            }
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    private function readDataset(string $packageDirectory, string $dataset): array
    {
        $rows = [];
        $path = rtrim($packageDirectory, '/').'/datasets/'.$dataset.'.jsonl';
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
