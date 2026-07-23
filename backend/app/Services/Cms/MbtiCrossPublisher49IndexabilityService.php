<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @review-surface mbti_cross_type_comparison_authority */
final class MbtiCrossPublisher49IndexabilityService
{
    public const CONTRACT = 'mbti.cross.publisher49.indexability.v1';

    public function __construct(private readonly MbtiCrossPublisher49Package $packageContract) {}

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorization
     * @return array<string,mixed>
     */
    public function plan(array $package, array $authorization): array
    {
        $records = $this->packageContract->validate($package, $authorization);
        $rows = $this->rows(false);
        $this->assertPublishedContent($rows, $records);
        $state = $this->discoverabilityState($rows);
        $heldReadbackSha = $this->heldContentReadbackSha($rows);

        return [
            'artifact' => self::CONTRACT,
            'ok' => true,
            'status' => $state === 'released' ? 'already_released' : 'ready',
            'mode' => 'dry_run',
            'record_count' => 3,
            'exact_slugs' => MbtiCrossPublisher49Package::EXACT_SLUGS,
            'package_sha256' => MbtiCrossPublisher49Package::PACKAGE_SHA256,
            'editorial_authorization_sha256' => MbtiCrossPublisher49Package::AUTHORIZATION_SHA256,
            'required_content_readback_sha256' => $heldReadbackSha,
            'required_production_authorization' => $this->expectedProductionAuthorization($heldReadbackSha),
            'content_fingerprint_sha256' => $this->contentFingerprint($rows),
            'discoverability_state' => $state,
            'indexability_write_committed' => false,
            'body_mutated' => false,
            'search_submission_executed' => false,
            'rollback_manifest' => $this->rollbackManifest($rows),
            'readback' => $rows,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorization
     * @return array<string,mixed>
     */
    public function release(
        array $package,
        array $authorization,
        string $contentReadbackSha256,
        string $productionAuthorization,
    ): array {
        $records = $this->packageContract->validate($package, $authorization);
        $this->assertProductionAuthorization($contentReadbackSha256, $productionAuthorization);

        return DB::transaction(function () use ($records, $contentReadbackSha256): array {
            $before = $this->rows(true);
            $this->assertPublishedContent($before, $records);
            $state = $this->discoverabilityState($before);
            $requiredReadbackSha = $this->heldContentReadbackSha($before);
            if (! hash_equals($requiredReadbackSha, $contentReadbackSha256)) {
                throw new RuntimeException('Successful content readback SHA-256 mismatch.');
            }

            $contentFingerprint = $this->contentFingerprint($before);
            $rollback = $this->rollbackManifest($before);
            if ($state === 'released') {
                return $this->writeSummary('already_released', $before, $requiredReadbackSha, $contentFingerprint, $rollback, false);
            }

            foreach ($before as $row) {
                $payload = (array) $row->content_payload_json;
                $payload['robots'] = 'index,follow';
                $row->forceFill([
                    'content_payload_json' => $payload,
                    'indexability_status' => 'released_by_mbti_cross_publisher_49',
                    'is_indexable' => true,
                    'sitemap_eligible' => true,
                    'llms_eligible' => true,
                    'search_submission_eligible' => false,
                ])->save();
            }

            $after = $this->rows(true);
            $this->assertPublishedContent($after, $records);
            if ($this->discoverabilityState($after) !== 'released'
                || ! hash_equals($contentFingerprint, $this->contentFingerprint($after))
            ) {
                throw new RuntimeException('Transactional indexability readback or body-preservation check failed.');
            }

            return $this->writeSummary('released', $after, $requiredReadbackSha, $contentFingerprint, $rollback, true);
        }, 3);
    }

    public function expectedProductionAuthorization(string $contentReadbackSha256): string
    {
        return 'I explicitly approve MBTI-CROSS-PUBLISHER-49 production indexability release for package SHA '
            .MbtiCrossPublisher49Package::PACKAGE_SHA256.' authorization SHA '
            .MbtiCrossPublisher49Package::AUTHORIZATION_SHA256.' successful content readback SHA '
            .$contentReadbackSha256.' covering only enfp-vs-entp, estj-vs-entj, and isfp-vs-infp; '
            .'release indexability, sitemap, llms, and llms-full without body changes or search submission.';
    }

    /**
     * @return list<MbtiCrossTypeComparisonAuthority>
     */
    private function rows(bool $lock): array
    {
        $rows = [];
        foreach (MbtiCrossPublisher49Package::EXACT_SLUGS as $slug) {
            $query = MbtiCrossTypeComparisonAuthority::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('locale', 'zh-CN')
                ->where('slug', $slug);
            $row = ($lock ? $query->lockForUpdate() : $query)->first();
            if (! $row instanceof MbtiCrossTypeComparisonAuthority) {
                throw new RuntimeException("Published content authority row {$slug} is missing.");
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<MbtiCrossTypeComparisonAuthority>  $rows
     * @param  list<array<string,mixed>>  $records
     */
    private function assertPublishedContent(array $rows, array $records): void
    {
        $expectedRows = $this->packageContract->desiredAuthorityRows($records);
        foreach ($rows as $index => $row) {
            $actual = $this->packageContract->normalizeDiscoverabilityToHeld($this->fullState($row));
            if ($actual !== $expectedRows[$index]) {
                throw new RuntimeException("Published content authority row {$row->slug} does not match the exact approved package.");
            }
        }
    }

    /**
     * @param  list<MbtiCrossTypeComparisonAuthority>  $rows
     */
    private function discoverabilityState(array $rows): string
    {
        $held = 0;
        $released = 0;
        foreach ($rows as $row) {
            $robots = strtolower((string) data_get($row->content_payload_json, 'robots', ''));
            $isHeld = ! (bool) $row->is_indexable
                && ! (bool) $row->sitemap_eligible
                && ! (bool) $row->llms_eligible
                && ! (bool) $row->search_submission_eligible
                && $robots === 'noindex,follow';
            $isReleased = (bool) $row->is_indexable
                && (bool) $row->sitemap_eligible
                && (bool) $row->llms_eligible
                && ! (bool) $row->search_submission_eligible
                && $robots === 'index,follow';
            $held += $isHeld ? 1 : 0;
            $released += $isReleased ? 1 : 0;
        }
        if ($held === 3) {
            return 'held';
        }
        if ($released === 3) {
            return 'released';
        }

        throw new RuntimeException('Mixed or invalid discoverability state fails closed.');
    }

    /**
     * @param  list<MbtiCrossTypeComparisonAuthority>  $rows
     */
    private function heldContentReadbackSha(array $rows): string
    {
        $normalized = array_map(function (MbtiCrossTypeComparisonAuthority $row): array {
            return $this->packageContract->normalizeDiscoverabilityToHeld($this->fullState($row));
        }, $rows);

        return $this->packageContract->sha([
            'contract' => 'mbti.cross.publisher49.content-readback.v1',
            'package_sha256' => MbtiCrossPublisher49Package::PACKAGE_SHA256,
            'records' => $normalized,
        ]);
    }

    /**
     * @param  list<MbtiCrossTypeComparisonAuthority>  $rows
     */
    private function contentFingerprint(array $rows): string
    {
        $content = array_map(function (MbtiCrossTypeComparisonAuthority $row): array {
            $state = $this->fullState($row);
            unset(
                $state['indexability_status'],
                $state['is_indexable'],
                $state['sitemap_eligible'],
                $state['llms_eligible'],
                $state['search_submission_eligible'],
                $state['content_payload_json']['robots'],
            );

            return $state;
        }, $rows);

        return $this->packageContract->sha($content);
    }

    /**
     * @return array<string,mixed>
     */
    private function fullState(MbtiCrossTypeComparisonAuthority $row): array
    {
        return [
            'org_id' => (int) $row->org_id,
            'locale' => (string) $row->locale,
            'slug' => (string) $row->slug,
            'comparison_type' => (string) $row->comparison_type,
            'left_type_code' => (string) $row->left_type_code,
            'right_type_code' => (string) $row->right_type_code,
            'title' => (string) $row->title,
            'seo_title' => (string) $row->seo_title,
            'seo_description' => (string) $row->seo_description,
            'summary' => (string) $row->summary,
            'content_payload_json' => (array) $row->content_payload_json,
            'claim_boundary' => (string) $row->claim_boundary,
            'source_package_id' => (string) $row->source_package_id,
            'source_sha256' => (string) $row->source_sha256,
            'authority_contract_version' => (string) $row->authority_contract_version,
            'readmodel_contract_version' => (string) $row->readmodel_contract_version,
            'review_status' => (string) $row->review_status,
            'publish_status' => (string) $row->publish_status,
            'indexability_status' => (string) $row->indexability_status,
            'is_public' => (bool) $row->is_public,
            'is_indexable' => (bool) $row->is_indexable,
            'sitemap_eligible' => (bool) $row->sitemap_eligible,
            'llms_eligible' => (bool) $row->llms_eligible,
            'search_submission_eligible' => (bool) $row->search_submission_eligible,
        ];
    }

    /**
     * @param  list<MbtiCrossTypeComparisonAuthority>  $rows
     * @return array<string,mixed>
     */
    private function rollbackManifest(array $rows): array
    {
        return [
            'contract' => 'mbti.cross.publisher49.indexability-rollback.v1',
            'exact_slugs' => MbtiCrossPublisher49Package::EXACT_SLUGS,
            'restore_discoverability' => array_map(static fn (MbtiCrossTypeComparisonAuthority $row): array => [
                'slug' => (string) $row->slug,
                'robots' => (string) data_get($row->content_payload_json, 'robots', ''),
                'indexability_status' => (string) $row->indexability_status,
                'is_indexable' => (bool) $row->is_indexable,
                'sitemap_eligible' => (bool) $row->sitemap_eligible,
                'llms_eligible' => (bool) $row->llms_eligible,
                'search_submission_eligible' => (bool) $row->search_submission_eligible,
            ], $rows),
            'body_restore_prohibited' => true,
            'atomic_restore_required' => true,
        ];
    }

    /**
     * @param  list<MbtiCrossTypeComparisonAuthority>  $rows
     * @param  array<string,mixed>  $rollback
     * @return array<string,mixed>
     */
    private function writeSummary(
        string $status,
        array $rows,
        string $contentReadbackSha,
        string $contentFingerprint,
        array $rollback,
        bool $committed,
    ): array {
        return [
            'artifact' => self::CONTRACT,
            'ok' => true,
            'status' => $status,
            'mode' => 'write',
            'record_count' => 3,
            'exact_slugs' => MbtiCrossPublisher49Package::EXACT_SLUGS,
            'package_sha256' => MbtiCrossPublisher49Package::PACKAGE_SHA256,
            'editorial_authorization_sha256' => MbtiCrossPublisher49Package::AUTHORIZATION_SHA256,
            'required_content_readback_sha256' => $contentReadbackSha,
            'content_fingerprint_sha256' => $contentFingerprint,
            'discoverability_state' => 'released',
            'indexability_write_committed' => $committed,
            'body_mutated' => false,
            'search_submission_executed' => false,
            'rollback_manifest' => $rollback,
            'readback' => array_map(fn (MbtiCrossTypeComparisonAuthority $row): array => $this->fullState($row), $rows),
        ];
    }

    private function assertProductionAuthorization(string $readbackSha, string $authorization): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $readbackSha)
            || ! hash_equals($this->expectedProductionAuthorization($readbackSha), $authorization)
        ) {
            throw new RuntimeException('Independent exact production indexability authorization is required.');
        }
    }
}
