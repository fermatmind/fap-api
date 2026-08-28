<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use RuntimeException;
use Throwable;

final class CareerCurrentAuthorityPublisherFailure extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        ?Throwable $previous = null,
        public readonly string $writeCommitState = 'confirmed_zero_write',
    ) {
        parent::__construct($safeCode, 0, $previous);
    }
}

final class CareerCurrentAuthorityPublisher
{
    public const CONTRACT_VERSION = 'career.current_authority_publish.v1';

    private const MANUAL_HOLD_SLUGS = ['software-developers'];

    private const HEALTH_SAMPLE_SLUGS = [
        'accountants-and-auditors',
        'actors',
        'registered-nurses',
        'writers-and-authors',
    ];

    private readonly CareerCurrentAuthorityStateMachine $stateMachine;

    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly CareerCurrentAuthorityPackageLoader $loader,
        private readonly CareerCurrentAuthorityCacheGateway $cache,
        private readonly CareerJobDisplaySurfaceBuilder $displaySurfaceBuilder,
        private readonly CareerCurrentAuthorityCompatibilityReader $compatibility,
        ?CareerCurrentAuthorityStateMachine $stateMachine = null,
    ) {
        $this->stateMachine = $stateMachine ?? app(CareerCurrentAuthorityStateMachine::class);
    }

    /** @return array<string,mixed> */
    public function execute(string $backendRoot, bool $fullScan = false): array
    {
        $pointersActivated = false;
        $prepared = [];
        $rollbackSnapshots = [];
        $cacheCompactionWrites = 0;

        try {
            $authority = $this->loader->indexForPublish($backendRoot);
            $slugs = $authority['slugs'];
            $this->compatibility->assertInventory($slugs);
            $beforeStateSha256 = $this->databaseRowSetHash($authority, $slugs, true);
            foreach ($this->compatibility->batches($slugs) as $chunk) {
                $cacheCompactionWrites += $this->cache->compactDerivedContentV3(
                    $chunk,
                    CareerCurrentAuthorityPackage::LOCALES,
                );
                $this->stateMachine->releaseLoadedContentPages();
                gc_collect_cycles();
            }

            $candidatePairs = $fullScan
                ? $this->staleCachePairs($authority, $slugs)
                : [];
            $candidatePairKeys = [];
            foreach ($candidatePairs as [$slug, $locale]) {
                $candidatePairKeys[$slug.'|'.$locale] = true;
            }

            foreach ($this->compatibility->batches($slugs) as $chunk) {
                $batchPairCount = 0;
                foreach ($chunk as $slug) {
                    foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                        if (isset($candidatePairKeys[$slug.'|'.$locale])) {
                            $batchPairCount++;
                        }
                    }
                }
                if ($batchPairCount === 0) {
                    continue;
                }

                $rows = $this->compatibility->rowsForSlugs($authority, $chunk);
                foreach ($chunk as $slug) {
                    foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                        if (! isset($candidatePairKeys[$slug.'|'.$locale])) {
                            continue;
                        }
                        $expectedCandidate = $this->stateMachine->assembleCandidate($rows[$slug], $locale);
                        try {
                            $entry = $this->cache->prepare($slug, $locale);
                        } catch (Throwable $throwable) {
                            throw new CareerCurrentAuthorityPublisherFailure(
                                $this->cacheCandidatePreparationFailureCode($throwable),
                                $throwable,
                            );
                        }
                        $this->stateMachine->assertPreparedTransition($entry);
                        $prepared[] = $entry;
                        $this->assertCachedPayload($entry, $rows[$slug], $locale, true);
                        $actualCandidate = $this->cache->preparedPayload($entry);
                        if (! is_array($actualCandidate) || ! hash_equals(
                            $this->stateMachine->canonicalPayloadHash(
                                $expectedCandidate['payload'],
                                $rows[$slug],
                                $locale,
                            ),
                            $this->stateMachine->canonicalPayloadHash(
                                $actualCandidate,
                                $rows[$slug],
                                $locale,
                            ),
                        )) {
                            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_CANDIDATE_ASSEMBLY_MISMATCH');
                        }
                        unset($expectedCandidate, $actualCandidate);
                    }
                }
                unset($rows);
                $this->stateMachine->releaseLoadedContentPages();
                gc_collect_cycles();
            }

            if ($prepared !== []) {
                $activation = $this->cache->activate($prepared);
                $this->stateMachine->assertActivationTransition($activation, count($prepared));
                $pointersActivated = true;
                $rollbackSnapshots = (array) ($activation['rollback_snapshots'] ?? []);
            }

            $changedSlugs = [];
            $verificationSlugs = $fullScan
                ? $this->publicSlugs($slugs)
                : $this->verificationSlugs($changedSlugs, $slugs);
            $readback = $this->assertPublicReadback($authority, $verificationSlugs);
            $this->assertManualHold();
            $afterStateSha256 = $this->databaseRowSetHash($authority, $slugs);
            if (! hash_equals($beforeStateSha256, $afterStateSha256)) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_DATABASE_READBACK_STATE_MISMATCH');
            }

            $writeCounts = [
                'database_update_count' => 0,
                'database_insert_count' => 0,
                'database_delete_count' => 0,
                'material_decision_write_count' => 0,
                'cache_derived_compaction_write_count' => $cacheCompactionWrites,
                'cache_candidate_write_count' => count($prepared) * 2,
                'cache_pointer_activation_count' => count($prepared),
                'occupation_write_count' => 0,
                'generation_write_count' => 0,
                'discoverability_write_count' => 0,
                'cms_write_count' => 0,
                'sitemap_write_count' => 0,
                'llms_write_count' => 0,
                'search_submission_count' => 0,
            ];
            $noop = array_sum($writeCounts) === 0;

            return [
                'package' => $authority['summary'],
                'authority' => [
                    'target_count' => count($slugs),
                    'unique_slug_count' => count($slugs),
                    'valid_component_order_count' => count($slugs),
                    'changed_slug_count' => 0,
                    'changed_slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue($changedSlugs),
                    'first_governance_cleanup' => $fullScan,
                    'before_state_sha256' => $beforeStateSha256,
                    'after_state_sha256' => $afterStateSha256,
                ],
                'public_readback' => $readback,
                'manual_hold_verified' => true,
                'idempotent_noop' => $noop,
                'write_counts' => $writeCounts,
                'state_sha256' => CareerCurrentAuthorityPackage::hashValue([
                    'versionless_projection_sha256' => $authority['summary']['versionless_projection_sha256'],
                    'database_sha256' => $afterStateSha256,
                    'public_readback_sha256' => $readback['aggregate_sha256'],
                ]),
            ];
        } catch (Throwable $throwable) {
            $compensationFailure = null;
            $pointerRestoreFailed = false;
            try {
                if ($pointersActivated) {
                    $this->cache->restore($prepared, $rollbackSnapshots);
                }
            } catch (Throwable $failure) {
                $compensationFailure = $failure;
                $pointerRestoreFailed = true;
            }
            if (! $pointerRestoreFailed) {
                try {
                    $this->cache->forget($prepared);
                } catch (Throwable $failure) {
                    $compensationFailure ??= $failure;
                }
            }

            if ($compensationFailure instanceof Throwable) {
                throw new CareerCurrentAuthorityPublisherFailure(
                    'CURRENT_PUBLISH_COMPENSATION_FAILED',
                    $compensationFailure,
                    'ambiguous',
                );
            }

            throw new CareerCurrentAuthorityPublisherFailure(
                $throwable instanceof CareerCurrentAuthorityPublisherFailure
                    ? $throwable->safeCode
                    : ($throwable instanceof CareerCurrentAuthorityPackageFailure
                        ? $throwable->safeCode
                        : 'CURRENT_PUBLISH_FAILED'),
                $throwable,
                $prepared !== [] ? 'rolled_back' : 'confirmed_zero_write',
            );
        }
    }

    private function cacheCandidatePreparationFailureCode(Throwable $throwable): string
    {
        for ($candidate = $throwable; $candidate instanceof Throwable; $candidate = $candidate->getPrevious()) {
            $message = strtolower($candidate->getMessage());
            if (str_contains($message, 'oom command not allowed') || str_contains($message, 'maxmemory')) {
                return 'CURRENT_CACHE_CAPACITY_EXHAUSTED';
            }
            $class = strtolower($candidate::class);
            if (str_contains($class, 'redis') || str_contains($class, 'predis')) {
                return 'CURRENT_CACHE_BACKEND_PREPARATION_FAILED';
            }
            if ($candidate instanceof \Illuminate\Database\QueryException || $candidate instanceof \PDOException) {
                return 'CURRENT_CACHE_DATABASE_DEPENDENCY_FAILED';
            }
            if ($candidate instanceof \TypeError || $candidate instanceof \ValueError || $candidate instanceof \JsonException) {
                return 'CURRENT_CACHE_PAYLOAD_BUILD_FAILED';
            }
        }

        return 'CURRENT_CACHE_PREPARATION_RUNTIME_FAILED';
    }

    /**
     * @param  array{entries:array<string,array<string,array<string,mixed>>>}  $authority
     * @param  list<string>  $slugs
     */
    private function databaseRowSetHash(array $authority, array $slugs, bool $verifyAccountants = false): string
    {
        $context = hash_init('sha256');
        hash_update($context, '[');
        $index = 0;
        foreach ($this->compatibility->batches($slugs) as $chunk) {
            $rows = $this->compatibility->rowsForSlugs($authority, $chunk);
            if ($verifyAccountants && isset($rows['accountants-and-auditors'])) {
                $this->assertAccountantsBoundaryNotice($rows['accountants-and-auditors']);
            }
            foreach ($rows as $row) {
                hash_update(
                    $context,
                    ($index === 0 ? '' : ',').CareerCurrentAuthorityPackage::encodeCanonical($row),
                );
                $index++;
            }
            unset($rows);
            gc_collect_cycles();
        }
        hash_update($context, ']');

        return hash_final($context);
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $row */
    private function assertCachedPayload(array $entry, array $row, string $locale, bool $prepared): void
    {
        $payload = $prepared
            ? $this->cache->preparedPayload($entry)
            : ($entry['payload'] ?? null);
        if (! is_array($payload) || ! is_array(data_get($payload, 'display_surface_v1'))) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_PAYLOAD_MISSING');
        }
        $mismatchCode = $this->stateMachine->payloadMismatchCode($payload, $row, $locale);
        if ($mismatchCode !== null) {
            $displayFailureCode = $this->displaySurfaceBuilder->diagnosticFailureCodeForSlug(
                (string) ($row['canonical_slug'] ?? ''),
                $locale,
            );
            if ($displayFailureCode !== null) {
                throw new CareerCurrentAuthorityPublisherFailure($displayFailureCode);
            }
            throw new CareerCurrentAuthorityPublisherFailure($mismatchCode);
        }
    }

    /** @param array<string,mixed>|null $payload @param array<string,mixed> $row */
    private function cachedPayloadMatches(?array $payload, array $row, string $locale): bool
    {
        return $this->stateMachine->payloadMatches($payload, $row, $locale);
    }

    /**
     * @param  array{entries:array<string,array<string,array<string,mixed>>>}  $authority
     * @param  list<string>  $slugs
     * @return list<array{string,string}>
     */
    private function staleCachePairs(array $authority, array $slugs): array
    {
        $pairs = [];
        foreach ($this->compatibility->batches($slugs) as $chunk) {
            $rows = $this->compatibility->rowsForSlugs($authority, $chunk);
            $cache = $this->cache->publicationSnapshot($chunk, CareerCurrentAuthorityPackage::LOCALES);
            foreach ($chunk as $slug) {
                if ($this->isManualHoldSlug($slug)) {
                    continue;
                }
                foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                    $entry = $cache[$slug][$locale] ?? null;
                    if (! is_array($entry)
                        || ($entry['published'] ?? null) !== true
                        || ($entry['classification'] ?? null) !== 'ready_active'
                        || ! $this->cachedPayloadMatches(
                            is_array($entry['payload'] ?? null) ? $entry['payload'] : null,
                            $rows[$slug],
                            $locale,
                        )) {
                        $pairs[] = [$slug, $locale];
                    }
                }
            }
            unset($cache, $rows);
            $this->stateMachine->releaseLoadedContentPages();
            gc_collect_cycles();
        }

        return $pairs;
    }

    /**
     * @param  array{entries:array<string,array<string,array<string,mixed>>>}  $authority
     * @param  list<string>  $slugs
     * @return array<string,mixed>
     */
    private function assertPublicReadback(array $authority, array $slugs): array
    {
        $hashes = [];
        foreach ($this->compatibility->batches($slugs) as $chunk) {
            $rows = $this->compatibility->rowsForSlugs($authority, $chunk);
            $cache = $this->cache->publicationSnapshot($chunk, CareerCurrentAuthorityPackage::LOCALES);
            foreach ($chunk as $slug) {
                foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                    $entry = $cache[$slug][$locale] ?? null;
                    if (! is_array($entry)
                        || ($entry['published'] ?? null) !== true
                        || ($entry['classification'] ?? null) !== 'ready_active') {
                        throw new CareerCurrentAuthorityPublisherFailure('CURRENT_ACTIVE_CACHE_READBACK_FAILED');
                    }
                    $this->assertCachedPayload($entry, $rows[$slug], $locale, false);
                    $api = $this->cache->verifyOnlyRead($slug, $locale);
                    if (($api['state'] ?? null) !== 'fresh' || ! is_array($api['payload'] ?? null)) {
                        throw new CareerCurrentAuthorityPublisherFailure('CURRENT_API_READBACK_FAILED');
                    }
                    $this->assertCachedPayload(['payload' => $api['payload']], $rows[$slug], $locale, false);
                    $hashes[] = $this->package->publicContentHash($rows[$slug], $locale);
                }
            }
            unset($cache, $rows);
            $this->stateMachine->releaseLoadedContentPages();
            gc_collect_cycles();
        }
        sort($hashes, SORT_STRING);

        return [
            'verified_slug_count' => count($slugs),
            'verified_locale_page_count' => count($hashes),
            'cache_content_match_count' => count($hashes),
            'api_content_match_count' => count($hashes),
            'aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($hashes),
        ];
    }

    private function assertManualHold(): void
    {
        foreach (self::MANUAL_HOLD_SLUGS as $slug) {
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $read = $this->cache->verifyOnlyRead($slug, $locale);
                if (($read['state'] ?? null) !== 'not_found' || ($read['payload'] ?? null) !== null) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_MANUAL_HOLD_PUBLIC_DRIFT');
                }
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function assertAccountantsBoundaryNotice(array $row): void
    {
        $pages = $row['page_payload_json']['page'] ?? $row['page_payload_json'] ?? null;
        foreach (['en', 'zh'] as $locale) {
            $notices = is_array($pages) && is_array($pages[$locale] ?? null)
                ? $pages[$locale]['boundary_notice'] ?? null
                : null;
            if (! is_array($notices) || ! array_is_list($notices) || $notices === []) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_ACCOUNTANTS_BOUNDARY_READBACK_INVALID');
            }
            foreach ($notices as $notice) {
                if (! is_string($notice) || trim($notice) === '') {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_ACCOUNTANTS_BOUNDARY_READBACK_INVALID');
                }
            }
        }
    }

    /** @param list<string> $changedSlugs @param list<string> $targetSlugs @return list<string> */
    private function verificationSlugs(array $changedSlugs, array $targetSlugs): array
    {
        $publicTargetSlugs = $this->publicSlugs($targetSlugs);
        $samples = array_values(array_intersect(self::HEALTH_SAMPLE_SLUGS, $publicTargetSlugs));
        if ($samples === [] && $publicTargetSlugs !== []) {
            $samples[] = $publicTargetSlugs[0];
        }
        $slugs = array_values(array_unique(array_merge($this->publicSlugs($changedSlugs), $samples)));
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /** @param list<string> $slugs @return list<string> */
    private function publicSlugs(array $slugs): array
    {
        return array_values(array_filter(
            $slugs,
            fn (string $slug): bool => ! $this->isManualHoldSlug($slug),
        ));
    }

    private function isManualHoldSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::MANUAL_HOLD_SLUGS, true);
    }
}
