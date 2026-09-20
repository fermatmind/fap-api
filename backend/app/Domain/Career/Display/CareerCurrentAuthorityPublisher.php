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
    /** @param null|list<array{slug:string,locale:string}> $changedPages */
    public function execute(string $backendRoot, bool $fullScan = false, ?array $changedPages = null): array
    {
        $authority = $this->loader->indexForPublish($backendRoot);
        $writes = 0;
        $hashes = [];
        $candidates = [];
        $allowedChanges = null;
        if ($changedPages !== null) {
            $allowedChanges = [];
            foreach ($changedPages as $changedPage) {
                $slug = strtolower(trim((string) ($changedPage['slug'] ?? '')));
                $locale = (string) ($changedPage['locale'] ?? '');
                if (! in_array($locale, CareerCurrentAuthorityPackage::LOCALES, true)
                    || ! in_array($slug, $authority['slugs'], true)) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CHANGED_PAGE_SET_INVALID', null, 'confirmed_zero_write');
                }
                $allowedChanges[$slug.'|'.$locale] = true;
            }
        }
        foreach ($authority['slugs'] as $slug) {
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $page = $this->pages->project($this->loader->pageFromPublishIndex($authority, $slug, $locale));
                $key = \App\Services\Career\CareerFilePageReader::cacheKey($page);
                $candidates[$slug.'|'.$locale] = ['key' => $key, 'page' => $page];
                $hashes[] = CareerCurrentAuthorityPackage::hashValue($page);
            }
        }
        $before = \App\Support\PublicProjectionCache::many(array_column($candidates, 'key'));
        $mismatches = [];
        foreach ($candidates as $identity => $candidate) {
            $key = $candidate['key'];
            $page = $candidate['page'];
            if (($before[$key] ?? null) !== $page) {
                if ($allowedChanges !== null && ! isset($allowedChanges[$identity])) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_UNCHANGED_FILE_PAGE_DRIFT', null, 'confirmed_zero_write');
                }
                $mismatches[] = $candidate;
            }
        }
        foreach ($mismatches as $candidate) {
            \App\Support\PublicProjectionCache::put($candidate['key'], $candidate['page'], 86400);
            $writes++;
        }
        $readback = \App\Support\PublicProjectionCache::many(array_column($candidates, 'key'));
        foreach ($candidates as $candidate) {
            if (($readback[$candidate['key']] ?? null) !== $candidate['page']) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_FILE_PAGE_CACHE_READBACK_FAILED');
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
        $changedIdentities = array_keys($allowedChanges ?? []);
        sort($changedIdentities, SORT_STRING);
        $changedSlugs = array_values(array_unique(array_map(
            static fn (string $identity): string => explode('|', $identity, 2)[0],
            $changedIdentities,
        )));

        return [
            'package' => $authority['summary'],
            'authority' => [
                'target_count' => $count, 'unique_slug_count' => $count, 'valid_component_order_count' => $count,
                'changed_slug_count' => count($changedSlugs),
                'changed_slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue($changedSlugs),
                'changed_locale_page_count' => count($changedIdentities),
                'changed_locale_page_set_sha256' => CareerCurrentAuthorityPackage::hashValue($changedIdentities),
                'first_governance_cleanup' => $fullScan,
                'before_state_sha256' => $authority['summary']['aggregate_sha256'],
                'after_state_sha256' => $after['summary']['aggregate_sha256'],
            ],
            'public_readback' => [
                'render_contract_version' => CareerPageProjector::VERSION,
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
