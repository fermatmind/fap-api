<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use RuntimeException;
use Throwable;

final class CareerCurrentAuthorityPublisherFailure extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        ?Throwable $previous = null,
        public readonly string $writeCommitState = 'ambiguous',
    ) {
        parent::__construct($safeCode, 0, $previous);
    }
}

final class CareerCurrentAuthorityPublisher
{
    public const CONTRACT_VERSION = 'career.current_authority_publish.v1';

    public function __construct(
        private readonly CareerCurrentAuthorityPackageLoader $loader,
        private readonly CareerPageProjector $pages,
        private readonly \App\Services\Career\PublicCareerAuthorityResponseCache $publication,
    ) {}

    /** Files activate atomically with the release. Cache entries are disposable, fingerprint-bound derivatives. */
    public function execute(string $backendRoot, bool $fullScan = false): array
    {
        $authority = $this->loader->indexForPublish($backendRoot);
        $writes = 0;
        $hashes = [];
        foreach ($authority['slugs'] as $slug) {
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $page = $this->pages->project($this->loader->pageFromPublishIndex($authority, $slug, $locale));
                $key = \App\Services\Career\CareerFilePageReader::cacheKey($page);
                if (\App\Support\PublicProjectionCache::get($key) !== $page) {
                    \App\Support\PublicProjectionCache::put($key, $page, 86400);
                    $writes++;
                }
                if (\App\Support\PublicProjectionCache::get($key) !== $page) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_FILE_PAGE_CACHE_READBACK_FAILED');
                }
                $hashes[] = CareerCurrentAuthorityPackage::hashValue($page);
            }
        }
        $hold = $this->publication->filePagePublication('software-developers', 'zh-CN');
        if ($this->publication->jobDetailProjectionItemIsPublished($hold)) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_MANUAL_HOLD_CHANGED');
        }
        $after = $this->loader->indexForPublish($backendRoot);
        if ($after['summary']['aggregate_sha256'] !== $authority['summary']['aggregate_sha256']) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_FILE_AUTHORITY_CHANGED_DURING_PUBLISH');
        }
        $digest = CareerCurrentAuthorityPackage::hashValue($hashes);
        $count = count($authority['slugs']);

        return [
            'package' => $authority['summary'],
            'authority' => [
                'target_count' => $count, 'unique_slug_count' => $count, 'valid_component_order_count' => $count,
                'changed_slug_count' => 0, 'changed_slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue([]),
                'first_governance_cleanup' => $fullScan,
                'before_state_sha256' => $authority['summary']['aggregate_sha256'],
                'after_state_sha256' => $after['summary']['aggregate_sha256'],
            ],
            'public_readback' => [
                'verified_slug_count' => $count, 'verified_locale_page_count' => count($hashes),
                'cache_content_match_count' => count($hashes),
                'api_content_match_count' => 0, 'aggregate_sha256' => $digest,
            ],
            'manual_hold_verified' => true,
            'idempotent_noop' => $writes === 0,
            'write_counts' => [
                'database_update_count' => 0, 'database_insert_count' => 0, 'database_delete_count' => 0,
                'material_decision_write_count' => 0, 'cache_derived_compaction_write_count' => 0,
                'cache_candidate_write_count' => $writes, 'cache_pointer_activation_count' => 0,
                'occupation_write_count' => 0, 'generation_write_count' => 0, 'discoverability_write_count' => 0,
                'cms_write_count' => 0, 'sitemap_write_count' => 0, 'llms_write_count' => 0, 'search_submission_count' => 0,
            ],
            'state_sha256' => $digest,
        ];
    }
}
