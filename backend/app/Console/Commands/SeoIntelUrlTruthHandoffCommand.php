<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ResearchReport;
use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use App\Services\SeoIntel\UrlTruthHandoffArtifact;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
use Illuminate\Console\Command;

final class SeoIntelUrlTruthHandoffCommand extends Command
{
    protected $signature = 'seo-intel:url-truth-handoff
        {--export= : Export a dry-run/no-write URL Truth handoff JSON artifact}
        {--import= : Import and validate a URL Truth handoff JSON artifact}
        {--dry-run : Validate or export without writes}
        {--write : Execute bounded write from a validated import artifact}
        {--limit=20 : Bound exported or imported candidates}
        {--page-type=research_report : Required page entity type}
        {--confirm-artifact-sha256= : Required SHA256 confirmation for write mode}
        {--json : Output safe machine-readable JSON}';

    protected $description = 'Export or validate bounded Research URL Truth handoff artifacts without cross-cloud direct writes.';

    public function handle(UrlTruthHandoffArtifact $artifact): int
    {
        $exportPath = $this->stringOption($this->option('export'));
        $importPath = $this->stringOption($this->option('import'));
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run') || ! $write;
        $limit = $this->boundedLimit($this->option('limit'));
        $pageType = $this->stringOption($this->option('page-type')) ?? ResearchReport::PAGE_ENTITY_TYPE;

        if (($exportPath === null && $importPath === null) || ($exportPath !== null && $importPath !== null)) {
            return $this->finish([
                'status' => 'blocked',
                'issues' => ['exactly_one_of_export_or_import_required'],
                'dry_run' => true,
                'writes_committed' => false,
            ]);
        }

        if ($pageType !== ResearchReport::PAGE_ENTITY_TYPE) {
            return $this->finish([
                'status' => 'blocked',
                'issues' => ['only_research_report_page_type_supported'],
                'dry_run' => true,
                'writes_committed' => false,
            ]);
        }

        if ($exportPath !== null) {
            return $this->export($artifact, $exportPath, $limit);
        }

        return $this->import($artifact, (string) $importPath, $limit, $dryRun, $write);
    }

