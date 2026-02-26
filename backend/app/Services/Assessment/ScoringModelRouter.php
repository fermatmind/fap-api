<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Support\SchemaBaseline;
use App\Support\StableBucket;
use Illuminate\Support\Facades\DB;

final class ScoringModelRouter
{
    /**
     * @return array{
     *   model_key:string,
     *   driver_type:string,
     *   scoring_spec_version:string,
     *   source:string,
     *   experiment_key:?string,
     *   experiment_variant:?string,
     *   rollout_id:?string,
     *   model_id:?string,
     *   experiments_json:array<string,string>
     * }
     */
    public function select(
        int $orgId,
        string $scaleCode,
        string $defaultDriverType,
        string $defaultSpecVersion,
        array $ctx = []
    ): array {
        $normalizedScaleCode = strtoupper(trim($scaleCode));
        $defaultDriverType = strtolower(trim($defaultDriverType));
        $defaultSpecVersion = trim($defaultSpecVersion);
        $experiments = $this->normalizeExperiments($ctx['experiments_json'] ?? []);

        $fallback = [
            'model_key' => 'default',
            'driver_type' => $defaultDriverType,
            'scoring_spec_version' => $defaultSpecVersion,
            'source' => 'default',
            'experiment_key' => null,
            'experiment_variant' => null,
            'rollout_id' => null,
            'model_id' => null,
            'experiments_json' => $experiments,
        ];

        if (
            $normalizedScaleCode === ''
            || !SchemaBaseline::hasTable('scoring_models')
            || !SchemaBaseline::hasTable('scoring_model_rollouts')
        ) {
            return $fallback;
        }

        $rollouts = $this->fetchRollouts($orgId, $normalizedScaleCode);
        if ($rollouts === []) {
            return $fallback;
        }

        $subjectKey = $this->resolveSubjectKey($ctx, $orgId, $normalizedScaleCode);

        foreach ($rollouts as $rollout) {
            if (!$this->isActiveTimeWindow($rollout)) {
                continue;
            }

            $experimentKey = trim((string) ($rollout->experiment_key ?? ''));
            $experimentVariant = trim((string) ($rollout->experiment_variant ?? ''));
            $matchedVariant = null;

            if ($experimentKey !== '') {
                $assignedVariant = $experiments[$experimentKey] ?? null;
                if (!is_string($assignedVariant) || trim($assignedVariant) === '') {
                    continue;
                }
                $assignedVariant = trim($assignedVariant);
                if ($experimentVariant !== '' && $experimentVariant !== $assignedVariant) {
                    continue;
                }
                $matchedVariant = $assignedVariant;
            }

            $rolloutPercent = (int) ($rollout->rollout_percent ?? 100);
            if ($rolloutPercent <= 0) {
                continue;
            }
            if ($rolloutPercent < 100) {
                $bucket = StableBucket::bucket($subjectKey.'|'.((string) ($rollout->id ?? '')), 100);
                if ($bucket >= $rolloutPercent) {
                    continue;
                }
            }

            $modelKey = trim((string) ($rollout->model_key ?? ''));
            if ($modelKey === '') {
                continue;
            }

            $model = $this->resolveModelRow($orgId, $normalizedScaleCode, $modelKey);
            if (!$model) {
                continue;
            }

            $driverType = strtolower(trim((string) ($model->driver_type ?? '')));
            if ($driverType === '') {
                $driverType = $defaultDriverType;
            }

            $specVersion = trim((string) ($model->scoring_spec_version ?? ''));
            if ($specVersion === '') {
                $specVersion = $defaultSpecVersion;
            }

            return [
                'model_key' => $modelKey,
                'driver_type' => $driverType,
                'scoring_spec_version' => $specVersion,
                'source' => 'rollout',
                'experiment_key' => $experimentKey !== '' ? $experimentKey : null,
                'experiment_variant' => $matchedVariant,
                'rollout_id' => ($rolloutId = trim((string) ($rollout->id ?? ''))) !== '' ? $rolloutId : null,
                'model_id' => ($modelId = trim((string) ($model->id ?? ''))) !== '' ? $modelId : null,
                'experiments_json' => $experiments,
            ];
        }

        return $fallback;
    }

