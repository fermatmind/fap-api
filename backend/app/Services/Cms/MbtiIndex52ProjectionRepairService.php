<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @review-surface mbti_cross_type_comparison_authority */
final class MbtiIndex52ProjectionRepairService
{
    public const CONTRACT = 'mbti.index52.comparison_projection_repair.plan.v1';

    public function __construct(private readonly MbtiIndex52ProjectionRepairPackage $packageContract) {}

    /** @return array<string,mixed> */
    public function plan(
        array $package,
        array $authorization,
        string $controlPlaneSha,
        string $activeRevision,
    ): array {
        $this->assertReleaseBinding($controlPlaneSha, $activeRevision);
        $records = $this->packageContract->validate($package, $authorization);
        $before = $this->snapshot($records, false, true);
        $desired = $this->desired($before, $records);
        $currentSha = $this->packageContract->sha($before);
        $desiredSha = $this->packageContract->sha($desired);
        $rollback = $this->rollbackManifest($before);
        $readback = $this->readbackContract($desired);
        $phrase = $this->expectedProductionAuthorization(
            $currentSha,
            $controlPlaneSha,
            $activeRevision,
        );

        return [
            'artifact' => self::CONTRACT,
            'ok' => true,
            'status' => hash_equals($currentSha, $desiredSha) ? 'already_applied' : 'ready',
            'mode' => 'dry_run',
            'record_count' => 23,
            'at_comparison_count' => 16,
            'cross_type_comparison_count' => 7,
            'exact_slugs' => [...MbtiIndex52ProjectionRepairPackage::AT_SLUGS, ...MbtiIndex52ProjectionRepairPackage::CROSS_SLUGS],
            'package_sha256' => MbtiIndex52ProjectionRepairPackage::PACKAGE_SHA256,
            'authorization_sha256' => MbtiIndex52ProjectionRepairPackage::AUTHORIZATION_SHA256,
            'control_plane_sha' => $controlPlaneSha,
            'active_revision' => $activeRevision,
            'current_state_sha256' => $currentSha,
            'desired_state_sha256' => $desiredSha,
            'rollback_manifest_sha256' => $this->packageContract->sha($rollback),
            'readback_contract_sha256' => $this->packageContract->sha($readback),
            'required_production_authorization' => $phrase,
            'production_authorization_sha256' => hash('sha256', $phrase),
            'writes_committed' => false,
            'body_or_faq_mutated' => false,
            'publication_or_indexability_mutated' => false,
            'sitemap_or_llms_mutated' => false,
            'search_submission_executed' => false,
            'rollback_manifest' => $rollback,
            'readback_contract' => $readback,
        ];
    }

    /** @return array<string,mixed> */
    public function publish(
        array $package,
        array $authorization,
        string $expectedCurrentStateSha256,
        string $expectedControlPlaneSha,
        string $expectedActiveRevision,
        string $productionAuthorization,
    ): array {
        $records = $this->packageContract->validate($package, $authorization);
        $this->assertReleaseBinding($expectedControlPlaneSha, $expectedActiveRevision);
        if (! preg_match('/^[a-f0-9]{64}$/', $expectedCurrentStateSha256)
            || ! hash_equals(
                $this->expectedProductionAuthorization(
                    $expectedCurrentStateSha256,
                    $expectedControlPlaneSha,
                    $expectedActiveRevision,
                ),
                $productionAuthorization,
            )
        ) {
            throw new RuntimeException('Exact production projection-repair authorization is required.');
        }

        return DB::transaction(function () use (
            $records,
            $expectedCurrentStateSha256,
            $expectedControlPlaneSha,
            $expectedActiveRevision,
        ): array {
            // The protected caller attests the exact streamed control-plane checkout;
            // only the deployed active release can be independently re-read here.
            $this->assertReleaseBinding($expectedControlPlaneSha, $expectedActiveRevision);
            if (! hash_equals($expectedActiveRevision, $this->runtimeActiveRevision())) {
                throw new RuntimeException('Active production release changed before transaction.');
            }
            $before = $this->snapshot($records, true, true);
            $beforeSha = $this->packageContract->sha($before);
            if (! hash_equals($expectedCurrentStateSha256, $beforeSha)) {
                throw new RuntimeException('Production current-state SHA-256 precondition mismatch.');
            }
            $desired = $this->desired($before, $records);
            $rollback = $this->rollbackManifest($before);
            $readback = $this->readbackContract($desired);
            foreach ($desired as $row) {
                if ($row['record_kind'] === 'at_comparison') {
                    $section = PersonalityProfileSection::query()->withoutGlobalScopes()->findOrFail($row['record_id']);
                    $section->payload_json = $row['payload_json'];
                    $section->save();
                } else {
                    $authority = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->findOrFail($row['record_id']);
                    $authority->content_payload_json = $row['payload_json'];
                    $authority->save();
                }
            }
            $after = $this->snapshot($records, true, false);
            if (! hash_equals($this->packageContract->sha($desired), $this->packageContract->sha($after))) {
                throw new RuntimeException('Atomic exact-23 projection repair readback mismatch.');
            }

            return [
                'artifact' => self::CONTRACT,
                'ok' => true,
                'status' => 'projection_repair_committed',
                'mode' => 'write',
                'record_count' => 23,
                'exact_slugs' => [...MbtiIndex52ProjectionRepairPackage::AT_SLUGS, ...MbtiIndex52ProjectionRepairPackage::CROSS_SLUGS],
                'package_sha256' => MbtiIndex52ProjectionRepairPackage::PACKAGE_SHA256,
                'authorization_sha256' => MbtiIndex52ProjectionRepairPackage::AUTHORIZATION_SHA256,
                'control_plane_sha' => $expectedControlPlaneSha,
                'active_revision' => $expectedActiveRevision,
                'prewrite_state_sha256' => $beforeSha,
                'postwrite_state_sha256' => $this->packageContract->sha($after),
                'rollback_manifest_sha256' => $this->packageContract->sha($rollback),
                'readback_contract_sha256' => $this->packageContract->sha($readback),
                'writes_committed' => true,
                'body_or_faq_mutated' => false,
                'publication_or_indexability_mutated' => false,
                'sitemap_or_llms_mutated' => false,
                'search_submission_executed' => false,
                'rollback_manifest' => $rollback,
                'readback_contract' => $readback,
            ];
        }, 3);
    }

