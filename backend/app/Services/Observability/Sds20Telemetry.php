<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Models\Attempt;
use App\Services\Analytics\EventRecorder;

final class Sds20Telemetry implements ScaleTelemetry
{
    public function __construct(
        private readonly EventRecorder $events,
    ) {}

    public function attemptStarted(Attempt $attempt, array $meta = []): void
    {
        $this->emit('attempt_started', $attempt, array_merge([
            'locale' => (string) ($attempt->locale ?? ''),
            'region' => (string) ($attempt->region ?? ''),
            'variant' => 'free',
            'locked' => true,
        ], $meta));
    }

    public function attemptSubmitted(Attempt $attempt, array $meta = []): void
    {
        $this->emit('submitted', $attempt, $meta);
    }

    public function attemptScored(Attempt $attempt, array $scoreDto = []): void
    {
        $quality = $this->extractArrayNode($scoreDto, [
            ['quality'],
            ['normed_json', 'quality'],
            ['result', 'quality'],
        ]);
        $versionSnapshot = $this->extractArrayNode($scoreDto, [
            ['version_snapshot'],
            ['normed_json', 'version_snapshot'],
            ['result', 'version_snapshot'],
        ]);
        $norms = $this->extractArrayNode($scoreDto, [
            ['norms'],
            ['normed_json', 'norms'],
            ['result', 'norms'],
        ]);

        $this->emit('scored', $attempt, [
            'quality_level' => strtoupper(trim((string) ($quality['level'] ?? 'D'))),
            'quality_flags' => array_values(array_filter(array_map('strval', (array) ($quality['flags'] ?? [])))),
            'crisis_alert' => (bool) ($quality['crisis_alert'] ?? false),
            'policy_hash' => trim((string) (
                $versionSnapshot['policy_hash']
                ?? data_get($scoreDto, 'policy_hash', '')
            )),
            'engine_version' => trim((string) (
                $versionSnapshot['engine_version']
                ?? data_get($scoreDto, 'engine_version', '')
            )),
            'manifest_hash' => trim((string) (
                $versionSnapshot['content_manifest_hash']
                ?? data_get($scoreDto, 'content_manifest_hash', '')
            )),
            'pack_version' => trim((string) (
                $versionSnapshot['pack_version']
                ?? $attempt->dir_version
                ?? ''
            )),
            'norms_status' => strtoupper(trim((string) ($norms['status'] ?? 'MISSING'))),
            'norms_version' => trim((string) ($norms['norms_version'] ?? '')),
            'norm_group_id' => trim((string) ($norms['group_id'] ?? '')),
        ]);
    }

    public function reportViewed(Attempt $attempt, array $meta = []): void
    {
        $this->emit('report_viewed', $attempt, $meta);
    }

    public function unlocked(Attempt $attempt, array $meta = []): void
    {
        $this->emit('unlocked', $attempt, $meta);
    }

    public function crisisTriggered(Attempt $attempt, array $meta = []): void
    {
        $this->emit('crisis_triggered', $attempt, array_merge([
            'crisis_alert' => true,
        ], $meta));
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function emit(string $suffix, Attempt $attempt, array $meta): void
    {
        $attemptId = (string) $attempt->id;
        $anonId = $attempt->anon_id !== null ? trim((string) $attempt->anon_id) : '';
        $packId = trim((string) ($attempt->pack_id ?? ''));
        $dirVersion = trim((string) ($attempt->dir_version ?? ''));
        $attemptMeta = $this->attemptMeta($attempt);

        $eventCode = 'sds_20_'.$suffix;
        $normalizedMeta = array_merge([
            'scale_code' => 'SDS_20',
            'attempt_id' => $attemptId,
            'pack_id' => $packId !== '' ? $packId : 'SDS_20',
            'pack_version' => $dirVersion,
            'manifest_hash' => trim((string) ($attemptMeta['pack_release_manifest_hash'] ?? '')),
            'policy_hash' => trim((string) ($attemptMeta['policy_hash'] ?? '')),
            'engine_version' => trim((string) ($attemptMeta['engine_version'] ?? '')),
            'locale' => (string) ($attempt->locale ?? ''),
            'region' => (string) ($attempt->region ?? ''),
        ], $meta);

        unset(
            $normalizedMeta['answers'],
            $normalizedMeta['answers_raw'],
            $normalizedMeta['answers_map'],
            $normalizedMeta['item_answers']
        );

        $this->events->record(
            $eventCode,
            $this->resolveUserId($attempt),
            $normalizedMeta,
            [
                'org_id' => (int) ($attempt->org_id ?? 0),
                'anon_id' => $anonId !== '' ? $anonId : null,
                'attempt_id' => $attemptId,
                'pack_id' => $packId !== '' ? $packId : null,
                'dir_version' => $dirVersion !== '' ? $dirVersion : null,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function attemptMeta(Attempt $attempt): array
    {
        $summary = is_array($attempt->answers_summary_json ?? null) ? $attempt->answers_summary_json : [];
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];

        return $meta;
    }

    private function resolveUserId(Attempt $attempt): ?int
    {
        $userId = trim((string) ($attempt->user_id ?? ''));
        if ($userId === '' || preg_match('/^\d+$/', $userId) !== 1) {
            return null;
        }

        return (int) $userId;
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  list<list<string>>  $paths
     * @return array<string,mixed>
     */
    private function extractArrayNode(array $source, array $paths): array
    {
        foreach ($paths as $path) {
            $value = data_get($source, implode('.', $path));
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }
}
