<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Domain\Career\Publish\CareerGenerationAuthorityLoader;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerRuntimePublishProjectionLookupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('app/private/career_generation_authority');
        File::deleteDirectory($this->root);
        File::deleteDirectory(storage_path('app/private/career_runtime_publish_projection'));
        File::deleteDirectory(storage_path('app/private/career_release_ledger'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory(storage_path('app/private/career_runtime_publish_projection'));
        File::deleteDirectory(storage_path('app/private/career_release_ledger'));

        parent::tearDown();
    }

    public function test_it_reads_only_the_generation_bound_published_projection(): void
    {
        $this->writeGeneration('generation-001', [
            'actors' => 'published',
            'software-developers' => 'quarantined',
        ]);

        $lookup = app(CareerRuntimePublishProjectionLookup::class);

        self::assertTrue($lookup->detailRouteEnabled('actors'));
        self::assertTrue($lookup->datasetVisible('actors'));
        self::assertTrue($lookup->releaseGatePass('actors'));
        self::assertNotNull($lookup->itemForSlug('actors', 'zh-CN'));
        self::assertSame(['actors'], array_column($lookup->publicDatasetItems(), 'slug'));
        self::assertFalse($lookup->detailRouteEnabled('software-developers'));
        self::assertNull($lookup->itemForSlug('software-developers'));
    }

    public function test_invalid_or_absent_pointer_never_scans_legacy_materialized_directories(): void
    {
        $legacyDir = storage_path('app/private/career_runtime_publish_projection/newest-by-mtime');
        File::ensureDirectoryExists($legacyDir);
        File::put($legacyDir.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME, json_encode([
            'projection_kind' => 'career_runtime_publish_projection',
            'items' => $this->projectionItems(['actors' => 'published']),
        ], JSON_THROW_ON_ERROR));

        $lookup = app(CareerRuntimePublishProjectionLookup::class);
        self::assertNull($lookup->itemForSlug('actors'));
        self::assertSame([], $lookup->publicDetailItems());

        File::ensureDirectoryExists($this->root);
        File::put($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, '{invalid');

        self::assertNull($lookup->itemForSlug('actors'));
        self::assertSame([], $lookup->publicDatasetItems());
    }

    public function test_pointer_payload_hash_schema_count_and_set_drift_fail_closed(): void
    {
        $document = $this->writeGeneration('generation-001', ['actors' => 'published']);

        foreach ([
            function (array &$value): void {
                $value['schema_version'] = 'career.generation_pointer.unknown';
            },
            function (array &$value): void {
                $value['payload']['counts']['public_slug_count'] = 2;
                $this->rehash($value);
            },
            function (array &$value): void {
                $value['payload']['authority']['target_slug_set_sha256'] = str_repeat('f', 64);
                $this->rehash($value);
            },
            function (array &$value): void {
                $value['payload_sha256'] = str_repeat('0', 64);
            },
        ] as $mutate) {
            $changed = $document;
            $mutate($changed);
            $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $changed);

            self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());
        }
    }

    public function test_path_traversal_and_symlinked_artifacts_fail_closed(): void
    {
        $document = $this->writeGeneration('generation-001', ['actors' => 'published']);
        $document['payload']['artifacts']['projection']['path'] = '../career-runtime-publish-projection.json';
        $this->rehash($document);
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);
        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());

        $document = $this->writeGeneration('generation-002', ['actors' => 'published']);
        $projectionPath = $this->root.'/'.$document['payload']['artifacts']['projection']['path'];
        $outside = storage_path('app/testing/career-generation-outside.json');
        File::ensureDirectoryExists(dirname($outside));
        File::put($outside, (string) file_get_contents($projectionPath));
        unlink($projectionPath);
        symlink($outside, $projectionPath);

        try {
            self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());
        } finally {
            @unlink($projectionPath);
            @unlink($outside);
        }
    }

    public function test_projection_and_ledger_must_bind_the_same_generation_and_slug_set(): void
    {
        $document = $this->writeGeneration('generation-001', [
            'actors' => 'published',
            'actuaries' => 'blocked',
        ]);
        $ledgerPath = $this->root.'/'.$document['payload']['artifacts']['ledger']['path'];
        $ledger = json_decode((string) file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
        $ledger['members'] = [['canonical_slug' => 'actors']];
        $this->writeJson($ledgerPath, $ledger);
        $document['payload']['artifacts']['ledger']['sha256'] = hash_file('sha256', $ledgerPath);
        $this->rehash($document);
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);

        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());

        $document = $this->writeGeneration('generation-002', ['actors' => 'published']);
        $projectionPath = $this->root.'/'.$document['payload']['artifacts']['projection']['path'];
        $projection = json_decode((string) file_get_contents($projectionPath), true, 512, JSON_THROW_ON_ERROR);
        $projection['generation_id'] = 'different-generation';
        $this->writeJson($projectionPath, $projection);
        $document['payload']['artifacts']['projection']['sha256'] = hash_file('sha256', $projectionPath);
        $this->rehash($document);
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);

        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());
    }

    public function test_projection_and_ledger_must_embed_the_exact_manifest_authority(): void
    {
        $document = $this->writeGeneration('generation-001', ['actors' => 'published']);
        $document['payload']['authority']['frozen_manifest_sha256'] = str_repeat('9', 64);
        $this->rehash($document);
        $this->writeJson(
            $this->root.'/generations/generation-001/'.CareerGenerationAuthorityLoader::GENERATION_POINTER_FILENAME,
            $document,
        );
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);

        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());
    }

    public function test_explicit_legacy_exact_bytes_bootstrap_binds_existing_artifacts_without_rewriting_them(): void
    {
        $document = $this->writeLegacyBootstrapGeneration();
        $projectionPath = storage_path('app/private/'.$document['payload']['artifacts']['projection']['path']);
        $ledgerPath = storage_path('app/private/'.$document['payload']['artifacts']['ledger']['path']);
        $projectionBefore = hash_file('sha256', $projectionPath);
        $ledgerBefore = hash_file('sha256', $ledgerPath);

        $projection = (new CareerGenerationAuthorityLoader)->activeProjection();

        self::assertIsArray($projection);
        self::assertSame(2, count($projection['items']));
        self::assertSame($projectionBefore, hash_file('sha256', $projectionPath));
        self::assertSame($ledgerBefore, hash_file('sha256', $ledgerPath));

        $document['payload']['artifact_format'] = CareerGenerationAuthorityLoader::ARTIFACT_FORMAT_GENERATION_NATIVE;
        $this->rehash($document);
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);
        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());
    }

    public function test_legacy_exact_bytes_format_is_bootstrap_only_and_rejects_unbounded_paths(): void
    {
        $document = $this->writeLegacyBootstrapGeneration();
        $document['payload']['artifacts']['projection']['path'] = 'career_runtime_publish_projection/../escape/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
        $this->rehash($document);
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);
        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());

        $document = $this->writeLegacyBootstrapGeneration();
        $document['payload']['lineage']['previous_generation_id'] = 'generation-before-bootstrap';
        $document['payload']['lineage']['previous_pointer_sha256'] = str_repeat('4', 64);
        $document['payload']['rollback']['eligible'] = true;
        $document['payload']['rollback']['previous_generation_id'] = 'generation-before-bootstrap';
        $this->rehash($document);
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);
        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());
    }

    public function test_unauthorized_cohort_shrink_is_rejected(): void
    {
        $previous = $this->writeGeneration('generation-001', [
            'actors' => 'published',
            'actuaries' => 'published',
        ]);
        $this->writeGeneration(
            generationId: 'generation-002',
            states: ['actors' => 'published', 'actuaries' => 'blocked'],
            previousDocument: $previous,
        );

        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());
    }

    public function test_equal_count_cohort_replacement_still_requires_revocation_receipts(): void
    {
        $previous = $this->writeGeneration('generation-001', [
            'actors' => 'published',
            'actuaries' => 'published',
        ]);
        $this->writeGeneration(
            generationId: 'generation-002',
            states: ['actors' => 'published', 'software-developers' => 'published'],
            previousDocument: $previous,
        );

        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());
    }

    public function test_exact_per_slug_revocation_receipt_allows_an_explicit_shrink(): void
    {
        $previous = $this->writeGeneration('generation-001', [
            'actors' => 'published',
            'actuaries' => 'published',
        ]);
        $this->writeGeneration(
            generationId: 'generation-002',
            states: ['actors' => 'published', 'actuaries' => 'blocked'],
            previousDocument: $previous,
            revokedSlugs: ['actuaries'],
        );

        $projection = (new CareerGenerationAuthorityLoader)->activeProjection();
        self::assertIsArray($projection);
        self::assertSame(4, count($projection['items']));
        self::assertTrue(app(CareerRuntimePublishProjectionLookup::class)->detailRouteEnabled('actors'));
        self::assertFalse(app(CareerRuntimePublishProjectionLookup::class)->detailRouteEnabled('actuaries'));
    }

    public function test_lkg_is_accepted_only_as_an_exact_same_generation_byte_copy(): void
    {
        $document = $this->writeGeneration('generation-001', ['actors' => 'published'], writeLkg: true);
        $projectionPath = $this->root.'/'.$document['payload']['artifacts']['projection']['path'];
        File::put($projectionPath, '{corrupt-primary');

        self::assertIsArray((new CareerGenerationAuthorityLoader)->activeProjection());

        $document['payload']['artifacts']['projection_lkg']['identity'] = 'career-runtime-publish-projection@other-generation';
        $this->rehash($document);
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);

        self::assertNull((new CareerGenerationAuthorityLoader)->activeProjection());
    }

    /**
     * @param  array<string, string>  $states
     * @param  list<string>|null  $revokedSlugs
     * @return array<string, mixed>
     */
    private function writeGeneration(
        string $generationId,
        array $states,
        ?array $previousDocument = null,
        ?array $revokedSlugs = null,
        bool $writeLkg = false,
    ): array {
        $generationDir = $this->root.'/generations/'.$generationId;
        File::ensureDirectoryExists($generationDir);

        $projection = [
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'source_authority' => 'CareerFullReleaseLedger',
            'generation_id' => $generationId,
            'artifact_identity' => 'career-runtime-publish-projection@'.$generationId,
            'items' => $this->projectionItems($states),
        ];
        $projectionPath = $generationDir.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
        $this->writeJson($projectionPath, $projection);

        $slugs = array_keys($states);
        sort($slugs, SORT_STRING);
        $ledger = [
            'ledger_kind' => 'career_full_release_ledger',
            'ledger_version' => 'career.full_release_ledger.v1',
            'generation_id' => $generationId,
            'artifact_identity' => 'career-full-release-ledger@'.$generationId,
            'members' => array_map(static fn (string $slug): array => ['canonical_slug' => $slug], $slugs),
        ];
        $ledgerPath = $generationDir.'/'.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME;
        $this->writeJson($ledgerPath, $ledger);

        $localeRows = [];
        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $localeRows[] = $slug.'|'.$locale;
            }
        }
        $publicSlugs = array_keys(array_filter($states, static fn (string $state): bool => $state === 'published'));
        $previousGenerationId = data_get($previousDocument, 'payload.generation_id');
        $authority = [
            'frozen_manifest_sha256' => str_repeat('1', 64),
            'target_slug_set_sha256' => CareerGenerationCanonicalJson::setSha256($slugs),
            'target_locale_row_set_sha256' => CareerGenerationCanonicalJson::setSha256($localeRows),
            'receipt_set_sha256' => str_repeat('2', 64),
        ];
        $projection['generation_authority'] = $authority;
        $ledger['generation_authority'] = $authority;
        $this->writeJson($projectionPath, $projection);
        $this->writeJson($ledgerPath, $ledger);
        $payload = [
            'generation_id' => $generationId,
            'artifact_format' => CareerGenerationAuthorityLoader::ARTIFACT_FORMAT_GENERATION_NATIVE,
            'artifacts' => [
                'projection' => [
                    'identity' => 'career-runtime-publish-projection@'.$generationId,
                    'path' => 'generations/'.$generationId.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME,
                    'sha256' => hash_file('sha256', $projectionPath),
                ],
                'ledger' => [
                    'identity' => 'career-full-release-ledger@'.$generationId,
                    'path' => 'generations/'.$generationId.'/'.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME,
                    'sha256' => hash_file('sha256', $ledgerPath),
                ],
            ],
            'authority' => $authority,
            'counts' => [
                'public_slug_count' => count($publicSlugs),
                'public_locale_row_count' => count($publicSlugs) * 2,
            ],
            'lineage' => [
                'previous_generation_id' => $previousGenerationId,
                'previous_pointer_sha256' => $previousDocument === null
                    ? null
                    : CareerGenerationCanonicalJson::sha256($previousDocument),
            ],
            'timestamps' => [
                'created_at' => '2026-08-12T00:00:00Z',
                'activated_at' => '2026-08-12T00:00:01Z',
            ],
            'activation_receipt' => [
                'identity' => 'activation:'.$generationId,
                'sha256' => str_repeat('3', 64),
            ],
            'rollback' => [
                'eligible' => $previousGenerationId !== null,
                'previous_generation_id' => $previousGenerationId,
            ],
            'discoverability' => [
                'sitemap_mutated' => false,
                'llms_mutated' => false,
                'search_mutated' => false,
            ],
            'revocation_receipt' => null,
        ];

        if ($writeLkg) {
            $lkgPath = $generationDir.'/lkg-'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
            File::copy($projectionPath, $lkgPath);
            $payload['artifacts']['projection_lkg'] = [
                'identity' => 'career-runtime-publish-projection@'.$generationId,
                'path' => 'generations/'.$generationId.'/lkg-'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME,
                'sha256' => hash_file('sha256', $lkgPath),
            ];
        }

        if ($revokedSlugs !== null) {
            $receipt = [
                'schema_version' => CareerGenerationAuthorityLoader::REVOCATION_SCHEMA_VERSION,
                'from_generation_id' => $previousGenerationId,
                'to_generation_id' => $generationId,
                'items' => array_map(static fn (string $slug): array => [
                    'slug' => $slug,
                    'decision' => 'unpublished',
                    'receipt_identity' => 'revocation:'.$generationId.':'.$slug,
                    'approved_at' => '2026-08-12T00:00:00Z',
                ], $revokedSlugs),
            ];
            $receiptPath = $generationDir.'/revocation-receipt.json';
            $this->writeJson($receiptPath, $receipt);
            $payload['revocation_receipt'] = [
                'identity' => 'career-generation-revocations@'.$generationId,
                'path' => 'generations/'.$generationId.'/revocation-receipt.json',
                'sha256' => hash_file('sha256', $receiptPath),
            ];
        }

        $document = [
            'schema_version' => CareerGenerationAuthorityLoader::POINTER_SCHEMA_VERSION,
            'payload_sha256' => CareerGenerationCanonicalJson::sha256($payload),
            'payload' => $payload,
        ];
        $this->writeJson($generationDir.'/'.CareerGenerationAuthorityLoader::GENERATION_POINTER_FILENAME, $document);
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);

        return $document;
    }

    /** @return array<string, mixed> */
    private function writeLegacyBootstrapGeneration(): array
    {
        $generationId = 'bootstrap-generation-001';
        $sourceId = 'frozen-342-authority';
        $projectionRelativePath = 'career_runtime_publish_projection/'.$sourceId.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
        $ledgerRelativePath = 'career_release_ledger/'.$sourceId.'/'.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME;
        $projectionPath = storage_path('app/private/'.$projectionRelativePath);
        $ledgerPath = storage_path('app/private/'.$ledgerRelativePath);
        $projection = [
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'source_authority' => 'CareerFullReleaseLedger',
            'items' => $this->projectionItems(['actors' => 'published']),
        ];
        $ledger = [
            'ledger_kind' => 'career_full_release_ledger',
            'ledger_version' => 'career.full_release_ledger.v1',
            'members' => [['canonical_slug' => 'actors']],
        ];
        $this->writeJson($projectionPath, $projection);
        $this->writeJson($ledgerPath, $ledger);

        $authority = [
            'frozen_manifest_sha256' => str_repeat('1', 64),
            'target_slug_set_sha256' => CareerGenerationCanonicalJson::setSha256(['actors']),
            'target_locale_row_set_sha256' => CareerGenerationCanonicalJson::setSha256(['actors|en', 'actors|zh']),
            'receipt_set_sha256' => str_repeat('2', 64),
        ];
        $payload = [
            'generation_id' => $generationId,
            'artifact_format' => CareerGenerationAuthorityLoader::ARTIFACT_FORMAT_LEGACY_EXACT_BYTES,
            'artifacts' => [
                'projection' => [
                    'identity' => 'career-runtime-publish-projection@'.$generationId,
                    'path' => $projectionRelativePath,
                    'sha256' => hash_file('sha256', $projectionPath),
                ],
                'ledger' => [
                    'identity' => 'career-full-release-ledger@'.$generationId,
                    'path' => $ledgerRelativePath,
                    'sha256' => hash_file('sha256', $ledgerPath),
                ],
            ],
            'authority' => $authority,
            'counts' => ['public_slug_count' => 1, 'public_locale_row_count' => 2],
            'lineage' => ['previous_generation_id' => null, 'previous_pointer_sha256' => null],
            'timestamps' => [
                'created_at' => '2026-08-12T00:00:00Z',
                'activated_at' => '2026-08-12T00:00:01Z',
            ],
            'activation_receipt' => [
                'identity' => 'activation:'.$generationId,
                'sha256' => str_repeat('3', 64),
            ],
            'rollback' => ['eligible' => false, 'previous_generation_id' => null],
            'discoverability' => [
                'sitemap_mutated' => false,
                'llms_mutated' => false,
                'search_mutated' => false,
            ],
            'revocation_receipt' => null,
        ];
        $document = [
            'schema_version' => CareerGenerationAuthorityLoader::POINTER_SCHEMA_VERSION,
            'payload_sha256' => CareerGenerationCanonicalJson::sha256($payload),
            'payload' => $payload,
        ];
        $this->writeJson($this->root.'/generations/'.$generationId.'/'.CareerGenerationAuthorityLoader::GENERATION_POINTER_FILENAME, $document);
        $this->writeJson($this->root.'/'.CareerGenerationAuthorityLoader::ACTIVE_POINTER_FILENAME, $document);

        return $document;
    }

    /** @param array<string, string> $states @return list<array<string, mixed>> */
    private function projectionItems(array $states): array
    {
        $items = [];
        foreach ($states as $slug => $state) {
            foreach (['en', 'zh'] as $locale) {
                $published = $state === 'published';
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'public_resolution_type' => 'public_canonical_job',
                    'runtime_publish_state' => $state,
                    'detail_route_enabled' => $published,
                    'dataset_visible' => $published,
                    'search_visible' => $published,
                    'sitemap_live' => false,
                    'llms_live' => false,
                    'llms_full_live' => false,
                    'canonical_self' => $published,
                    'robots_indexable' => $published,
                    'release_gate_pass' => $published,
                ];
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $document */
    private function rehash(array &$document): void
    {
        $document['payload_sha256'] = CareerGenerationCanonicalJson::sha256($document['payload']);
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        )."\n");
    }
}
