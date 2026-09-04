<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use RuntimeException;
use Throwable;

final class CompetitiveReleasePrepareEnvelope
{
    public function __construct(private readonly CompetitiveCloseoutBuilder $closeout) {}

    /** @param array<string, mixed> $payload */
    public function verify(array $payload, string $candidateSha, string $environment): bool
    {
        $receipt = $payload['preactivation_receipt'] ?? null;

        return preg_match('/^[a-f0-9]{40}$/D', $candidateSha) === 1
            && $environment === 'production'
            && ($payload['schema_version'] ?? null) === 'seo.competitive_release_prepare.v1'
            && ($payload['status'] ?? null) === 'READY'
            && ($payload['failed_stage'] ?? null) === 'none'
            && ($payload['reason_code'] ?? null) === 'NONE'
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($payload['measurement_snapshot_set_hash'] ?? '')) === 1
            && is_int(data_get($payload, 'dependency_ingestion.external_reads'))
            && (int) data_get($payload, 'dependency_ingestion.external_reads') >= 0
            && is_array($receipt)
            && $this->closeout->verify($receipt, $candidateSha)
            && ($receipt['candidate_sha'] ?? null) === $candidateSha
            && ($receipt['environment'] ?? null) === $environment
            && ($receipt['closeout_state'] ?? null) === 'HOLD'
            && ($receipt['production_sha'] ?? null) === null
            && ($receipt['competitive_context_status'] ?? null) === 'READY'
            && ($receipt['competitive_hold_reason'] ?? null) === 'NONE'
            && ($receipt['execution_allowed'] ?? null) === false;
    }

    /** @return array<string, mixed> */
    public function extract(string $output, string $candidateSha, string $environment): array
    {
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, '{')) {
                continue;
            }
            try {
                $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($payload) && $this->verify($payload, $candidateSha, $environment)) {
                return (array) $payload['preactivation_receipt'];
            }
        }

        throw new RuntimeException('COMPETITIVE_PREACTIVATION_ENVELOPE_INVALID');
    }
}
