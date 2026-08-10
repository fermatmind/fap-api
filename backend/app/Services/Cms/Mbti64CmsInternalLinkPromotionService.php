<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\DTO\ReviewGovernance\ReviewTargetSet;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Models\ReviewAttestation;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @review-surface mbti_approval_batch */
final class Mbti64CmsInternalLinkPromotionService
{
    private const INTP_A_SEO_EXPERIMENT_SCHEMA_VERSION = 'personality.mbti-seo-title-experiment.v1';

    private const INTP_A_SEO_EXPERIMENT_ID = 'zh-intp-a-seo-title-20260810-v1';

    public const ARTIFACT = 'PER02-MBTI-EN64-REVISION-BOUND-INTERNAL-LINK-PROMOTION-V1';

    private const SNAPSHOT_KEY = 'mbti64_internal_link_graph_v1';

    private const REVIEW_SURFACE = 'mbti_approval_batch';

    private const SECTION_KEY = 'mbti_content15_internal_links';

    private const CHECKPOINT112_INVENTORY_SHA256 = 'e18dd567a2826678f16fd06cd1de976a7831dd6ab505b75e213abf51ae257908';

    private const CHECKPOINT112_SECTION_INVENTORY_SHA256 = 'b595b38be3fc50c9ee69cda1749644197207af13c6b90e5c1fd480370bff813d';

    private const REQUIRED_ROLES = [
        'variant_at_pair',
        'variant_to_comparison',
    ];

    private const ALLOWED_LINK_KEYS = [
        'anchor_text',
        'href',
        'priority',
        'reason',
        'role',
        'safe_public_route',
        'source',
    ];

    private const FORBIDDEN_ROUTE_PATTERN = '#/(?:results|orders|share|pay|payment|history|private|account)(?:/|$)|(?:[?&](?:token|session|user|result_id|report_id|order_no)=)#i';

    public function __construct(
        private readonly PersonalityReviewAttestationService $reviewAttestations,
        private readonly ReviewAttestationCanonicalizer $canonicalizer,
        private readonly PersonalityPublicReadModelCache $readModelCache,
    ) {}

    /**
     * @param  array<string,string|int>  $options
     * @return array<string,mixed>
     */
    public function plan(array $options): array
    {
        $this->assertRuntime($options);
        $inventory = $this->inventory($options, false);

        return $this->summary($inventory, $options, [
            'action' => 'would_promote_exact_32_sections',
            'dry_run' => true,
            'write' => false,
            'writes_committed' => false,
        ]);
    }

    /**
     * @param  array<string,string|int>  $options
     * @param  array<string,mixed>  $attestation
     * @return array<string,mixed>
     */
    public function bindReview(array $options, array $attestation, int $actorAdminUserId): array
    {
        $this->assertRuntime($options);
        $expectedEvidenceSha = $this->requiredHash(
            $options,
            'expected_review_evidence_sha256'
        );
        if (! hash_equals($expectedEvidenceSha, (string) ($attestation['evidence_sha256'] ?? ''))) {
            throw new RuntimeException('The exact review evidence SHA256 does not match.');
        }

        [$inventory, $record] = DB::transaction(function () use (
            $options,
            $attestation,
            $actorAdminUserId,
        ): array {
            $inventory = $this->inventory($options, true);
            $this->assertAllSectionsAbsent($inventory);
            $record = $this->reviewAttestations->bindApproved(
                attestation: $attestation,
                surfaceId: self::REVIEW_SURFACE,
                authoritativeTargets: $inventory['review_targets'],
                actorAdminUserId: $actorAdminUserId,
                expectedPackageSha256: $inventory['revision_identity_sha256'],
            );

            return [$inventory, $record];
        }, 3);
        $record->loadMissing('targetEvidences');
        if ((int) $record->target_count !== 32 || $record->targetEvidences->count() !== 32) {
            throw new RuntimeException('Bound review evidence readback did not contain exactly 32 targets.');
        }

        return $this->summary($inventory, $options, [
            'action' => $record->wasRecentlyCreated
                ? 'bound_exact_32_target_review'
                : 'skipped_existing_exact_review',
            'dry_run' => false,
            'bind_review' => true,
            'write' => false,
            'writes_committed' => $record->wasRecentlyCreated,
            'review_attestation_id' => (int) $record->id,
            'review_evidence_sha256' => (string) $record->evidence_sha256,
            'review_target_evidence_count' => $record->targetEvidences->count(),
        ]);
    }

