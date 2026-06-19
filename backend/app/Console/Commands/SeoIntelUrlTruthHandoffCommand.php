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
        {--confirm-bounded-write-override= : Exact approval phrase for command-scoped write override when seo_intel config write flags are disabled}
        {--json : Output safe machine-readable JSON}';

    protected $description = 'Export or validate bounded URL Truth handoff artifacts without cross-cloud direct writes.';

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

        if (! $artifact->supportsPageEntityType($pageType)) {
            return $this->finish([
                'status' => 'blocked',
                'issues' => ['unsupported_page_entity_type'],
                'dry_run' => true,
                'writes_committed' => false,
            ], $pageType);
        }

        if ($exportPath !== null) {
            return $this->export($artifact, $exportPath, $limit, $pageType);
        }

        return $this->import($artifact, (string) $importPath, $limit, $dryRun, $write, $pageType);
    }

    private function export(UrlTruthHandoffArtifact $artifact, string $path, int $limit, string $pageType): int
    {
        $pathSafetyIssue = $artifact->artifactPathSafetyIssue($path, forWrite: true);
        if ($pathSafetyIssue !== null) {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'export',
                'issues' => [$pathSafetyIssue],
                'dry_run' => true,
                'writes_committed' => false,
            ], $pageType);
        }

        $source = new BackendAuthorityUrlTruthSource;
        $records = array_values(array_filter(
            $source->candidates(),
            static fn (UrlTruthInventoryRecord $record): bool => $record->pageEntityType === $pageType
                && $record->sourceAuthority === UrlTruthHandoffArtifact::SOURCE_AUTHORITY
        ));

        usort($records, static fn (UrlTruthInventoryRecord $a, UrlTruthInventoryRecord $b): int => strcmp(
            $a->canonicalUrlHash(),
            $b->canonicalUrlHash(),
        ));

        $payload = $artifact->fromRecords($records, $source->metadata(), $limit, $pageType);
        $validation = $artifact->validate($payload, $limit, $pageType);

        if ($validation['status'] === 'blocked') {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'export',
                'issues' => $validation['issues'],
                'dry_run' => true,
                'writes_committed' => false,
            ], $pageType);
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
        ], $pageType);
    }

    private function import(UrlTruthHandoffArtifact $artifact, string $path, int $limit, bool $dryRun, bool $write, string $pageType): int
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
            ], $pageType);
        }

        if (! is_file($path)) {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'import',
                'issues' => ['handoff_artifact_missing'],
                'dry_run' => true,
                'writes_committed' => false,
            ], $pageType);
        }

        $sha256 = $artifact->sha256($path);
        $payload = $artifact->readJson($path);
        $validation = $artifact->validate($payload, $limit, $pageType);

        if ($validation['status'] === 'blocked') {
            return $this->finish([
                'status' => 'blocked',
                'mode' => 'import',
                'artifact_sha256' => $sha256,
                'issues' => $validation['issues'],
                'dry_run' => true,
                'writes_committed' => false,
                'target_tables' => ['seo_urls', 'seo_url_entities'],
            ], $pageType);
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
            ], $pageType);
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
            ], $pageType);
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
            ], $pageType);
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
            ], $pageType);
        }

        $configWriteEnabled = (bool) config('seo_intel.enabled', false) && (bool) config('seo_intel.write_enabled', false);
        $writeAuthorization = 'config_flags';
        $configWriteFlagsBypassed = false;

        if (! $configWriteEnabled) {
            $expectedOverrideConfirmation = $this->boundedWriteOverrideConfirmation($pageType, $sha256, $path);
            $overrideConfirmation = $this->stringOption($this->option('confirm-bounded-write-override'));

            if ($overrideConfirmation === null || ! hash_equals($expectedOverrideConfirmation, $overrideConfirmation)) {
                return $this->finish([
                    'status' => 'blocked',
                    'mode' => 'import_write',
                    'artifact_sha256' => $sha256,
                    'issues' => [
                        'seo_intel_write_flags_disabled',
                        'bounded_write_override_confirmation_required',
                    ],
                    'required_bounded_write_override_confirmation' => $expectedOverrideConfirmation,
                    'dry_run' => false,
                    'writes_committed' => false,
                    'target_tables' => ['seo_urls', 'seo_url_entities'],
                ], $pageType);
            }

            $writeAuthorization = 'bounded_command_override';
            $configWriteFlagsBypassed = true;
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
            'write_authorization' => $writeAuthorization,
            'config_write_flags_bypassed' => $configWriteFlagsBypassed,
            'target_tables' => ['seo_urls', 'seo_url_entities'],
            'external_api_calls' => false,
            'search_url_submission' => false,
            'crawler_log_read' => false,
            'issues' => [],
        ], $pageType);
    }

    private function boundedWriteOverrideConfirmation(string $pageType, string $sha256, string $path): string
    {
        return sprintf(
            'I explicitly approve bounded URL Truth handoff import write for %s page_entity_type using artifact sha256 %s generated at %s; no search submission, no CMS content changes, no publish, no schema/hreflang writes.',
            $pageType,
            $sha256,
            $path,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, ?string $pageType = null): int
    {
        $output = [
            'task' => 'SEO-INTEL-TWO-STAGE-URL-TRUTH-HANDOFF-PR-00',
            'collector' => UrlTruthHandoffArtifact::COLLECTOR,
            'page_entity_type' => $pageType ?? UrlTruthHandoffArtifact::PAGE_ENTITY_TYPE,
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
        $max = max(1, (int) config(
            'seo_intel.url_truth_inventory.handoff_max_limit',
            config('seo_intel.url_truth_inventory.canary_max_limit', 50)
        ));

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
