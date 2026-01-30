<?php

namespace App\Services\Experiments;

use App\Models\ExperimentAssignment;
use App\Support\StableBucket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExperimentAssigner
{
    private string $salt;

    public function __construct()
    {
        $this->salt = (string) config('fap_experiments.salt', '');
    }

    public function assignActive(int $orgId, ?string $anonId, ?int $userId): array
    {
        $configs = config('fap_experiments.experiments', []);
        if (!is_array($configs)) {
            return [];
        }

        $active = [];
        foreach ($configs as $key => $config) {
            if (!is_array($config)) {
                continue;
            }
            if (!($config['is_active'] ?? false)) {
                continue;
            }
            $active[$key] = $config;
        }

        return $this->assignMany($active, $orgId, $anonId, $userId);
    }

    public function assignMany(array $experiments, int $orgId, ?string $anonId, ?int $userId): array
    {
        $assignments = [];
        foreach ($experiments as $experimentKey => $config) {
            $variant = $this->assignOne((string) $experimentKey, $config, $orgId, $anonId, $userId);
            if ($variant !== null) {
                $assignments[(string) $experimentKey] = $variant;
            }
        }

        return $assignments;
    }

    public function attachUserId(int $orgId, string $anonId, int $userId): int
    {
        if (!Schema::hasTable('experiment_assignments')) {
            return 0;
        }

        return DB::table('experiment_assignments')
            ->where('org_id', $orgId)
            ->where('anon_id', $anonId)
            ->whereNull('user_id')
            ->update([
                'user_id' => $userId,
                'updated_at' => now(),
            ]);
    }

    public function mergeExperiments(array $bootExperiments, array $assignments): array
    {
        if ($bootExperiments === [] && $assignments === []) {
            return [];
        }

        foreach ($assignments as $key => $value) {
            $bootExperiments[$key] = $value;
        }

        return $bootExperiments;
    }

    private function assignOne(string $experimentKey, $config, int $orgId, ?string $anonId, ?int $userId): ?string
    {
        $experimentKey = trim($experimentKey);
        if ($experimentKey === '' || !is_array($config)) {
            return null;
        }

        $anonId = trim((string) ($anonId ?? ''));
        if ($anonId === '') {
            return null;
        }

        if (Schema::hasTable('experiment_assignments')) {
            $existing = $this->findExisting($experimentKey, $orgId, $userId, $anonId);
            if ($existing) {
                if ($userId !== null && empty($existing->user_id)) {
                    $existing->user_id = $userId;
                    $existing->updated_at = now();
                    $existing->save();
                }

                $variant = trim((string) ($existing->variant ?? ''));
                if ($variant !== '') {
                    return $variant;
                }
            }
        }

        $variants = $config['variants'] ?? [];
        if (!is_array($variants) || $variants === []) {
            return null;
        }

        $subjectKey = $userId !== null ? 'user:' . $userId : 'anon:' . $anonId;
        $variant = $this->pickVariant($variants, $subjectKey, $orgId, $experimentKey);
        if ($variant === null) {
            return null;
        }

        if (Schema::hasTable('experiment_assignments')) {
            $now = now();
            DB::table('experiment_assignments')->updateOrInsert(
                [
                    'org_id' => $orgId,
                    'anon_id' => $anonId,
                    'experiment_key' => $experimentKey,
                ],
                [
                    'user_id' => $userId,
                    'variant' => $variant,
                    'assigned_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return $variant;
    }

    private function findExisting(string $experimentKey, int $orgId, ?int $userId, string $anonId): ?ExperimentAssignment
    {
        if ($userId !== null) {
            $row = ExperimentAssignment::query()
                ->where('org_id', $orgId)
                ->where('user_id', $userId)
                ->where('experiment_key', $experimentKey)
                ->orderBy('id')
                ->first();
            if ($row) {
                return $row;
            }
        }

        return ExperimentAssignment::query()
            ->where('org_id', $orgId)
            ->where('anon_id', $anonId)
            ->where('experiment_key', $experimentKey)
            ->orderBy('id')
            ->first();
    }

    private function pickVariant(array $variants, string $subjectKey, int $orgId, string $experimentKey): ?string
    {
        $total = 0;
        foreach ($variants as $weight) {
            if (is_numeric($weight)) {
                $w = (int) round((float) $weight);
                if ($w > 0) {
                    $total += $w;
                }
            }
        }

        if ($total <= 0) {
            return null;
        }

        $bucket = StableBucket::bucket($subjectKey . '|' . $orgId . '|' . $experimentKey . '|' . $this->salt, 100);
        $target = $bucket % $total;

        $cursor = 0;
        foreach ($variants as $variant => $weight) {
            if (!is_numeric($weight)) {
                continue;
            }
            $w = (int) round((float) $weight);
            if ($w <= 0) {
                continue;
            }
            $cursor += $w;
            if ($target < $cursor) {
                return (string) $variant;
            }
        }

        return null;
    }
}
