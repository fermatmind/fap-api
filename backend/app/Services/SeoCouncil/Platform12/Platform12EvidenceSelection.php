<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceContractRegistry;
use App\Services\SeoAgentEvidence\Competitive\CompetitivePolicyObservationSet;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveReleaseIdentity;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveSourcePolicyRegistry;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoCouncil\Competitive\CompetitiveCloseoutBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only selection; immutable history and replacement proof remain auditable. */
final readonly class Platform12EvidenceSelection
{
    public const COHORT = 'competitive.big-five.live.v2';

    public const EXIT_REASON = 'SUPERSEDED_BY_VERIFIED_PRODUCTION_RECEIPT';

    public function __construct(private SeoEvidenceCanonicalHasher $hasher) {}

    /** @return array{rows:Collection,freshness:array} */
    public function read(CarbonImmutable $at, ?string $sha): array
    {
        $rows = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_evidence_bundles')->orderBy('bundle_hash')->limit(201)
            ->get(['bundle_id', 'bundle_version', 'bundle_hash', 'expires_at', 'bundle_json']);
        $historyPaged = $rows->count() > 200;
        $reference = null;
        $reason = 'NONE';
        try {
            $reference = $this->currentReference($rows, $at, $sha);
        } catch (Throwable $error) {
            $reason = preg_match('/^[A-Z_]{3,64}$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'CURRENT_RECEIPT_INVALID';
            // A broken reference cannot retire any history or erase known faults.
            $reference = null;
        }
        if ($historyPaged) {
            $audit = $this->auditState($reference);
            if ($audit['fault_count'] > 0) {
                throw new \RuntimeException('HISTORY_INVALID');
            }
            if ($reference === null || $audit['state'] !== 'HISTORY_COMPLETE') {
                throw new \RuntimeException('HISTORY_SCAN_PENDING');
            }
            // Current consumers have a bounded necessary set; history uses a separate cursor.
            $rows = DB::connection((string) config('seo_intel.connection', 'seo_intel'))->table('seo_evidence_bundles as current')
                ->where('bundle_id', 'not like', 'competitive:production:release_%')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('seo_evidence_bundles as newer')
                        ->whereColumn('newer.bundle_id', 'current.bundle_id')->whereColumn('newer.bundle_version', '>', 'current.bundle_version');
                })->limit(201)->get(['bundle_id', 'bundle_version', 'bundle_hash', 'expires_at', 'bundle_json']);
            if ($reference !== null) {
                $row = $this->connection()->table('seo_evidence_bundles')->where('bundle_hash', $reference['bundle']['bundle_hash'])->first();
                if ($row !== null) {
                    $rows->push($row);
                }
            }
            if ($rows->count() > 200) {
                throw new \RuntimeException('CURRENT_EVIDENCE_SCAN_BUDGET_HOLD');
            }
        }
        $exits = [];
        $selected = $rows->filter(function (object $row) use ($reference, $at, &$exits): bool {
            if ($reference === null || $row->bundle_hash === $reference['bundle']['bundle_hash']) {
                return true;
            }
            try {
                $bundle = $this->verifiedRow($row);
                $replaced = $this->sameScope($bundle)
                    && CarbonImmutable::parse($bundle['captured_at'])->lessThan(CarbonImmutable::parse($reference['bundle']['captured_at']))
                    && CarbonImmutable::parse($bundle['captured_at'])->lessThanOrEqualTo($at);
                if ($replaced) {
                    $exits[] = $bundle['bundle_hash'];

                    return false;
                }
            } catch (Throwable) {
                // Invalid, unrelated and unproven records stay in the safety check.
                return true;
            }

            return true;
        })->values();
        sort($exits, SORT_STRING);
        $expired = $selected->filter(static fn (object $row): bool => CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo($at))->count();
        $freshness = [
            'total_count' => $selected->count(),
            'fresh_count' => $selected->count() - $expired,
            'expired_count' => $expired,
            'stored_count' => $rows->count(),
            'superseded_count' => count($exits),
            'current_reference_reason' => $reason,
            'historical_scan_state' => $historyPaged ? 'PAGED_IN_OPERATIONS' : 'BOUNDED_COMPLETE',
            'current_reference_state' => $reference === null ? 'UNAVAILABLE' : 'VALID',
            'production_sha' => $sha,
            'current_receipt_hash' => $reference['receipt']['receipt_hash'] ?? null,
            'current_bundle_hash' => $reference['bundle']['bundle_hash'] ?? null,
            'historical_exit_reason' => $exits === [] ? 'NONE' : self::EXIT_REASON,
            // Each hash identifies the retained immutable row. The receipt and new
            // bundle above are its replacement basis in the frozen formal record.
            'superseded_bundle_hashes' => implode(',', $exits),
        ];
        $freshness['selection_hash'] = $this->hasher->hash([
            'freshness' => $freshness,
            'selected_hashes' => $selected->pluck('bundle_hash')->all(),
        ]);

        return ['rows' => $selected, 'freshness' => $freshness];
    }

    public function currentReference(Collection $rows, CarbonImmutable $at, ?string $sha, ?array $candidate = null): array
    {
        if (! is_string($sha) || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new \RuntimeException('CURRENT_RELEASE_MISSING');
        }
        $current = storage_path('app/release-receipts/seo-competitive-evidence/current.json');
        $path = (is_file($current) || is_link($current)) ? $current : storage_path('app/release-receipts/seo-competitive-evidence/'.$sha.'.json');
        if ($candidate === null && (is_link($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 131072)) {
            throw new \RuntimeException('CURRENT_RECEIPT_MISSING');
        }
        $receipt = $candidate ?? json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (($receipt['refresh_status'] ?? null) === 'failed') {
            throw new \RuntimeException('REFRESH_FAILED');
        }
        $sourceSha = $receipt['candidate_sha'] ?? '';
        if ($sourceSha !== $sha && data_get($receipt, 'dependency_ingestion.dependency_hash') !== app(CompetitiveReleaseIdentity::class)->dependencyHash()) {
            throw new \RuntimeException('DEPENDENCY_CHANGED');
        }
        if (data_get($receipt, 'dependency_ingestion.dependency_hash') !== null
            && data_get($receipt, 'dependency_ingestion.dependency_hash') !== app(CompetitiveReleaseIdentity::class)->dependencyHash()) {
            throw new \RuntimeException('DEPENDENCY_CHANGED');
        }
        $snapshot = app(CompetitiveSourcePolicyRegistry::class)->snapshot(self::COHORT);
        $releaseRef = app(CompetitiveReleaseIdentity::class)->reference('production', $sourceSha, data_get($receipt, 'dependency_ingestion.collection_cycle'));
        if (! is_array($receipt) || ($receipt['closeout_state'] ?? null) !== 'CLOSED'
            || ! app(CompetitiveCloseoutBuilder::class)->verify($receipt, $sourceSha)
            || array_diff_assoc($snapshot, $receipt) !== []
            || ($receipt['contract_manifest_hash'] ?? null) !== app(CompetitiveEvidenceContractRegistry::class)->manifest()['manifest_hash']
            || data_get($receipt, 'dependency_ingestion.release_ref') !== $releaseRef) {
            throw new \RuntimeException('CURRENT_RECEIPT_INVALID');
        }
        foreach (['model_calls', 'tool_calls', 'external_calls', 'cms_writes', 'url_truth_writes', 'search_writes', 'business_writes', 'production_permissions', 'outreach_actions'] as $metric) {
            if (($receipt[$metric] ?? null) !== 0) {
                throw new \RuntimeException('CURRENT_RECEIPT_BOUNDARY_INVALID');
            }
        }
        $matches = $rows->filter(static fn (object $row): bool => $row->bundle_id === 'competitive:production:'.$releaseRef);
        if ($matches->count() === 0) {
            $matches = $this->connection()->table('seo_evidence_bundles')->where('bundle_id', 'competitive:production:'.$releaseRef)
                ->get(['bundle_id', 'bundle_version', 'bundle_hash', 'expires_at', 'bundle_json']);
        }
        if ($matches->count() !== 1) {
            throw new \RuntimeException('CURRENT_BUNDLE_MISSING_OR_AMBIGUOUS');
        }
        $bundle = $this->verifiedRow($matches->first());
        if (! $this->sameScope($bundle)
            || $bundle['source_ref'] !== $this->hasher->hash(['production', $sourceSha, self::COHORT])
            || $bundle['bundle_hash'] !== data_get($receipt, 'dependency_ingestion.bundle_hash')
            || $bundle['authority_revision'] !== hash_file('sha256', base_path('content_assets/personality_public/current/manifest.json'))
            || $bundle['lineage_refs'] !== [$receipt['search_measurement']['bundle_hash'], $receipt['cro_measurement']['bundle_hash']]
            || data_get($bundle, 'payload.measurement_bundle_set_hash') !== $receipt['measurement_bundle_set_hash']
            || data_get($bundle, 'payload.policy_observation_set_hash') !== data_get($receipt, 'dependency_ingestion.policy_observation_set_hash')
            || CarbonImmutable::parse($bundle['captured_at'])->greaterThan($at)
            || CarbonImmutable::parse($bundle['expires_at'])->lessThanOrEqualTo($at)) {
            throw new \RuntimeException('CURRENT_BUNDLE_INVALID_OR_STALE');
        }
        foreach ($bundle['payload']['policy_observations'] as $observation) {
            if (CarbonImmutable::parse($observation['reviewed_at'])->greaterThan($at)
                || CarbonImmutable::parse($observation['valid_until'])->lessThanOrEqualTo($at)) {
                throw new \RuntimeException('CURRENT_POLICY_OBSERVATION_STALE');
            }
        }

        return ['receipt' => $receipt, 'bundle' => $bundle, 'source_sha' => $sourceSha];
    }

    public function publish(array $receipt, string $sha, string $generation): void
    {
        $this->currentReference(collect(), CarbonImmutable::now('UTC'), $sha, $receipt);
        app(Platform12RuntimeControl::class)->withControlLock(function () use ($receipt, $sha, $generation): void {
            if (! app(Platform12RuntimeControl::class)->allowsMission(Platform12DailyMissionSet::IDS[2], false, $generation)
                || trim((string) file_get_contents(config('seo_council.release_revision_path'))) !== $sha) {
                throw new \RuntimeException('REFRESH_CONTROL_CHANGED');
            }
            $this->atomicReference($receipt);
        });
    }

    public function atomicReference(array $receipt): void
    {
        $path = storage_path('app/release-receipts/seo-competitive-evidence/current.json');
        if (is_link($path)) {
            throw new \RuntimeException('CURRENT_RECEIPT_INVALID');
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $temp = tempnam(dirname($path), '.current-');
        try {
            $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('CURRENT_REFERENCE_WRITE_FAILED');
            }
            chmod($temp, 0640);
            if (! rename($temp, $path)) {
                throw new \RuntimeException('CURRENT_REFERENCE_WRITE_FAILED');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /** Bounded historical verification. Invalid or unproven history is never excluded. */
    public function auditHistory(?string $sha = null): array
    {
        $store = \Illuminate\Support\Facades\Cache::store(config('seo_council.runtime_cache_store', config('cache.default')));
        $reference = null;
        try {
            $sha ??= trim((string) file_get_contents(config('seo_council.release_revision_path')));
            $reference = $this->currentReference(collect(), CarbonImmutable::now('UTC'), $sha);
        } catch (Throwable) {
            // Integrity scanning continues, but no replacement is authorized.
            $reference = null;
        }
        $key = $this->auditKey($reference);
        $prior = (array) $store->get('seo:evidence:history:progress', []);
        $cursor = ($prior['key'] ?? null) === $key ? (int) ($prior['cursor'] ?? 0) : 0;
        if (($prior['state'] ?? null) === 'HISTORY_COMPLETE' && ($prior['completed_at'] ?? '') < now('UTC')->subDay()->toAtomString()) {
            $cursor = 0;
        }
        $rows = $this->connection()->table('seo_evidence_bundles')->where('id', '>', $cursor)->orderBy('id')->limit(200)->get();
        $faults = (array) $store->get('seo:evidence:history:faults', []);
        $unproven = ($prior['key'] ?? null) === $key && $cursor !== 0 ? (int) ($prior['unproven'] ?? 0) : 0;
        foreach ($rows as $row) {
            try {
                $bundle = $this->verifiedRow($row);
                if (str_starts_with($bundle['bundle_id'], 'competitive:production:')
                    && ($reference === null || $bundle['bundle_hash'] !== $reference['bundle']['bundle_hash'])
                    && ! ($reference !== null && $this->sameScope($bundle)
                        && CarbonImmutable::parse($bundle['captured_at'])->lt(CarbonImmutable::parse($reference['bundle']['captured_at'])))) {
                    $unproven++;
                }
            } catch (Throwable) {
                $faults[$row->bundle_hash] = 'STORED_EVIDENCE_INVALID';
            }
            $cursor = (int) $row->id;
        }
        $pending = $this->connection()->table('seo_evidence_bundles')->where('id', '>', $cursor)->exists();
        $state = $faults !== [] ? 'HISTORY_INVALID' : ($pending ? 'HISTORY_PENDING' : ($unproven > 0 ? 'HISTORY_UNPROVEN' : 'HISTORY_COMPLETE'));
        $progress = ['key' => $key, 'cursor' => $cursor, 'unproven' => $unproven, 'state' => $state,
            'completed_at' => $pending ? null : now('UTC')->toAtomString()];
        $store->forever('seo:evidence:history:progress', $progress);
        $store->forever('seo:evidence:history:faults', $faults);

        return [...$progress, 'scanned' => $rows->count(), 'fault_count' => count($faults)];
    }

    private function auditKey(?array $reference): string
    {
        return $this->hasher->hash([$reference['bundle']['bundle_hash'] ?? null, app(CompetitiveReleaseIdentity::class)->dependencyHash()]);
    }

    private function auditState(?array $reference): array
    {
        $store = \Illuminate\Support\Facades\Cache::store(config('seo_council.runtime_cache_store', config('cache.default')));
        $progress = (array) $store->get('seo:evidence:history:progress', []);
        $complete = ($progress['key'] ?? null) === $this->auditKey($reference)
            && ($progress['state'] ?? null) === 'HISTORY_COMPLETE'
            && ($progress['completed_at'] ?? '') >= now('UTC')->subDay()->toAtomString()
            && ! $this->connection()->table('seo_evidence_bundles')->where('id', '>', $progress['cursor'] ?? 0)->exists();

        return ['state' => $complete ? 'HISTORY_COMPLETE' : 'HISTORY_PENDING',
            'fault_count' => count((array) $store->get('seo:evidence:history:faults', []))];
    }

    private function connection(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection((string) config('seo_intel.connection', 'seo_intel'));
    }

    private function verifiedRow(object $row): array
    {
        if (! is_string($row->bundle_json) || strlen($row->bundle_json) > 131072) {
            throw new \RuntimeException('EVIDENCE_PAYLOAD_BUDGET_HOLD');
        }
        $bundle = json_decode($row->bundle_json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($bundle) || ! app(SeoEvidenceBundleVerifier::class)->verify($bundle)['valid']
            || $bundle['bundle_hash'] !== $row->bundle_hash || $bundle['bundle_id'] !== $row->bundle_id
            || $bundle['bundle_version'] !== (int) $row->bundle_version
            || ! CarbonImmutable::parse($bundle['expires_at'])->equalTo(CarbonImmutable::parse($row->expires_at))) {
            throw new \RuntimeException('STORED_EVIDENCE_INVALID');
        }

        return $bundle;
    }

    private function sameScope(array $bundle): bool
    {
        $payload = $bundle['payload'];
        $policies = app(CompetitiveSourcePolicyRegistry::class)->policies();
        $expected = array_keys($policies);
        $ids = array_column((array) ($payload['projections'] ?? []), 'source_id');
        sort($ids, SORT_STRING);
        sort($expected, SORT_STRING);

        return $bundle['bundle_version'] === 1
            && $bundle['bundle_id'] === 'competitive:production:'.($payload['release_ref'] ?? '')
            && $bundle['mission_id'] === 'competitive:ingestion:'.($payload['release_ref'] ?? '')
            && $bundle['source_type'] === 'external_gateway'
            && $bundle['authority_type'] === 'competitive_structural_projection'
            && $bundle['data_usage_purpose'] === 'competitive_evidence'
            && $bundle['page_family'] === 'tests' && $bundle['locale'] === 'en'
            && $bundle['evidence_state'] === 'verified' && $bundle['freshness_state'] === 'fresh'
            && $bundle['source_capability_state'] === 'available'
            && ($payload['environment'] ?? null) === 'production'
            && ($payload['cohort_id'] ?? null) === self::COHORT && $ids === $expected
            && app(CompetitivePolicyObservationSet::class)->verify(
                $payload['policy_observations'], $payload['policy_observation_set_hash'],
                'production', $payload['release_ref'], $payload['source_policy_set_hash'],
                count($expected), $expected, array_column($policies, 'policy_hash', 'source_id'),
            );
    }
}