    /**
     * @param  array<string,string|int>  $options
     * @return array<string,mixed>
     */
    public function promote(array $options): array
    {
        $this->assertRuntime($options);
        $preflight = $this->inventory($options, false, allowPromotedState: true);
        $this->assertApprovedReview($preflight, $options);
        $expectedAuthorization = $this->requiredHash(
            $options,
            'expected_promotion_authorization_sha256'
        );
        if (! hash_equals(
            $expectedAuthorization,
            $this->promotionAuthorizationSha($preflight, $options)
        )) {
            throw new RuntimeException('The exact promotion authorization SHA256 does not match.');
        }
        $result = DB::transaction(function () use ($options): array {
            $inventory = $this->inventory($options, true, allowPromotedState: true);
            $this->assertApprovedReview($inventory, $options);
            $sections = $this->sectionsForTargets($inventory['target_ids'], true);
            $this->assertSectionState($sections, $inventory);
            $beforeState = $this->publicState($inventory['variants'], true);

            if ($sections->count() === 0) {
                foreach ($inventory['rows'] as $row) {
                    PersonalityProfileVariantSection::query()->create(
                        $this->sectionAttributes($row)
                    );
                }
            }

            $liveSections = $this->sectionsForTargets($inventory['target_ids'], true);
            $receipt = $this->promotionReceipt($liveSections, $inventory);
            if ($liveSections->count() !== 32
                || $this->publicState($inventory['variants'], true) !== $beforeState) {
                throw new RuntimeException(
                    'Promotion transaction readback changed the exact row count or a public/indexability invariant.'
                );
            }

            return [
                'inventory' => $inventory,
                'receipt' => $receipt,
                'created_count' => $sections->count() === 0 ? 32 : 0,
            ];
        }, 3);

        $inventory = $result['inventory'];
        $receipt = $result['receipt'];
        $cache = $this->invalidateCaches($inventory['runtime_types']);

        return $this->summary($inventory, $options, [
            'action' => $result['created_count'] === 32
                ? 'promoted_exact_32_sections'
                : 'skipped_existing_exact_promotion',
            'dry_run' => false,
            'write' => true,
            'writes_committed' => $result['created_count'] === 32,
            'created_section_count' => $result['created_count'],
            'promotion_receipt' => $receipt,
            'promotion_receipt_sha256' => $receipt['receipt_sha256'],
            'rollback_authorization_sha256' => $this->rollbackAuthorizationSha(
                $inventory,
                $receipt['receipt_sha256'],
                $options
            ),
            'cache_closeout' => $cache,
            'ok' => $cache['invalidated_count'] === 32,
            'status' => $cache['invalidated_count'] === 32
                ? 'pass'
                : 'partial_cache_closeout',
        ]);
    }

    /**
     * @param  array<string,string|int>  $options
     * @return array<string,mixed>
     */
    public function rollback(array $options): array
    {
        $this->assertRuntime($options);
        $result = DB::transaction(function () use ($options): array {
            $inventory = $this->rollbackInventory($options);
            $sections = $this->sectionsForTargets($inventory['target_ids'], true);
            $this->assertFullyPromoted($sections, $inventory);
            $receipt = $this->promotionReceipt($sections, $inventory);
            $expectedReceipt = $this->requiredHash(
                $options,
                'expected_promotion_receipt_sha256'
            );
            if (! hash_equals($expectedReceipt, $receipt['receipt_sha256'])) {
                throw new RuntimeException('The exact promotion receipt SHA256 does not match.');
            }
            $expectedAuthorization = $this->requiredHash(
                $options,
                'expected_rollback_authorization_sha256'
            );
            if (! hash_equals(
                $expectedAuthorization,
                $this->rollbackAuthorizationSha(
                    $inventory,
                    $receipt['receipt_sha256'],
                    $options
                )
            )) {
                throw new RuntimeException('The exact rollback authorization SHA256 does not match.');
            }
            $beforeState = $this->publicState($inventory['variants'], true);
            $ids = $sections->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $deleted = PersonalityProfileVariantSection::query()
                ->whereIn('id', $ids)
                ->delete();
            if ($deleted !== 32
                || $this->sectionsForTargets($inventory['target_ids'], true)->count() !== 0
                || $this->publicState($inventory['variants'], true) !== $beforeState) {
                throw new RuntimeException(
                    'Rollback transaction readback changed the exact deletion count or a public/indexability invariant.'
                );
            }

            return ['inventory' => $inventory, 'receipt' => $receipt];
        }, 3);

        $cache = $this->invalidateCaches($result['inventory']['runtime_types']);

        return $this->summary($result['inventory'], $options, [
            'action' => 'rolled_back_exact_32_sections',
            'dry_run' => false,
            'rollback' => true,
            'write' => true,
            'writes_committed' => true,
            'deleted_section_count' => 32,
            'promotion_receipt_sha256' => $result['receipt']['receipt_sha256'],
            'cache_closeout' => $cache,
            'ok' => $cache['invalidated_count'] === 32,
            'status' => $cache['invalidated_count'] === 32
                ? 'pass'
                : 'partial_cache_closeout',
        ]);
    }

    /**
     * @param  array<string,string|int>  $options
     * @return array<string,mixed>
     */
    public function cacheCloseout(array $options): array
    {
        $this->assertRuntime($options);
        $state = (string) ($options['expected_live_state'] ?? '');
        if (! in_array($state, ['promoted', 'rolled_back'], true)) {
            throw new RuntimeException(
                '--expected-live-state must be promoted or rolled_back with --cache-closeout-only.'
            );
        }
        $inventory = $state === 'promoted'
            ? $this->rollbackInventory($options, false)
            : $this->rolledBackInventory($options);
        $sections = $this->sectionsForTargets($inventory['target_ids'], false);
        if ($state === 'promoted') {
            $this->assertFullyPromoted($sections, $inventory);
            $receipt = $this->promotionReceipt($sections, $inventory);
            if (! hash_equals(
                $this->requiredHash($options, 'expected_promotion_receipt_sha256'),
                $receipt['receipt_sha256']
            )) {
                throw new RuntimeException('The exact promotion receipt SHA256 does not match.');
            }
        } elseif ($sections->count() !== 0) {
            throw new RuntimeException('Rolled-back cache closeout requires zero target sections.');
        }
        $cache = $this->invalidateCaches($inventory['runtime_types']);

        return $this->summary($inventory, $options, [
            'action' => 'cache_closeout_only_'.$state,
            'dry_run' => false,
            'write' => false,
            'writes_committed' => false,
            'cache_closeout' => $cache,
            'ok' => $cache['invalidated_count'] === 32,
            'status' => $cache['invalidated_count'] === 32
                ? 'pass'
                : 'partial_cache_closeout',
        ]);
    }

