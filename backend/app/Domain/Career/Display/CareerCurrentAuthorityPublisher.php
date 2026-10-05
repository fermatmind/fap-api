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
        private readonly \App\Services\Career\PublicCareerAuthorityResponseCache $publication,
    ) {}

    /** Files activate atomically with the release. Cache entries are disposable, fingerprint-bound derivatives. */
    /** @param null|list<array{slug:string,locale:string}> $changedPages */
    public function execute(string $backendRoot, bool $fullScan = false, ?array $changedPages = null): array
    {
        $authority = $this->loader->indexForPublish($backendRoot);
        $hashes = [];
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
        // The preactivation receipt already validated schema and all file bodies.
        // Read back the installed bytes only. Caches neither publish nor veto them.
        foreach ($authority['slugs'] as $slug) {
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $entry = $authority['entries'][$slug][$locale];
                $path = $authority['root'].'/'.$entry['path'];
                if (! is_file($path) || is_link($path)
                    || filesize($path) !== $entry['bytes']
                    || ! hash_equals($entry['sha256'], hash_file('sha256', $path))) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_FILE_AUTHORITY_READBACK_FAILED', null, 'confirmed_zero_write');
                }
                $hashes[] = $entry['source_content_sha256'];
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
                'target_count' => $count, 'unique_slug_count' => $count, 'verified_identity_count' => $count,
                'changed_slug_count' => count($changedSlugs),
                'changed_slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue($changedSlugs),
                'changed_locale_page_count' => count($changedIdentities),
                'changed_locale_page_set_sha256' => CareerCurrentAuthorityPackage::hashValue($changedIdentities),
                'first_governance_cleanup' => $fullScan,
                'before_state_sha256' => $authority['summary']['aggregate_sha256'],
                'after_state_sha256' => $after['summary']['aggregate_sha256'],
            ],
            'file_readback' => [
                'delivery_mode' => 'file_authoritative',
                'verified_slug_count' => $count, 'verified_locale_page_count' => count($hashes),
                'file_hash_match_count' => count($hashes), 'aggregate_sha256' => $digest,
            ],
            'manual_hold_verified' => true,
            'idempotent_noop' => true,
            'write_counts' => [
                'database_update_count' => 0, 'database_insert_count' => 0, 'database_delete_count' => 0,
                'material_decision_write_count' => 0, 'cache_derived_compaction_write_count' => 0,
                'cache_candidate_write_count' => 0, 'cache_pointer_activation_count' => 0,
                'occupation_write_count' => 0, 'generation_write_count' => 0, 'discoverability_write_count' => 0,
                'cms_write_count' => 0, 'sitemap_write_count' => 0, 'llms_write_count' => 0, 'search_submission_count' => 0,
            ],
            'state_sha256' => $digest,
        ];
    }
}
