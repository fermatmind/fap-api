<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Retention;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoEvidenceRetentionJanitor
{
    public function __construct(
        private readonly SeoEvidenceBundleVerifier $verifier,
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoEvidenceRetentionPolicyRegistry $policy,
    ) {}

    /** @return list<array<string, mixed>> */
    public function planExpired(CarbonImmutable $now, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));

        return $this->connection()->table('seo_evidence_bundles')
            ->where('expires_at', '<=', $now->utc()->format('Y-m-d H:i:s'))
            ->orderBy('expires_at')->orderBy('bundle_id')->orderBy('bundle_version')
            ->limit($limit)->get(['bundle_id', 'bundle_version', 'bundle_hash', 'expires_at', 'bundle_json'])
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @param list<array<string, mixed>> $plan @return array{deleted:int,receipts:int} */
    public function executeExpired(array $plan): array
    {
        if (! (bool) config('seo_agent_evidence.retention_delete_enabled', false)) {
            return ['deleted' => 0, 'receipts' => 0];
        }
        if (count($plan) > 100) {
            throw new RuntimeException('SEO_EVIDENCE_RETENTION_LIMIT');
        }
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $plan): array {
            $deleted = 0;
            foreach ($plan as $planned) {
                $row = $connection->table('seo_evidence_bundles')
                    ->where('bundle_id', $planned['bundle_id'] ?? null)
                    ->where('bundle_version', $planned['bundle_version'] ?? null)
                    ->where('bundle_hash', $planned['bundle_hash'] ?? null)
                    ->lockForUpdate()
                    ->first(['bundle_id', 'bundle_version', 'bundle_hash', 'expires_at', 'bundle_json']);
                if ($row === null || CarbonImmutable::parse((string) $row->expires_at)->isFuture()) {
                    continue;
                }
                $row = (array) $row;
                $bundle = json_decode((string) $row['bundle_json'], true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($bundle) || ! $this->verifier->verify($bundle)['valid']) {
                    throw new RuntimeException('SEO_EVIDENCE_RETENTION_HASH_INVALID');
                }
                $receipt = [
                    'schema_version' => 'seo.evidence_deletion_receipt.v1',
                    'bundle_id' => (string) $row['bundle_id'],
                    'bundle_version' => (int) $row['bundle_version'],
                    'bundle_hash' => (string) $row['bundle_hash'],
                    'policy_version' => SeoEvidenceRetentionPolicyRegistry::VERSION,
                    'policy_hash' => $this->policy->hash(),
                    'expired_at' => CarbonImmutable::parse((string) $row['expires_at'])->utc()->format('Y-m-d\TH:i:s\Z'),
                    'deleted_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'reason' => 'retention_expired',
                ];
                $receipt['receipt_hash'] = $this->hasher->hash($receipt);
                $connection->table('seo_evidence_deletion_receipts')->insertOrIgnore([
                    ...$receipt,
                    'created_at' => now('UTC'),
                ]);
                $deleted += $connection->table('seo_evidence_bundles')
                    ->where('bundle_id', $row['bundle_id'])
                    ->where('bundle_version', $row['bundle_version'])
                    ->where('bundle_hash', $row['bundle_hash'])
                    ->delete();
            }

            return ['deleted' => $deleted, 'receipts' => $deleted];
        });
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection((string) config('seo_agent_evidence.connection', 'seo_intel'));
    }
}
