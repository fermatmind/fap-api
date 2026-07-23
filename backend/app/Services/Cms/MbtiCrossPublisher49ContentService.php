<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @review-surface mbti_cross_type_comparison_authority */
final class MbtiCrossPublisher49ContentService
{
    public const CONTRACT = 'mbti.cross.publisher49.content.v1';

    public function __construct(private readonly MbtiCrossPublisher49Package $packageContract) {}

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorization
     * @return array<string,mixed>
     */
    public function plan(array $package, array $authorization): array
    {
        $records = $this->packageContract->validate($package, $authorization);
        $before = $this->snapshot(false);
        $desired = $this->desiredRows($records);
        $alreadyApplied = $this->comparable($before) === $this->comparable($desired);

        return [
            'artifact' => self::CONTRACT,
            'ok' => true,
            'status' => $alreadyApplied ? 'already_applied' : 'ready',
            'mode' => 'dry_run',
            'record_count' => 3,
            'exact_slugs' => MbtiCrossPublisher49Package::EXACT_SLUGS,
            'package_sha256' => MbtiCrossPublisher49Package::PACKAGE_SHA256,
            'editorial_authorization_sha256' => MbtiCrossPublisher49Package::AUTHORIZATION_SHA256,
            'current_state_sha256' => $this->packageContract->sha($before),
            'desired_state_sha256' => $this->packageContract->sha($desired),
            'required_production_authorization' => $this->expectedProductionAuthorization(
                $this->packageContract->sha($before),
            ),
            'content_readback_sha256' => $alreadyApplied ? $this->contentReadbackSha($before) : null,
            'already_applied' => $alreadyApplied,
            'writes_committed' => false,
            'indexability_mutated' => false,
            'sitemap_or_llms_mutated' => false,
            'search_submission_executed' => false,
            'rollback_manifest' => $this->rollbackManifest($before),
            'readback' => $before,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorization
     * @return array<string,mixed>
     */
    public function publish(
        array $package,
        array $authorization,
        string $expectedCurrentStateSha256,
        string $productionAuthorization,
    ): array {
        $records = $this->packageContract->validate($package, $authorization);
        $this->assertProductionAuthorization($expectedCurrentStateSha256, $productionAuthorization);

        return DB::transaction(function () use ($records, $expectedCurrentStateSha256): array {
            $before = $this->snapshot(true);
            $desired = $this->desiredRows($records);
            $beforeSha = $this->packageContract->sha($before);
            $rollback = $this->rollbackManifest($before);

            if ($this->comparable($before) === $this->comparable($desired)) {
                return $this->writeSummary('already_applied', $before, $beforeSha, $rollback, false);
            }
            if (! hash_equals($beforeSha, $expectedCurrentStateSha256)) {
                throw new RuntimeException('Production current-state SHA-256 precondition mismatch.');
            }

            foreach ($desired as $row) {
                MbtiCrossTypeComparisonAuthority::query()
                    ->withoutGlobalScopes()
                    ->updateOrCreate(
                        ['org_id' => 0, 'locale' => 'zh-CN', 'slug' => $row['slug']],
                        $row,
                    );
            }

            $after = $this->snapshot(true);
            if ($this->comparable($after) !== $this->comparable($desired)) {
                throw new RuntimeException('Transactional exact-three content readback mismatch.');
            }

            return $this->writeSummary('published_noindex', $after, $beforeSha, $rollback, true);
        }, 3);
    }

    public function expectedProductionAuthorization(string $expectedCurrentStateSha256): string
    {
        return 'I explicitly approve MBTI-CROSS-PUBLISHER-49 production content write for package SHA '
            .MbtiCrossPublisher49Package::PACKAGE_SHA256.' authorization SHA '
            .MbtiCrossPublisher49Package::AUTHORIZATION_SHA256.' current state SHA '
            .$expectedCurrentStateSha256.' covering only enfp-vs-entp, estj-vs-entj, and isfp-vs-infp; '
            .'keep noindex, sitemap, llms, llms-full, and search submission held.';
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return list<array<string,mixed>>
     */
    private function desiredRows(array $records): array
    {
        return array_map(function (array $record): array {
            $payload = (array) $record['candidate_payload'];

            return [
                'org_id' => 0,
                'locale' => 'zh-CN',
                'slug' => (string) $record['slug'],
                'comparison_type' => MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE,
                'left_type_code' => (string) $payload['left_type'],
                'right_type_code' => (string) $payload['right_type'],
                'title' => (string) $payload['title'],
                'seo_title' => (string) $payload['seo_title'],
                'seo_description' => (string) $payload['seo_description'],
                'summary' => (string) $payload['summary'],
                'content_payload_json' => [
                    'sections' => (array) $payload['sections'],
                    'faq' => (array) $payload['faq'],
                    'internal_links' => (array) $payload['internal_links'],
                    'source_notes' => (array) $payload['source_notes'],
                    'canonical' => (string) $payload['canonical_url'],
                    'robots' => 'noindex,follow',
                    'approval_record_id' => (string) $record['approval_record_id'],
                    'content_sha256' => (string) $record['content_sha256'],
                    'package_sha256' => MbtiCrossPublisher49Package::PACKAGE_SHA256,
                ],
                'claim_boundary' => (string) $payload['claim_boundary'],
                'source_package_id' => 'MBTI-CROSS-APPROVAL-48',
                'source_sha256' => (string) $record['content_sha256'],
                'authority_contract_version' => MbtiCrossTypeComparisonAuthority::AUTHORITY_CONTRACT_VERSION,
                'readmodel_contract_version' => MbtiCrossTypeComparisonAuthority::READMODEL_CONTRACT_VERSION,
                'review_status' => 'approved',
                'publish_status' => 'published',
                'indexability_status' => 'held_for_mbti_cross_indexability_release',
                'is_public' => true,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'search_submission_eligible' => false,
            ];
        }, $records);
    }

    /**
     * @return list<array<string,mixed>|null>
     */
    private function snapshot(bool $lock): array
    {
        $rows = [];
        foreach (MbtiCrossPublisher49Package::EXACT_SLUGS as $slug) {
            $query = MbtiCrossTypeComparisonAuthority::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('locale', 'zh-CN')
                ->where('slug', $slug);
            $model = ($lock ? $query->lockForUpdate() : $query)->first();
            $rows[] = $model instanceof MbtiCrossTypeComparisonAuthority
                ? $this->row($model)
                : null;
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function row(MbtiCrossTypeComparisonAuthority $model): array
    {
        return [
            'org_id' => (int) $model->org_id,
            'locale' => (string) $model->locale,
            'slug' => (string) $model->slug,
            'comparison_type' => (string) $model->comparison_type,
            'left_type_code' => (string) $model->left_type_code,
            'right_type_code' => (string) $model->right_type_code,
            'title' => (string) $model->title,
            'seo_title' => (string) $model->seo_title,
            'seo_description' => (string) $model->seo_description,
            'summary' => (string) $model->summary,
            'content_payload_json' => (array) $model->content_payload_json,
            'claim_boundary' => (string) $model->claim_boundary,
            'source_package_id' => (string) $model->source_package_id,
            'source_sha256' => (string) $model->source_sha256,
            'authority_contract_version' => (string) $model->authority_contract_version,
            'readmodel_contract_version' => (string) $model->readmodel_contract_version,
            'review_status' => (string) $model->review_status,
            'publish_status' => (string) $model->publish_status,
            'indexability_status' => (string) $model->indexability_status,
            'is_public' => (bool) $model->is_public,
            'is_indexable' => (bool) $model->is_indexable,
            'sitemap_eligible' => (bool) $model->sitemap_eligible,
            'llms_eligible' => (bool) $model->llms_eligible,
            'search_submission_eligible' => (bool) $model->search_submission_eligible,
        ];
    }

    private function comparable(array $rows): array
    {
        return array_map(static function (mixed $row): mixed {
            if (! is_array($row)) {
                return $row;
            }
            unset($row['id']);

            return $row;
        }, $rows);
    }

    /**
     * @param  list<array<string,mixed>|null>  $before
     * @return array<string,mixed>
     */
    private function rollbackManifest(array $before): array
    {
        return [
            'contract' => 'mbti.cross.publisher49.rollback.v1',
            'exact_slugs' => MbtiCrossPublisher49Package::EXACT_SLUGS,
            'prewrite_state_sha256' => $this->packageContract->sha($before),
            'restore_rows' => $before,
            'missing_before_write_requires_delete' => array_values(array_filter(array_map(
                static fn (mixed $row, int $index): ?string => $row === null
                    ? MbtiCrossPublisher49Package::EXACT_SLUGS[$index]
                    : null,
                $before,
                array_keys($before),
            ))),
            'atomic_restore_required' => true,
            'preserve_non_target_rows' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>|null>  $rows
     * @param  array<string,mixed>  $rollback
     * @return array<string,mixed>
     */
    private function writeSummary(string $status, array $rows, string $beforeSha, array $rollback, bool $committed): array
    {
        return [
            'artifact' => self::CONTRACT,
            'ok' => true,
            'status' => $status,
            'mode' => 'write',
            'record_count' => 3,
            'exact_slugs' => MbtiCrossPublisher49Package::EXACT_SLUGS,
            'package_sha256' => MbtiCrossPublisher49Package::PACKAGE_SHA256,
            'editorial_authorization_sha256' => MbtiCrossPublisher49Package::AUTHORIZATION_SHA256,
            'prewrite_state_sha256' => $beforeSha,
            'postwrite_state_sha256' => $this->packageContract->sha($rows),
            'content_readback_sha256' => $this->contentReadbackSha($rows),
            'already_applied' => ! $committed,
            'writes_committed' => $committed,
            'indexability_mutated' => false,
            'sitemap_or_llms_mutated' => false,
            'search_submission_executed' => false,
            'rollback_manifest' => $rollback,
            'readback' => $rows,
        ];
    }

    private function contentReadbackSha(array $rows): string
    {
        return $this->packageContract->sha([
            'contract' => 'mbti.cross.publisher49.content-readback.v1',
            'package_sha256' => MbtiCrossPublisher49Package::PACKAGE_SHA256,
            'records' => $rows,
        ]);
    }

    private function assertProductionAuthorization(string $expectedStateSha, string $authorization): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $expectedStateSha)
            || ! hash_equals($this->expectedProductionAuthorization($expectedStateSha), $authorization)
        ) {
            throw new RuntimeException('Exact production content-write authorization is required.');
        }
    }
}