    /**
     * @param  array<string,string|int>  $options
     * @return array<string,mixed>
     */
    private function inventory(
        array $options,
        bool $lock,
        bool $allowPromotedState = false,
    ): array {
        $graphSha = $this->requiredHash($options, 'expected_graph_sha256');
        $cohortSha = $this->requiredHash($options, 'expected_cohort_sha256');
        if (($options['expected_checkpoint112_inventory_sha256'] ?? null)
                !== self::CHECKPOINT112_INVENTORY_SHA256
            || ($options['expected_section_inventory_sha256'] ?? null)
                !== self::CHECKPOINT112_SECTION_INVENTORY_SHA256
            || (int) ($options['expected_rows'] ?? 0) !== 32
            || (int) ($options['expected_edges'] ?? 0) !== 64) {
            throw new RuntimeException(
                'Checkpoint 112 inventory, section inventory or exact 32/64 boundary does not match.'
            );
        }

        $variants = $this->publicVariants($lock);

        $rows = [];
        foreach ($variants as $variant) {
            $revisionQuery = PersonalityProfileVariantRevision::query()
                ->where('personality_profile_variant_id', (int) $variant->id)
                ->orderByDesc('revision_no')
                ->orderByDesc('id');
            if ($lock) {
                $revisionQuery->lockForUpdate();
            }
            $latest = $revisionQuery->get()->first(function ($revision): bool {
                $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];

                return ! $this->isIntpASeoExperimentRevision($snapshot);
            });
            $snapshot = $latest instanceof PersonalityProfileVariantRevision
                && is_array($latest->snapshot_json)
                ? $latest->snapshot_json
                : [];
            $node = data_get($snapshot, self::SNAPSHOT_KEY);
            $source = is_array($node) ? data_get($node, 'source') : null;
            $links = is_array($node)
                ? data_get($node, 'first_class_draft_fields.internal_links')
                : null;
            if (! $latest instanceof PersonalityProfileVariantRevision
                || ! is_array($node)
                || ! is_array($source)
                || ! is_array($links)
                || ($source['source_sha256'] ?? null) !== $graphSha
                || ($source['cohort_payload_sha256'] ?? null) !== $cohortSha) {
                throw new RuntimeException(
                    'Every target must retain the exact bounded graph/cohort revision as its latest revision.'
                );
            }
            $runtimeType = strtoupper((string) $variant->runtime_type_code);
            $this->assertLinks($runtimeType, $links);
            $snapshotSha = $this->rawJsonSha($snapshot);
            $linksSha = $this->rawJsonSha($links);
            $identity = [
                'runtime_type_code' => $runtimeType,
                'target_id' => (int) $variant->id,
                'revision_id' => (int) $latest->id,
                'revision_no' => (int) $latest->revision_no,
                'snapshot_sha256' => $snapshotSha,
                'internal_links_sha256' => $linksSha,
            ];
            $rows[] = array_merge($identity, [
                'target_sha256' => $this->canonicalSha($identity),
                'links' => array_values($links),
            ]);
        }
        usort(
            $rows,
            static fn (array $left, array $right): int => $left['runtime_type_code']
                <=> $right['runtime_type_code']
        );
        $revisionIdentity = $this->canonicalSha(array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip([
                'runtime_type_code',
                'target_id',
                'revision_id',
                'revision_no',
                'snapshot_sha256',
                'internal_links_sha256',
            ])),
            $rows
        ));
        if (! hash_equals(
            $this->requiredHash($options, 'expected_revision_identity_sha256'),
            $revisionIdentity
        )) {
            throw new RuntimeException('The exact live revision identity SHA256 does not match.');
        }

        $targetIds = array_column($rows, 'target_id');
        $sections = $this->sectionsForTargets($targetIds, $lock);
        $markers = $this->rollbackMarkers($rows);
        $rollbackMarkersSha = $this->canonicalSha($markers);
        if (! $allowPromotedState && $sections->count() !== 0) {
            throw new RuntimeException('The exact target-section baseline must remain empty.');
        }
        if (! hash_equals(
            $this->requiredHash($options, 'expected_rollback_markers_sha256'),
            $rollbackMarkersSha
        )) {
            throw new RuntimeException('The exact rollback absence markers SHA256 does not match.');
        }

        return [
            'rows' => $rows,
            'variants' => $variants,
            'target_ids' => $targetIds,
            'runtime_types' => array_column($rows, 'runtime_type_code'),
            'review_targets' => array_map(
                static fn (array $row): array => [
                    'identity' => sprintf(
                        'mbti_en64_internal_link_revision:%s:target:%d:revision:%d',
                        $row['runtime_type_code'],
                        $row['target_id'],
                        $row['revision_id']
                    ),
                    'sha256' => $row['target_sha256'],
                ],
                $rows
            ),
            'revision_identity_sha256' => $revisionIdentity,
            'rollback_markers_sha256' => $rollbackMarkersSha,
            'graph_sha256' => $graphSha,
            'cohort_sha256' => $cohortSha,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function isIntpASeoExperimentRevision(array $snapshot): bool
    {
        return ($snapshot['schema_version'] ?? null) === self::INTP_A_SEO_EXPERIMENT_SCHEMA_VERSION
            && ($snapshot['experiment_id'] ?? null) === self::INTP_A_SEO_EXPERIMENT_ID;
    }

    /**
     * @return Collection<int,PersonalityProfileVariant>
     */
    private function publicVariants(bool $lock): Collection
    {
        $variantQuery = PersonalityProfileVariant::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('is_published', true)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->whereHas('profile', static fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                ->where('locale', 'en')
                ->publishedPublic()
                ->where(static function ($nested): void {
                    $nested->whereNull('published_at')
                        ->orWhere('published_at', '<=', now());
                }))
            ->with(['profile' => static fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('runtime_type_code');
        if ($lock) {
            $variantQuery->lockForUpdate();
        }
        /** @var Collection<int,PersonalityProfileVariant> $variants */
        $variants = $variantQuery->get();
        $variants = $variants
            ->filter(static fn (PersonalityProfileVariant $variant): bool => preg_match(
                '/^[EI][SN][TF][JP]-[AT]$/',
                (string) $variant->runtime_type_code
            ) === 1)
            ->values();
        if ($variants->count() !== 32) {
            throw new RuntimeException('Exactly 32 English MBTI A/T variant targets are required.');
        }
        foreach ($variants as $variant) {
            $this->assertPublicVariantParentIdentity($variant);
        }

        return $variants;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function rollbackMarkers(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'runtime_type_code' => $row['runtime_type_code'],
                'target_id' => $row['target_id'],
                'section_exists' => false,
                'section_id' => null,
                'section_sha256' => null,
            ],
            $rows
        );
    }

    /**
     * @param  array<string,string|int>  $options
     * @return array<string,mixed>
     */
    private function rollbackInventory(array $options, bool $lock = true): array
    {
        $graphSha = $this->requiredHash($options, 'expected_graph_sha256');
        $cohortSha = $this->requiredHash($options, 'expected_cohort_sha256');
        if (($options['expected_checkpoint112_inventory_sha256'] ?? null)
                !== self::CHECKPOINT112_INVENTORY_SHA256
            || ($options['expected_section_inventory_sha256'] ?? null)
                !== self::CHECKPOINT112_SECTION_INVENTORY_SHA256
            || (int) ($options['expected_rows'] ?? 0) !== 32
            || (int) ($options['expected_edges'] ?? 0) !== 64) {
            throw new RuntimeException(
                'Checkpoint 112 inventory, section inventory or exact 32/64 boundary does not match.'
            );
        }
        $variants = $this->publicVariants($lock);
        $targetIds = $variants->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $sections = $this->sectionsForTargets($targetIds, $lock);
        if ($sections->count() !== 32) {
            throw new RuntimeException('Exactly 32 promoted target sections are required.');
        }
        $sectionsByTarget = $sections->keyBy('personality_profile_variant_id');
        $rows = [];
        foreach ($variants as $variant) {
            $section = $sectionsByTarget->get((int) $variant->id);
            $links = $section instanceof PersonalityProfileVariantSection
                ? data_get($section->payload_json, 'items')
                : null;
            if (! is_array($links)) {
                throw new RuntimeException('A receipt-bound target section has no exact link payload.');
            }
            $runtimeType = strtoupper((string) $variant->runtime_type_code);
            $this->assertLinks($runtimeType, $links);
            $rows[] = [
                'runtime_type_code' => $runtimeType,
                'target_id' => (int) $variant->id,
                'links' => array_values($links),
            ];
        }
        usort(
            $rows,
            static fn (array $left, array $right): int => $left['runtime_type_code']
                <=> $right['runtime_type_code']
        );
        $rollbackMarkersSha = $this->canonicalSha($this->rollbackMarkers($rows));
        if (! hash_equals(
            $this->requiredHash($options, 'expected_rollback_markers_sha256'),
            $rollbackMarkersSha
        )) {
            throw new RuntimeException('The exact rollback absence markers SHA256 does not match.');
        }

        return [
            'rows' => $rows,
            'variants' => $variants,
            'target_ids' => $targetIds,
            'runtime_types' => array_column($rows, 'runtime_type_code'),
            'review_targets' => [],
            'revision_identity_sha256' => $this->requiredHash(
                $options,
                'expected_revision_identity_sha256'
            ),
            'rollback_markers_sha256' => $rollbackMarkersSha,
            'graph_sha256' => $graphSha,
            'cohort_sha256' => $cohortSha,
        ];
    }

    /**
     * @param  array<string,string|int>  $options
     * @return array<string,mixed>
     */
    private function rolledBackInventory(array $options): array
    {
        $graphSha = $this->requiredHash($options, 'expected_graph_sha256');
        $cohortSha = $this->requiredHash($options, 'expected_cohort_sha256');
        if (($options['expected_checkpoint112_inventory_sha256'] ?? null)
                !== self::CHECKPOINT112_INVENTORY_SHA256
            || ($options['expected_section_inventory_sha256'] ?? null)
                !== self::CHECKPOINT112_SECTION_INVENTORY_SHA256
            || (int) ($options['expected_rows'] ?? 0) !== 32
            || (int) ($options['expected_edges'] ?? 0) !== 64) {
            throw new RuntimeException(
                'Checkpoint 112 inventory, section inventory or exact 32/64 boundary does not match.'
            );
        }
        $variants = $this->publicVariants(false);
        $rows = $variants->map(static fn (
            PersonalityProfileVariant $variant
        ): array => [
            'runtime_type_code' => strtoupper((string) $variant->runtime_type_code),
            'target_id' => (int) $variant->id,
        ])->values()->all();
        $targetIds = array_column($rows, 'target_id');
        if ($this->sectionsForTargets($targetIds, false)->count() !== 0) {
            throw new RuntimeException('Rolled-back cache closeout requires zero target sections.');
        }
        $rollbackMarkersSha = $this->canonicalSha($this->rollbackMarkers($rows));
        if (! hash_equals(
            $this->requiredHash($options, 'expected_rollback_markers_sha256'),
            $rollbackMarkersSha
        )) {
            throw new RuntimeException('The exact rollback absence markers SHA256 does not match.');
        }

        return [
            'rows' => $rows,
            'variants' => $variants,
            'target_ids' => $targetIds,
            'runtime_types' => array_column($rows, 'runtime_type_code'),
            'review_targets' => [],
            'revision_identity_sha256' => $this->requiredHash(
                $options,
                'expected_revision_identity_sha256'
            ),
            'rollback_markers_sha256' => $rollbackMarkersSha,
            'graph_sha256' => $graphSha,
            'cohort_sha256' => $cohortSha,
        ];
    }

    /**
     * @param  list<mixed>  $links
     */
    private function assertLinks(string $runtimeType, array $links): void
    {
        if (count($links) !== 2 || ! str_ends_with($runtimeType, '-A')
            && ! str_ends_with($runtimeType, '-T')) {
            throw new RuntimeException('Every exact variant revision must contain two internal links.');
        }
        $baseType = substr($runtimeType, 0, 4);
        $roles = array_column($links, 'role');
        if ($roles !== self::REQUIRED_ROLES) {
            throw new RuntimeException('Every exact revision must contain the two required link roles in order.');
        }
        $expectedCopy = [
            'Compare '.$baseType.'-A and '.$baseType.'-T',
            $baseType.'-A vs '.$baseType.'-T',
        ];
        $expectedHrefs = [
            '/en/personality/'.strtolower($baseType).'-'.(
                str_ends_with($runtimeType, '-A') ? 't' : 'a'
            ),
            '/en/personality/'.strtolower($baseType).'-a-vs-'.strtolower($baseType).'-t',
        ];
        foreach ($links as $index => $link) {
            if (! is_array($link)
                || array_diff(array_keys($link), self::ALLOWED_LINK_KEYS) !== []) {
                throw new RuntimeException(
                    'An exact internal link contains unsafe or unapproved copy/route data.'
                );
            }
            $href = (string) ($link['href'] ?? '');
            if (($link['anchor_text'] ?? null) !== $expectedCopy[$index]
                || $href !== $expectedHrefs[$index]
                || ($link['safe_public_route'] ?? null) !== true
                || ! str_starts_with($href, '/en/personality/')
                || preg_match(self::FORBIDDEN_ROUTE_PATTERN, $href) === 1
                || str_contains($href, '?')) {
                throw new RuntimeException('An exact internal link contains unsafe or unapproved copy/route data.');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @param  array<string,string|int>  $options
     */
    private function assertApprovedReview(array $inventory, array $options): void
    {
        $this->reviewAttestations->assertApprovedEvidence(
            self::REVIEW_SURFACE,
            $inventory['review_targets']
        );
        $targetSet = ReviewTargetSet::fromArray(
            $this->reviewAttestations->targets(
                self::REVIEW_SURFACE,
                $inventory['review_targets']
            ),
            $this->canonicalizer,
        );
        $evidenceSha = $this->requiredHash($options, 'expected_review_evidence_sha256');
        $currentOwnerAdminUserId = (int) config(
            'review_governance.solo_owner_admin_user_id'
        );
        $exists = ReviewAttestation::query()
            ->where('evidence_sha256', $evidenceSha)
            ->where('review_mode', 'solo_owner')
            ->where(
                'review_source',
                (string) config('review_governance.attestation.review_source')
            )
            ->where('attested_by_admin_user_id', $currentOwnerAdminUserId)
            ->where('decision', 'approved_all')
            ->where('target_count', 32)
            ->where('target_set_sha256', $targetSet->sha256)
            ->where('package_sha256', $inventory['revision_identity_sha256'])
            ->whereHas('targetEvidences', static function ($query) use ($targetSet): void {
                $query->where('target_decision', 'approved')
                    ->where(static function ($query) use ($targetSet): void {
                        foreach ($targetSet->targets as $target) {
                            $query->orWhere(static function ($query) use ($target): void {
                                $query->where('target_identity', $target->identity)
                                    ->where('target_sha256', $target->sha256);
                            });
                        }
                    });
            }, '=', $targetSet->count())
            ->has('targetEvidences', '=', 32)
            ->exists();
        if (! $exists) {
            throw new RuntimeException(
                'The exact approved 32-target revision-bound review evidence is missing or stale.'
            );
        }
    }

    /**
     * @param  Collection<int,PersonalityProfileVariantSection>  $sections
     * @param  array<string,mixed>  $inventory
     */
    private function assertSectionState(Collection $sections, array $inventory): void
    {
        if ($sections->count() === 0) {
            return;
        }
        if ($sections->count() !== 32) {
            throw new RuntimeException('A partial target-section state is forbidden.');
        }
        $this->assertFullyPromoted($sections, $inventory);
    }

    /**
     * @param  array<string,mixed>  $inventory
     */
    private function assertAllSectionsAbsent(array $inventory): void
    {
        if ($this->sectionsForTargets($inventory['target_ids'], true)->count() !== 0) {
            throw new RuntimeException(
                'Review binding requires the exact 32-target public section baseline to remain empty.'
            );
        }
    }

    /**
     * @param  Collection<int,PersonalityProfileVariantSection>  $sections
     * @param  array<string,mixed>  $inventory
     */
    private function assertFullyPromoted(Collection $sections, array $inventory): void
    {
        if ($sections->count() !== 32) {
            throw new RuntimeException('Exactly 32 promoted target sections are required.');
        }
        $byTarget = $sections->keyBy('personality_profile_variant_id');
        foreach ($inventory['rows'] as $row) {
            $section = $byTarget->get($row['target_id']);
            if (! $section instanceof PersonalityProfileVariantSection
                || ! hash_equals(
                    $this->canonicalSha($this->expectedSectionComparable($row)),
                    $this->canonicalSha($this->sectionComparable($section))
                )) {
                throw new RuntimeException('A promoted target section is missing, extra or drifted.');
            }
        }
    }

    /**
     * @param  list<int>  $targetIds
     * @return Collection<int,PersonalityProfileVariantSection>
     */
    private function sectionsForTargets(array $targetIds, bool $lock): Collection
    {
        $query = PersonalityProfileVariantSection::query()
            ->withoutGlobalScopes()
            ->whereIn('personality_profile_variant_id', $targetIds)
            ->where('section_key', self::SECTION_KEY)
            ->orderBy('personality_profile_variant_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function sectionAttributes(array $row): array
    {
        return [
            'org_id' => 0,
            'personality_profile_variant_id' => (int) $row['target_id'],
            'section_key' => self::SECTION_KEY,
            'render_variant' => 'links',
            'body_md' => null,
            'body_html' => null,
            'payload_json' => ['items' => $this->publicLinks($row['links'])],
            'sort_order' => 981,
            'is_enabled' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function expectedSectionComparable(array $row): array
    {
        return array_diff_key(
            $this->sectionAttributes($row),
            ['personality_profile_variant_id' => true]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function sectionComparable(PersonalityProfileVariantSection $section): array
    {
        return [
            'org_id' => (int) $section->org_id,
            'section_key' => (string) $section->section_key,
            'render_variant' => (string) $section->render_variant,
            'body_md' => $section->body_md,
            'body_html' => $section->body_html,
            'payload_json' => $section->payload_json,
            'sort_order' => (int) $section->sort_order,
            'is_enabled' => (bool) $section->is_enabled,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $links
     * @return list<array<string,mixed>>
     */
    private function publicLinks(array $links): array
    {
        return array_map(
            static fn (array $link): array => [
                'href' => (string) $link['href'],
                'anchor_text' => (string) $link['anchor_text'],
                'role' => (string) $link['role'],
                'safe_public_route' => true,
            ],
            $links
        );
    }

    /**
     * @param  Collection<int,PersonalityProfileVariantSection>  $sections
     * @param  array<string,mixed>  $inventory
     * @return array<string,mixed>
     */
    private function promotionReceipt(Collection $sections, array $inventory): array
    {
        $rows = $sections->map(fn (PersonalityProfileVariantSection $section): array => [
            'section_id' => (int) $section->id,
            'target_id' => (int) $section->personality_profile_variant_id,
            'section_sha256' => $this->canonicalSha($this->sectionComparable($section)),
        ])->sortBy('target_id')->values()->all();
        $receipt = [
            'schema_version' => 'per02-mbti-en64-promotion-receipt.v1',
            'graph_sha256' => $inventory['graph_sha256'],
            'cohort_sha256' => $inventory['cohort_sha256'],
            'checkpoint112_inventory_sha256' => self::CHECKPOINT112_INVENTORY_SHA256,
            'revision_identity_sha256' => $inventory['revision_identity_sha256'],
            'section_inventory_sha256' => self::CHECKPOINT112_SECTION_INVENTORY_SHA256,
            'rollback_markers_sha256' => $inventory['rollback_markers_sha256'],
            'section_count' => count($rows),
            'rows' => $rows,
        ];
        $receipt['receipt_sha256'] = $this->canonicalSha($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @param  array<string,string|int>  $options
     */
    private function promotionAuthorizationSha(array $inventory, array $options): string
    {
        return $this->canonicalSha([
            'schema_version' => 'per02-mbti-en64-promotion-authorization.v1',
            'deployed_sha' => (string) $options['confirm_writer_deploy_sha'],
            'release' => (string) $options['confirm_release'],
            'checkpoint112_inventory_sha256' => self::CHECKPOINT112_INVENTORY_SHA256,
            'revision_identity_sha256' => $inventory['revision_identity_sha256'],
            'section_inventory_sha256' => self::CHECKPOINT112_SECTION_INVENTORY_SHA256,
            'rollback_markers_sha256' => $inventory['rollback_markers_sha256'],
            'review_evidence_sha256' => (string) $options['expected_review_evidence_sha256'],
            'rows' => 32,
            'edges' => 64,
            'mutations' => [
                'public_content_sections' => true,
                'cache_generation' => true,
                'publication' => false,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'search' => false,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $inventory
     */
    private function rollbackAuthorizationSha(
        array $inventory,
        string $receiptSha,
        array $options,
    ): string {
        return $this->canonicalSha([
            'schema_version' => 'per02-mbti-en64-rollback-authorization.v1',
            'deployed_sha' => (string) $options['confirm_writer_deploy_sha'],
            'release' => (string) $options['confirm_release'],
            'checkpoint112_inventory_sha256' => self::CHECKPOINT112_INVENTORY_SHA256,
            'revision_identity_sha256' => $inventory['revision_identity_sha256'],
            'section_inventory_sha256' => self::CHECKPOINT112_SECTION_INVENTORY_SHA256,
            'rollback_markers_sha256' => $inventory['rollback_markers_sha256'],
            'promotion_receipt_sha256' => $receiptSha,
            'delete_exact_section_count' => 32,
            'cache_generation_invalidations' => 32,
            'publication_change' => false,
            'indexability_change' => false,
        ]);
    }

    /**
     * @param  list<string>  $runtimeTypes
     * @return array<string,mixed>
     */
    private function invalidateCaches(array $runtimeTypes): array
    {
        $results = [];
        foreach ($runtimeTypes as $runtimeType) {
            $results[$runtimeType] = $this->readModelCache->forgetType(
                $runtimeType,
                'en',
                0,
                PersonalityProfile::SCALE_CODE_MBTI,
            );
        }

        return [
            'attempted_count' => count($results),
            'invalidated_count' => count(array_filter($results)),
            'failed_runtime_types' => array_keys(array_filter(
                $results,
                static fn (bool $passed): bool => ! $passed
            )),
        ];
    }

    /**
     * @param  Collection<int,PersonalityProfileVariant>  $variants
     */
    private function publicState(Collection $variants, bool $lock = false): string
    {
        if ($lock) {
            $variants = $this->reloadWithLockedProfiles($variants);
        }
        $targetIds = $variants
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $profileIds = $variants
            ->pluck('personality_profile_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->canonicalSha([
            'variants' => $variants->map(static function (
                PersonalityProfileVariant $variant
            ): array {
                $profile = $variant->profile;

                return [
                    'target_id' => (int) $variant->id,
                    'runtime_type_code' => (string) $variant->runtime_type_code,
                    'is_published' => (bool) $variant->is_published,
                    'published_at' => optional($variant->published_at)->toJSON(),
                    'profile_id' => (int) $profile->id,
                    'profile_status' => (string) $profile->status,
                    'profile_is_public' => (bool) $profile->is_public,
                    'profile_is_indexable' => (bool) $profile->is_indexable,
                    'profile_published_at' => optional($profile->published_at)->toJSON(),
                ];
            })->values()->all(),
            'variant_seo' => $this->variantSeoState($targetIds, $lock),
            'profile_seo' => $this->profileSeoState($profileIds, $lock),
        ]);
    }

    /**
     * @param  list<int>  $targetIds
     * @return list<array<string,mixed>>
     */
    private function variantSeoState(array $targetIds, bool $lock): array
    {
        $query = PersonalityProfileVariantSeoMeta::query()
            ->withoutGlobalScopes()
            ->whereIn('personality_profile_variant_id', $targetIds)
            ->orderBy('personality_profile_variant_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $seoByTarget = $query->get()->keyBy('personality_profile_variant_id');

        return array_map(static function (int $targetId) use ($seoByTarget): array {
            $seo = $seoByTarget->get($targetId);

            return [
                'target_id' => $targetId,
                'exists' => $seo instanceof PersonalityProfileVariantSeoMeta,
                'seo_meta_id' => $seo instanceof PersonalityProfileVariantSeoMeta
                    ? (int) $seo->id
                    : null,
                'org_id' => $seo instanceof PersonalityProfileVariantSeoMeta
                    ? (int) $seo->org_id
                    : null,
                'seo_title' => $seo?->seo_title,
                'seo_description' => $seo?->seo_description,
                'canonical_url' => $seo?->canonical_url,
                'og_title' => $seo?->og_title,
                'og_description' => $seo?->og_description,
                'og_image_url' => $seo?->og_image_url,
                'twitter_title' => $seo?->twitter_title,
                'twitter_description' => $seo?->twitter_description,
                'twitter_image_url' => $seo?->twitter_image_url,
                'robots' => $seo?->robots,
                'jsonld_overrides_json' => $seo?->jsonld_overrides_json,
            ];
        }, $targetIds);
    }

    /**
     * @param  list<int>  $profileIds
     * @return list<array<string,mixed>>
     */
    private function profileSeoState(array $profileIds, bool $lock): array
    {
        $query = PersonalityProfileSeoMeta::query()
            ->withoutGlobalScopes()
            ->whereIn('profile_id', $profileIds)
            ->orderBy('profile_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $seoByProfile = $query->get()->keyBy('profile_id');

        return array_map(static function (int $profileId) use ($seoByProfile): array {
            $seo = $seoByProfile->get($profileId);

            return [
                'profile_id' => $profileId,
                'exists' => $seo instanceof PersonalityProfileSeoMeta,
                'seo_meta_id' => $seo instanceof PersonalityProfileSeoMeta
                    ? (int) $seo->id
                    : null,
                'org_id' => $seo instanceof PersonalityProfileSeoMeta
                    ? (int) $seo->org_id
                    : null,
                'seo_title' => $seo?->seo_title,
                'seo_description' => $seo?->seo_description,
                'canonical_url' => $seo?->canonical_url,
                'og_title' => $seo?->og_title,
                'og_description' => $seo?->og_description,
                'og_image_url' => $seo?->og_image_url,
                'twitter_title' => $seo?->twitter_title,
                'twitter_description' => $seo?->twitter_description,
                'twitter_image_url' => $seo?->twitter_image_url,
                'robots' => $seo?->robots,
                'jsonld_overrides_json' => $seo?->jsonld_overrides_json,
            ];
        }, $profileIds);
    }

    /**
     * @param  Collection<int,PersonalityProfileVariant>  $variants
     * @return Collection<int,PersonalityProfileVariant>
     */
    private function reloadWithLockedProfiles(Collection $variants): Collection
    {
        $targetIds = $variants
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $freshVariants = PersonalityProfileVariant::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $targetIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($freshVariants->count() !== count($targetIds)) {
            throw new RuntimeException(
                'A target variant disappeared while locking the public-state invariant.'
            );
        }

        $profileIds = $freshVariants
            ->pluck('personality_profile_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $profiles = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $profileIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($profiles->count() !== count($profileIds)) {
            throw new RuntimeException(
                'A parent profile disappeared while locking the public-state invariant.'
            );
        }

        foreach ($freshVariants as $variant) {
            $profile = $profiles->get((int) $variant->personality_profile_id);
            if ((int) $variant->org_id !== 0
                || ! (bool) $variant->is_published
                || ($variant->published_at !== null && $variant->published_at->isFuture())
                || ! $profile instanceof PersonalityProfile
                || (int) $profile->org_id !== 0
                || (string) $profile->scale_code !== PersonalityProfile::SCALE_CODE_MBTI
                || (string) $profile->locale !== 'en'
                || (string) $profile->status !== 'published'
                || ! (bool) $profile->is_public
                || ($profile->published_at !== null && $profile->published_at->isFuture())) {
                throw new RuntimeException(
                    'A variant or parent profile drifted outside the exact public English MBTI authority boundary.'
                );
            }
            $variant->setRelation('profile', $profile);
            $this->assertPublicVariantParentIdentity($variant);
        }

        return $freshVariants;
    }

    private function assertPublicVariantParentIdentity(
        PersonalityProfileVariant $variant
    ): void {
        $runtimeType = strtoupper((string) $variant->runtime_type_code);
        $baseType = substr($runtimeType, 0, 4);
        $variantCode = substr($runtimeType, 5, 1);
        $profile = $variant->profile;
        if (! $profile instanceof PersonalityProfile
            || (string) $variant->canonical_type_code !== $baseType
            || (string) $variant->variant_code !== $variantCode
            || (string) $profile->type_code !== $baseType
            || (string) $profile->canonical_type_code !== $baseType
            || (string) $profile->slug !== strtolower($baseType)) {
            throw new RuntimeException(
                'A variant identity does not match its exact public parent profile.'
            );
        }
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @param  array<string,string|int>  $options
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function summary(array $inventory, array $options, array $overrides): array
    {
        $base = [
            'artifact' => self::ARTIFACT,
            'ok' => true,
            'status' => 'pass',
            'dry_run' => false,
            'bind_review' => false,
            'write' => false,
            'rollback' => false,
            'writes_committed' => false,
            'deployed_sha' => (string) $options['confirm_writer_deploy_sha'],
            'release' => (string) $options['confirm_release'],
            'graph_sha256' => $inventory['graph_sha256'],
            'cohort_sha256' => $inventory['cohort_sha256'],
            'checkpoint112_inventory_sha256' => self::CHECKPOINT112_INVENTORY_SHA256,
            'revision_identity_sha256' => $inventory['revision_identity_sha256'],
            'section_inventory_sha256' => self::CHECKPOINT112_SECTION_INVENTORY_SHA256,
            'rollback_markers_sha256' => $inventory['rollback_markers_sha256'],
            'row_count' => 32,
            'edge_count' => 64,
            'review_target_count' => 32,
            'promotion_package_sha256' => $this->canonicalSha([
                'artifact' => self::ARTIFACT,
                'revision_identity_sha256' => $inventory['revision_identity_sha256'],
                'rollback_markers_sha256' => $inventory['rollback_markers_sha256'],
                'review_targets' => $inventory['review_targets'],
                'section_key' => self::SECTION_KEY,
                'section_count' => 32,
                'edge_count' => 64,
            ]),
            'promotion_authorization_sha256' => $this->promotionAuthorizationSha(
                $inventory,
                $options
            ),
            'publication_changed' => false,
            'indexability_changed' => false,
            'sitemap_mutated' => false,
            'llms_mutated' => false,
            'search_release_mutated' => false,
            'errors' => [],
        ];

        return array_merge($base, $overrides);
    }

    /**
     * @param  array<string,string|int>  $options
     */
    private function assertRuntime(array $options): void
    {
        $expectedSha = (string) ($options['confirm_writer_deploy_sha'] ?? '');
        $expectedRelease = (string) ($options['confirm_release'] ?? '');
        if (preg_match('/^[0-9a-f]{40}$/', $expectedSha) !== 1
            || $expectedRelease === ''
            || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $expectedRelease) !== 1) {
            throw new RuntimeException('Exact deployed SHA and release are required.');
        }
        $revisionPath = base_path('../REVISION');
        $actualSha = is_file($revisionPath) ? trim((string) file_get_contents($revisionPath)) : '';
        $releaseRoot = realpath(base_path('..'));
        $actualRelease = is_string($releaseRoot) ? basename($releaseRoot) : '';
        if (! hash_equals($expectedSha, $actualSha)
            || ! hash_equals($expectedRelease, $actualRelease)) {
            throw new RuntimeException('Deployed REVISION or active release identity does not match.');
        }
    }

    /**
     * @param  array<string,string|int>  $options
     */
    private function requiredHash(array $options, string $key): string
    {
        $value = strtolower(trim((string) ($options[$key] ?? '')));
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new RuntimeException($key.' must be an exact lowercase SHA256.');
        }

        return $value;
    }

    private function rawJsonSha(mixed $value): string
    {
        return hash('sha256', (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalSha(mixed $value): string
    {
        return hash('sha256', $this->canonicalizer->encode($value));
    }
}