    public function expectedProductionAuthorization(
        string $currentStateSha256,
        string $controlPlaneSha,
        string $activeRevision,
    ): string {
        return 'I explicitly approve MBTI-INDEX-52 production comparison projection repair for package SHA '
            .MbtiIndex52ProjectionRepairPackage::PACKAGE_SHA256.' authorization SHA '
            .MbtiIndex52ProjectionRepairPackage::AUTHORIZATION_SHA256.' current state SHA '
            .$currentStateSha256.' control-plane SHA '.$controlPlaneSha
            .' active SHA '.$activeRevision
            .' covering exact 16 A/T and 7 cross-comparison zh-CN records; '
            .'English alternate remains held pending a real en backend authority record; '
            .'no body/FAQ/publication/indexability/sitemap/llms/search changes.';
    }

    private function assertReleaseBinding(string $controlPlaneSha, string $activeRevision): void
    {
        if (preg_match('/^[a-f0-9]{40}$/', $controlPlaneSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $activeRevision) !== 1
        ) {
            throw new RuntimeException('Exact control-plane and active release SHAs are required.');
        }
    }

    private function runtimeActiveRevision(): string
    {
        if (app()->environment('testing')) {
            $override = trim((string) config('app.mbti_index52_test_active_revision'));
            if (preg_match('/^[a-f0-9]{40}$/', $override) === 1) {
                return $override;
            }
        }
        $revisionPath = dirname(base_path()).'/REVISION';
        $revision = is_file($revisionPath) ? trim((string) file_get_contents($revisionPath)) : '';
        if (preg_match('/^[a-f0-9]{40}$/', $revision) !== 1) {
            throw new RuntimeException('Active production revision is unavailable.');
        }

        return $revision;
    }

