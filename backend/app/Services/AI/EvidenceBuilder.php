<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Attempt;
use App\Models\Result;
final class EvidenceBuilder
{
    public function build(?Attempt $attempt, ?Result $result): array
    {
        $items = [];
        $now = now()->toIso8601String();

        if ($attempt) {
            $typeCode = trim((string) ($attempt->type_code ?? ''));
            if ($typeCode !== '') {
                $this->pushEvidence($items, 'type_code', 'attempt', 'attempts.type_code', "type_code={$typeCode}", $now);
            }

            $packId = trim((string) ($attempt->pack_id ?? ''));
            if ($packId !== '') {
                $this->pushEvidence($items, 'pack_id', 'attempt', 'attempts.pack_id', "pack_id={$packId}", $now);
            }

            $dirVersion = trim((string) ($attempt->dir_version ?? ''));
            if ($dirVersion !== '') {
                $this->pushEvidence($items, 'dir_version', 'attempt', 'attempts.dir_version', "dir_version={$dirVersion}", $now);
            }

            $normVersion = trim((string) ($attempt->norm_version ?? ''));
            if ($normVersion !== '') {
                $this->pushEvidence($items, 'norm_version', 'attempt', 'attempts.norm_version', "norm_version={$normVersion}", $now);
            }

            $scoringSpec = trim((string) ($attempt->scoring_spec_version ?? ''));
            if ($scoringSpec !== '') {
                $this->pushEvidence(
                    $items,
                    'scoring_spec_version',
                    'attempt',
                    'attempts.scoring_spec_version',
                    "scoring_spec_version={$scoringSpec}",
                    $now
                );
            }

            $snapshot = $attempt->calculation_snapshot_json;
            if (is_string($snapshot)) {
                $decoded = json_decode($snapshot, true);
                $snapshot = is_array($decoded) ? $decoded : null;
            }

            if (is_array($snapshot)) {
                $version = trim((string) ($snapshot['version'] ?? ''));
                if ($version !== '') {
                    $this->pushEvidence(
                        $items,
                        'calc_snapshot_version',
                        'psychometrics',
                        'attempts.calculation_snapshot_json.version',
                        "snapshot_version={$version}",
                        $now
                    );
                }
            }
        }

        if ($result) {
            $typeCode = trim((string) ($result->type_code ?? ''));
            if ($typeCode !== '') {
                $this->pushEvidence($items, 'type_code', 'result', 'results.type_code', "type_code={$typeCode}", $now);
            }

            $scoresPct = $result->scores_pct;
            if (is_string($scoresPct)) {
                $decoded = json_decode($scoresPct, true);
                $scoresPct = is_array($decoded) ? $decoded : null;
            }

            if (is_array($scoresPct)) {
                $this->appendKeyValueEvidence($items, 'score_pct', 'result', 'results.scores_pct', $scoresPct, 4, $now);
            }

            $axisStates = $result->axis_states;
            if (is_string($axisStates)) {
                $decoded = json_decode($axisStates, true);
                $axisStates = is_array($decoded) ? $decoded : null;
            }

            if (is_array($axisStates)) {
                $this->appendKeyValueEvidence($items, 'axis_state', 'result', 'results.axis_states', $axisStates, 4, $now);
            }
        }

        return $items;
    }

    private function appendKeyValueEvidence(
        array &$items,
        string $type,
        string $source,
        string $pointerPrefix,
        array $data,
        int $limit,
        string $createdAt
    ): void {
        $count = 0;
        foreach ($data as $key => $value) {
            if ($count >= $limit) {
                break;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $keyStr = is_string($key) ? $key : (string) $key;
            $valStr = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $this->pushEvidence(
                $items,
                $type,
                $source,
                $pointerPrefix . '.' . $keyStr,
                "{$keyStr}={$valStr}",
                $createdAt
            );
            $count++;
        }
    }

    private function pushEvidence(
        array &$items,
        string $type,
        string $source,
        string $pointer,
        string $quote,
        string $createdAt
    ): void {
        $payload = [
            'type' => $type,
            'source' => $source,
            'pointer' => $pointer,
            'quote' => $quote,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $items[] = [
            'type' => $type,
            'source' => $source,
            'pointer' => $pointer,
            'quote' => $quote,
            'hash' => $hash,
            'created_at' => $createdAt,
        ];
    }
}