    /**
     * @return list<object>
     */
    private function fetchRollouts(int $orgId, string $scaleCode): array
    {
        $orgIds = $orgId > 0 ? [0, $orgId] : [0];

        $rows = DB::table('scoring_model_rollouts')
            ->whereIn('org_id', $orgIds)
            ->where('scale_code', $scaleCode)
            ->where('is_active', true)
            ->get()
            ->all();

        usort($rows, function (object $a, object $b) use ($orgId): int {
            $aOrg = (int) ($a->org_id ?? 0);
            $bOrg = (int) ($b->org_id ?? 0);

            $aOrgRank = $aOrg === $orgId ? 0 : 1;
            $bOrgRank = $bOrg === $orgId ? 0 : 1;
            if ($aOrgRank !== $bOrgRank) {
                return $aOrgRank <=> $bOrgRank;
            }

            $aPriority = (int) ($a->priority ?? 100);
            $bPriority = (int) ($b->priority ?? 100);
            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            $aCreatedAt = (string) ($a->created_at ?? '');
            $bCreatedAt = (string) ($b->created_at ?? '');

            return strcmp($aCreatedAt, $bCreatedAt);
        });

        return $rows;
    }

    private function resolveModelRow(int $orgId, string $scaleCode, string $modelKey): ?object
    {
        $orgIds = $orgId > 0 ? [0, $orgId] : [0];

        $rows = DB::table('scoring_models')
            ->whereIn('org_id', $orgIds)
            ->where('scale_code', $scaleCode)
            ->where('model_key', $modelKey)
            ->where('is_active', true)
            ->get()
            ->all();

        if ($rows === []) {
            return null;
        }

        usort($rows, function (object $a, object $b) use ($orgId): int {
            $aOrg = (int) ($a->org_id ?? 0);
            $bOrg = (int) ($b->org_id ?? 0);

            $aOrgRank = $aOrg === $orgId ? 0 : 1;
            $bOrgRank = $bOrg === $orgId ? 0 : 1;
            if ($aOrgRank !== $bOrgRank) {
                return $aOrgRank <=> $bOrgRank;
            }

            $aPriority = (int) ($a->priority ?? 100);
            $bPriority = (int) ($b->priority ?? 100);
            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            $aCreatedAt = (string) ($a->created_at ?? '');
            $bCreatedAt = (string) ($b->created_at ?? '');

            return strcmp($aCreatedAt, $bCreatedAt);
        });

        return $rows[0] ?? null;
    }

    private function isActiveTimeWindow(object $rollout): bool
    {
        $nowTs = time();

        $startsAt = trim((string) ($rollout->starts_at ?? ''));
        if ($startsAt !== '') {
            $startTs = strtotime($startsAt);
            if (is_int($startTs) && $startTs > $nowTs) {
                return false;
            }
        }

        $endsAt = trim((string) ($rollout->ends_at ?? ''));
        if ($endsAt !== '') {
            $endTs = strtotime($endsAt);
            if (is_int($endTs) && $endTs < $nowTs) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,string>
     */
    private function normalizeExperiments(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $key => $value) {
            $experimentKey = trim((string) $key);
            $variant = trim((string) $value);
            if ($experimentKey === '' || $variant === '') {
                continue;
            }
            $normalized[$experimentKey] = $variant;
        }

        return $normalized;
    }

    private function resolveSubjectKey(array $ctx, int $orgId, string $scaleCode): string
    {
        $userId = trim((string) ($ctx['user_id'] ?? ''));
        if ($userId !== '') {
            return 'user:'.$userId;
        }

        $anonId = trim((string) ($ctx['anon_id'] ?? ''));
        if ($anonId !== '') {
            return 'anon:'.$anonId;
        }

        $attemptId = trim((string) ($ctx['attempt_id'] ?? ''));
        if ($attemptId !== '') {
            return 'attempt:'.$attemptId;
        }

        return 'org:'.$orgId.'|scale:'.$scaleCode;
    }
}