    private function export(UrlTruthHandoffArtifact $artifact, string $path, int $limit): int
    {
        $pathSafetyIssue = $artifact->artifactPathSafetyIssue($path, forWrite: true);
        if ($pathSafetyIssue !== null) {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'export',
                'issues' => [$pathSafetyIssue],
                'dry_run' => true,
                'writes_committed' => false,
            ]);
        }

        $source = new BackendAuthorityUrlTruthSource;
        $records = array_values(array_filter(
            $source->candidates(),
            static fn (UrlTruthInventoryRecord $record): bool => $record->pageEntityType === ResearchReport::PAGE_ENTITY_TYPE
                && $record->sourceAuthority === UrlTruthHandoffArtifact::SOURCE_AUTHORITY
        ));

        usort($records, static fn (UrlTruthInventoryRecord $a, UrlTruthInventoryRecord $b): int => strcmp(
            $a->canonicalUrlHash(),
            $b->canonicalUrlHash(),
        ));

        $payload = $artifact->fromRecords($records, $source->metadata(), $limit);
        $validation = $artifact->validate($payload, $limit);

        if ($validation['status'] === 'blocked') {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'export',
                'issues' => $validation['issues'],
                'dry_run' => true,
                'writes_committed' => false,
            ]);
        }

        $artifact->writeJson($path, $payload);

        return $this->finish([
            'status' => 'success',
            'mode' => 'export',
            'artifact_path' => $path,
            'artifact_sha256' => $artifact->sha256($path),
            'dry_run' => true,
            'writes_attempted' => false,
            'writes_committed' => false,
            'planned_url_count' => $validation['metadata']['planned_url_count'],
            'planned_entity_count' => $validation['metadata']['planned_entity_count'],
            'target_tables' => ['seo_urls', 'seo_url_entities'],
            'external_api_calls' => false,
            'search_url_submission' => false,
            'crawler_log_read' => false,
            'issues' => [],
        ]);
    }

    private function import(UrlTruthHandoffArtifact $artifact, string $path, int $limit, bool $dryRun, bool $write): int
    {
        $pathSafetyIssue = $artifact->artifactPathSafetyIssue($path, forWrite: false);
        if ($pathSafetyIssue !== null) {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'import',
                'issues' => [$pathSafetyIssue],
                'dry_run' => true,
                'writes_committed' => false,
                'target_tables' => ['seo_urls', 'seo_url_entities'],
            ]);
        }

        if (! is_file($path)) {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'import',
                'issues' => ['handoff_artifact_missing'],
                'dry_run' => true,
                'writes_committed' => false,
            ]);
        }

        $sha256 = $artifact->sha256($path);
        $payload = $artifact->readJson($path);
        $validation = $artifact->validate($payload, $limit);

        if ($validation['status'] === 'blocked') {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'import',
                'artifact_sha256' => $sha256,
                'issues' => $validation['issues'],
                'dry_run' => true,
                'writes_committed' => false,
                'target_tables' => ['seo_urls', 'seo_url_entities'],
            ]);
        }

        if ($write && $dryRun) {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'import',
                'artifact_sha256' => $sha256,
                'issues' => ['write_mode_cannot_be_dry_run'],
                'dry_run' => true,
                'writes_committed' => false,
                'target_tables' => ['seo_urls', 'seo_url_entities'],
            ]);
        }

        if (! $write) {
            return $this->finish([
                'status' => 'success',
                'mode' => 'import_dry_run',
                'artifact_sha256' => $sha256,
                'dry_run' => true,
                'writes_attempted' => false,
                'writes_committed' => false,
                'planned_url_count' => $validation['metadata']['planned_url_count'],
                'planned_entity_count' => $validation['metadata']['planned_entity_count'],
                'target_tables' => ['seo_urls', 'seo_url_entities'],
                'external_api_calls' => false,
                'search_url_submission' => false,
                'crawler_log_read' => false,
                'issues' => [],
            ]);
        }

        if ($validation['records'] === []) {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'import_write',
                'artifact_sha256' => $sha256,
                'issues' => ['handoff_artifact_has_no_candidates'],
                'dry_run' => false,
                'writes_committed' => false,
                'target_tables' => ['seo_urls', 'seo_url_entities'],
            ]);
        }

        $confirmation = $this->stringOption($this->option('confirm-artifact-sha256'));
        if ($confirmation === null || ! hash_equals($sha256, $confirmation)) {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'import_write',
                'artifact_sha256' => $sha256,
                'issues' => ['artifact_sha256_confirmation_required'],
                'dry_run' => false,
                'writes_committed' => false,
                'target_tables' => ['seo_urls', 'seo_url_entities'],
            ]);
        }

        if (! (bool) config('seo_intel.enabled', false) || ! (bool) config('seo_intel.write_enabled', false)) {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'import_write',
                'artifact_sha256' => $sha256,
                'issues' => ['seo_intel_write_flags_disabled'],
                'dry_run' => false,
                'writes_committed' => false,
                'target_tables' => ['seo_urls', 'seo_url_entities'],
            ]);
        }

        $written = (new UrlTruthInventoryRecordWriter)->write($validation['records']);

        return $this->finish([
            'status' => 'success',
            'mode' => 'import_write',
            'artifact_sha256' => $sha256,
            'dry_run' => false,
            'writes_attempted' => true,
            'writes_committed' => true,
            'written_records' => $written,
            'planned_url_count' => $validation['metadata']['planned_url_count'],
            'planned_entity_count' => $validation['metadata']['planned_entity_count'],
            'target_tables' => ['seo_urls', 'seo_url_entities'],
            'external_api_calls' => false,
            'search_url_submission' => false,
            'crawler_log_read' => false,
            'issues' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload): int
    {
        $output = [
            'task' => 'SEO-INTEL-TWO-STAGE-URL-TRUTH-HANDOFF-PR-00',
            'collector' => UrlTruthHandoffArtifact::COLLECTOR,
            'page_entity_type' => UrlTruthHandoffArtifact::PAGE_ENTITY_TYPE,
            'source_authority' => UrlTruthHandoffArtifact::SOURCE_AUTHORITY,
            'target_tables' => ['seo_urls', 'seo_url_entities'],
            'external_api_calls' => false,
            'search_url_submission' => false,
            'crawler_log_read' => false,
        ] + $payload;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status='.$output['status']);
            $this->line('mode='.($output['mode'] ?? 'n/a'));
            $this->line('dry_run='.((bool) ($output['dry_run'] ?? true) ? '1' : '0'));
            $this->line('writes_committed='.((bool) ($output['writes_committed'] ?? false) ? '1' : '0'));
        }

        return ($output['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }

    private function boundedLimit(mixed $rawLimit): int
    {
        $max = max(1, (int) config('seo_intel.url_truth_inventory.canary_max_limit', 50));

        if ($rawLimit === null || $rawLimit === '') {
            return min($max, 20);
        }

        return min($max, max(1, (int) $rawLimit));
    }

    private function stringOption(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
