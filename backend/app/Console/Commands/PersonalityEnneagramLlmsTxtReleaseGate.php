<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueEligibilityEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PersonalityEnneagramLlmsTxtReleaseGate extends Command
{
    private const EXPECTED_COUNT = 116;

    private const EXPECTED_ENTITY_COUNTS = [
        PersonalityPublicContentAsset::ENTITY_HUB => 2,
        PersonalityPublicContentAsset::ENTITY_CENTER => 6,
        PersonalityPublicContentAsset::ENTITY_CORE_TYPE => 18,
        PersonalityPublicContentAsset::ENTITY_WING => 36,
        PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE => 54,
    ];

    private const SCHEMA_VERSION = 'enneagram-llms-txt-release-gate.v1';

    protected $signature = 'personality:enneagram-llms-txt-release-gate
        {--dry-run : Inspect the exact cohort without writes.}
        {--write : Set llms_eligible=true only for the validated exact cohort.}
        {--deployed-sha= : Exact deployed backend SHA.}
        {--confirm-cohort-sha256= : Required exact cohort fingerprint for write mode.}
        {--operator-approved= : Required SHA- and cohort-bound command token for write mode.}
        {--json : Emit a sanitized machine-readable report.}';

    protected $description = 'Fail-closed exact-116 Enneagram llms.txt eligibility gate; llms-full and Search remain unchanged.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $write = (bool) $this->option('write');
        $deployedSha = strtolower(trim((string) $this->option('deployed-sha')));
        $modeIssues = [];
        if ($dryRun === $write) {
            $modeIssues[] = 'exactly_one_mode_required';
        }
        if (preg_match('/^[0-9a-f]{40}$/', $deployedSha) !== 1) {
            $modeIssues[] = 'deployed_sha_invalid';
        }

        $assets = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->orderBy('locale')
            ->orderBy('entity_type')
            ->orderBy('entity_key')
            ->get();

        [$rows, $issues] = $this->inspect($assets->all());
        $issues = array_values(array_unique([...$modeIssues, ...$issues]));
        $cohortSha = $this->cohortSha($rows);
        $llmsTrue = $assets->where('llms_eligible', true)->count();
        $llmsFalse = $assets->where('llms_eligible', false)->count();
        if (! (($llmsTrue === 0 && $llmsFalse === self::EXPECTED_COUNT)
            || ($llmsTrue === self::EXPECTED_COUNT && $llmsFalse === 0))) {
            $issues[] = 'partial_llms_release_state';
        }

        if ($write) {
            $issues = [...$issues, ...$this->writeAuthorizationIssues($deployedSha, $cohortSha)];
        }

        if ($issues !== []) {
            return $this->finish($this->payload('blocked', $dryRun, $write, $deployedSha, $cohortSha, $rows, $issues, 0));
        }

        if ($dryRun) {
            return $this->finish($this->payload(
                $llmsTrue === self::EXPECTED_COUNT ? 'already_released' : 'dry_run_ready',
                true,
                false,
                $deployedSha,
                $cohortSha,
                $rows,
                [],
                0,
            ));
        }

        if ($llmsTrue === self::EXPECTED_COUNT) {
            return $this->finish($this->payload('already_released', false, true, $deployedSha, $cohortSha, $rows, [], 0));
        }

        $updated = DB::transaction(function () use ($assets): int {
            $ids = $assets->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $updated = PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->where('llms_eligible', false)
                ->update(['llms_eligible' => true]);
            if ($updated !== self::EXPECTED_COUNT) {
                throw new \RuntimeException('llms_write_count_mismatch');
            }
            $readback = PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->where('llms_eligible', true)
                ->count();
            if ($readback !== self::EXPECTED_COUNT) {
                throw new \RuntimeException('llms_readback_count_mismatch');
            }

            return $updated;
        });

        return $this->finish($this->payload('released', false, true, $deployedSha, $cohortSha, $rows, [], $updated));
    }

    /** @param list<PersonalityPublicContentAsset> $assets @return array{list<array<string, string>>, list<string>} */
    private function inspect(array $assets): array
    {
        $issues = [];
        if (count($assets) !== self::EXPECTED_COUNT) {
            $issues[] = 'asset_count_mismatch';
        }
        if (collect($assets)->countBy('locale')->sortKeys()->all() !== ['en' => 58, 'zh-CN' => 58]) {
            $issues[] = 'locale_count_mismatch';
        }
        $expectedEntityCounts = self::EXPECTED_ENTITY_COUNTS;
        ksort($expectedEntityCounts);
        if (collect($assets)->countBy('entity_type')->sortKeys()->all() !== $expectedEntityCounts) {
            $issues[] = 'entity_count_mismatch';
        }

        $rows = [];
        $identityPaths = [];
        foreach ($assets as $asset) {
            $rowIssues = $this->assetIssues($asset);
            $path = SearchChannelQueueEligibilityEvaluator::normalizePublicPath(
                (string) data_get($asset->canonical_json, 'path', '')
            );
            $identity = $asset->entity_type.'|'.$asset->entity_key;
            if ($path !== null) {
                $identityPaths[$identity][(string) $asset->locale] = $path;
            }
            foreach ($rowIssues as $issue) {
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

        if (count(array_unique(array_column($rows, 'canonical_path_hash'))) !== self::EXPECTED_COUNT) {
            $issues[] = 'canonical_set_not_unique';
        }
        foreach ($identityPaths as $identity => $localized) {
            if (array_keys($localized) !== ['en', 'zh-CN'] && array_keys($localized) !== ['zh-CN', 'en']) {
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

        sort($issues);

        return [$rows, $issues];
    }

    /** @return list<string> */
    private function assetIssues(PersonalityPublicContentAsset $asset): array
    {
        $issues = [];
        $path = SearchChannelQueueEligibilityEvaluator::normalizePublicPath((string) data_get($asset->canonical_json, 'path', ''));
        if ($path === null || ! str_starts_with($path, $asset->locale === 'en' ? '/en/personality/enneagram' : '/zh/personality/enneagram')) {
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
        if (trim((string) $asset->title) === '' || trim((string) $asset->summary) === '' || $this->visibleTextLength($asset->content_sections_json) < 80) {
            $issues[] = 'visible_content_incomplete';
        }
        if ($this->visibleTextLength($asset->method_boundary_json) < 10 || ! $this->hasSourceId($asset->evidence_notes_json)) {
            $issues[] = 'evidence_or_claim_boundary_incomplete';
        }
        foreach ((array) $asset->internal_links_json as $link) {
            $href = is_array($link) ? ($link['href'] ?? $link['url'] ?? null) : null;
            if ($href !== null && ! $this->isSafeInternalHref((string) $href)) {
                $issues[] = 'internal_link_invalid_or_private';
                break;
            }
        }

        return $issues;
    }

    private function isSafeInternalHref(string $href): bool
    {
        $href = trim($href);
        if (preg_match('/^#[A-Za-z0-9][\w:.-]{0,127}$/', $href) === 1) {
            return true;
        }

        return SearchChannelQueueEligibilityEvaluator::normalizePublicPath($href) !== null
            || SearchChannelQueueEligibilityEvaluator::publicPathFromCanonicalUrl($href) !== null;
    }

    private function visibleTextLength(mixed $value): int
    {
        if (is_string($value) || is_numeric($value)) {
            return mb_strlen(trim(strip_tags((string) $value)));
        }
        if (! is_array($value)) {
            return 0;
        }

        return array_sum(array_map(fn (mixed $item): int => $this->visibleTextLength($item), $value));
    }

    private function hasSourceId(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['source_id', 'source_ids'], true) && $this->visibleTextLength($item) > 0) {
                return true;
            }
            if (is_array($item) && $this->hasSourceId($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $rows */
    private function cohortSha(array $rows): string
    {
        return hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function writeAuthorizationIssues(string $deployedSha, string $cohortSha): array
    {
        $issues = [];
        if (getenv('ENNEAGRAM_LLMS_TXT_WRITE_ENABLED') !== 'true') {
            $issues[] = 'process_write_gate_disabled';
        }
        if (! hash_equals($cohortSha, strtolower(trim((string) $this->option('confirm-cohort-sha256'))))) {
            $issues[] = 'cohort_sha256_confirmation_mismatch';
        }
        $expected = 'ENNEAGRAM-LLMS-TXT-RELEASE-01:'.$deployedSha.':'.$cohortSha;
        if (! hash_equals($expected, trim((string) $this->option('operator-approved')))) {
            $issues[] = 'operator_approval_mismatch';
        }

        return $issues;
    }

    /** @param list<array<string, string>> $rows @param list<string> $issues @return array<string, mixed> */
    private function payload(string $status, bool $dryRun, bool $write, string $deployedSha, string $cohortSha, array $rows, array $issues, int $updated): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'ok' => in_array($status, ['dry_run_ready', 'released', 'already_released'], true),
            'dry_run' => $dryRun,
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

    /** @param array<string, mixed> $payload */
    private function finish(array $payload): int
    {
        $this->line((bool) $this->option('json')
            ? (string) json_encode($payload, JSON_UNESCAPED_SLASHES)
            : 'status='.$payload['status']);

        return ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
