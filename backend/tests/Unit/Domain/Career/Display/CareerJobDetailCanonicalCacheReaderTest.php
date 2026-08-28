<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader;
use App\Services\ReviewGovernance\PublicReviewContract;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class CareerJobDetailCanonicalCacheReaderTest extends TestCase
{
    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if (is_string($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->fixtureRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->fixtureRoot);
        }
        parent::tearDown();
    }

    public function test_it_reads_gzip_and_legacy_payloads_with_identical_file_backed_content_v3(): void
    {
        [$reader, $payload, $pages] = $this->fixtureReader();

        $gzip = $reader->read($reader->encode($payload), 'actors', 'en');
        $legacy = $reader->read($payload, 'actors', 'en');

        self::assertTrue($reader->isSupportedEnvelope($reader->encode($payload)));
        self::assertFalse($reader->isSupportedEnvelope($payload));
        self::assertSame($gzip, $legacy);
        self::assertSame(
            CareerCurrentAuthorityPackage::hashValue($pages['en']),
            CareerCurrentAuthorityPackage::hashValue(data_get($gzip, 'display_surface_v1.content_v3')),
        );
        self::assertSame('legacy title', data_get($gzip, 'display_surface_v1.page.content.hero.title'));
    }

    public function test_it_replaces_legacy_body_drift_but_fails_closed_for_identity_or_envelope_corruption(): void
    {
        [$reader, $payload, $pages] = $this->fixtureReader();
        $stored = $reader->encode($payload);
        $stored['sha256'] = str_repeat('0', 64);

        self::assertNull($reader->read($stored, 'actors', 'en'));
        self::assertNull($reader->read([
            'codec' => 'career.job-detail.unknown.v2',
            'payload' => '',
            'sha256' => str_repeat('0', 64),
        ], 'actors', 'en'));

        data_set($payload, 'display_surface_v1.page.content.hero.title', 'drifted');
        $hydrated = $reader->read($payload, 'actors', 'en');
        self::assertSame('drifted', data_get($hydrated, 'display_surface_v1.page.content.hero.title'));
        self::assertSame(
            CareerCurrentAuthorityPackage::hashValue($pages['en']),
            CareerCurrentAuthorityPackage::hashValue(data_get($hydrated, 'display_surface_v1.content_v3')),
        );

        data_set($payload, 'display_surface_v1.page.locale', 'zh-CN');
        self::assertNull($reader->read($payload, 'actors', 'en'));

        data_set($payload, 'display_surface_v1.page.locale', 'en');
        data_set($payload, 'display_surface_v1.subject.canonical_slug', 'accountants-and-auditors');
        self::assertNull($reader->read($payload, 'actors', 'en'));
    }

    public function test_placeholder_authority_removes_every_legacy_visible_body(): void
    {
        [$reader, $payload] = $this->fixtureReader(true);

        $hydrated = $reader->read($payload, 'actors', 'en');

        self::assertSame([], data_get($hydrated, 'display_surface_v1.page.content'));
        self::assertSame([], data_get($hydrated, 'display_surface_v1.component_order'));
        self::assertSame([], data_get($hydrated, 'display_surface_v1.sources'));
        self::assertSame([], data_get($hydrated, 'display_surface_v1.structured_data_from_visible_content'));
        self::assertSame([], data_get($hydrated, 'display_surface_v1.implementation_contract'));
        self::assertArrayNotHasKey('presentation_v1', (array) data_get($hydrated, 'display_surface_v1'));
        self::assertArrayNotHasKey('presentation_v2', (array) data_get($hydrated, 'display_surface_v1'));
        self::assertSame([], data_get($hydrated, 'display_surface_v1.content_v3.blocks'));
    }

    public function test_it_normalizes_reviews_and_validates_snapshot_identity(): void
    {
        [$reader] = $this->fixtureReader();
        $normalized = $reader->normalizeReviewContainer([
            'reviewer_status' => 'human_reviewed',
            'reviewed_at' => '2026-08-28T00:00:00Z',
        ]);

        self::assertSame('approved', $normalized['review_state']);
        self::assertNull($normalized['reviewer']);
        self::assertTrue($reader->snapshotIsValid(
            ['slug' => 'actors', 'locale' => 'en', 'state' => 'published'],
            'actors',
            'en',
            static fn (array $snapshot): bool => $snapshot['state'] === 'published',
        ));
        self::assertFalse($reader->snapshotIsValid(
            ['slug' => 'actors', 'locale' => 'zh-CN', 'state' => 'published'],
            'actors',
            'en',
            static fn (): bool => true,
        ));
    }

    /** @return array{CareerJobDetailCanonicalCacheReader,array<string,mixed>,array<string,array<string,mixed>>} */
    private function fixtureReader(bool $placeholder = false): array
    {
        $root = tempnam(sys_get_temp_dir(), 'career-cache-v3-');
        self::assertIsString($root);
        unlink($root);
        $current = $root.'/content_assets/career/current';
        self::assertTrue(mkdir($current.'/careers/actors', 0700, true));
        $this->fixtureRoot = $root;

        $surface = [
            'surface_version' => 'display.surface.v1',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'available_locales' => ['en', 'zh-CN'],
            'page' => ['locale' => 'en', 'content' => ['hero' => ['title' => 'legacy title']]],
            'component_order' => ['hero'],
            'sources' => ['references' => []],
            'structured_data_from_visible_content' => ['@type' => 'Occupation'],
            'implementation_contract' => ['version' => 'v1'],
        ];
        $pages = [];
        $files = [];
        $semantics = [];
        $projections = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $localizedSurface = $surface;
            $localizedSurface['page']['locale'] = $locale;
            $sourceHash = CareerCurrentAuthorityPackage::hashValue($localizedSurface['page']['content']);
            $page = [
                'contract_version' => 'career.detail.content.v3',
                'locale' => $locale,
                'subject' => ['canonical_slug' => 'actors', 'name' => $locale === 'en' ? 'Actors' : '演员', 'summary' => null],
                'content_state' => 'legacy',
                'source_content_sha256' => $sourceHash,
                'blocks' => $placeholder ? [] : [[
                    'id' => 'profile', 'copy_key' => 'career.block.profile', 'content_state' => 'legacy',
                    'availability' => 'available', 'items' => [[
                        'id' => 'profile-1', 'copy_key' => 'career.item.definition', 'type' => 'prose',
                        'availability' => 'available', 'data' => ['paragraphs' => ['Body.']],
                    ]],
                ]],
            ];
            $pages[$locale] = $page;
            $path = 'careers/actors/'.$locale.'.json';
            $bytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($page);
            file_put_contents($current.'/'.$path, $bytes);
            $projectionHash = CareerCurrentAuthorityPackage::hashValue($localizedSurface);
            $files[] = [
                'bytes' => strlen($bytes), 'canonical_slug' => 'actors',
                'legacy_projection_sha256' => $projectionHash, 'legacy_row_sha256' => str_repeat('d', 64),
                'locale' => $locale, 'path' => $path, 'sha256' => hash('sha256', $bytes),
                'source_content_sha256' => $sourceHash,
            ];
            $semantics[] = $sourceHash;
            $projections[] = $projectionHash;
        }
        $manifest = [
            'aggregate_sha256' => '', 'authority_path' => 'backend/content_assets/career/current',
            'compiler_version' => CareerContentV3AuthorityPackage::COMPILER_VERSION,
            'contract_version' => CareerContentV3AuthorityPackage::CONTRACT_VERSION,
            'coverage' => ['slugs' => 1, 'locales' => 2, 'locale_pages' => 2, 'files' => 2, 'enhanced_locale_pages' => 0, 'legacy_locale_pages' => 2],
            'files' => $files, 'locales' => ['en', 'zh-CN'],
            'schema_version' => CareerContentV3AuthorityPackage::SCHEMA_VERSION,
            'set_hashes' => [
                'legacy_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($projections),
                'legacy_versionless_projection_sha256' => str_repeat('b', 64),
                'locale_page_set_sha256' => CareerCurrentAuthorityPackage::hashValue(['actors|en', 'actors|zh-CN']),
                'slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue(['actors']),
                'source_semantic_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($semantics),
            ],
            'source_registry_sha256' => str_repeat('c', 64),
        ];
        $projection = $manifest;
        unset($projection['aggregate_sha256']);
        $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($projection);
        file_put_contents($current.'/manifest.json', CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest));

        $package = new CareerContentV3AuthorityPackage(1, 2, 0, CareerCurrentAuthorityPackage::hashValue(['actors']));
        $canonical = new CareerContentV3CanonicalReader($package, $root);
        $reader = new CareerJobDetailCanonicalCacheReader(
            app(PublicReviewContract::class),
            $canonical,
        );

        return [$reader, ['display_surface_v1' => $surface], $pages];
    }
}
