<?php

declare(strict_types=1);

namespace App\Domain\Career\Production;

use App\DTO\Career\CareerAssetBatchManifest;

final class CareerAssetBatchValidator
{
    /**
     * @var array<string, true>
     */
    private const ALLOWED_CROSSWALK_MODES = [
        'exact' => true,
        'trust_inheritance' => true,
        'functional_equivalent' => true,
        'direct_match' => true,
        'family_proxy' => true,
        'local_heavy_interpretation' => true,
        'unmapped' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const ALLOWED_BATCH_KINDS = [
        CareerAssetBatchManifestBuilder::BATCH_KIND_1 => true,
        CareerAssetBatchManifestBuilder::BATCH_KIND_2 => true,
        CareerAssetBatchManifestBuilder::BATCH_KIND_3 => true,
        CareerAssetBatchManifestBuilder::BATCH_KIND_4 => true,
    ];

    /**
     * @var array<string, true>
     */
    private const ALLOWED_BATCH_ROLES = [
        'stable_seed' => true,
        'candidate_seed' => true,
        'hold_seed' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const ALLOWED_PUBLISH_TRACKS = [
        'stable' => true,
        'candidate' => true,
        'hold' => true,
        'explorer_only' => true,
        'review_needed' => true,
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PUBLISH_TRACKS = [
        'stable_seed' => ['stable'],
        'candidate_seed' => ['candidate'],
        'hold_seed' => ['hold', 'explorer_only', 'review_needed'],
    ];

    /**
     * @param  array<string, array<string, mixed>>  $truthBySlug
     * @return array<string, mixed>
     */
    public function validate(CareerAssetBatchManifest $manifest, array $truthBySlug): array
    {
        $seenSlugs = [];
        $seenIds = [];
        $members = [];

        foreach ($manifest->members as $member) {
            $errors = [];
            $warnings = [];
            $slug = $member->canonicalSlug;
            $occupationId = $member->occupationUuid;

            if (isset($seenSlugs[$slug])) {
                $errors[] = 'duplicate_canonical_slug';
            }
            if (isset($seenIds[$occupationId])) {
                $errors[] = 'duplicate_occupation_uuid';
            }

            $seenSlugs[$slug] = true;
            $seenIds[$occupationId] = true;

            if (! isset(self::ALLOWED_CROSSWALK_MODES[$member->crosswalkMode])) {
                $errors[] = 'unsupported_crosswalk_mode';
            }

            if (! isset(self::ALLOWED_BATCH_ROLES[$member->batchRole])) {
                $errors[] = 'unsupported_batch_role';
            }

            if (! isset(self::ALLOWED_PUBLISH_TRACKS[$member->expectedPublishTrack])) {
                $errors[] = 'unsupported_expected_publish_track';
            }

            if ($member->batchRole === 'stable_seed' && ! $member->stableSeed) {
                $errors[] = 'stable_seed_role_mismatch';
            }
            if ($member->batchRole === 'candidate_seed' && ! $member->candidateSeed) {
                $errors[] = 'candidate_seed_role_mismatch';
            }
            if ($member->batchRole === 'hold_seed' && ! $member->holdSeed) {
                $errors[] = 'hold_seed_role_mismatch';
            }
            if ($member->batchRole === 'stable_seed' && ($member->candidateSeed || $member->holdSeed)) {
                $errors[] = 'seed_track_must_be_exclusive';
            }
            if ($member->batchRole === 'candidate_seed' && ($member->stableSeed || $member->holdSeed)) {
                $errors[] = 'seed_track_must_be_exclusive';
            }
            if ($member->batchRole === 'hold_seed' && ($member->stableSeed || $member->candidateSeed)) {
                $errors[] = 'seed_track_must_be_exclusive';
            }
            if (
                isset(self::ROLE_PUBLISH_TRACKS[$member->batchRole])
                && ! in_array($member->expectedPublishTrack, self::ROLE_PUBLISH_TRACKS[$member->batchRole], true)
            ) {
                $errors[] = 'expected_publish_track_role_mismatch';
            }

            if (! isset($truthBySlug[$slug])) {
                $warnings[] = 'missing_backend_truth';
            }

            $members[] = [
                'canonical_slug' => $slug,
                'valid' => $errors === [],
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $errors = [];
        if (! isset(self::ALLOWED_BATCH_KINDS[$manifest->batchKind])) {
            $errors[] = 'unsupported_batch_kind';
        }
        if ($manifest->batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_1 && $manifest->memberCount !== 10) {
            $errors[] = 'batch_1_member_count_must_equal_10';
        }
        if ($manifest->batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_2 && $manifest->memberCount !== 30) {
            $errors[] = 'batch_2_member_count_must_equal_30';
        }
        if ($manifest->batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_3 && $manifest->memberCount !== 80) {
            $errors[] = 'batch_3_member_count_must_equal_80';
        }
        if ($manifest->batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_4 && $manifest->memberCount !== 222) {
            $errors[] = 'batch_4_member_count_must_equal_222';
        }

        return [
            'stage' => 'validate',
            'passed' => $errors === [] && collect($members)->every(static fn (array $row): bool => (bool) $row['valid']),
            'errors' => $errors,
            'counts' => [
                'total' => count($members),
                'valid' => collect($members)->where('valid', true)->count(),
                'invalid' => collect($members)->where('valid', false)->count(),
                'warnings' => collect($members)->sum(static fn (array $row): int => count((array) ($row['warnings'] ?? []))),
            ],
            'members' => $members,
        ];
    }
}