    /** @param list<array<string,mixed>> $records @return list<array<string,mixed>> */
    private function snapshot(array $records, bool $lock, bool $assertSourceRevision): array
    {
        $rows = [];
        foreach ($records as $record) {
            $slug = (string) $record['slug'];
            if ($record['record_kind'] === 'at_comparison') {
                $baseType = strtoupper(substr($slug, 0, 4));
                $profileQuery = PersonalityProfile::query()->withoutGlobalScopes()
                    ->where('org_id', 0)->where('locale', 'zh-CN')->where('scale_code', 'MBTI')
                    ->where('canonical_type_code', $baseType);
                $profile = ($lock ? $profileQuery->lockForUpdate() : $profileQuery)->first();
                if (! $profile instanceof PersonalityProfile) {
                    throw new RuntimeException("Missing A/T profile authority: {$slug}");
                }
                $sectionQuery = PersonalityProfileSection::query()->withoutGlobalScopes()
                    ->where('profile_id', $profile->id)->where('section_key', 'mbti64_comparison_a_vs_t');
                $section = ($lock ? $sectionQuery->lockForUpdate() : $sectionQuery)->first();
                if (! $section instanceof PersonalityProfileSection || ! $section->is_enabled) {
                    throw new RuntimeException("Missing enabled A/T comparison authority: {$slug}");
                }
                $payload = (array) $section->payload_json;
                if ($assertSourceRevision
                    && ! hash_equals(
                        (string) $record['source_revision_sha256'],
                        $this->packageContract->sha($payload),
                    )
                ) {
                    throw new RuntimeException("A/T comparison authority pre-state mismatch: {$slug}");
                }
                $this->assertSectionFingerprint($record, $this->normalizeSections($payload['sections'] ?? []));
                $rows[] = [
                    'slug' => $slug,
                    'record_kind' => 'at_comparison',
                    'record_id' => (int) $section->id,
                    'payload_json' => $payload,
                    'invariants' => [
                        'profile_status' => (string) $profile->status,
                        'is_public' => (bool) $profile->is_public,
                        'is_indexable' => (bool) $profile->is_indexable,
                        'section_enabled' => (bool) $section->is_enabled,
                    ],
                ];

                continue;
            }

            $query = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
                ->where('org_id', 0)->where('locale', 'zh-CN')->where('slug', $slug);
            $authority = ($lock ? $query->lockForUpdate() : $query)->first();
            if (! $authority instanceof MbtiCrossTypeComparisonAuthority
                || $authority->publish_status !== 'published'
                || ! $authority->is_public
                || ! hash_equals((string) $record['source_revision_sha256'], (string) $authority->source_sha256)
            ) {
                throw new RuntimeException("Cross-comparison authority pre-state mismatch: {$slug}");
            }
            $payload = (array) $authority->content_payload_json;
            $this->assertSectionFingerprint($record, $this->normalizeSections($payload['sections'] ?? []));
            $rows[] = [
                'slug' => $slug,
                'record_kind' => 'cross_type_comparison',
                'record_id' => (int) $authority->id,
                'payload_json' => $payload,
                'invariants' => [
                    'review_status' => (string) $authority->review_status,
                    'publish_status' => (string) $authority->publish_status,
                    'indexability_status' => (string) $authority->indexability_status,
                    'is_public' => (bool) $authority->is_public,
                    'is_indexable' => (bool) $authority->is_indexable,
                    'sitemap_eligible' => (bool) $authority->sitemap_eligible,
                    'llms_eligible' => (bool) $authority->llms_eligible,
                    'search_submission_eligible' => (bool) $authority->search_submission_eligible,
                ],
            ];
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $before @param list<array<string,mixed>> $records @return list<array<string,mixed>> */
    private function desired(array $before, array $records): array
    {
        return array_map(function (array $row, array $record): array {
            $payload = $row['payload_json'];
            $patch = (array) $record['patch'];
            if ($row['record_kind'] === 'at_comparison') {
                $payload['claim_boundary'] = $patch['claim_boundary'];
            } else {
                foreach (['internal_links', 'answer_surface_v1'] as $key) {
                    $payload[$key] = $patch[$key];
                }
            }
            $row['payload_json'] = $payload;

            return $row;
        }, $before, $records);
    }

    /** @param list<array<string,mixed>> $before @return array<string,mixed> */
    private function rollbackManifest(array $before): array
    {
        return [
            'contract' => 'mbti.index52.comparison_projection_repair.rollback.v1',
            'exact_slugs' => array_column($before, 'slug'),
            'prewrite_state_sha256' => $this->packageContract->sha($before),
            'restore_rows' => $before,
            'atomic_restore_required' => true,
            'preserve_non_target_rows' => true,
            'automatic_rollback' => false,
        ];
    }

    /** @param list<array<string,mixed>> $desired @return array<string,mixed> */
    private function readbackContract(array $desired): array
    {
        return [
            'contract' => 'mbti.index52.comparison_projection_repair.readback.v1',
            'exact_slugs' => array_column($desired, 'slug'),
            'desired_state_sha256' => $this->packageContract->sha($desired),
            'required_public_fields' => ['sections', 'claim_boundary', 'internal_links', 'answer_surface_v1'],
            'english_alternate_authority' => 'held_missing_en_backend_record',
            'preserve_body_and_faq' => true,
            'preserve_publication_and_indexability' => true,
        ];
    }

    private function assertSectionFingerprint(array $record, array $sections): void
    {
        if (count($sections) !== (int) $record['expected_runtime_sections_count']
            || ! hash_equals((string) $record['expected_runtime_sections_sha256'], $this->packageContract->sha($sections))
        ) {
            throw new RuntimeException('Runtime section pre-state mismatch: '.(string) $record['slug']);
        }
    }

    /** @return list<array<string,mixed>> */
    private function normalizeSections(mixed $source): array
    {
        $sections = [];
        foreach (array_values(is_array($source) ? $source : []) as $index => $section) {
            if (! is_array($section)) {
                continue;
            }
            $key = trim((string) ($section['key'] ?? $section['id'] ?? '')) ?: 'section-'.($index + 1);
            $title = trim((string) ($section['title'] ?? ''));
            $bodySource = $section['body'] ?? [];
            $bodyValues = is_array($bodySource) ? $bodySource : [$bodySource];
            $body = array_values(array_filter(array_map(
                static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
                $bodyValues,
            )));
            $rows = is_array($section['rows'] ?? null) ? array_values($section['rows']) : [];
            if ($title === '' || ($body === [] && $rows === [])) {
                continue;
            }
            $normalized = ['id' => $key, 'title' => $title, 'body' => $body];
            if ($rows !== []) {
                $normalized['rows'] = $rows;
            }
            $sections[] = $normalized;
        }

        return $sections;
    }
}
