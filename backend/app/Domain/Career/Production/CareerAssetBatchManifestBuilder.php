<?php

declare(strict_types=1);

namespace App\Domain\Career\Production;

use App\DTO\Career\CareerAssetBatchManifest;
use App\DTO\Career\CareerAssetBatchManifestMember;
use RuntimeException;

final class CareerAssetBatchManifestBuilder
{
    public const BATCH_KIND_1 = 'career_asset_batch_1';

    public const BATCH_KIND_2 = 'career_asset_batch_2';

    public function fromPath(string $path): CareerAssetBatchManifest
    {
        $resolved = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! is_file($resolved)) {
            throw new RuntimeException(sprintf('Career asset batch manifest not found at [%s].', $resolved));
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Career asset batch manifest must be valid JSON: [%s].', $resolved));
        }

        $members = $decoded['members'] ?? null;
        if (! is_array($members)) {
            throw new RuntimeException('Career asset batch manifest must contain a members array.');
        }

        $batchKind = $this->requiredString($decoded, 'batch_kind');
        $batchVersion = $this->requiredString($decoded, 'batch_version');
        $batchKey = $this->requiredString($decoded, 'batch_key');
        $scope = $this->requiredString($decoded, 'scope');
        $memberCount = (int) ($decoded['member_count'] ?? 0);

        if ($memberCount < 1) {
            throw new RuntimeException('Career asset batch manifest member_count must be greater than zero.');
        }

        if ($memberCount !== count($members)) {
            throw new RuntimeException('Career asset batch manifest member_count must equal members length.');
        }

        $normalizedMembers = array_map(
            fn (mixed $row): CareerAssetBatchManifestMember => $this->normalizeMember($row),
            $members,
        );

        return new CareerAssetBatchManifest(
            batchKind: $batchKind,
            batchVersion: $batchVersion,
            batchKey: $batchKey,
            scope: $scope,
            memberCount: $memberCount,
            members: $normalizedMembers,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        $value = trim((string) ($payload[$field] ?? ''));
        if ($value === '') {
            throw new RuntimeException(sprintf('Career asset batch manifest missing required field [%s].', $field));
        }

        return $value;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private function normalizeMember(mixed $row): CareerAssetBatchManifestMember
    {
        if (! is_array($row)) {
            throw new RuntimeException('Career asset batch manifest member rows must be objects.');
        }

        return new CareerAssetBatchManifestMember(
            occupationUuid: $this->requiredString($row, 'occupation_uuid'),
            canonicalSlug: $this->requiredString($row, 'canonical_slug'),
            canonicalTitleEn: $this->requiredString($row, 'canonical_title_en'),
            familySlug: $this->requiredString($row, 'family_slug'),
            crosswalkMode: $this->requiredString($row, 'crosswalk_mode'),
            batchRole: $this->requiredString($row, 'batch_role'),
            stableSeed: $this->toBool($row['stable_seed'] ?? false),
            candidateSeed: $this->toBool($row['candidate_seed'] ?? false),
            holdSeed: $this->toBool($row['hold_seed'] ?? false),
            expectedPublishTrack: $this->requiredString($row, 'expected_publish_track'),
        );
    }
}
