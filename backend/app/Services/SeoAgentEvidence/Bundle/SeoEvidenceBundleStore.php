<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Bundle;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SeoEvidenceBundleStore
{
    public function __construct(private readonly SeoEvidenceBundleVerifier $verifier) {}

    /** @param array<string, mixed> $bundle */
    public function create(array $bundle): void
    {
        if (! (bool) config('seo_agent_evidence.bundle_write_enabled', false)) {
            throw new InvalidArgumentException('SEO_EVIDENCE_WRITE_DISABLED');
        }
        if (! $this->verifier->verify($bundle)['valid']) {
            throw new InvalidArgumentException('SEO_EVIDENCE_INVALID');
        }
        $connection = $this->connection();
        $latest = $connection->table('seo_evidence_bundles')
            ->where('bundle_id', $bundle['bundle_id'])
            ->orderByDesc('bundle_version')
            ->first(['bundle_version', 'bundle_hash', 'bundle_json']);
        $version = (int) $bundle['bundle_version'];
        if ($latest === null && $version !== 1) {
            throw new InvalidArgumentException('SEO_EVIDENCE_VERSION_MUST_START_AT_ONE');
        }
        if ($latest !== null) {
            if ($version !== (int) $latest->bundle_version + 1) {
                throw new InvalidArgumentException('SEO_EVIDENCE_VERSION_SEQUENCE');
            }
            $latestBundle = json_decode((string) $latest->bundle_json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($latestBundle) || ($latestBundle['content_hash'] ?? null) === $bundle['content_hash']) {
                throw new InvalidArgumentException('SEO_EVIDENCE_NOOP_REVISION');
            }
            if (! in_array((string) $latest->bundle_hash, (array) $bundle['lineage_refs'], true)) {
                throw new InvalidArgumentException('SEO_EVIDENCE_LINEAGE_REQUIRED');
            }
        }
        $connection->table('seo_evidence_bundles')->insert([
            'bundle_id' => $bundle['bundle_id'],
            'bundle_version' => $version,
            'bundle_hash' => $bundle['bundle_hash'],
            'mission_id' => $bundle['mission_id'],
            'page_family' => $bundle['page_family'],
            'locale' => $bundle['locale'],
            'source_type' => $bundle['source_type'],
            'expires_at' => CarbonImmutable::parse((string) $bundle['expires_at'])->utc()->format('Y-m-d H:i:s'),
            'bundle_json' => json_encode($bundle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now('UTC'),
        ]);
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection((string) config('seo_agent_evidence.connection', 'seo_intel'));
    }
}
