<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentIdentity;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\SeoIntel\Sources\CurrentPublicUrlAuthoritySource;
use App\Services\SeoIntel\UrlTruth\EffectivePublicUrlEvaluator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SeoCareerCurrentRevisionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/seo-career-current-'.bin2hex(random_bytes(8));
        foreach (['actors', 'actuaries'] as $slug) {
            mkdir($this->root.'/content_assets/career/current/careers/'.$slug, 0700, true);
            foreach (['en', 'zh-CN'] as $locale) {
                $this->writePage($slug, $locale, [
                    'contract_version' => 'career.detail.content.v3', 'locale' => $locale,
                    'subject' => ['canonical_slug' => $slug, 'name' => $slug, 'summary' => null],
                    'content_state' => 'enhanced', 'source_content_sha256' => str_repeat('a', 64),
                    'blocks' => [[
                        'id' => 'profile', 'copy_key' => 'career.block.profile', 'content_state' => 'enhanced',
                        'availability' => 'available', 'items' => [[
                            'id' => 'profile-1', 'copy_key' => 'career.item.definition', 'type' => 'prose',
                            'availability' => 'available', 'data' => ['paragraphs' => ['Published body.']],
                        ]],
                    ]],
                ]);
            }
        }
        $this->refreshManifest();
        $package = new CareerContentV3AuthorityPackage(2, 4, null, CareerCurrentAuthorityPackage::hashValue(['actors', 'actuaries']));
        $reader = new CareerContentV3CanonicalReader($package, $this->root);
        app()->instance(CareerContentV3AuthorityPackage::class, $package);
        app()->instance(CareerContentV3CanonicalReader::class, $reader);
        app()->instance(CareerCurrentIdentity::class, new CareerCurrentIdentity($reader));
        config(['seo_intel.enabled' => false]);
        $this->directory(['actors', 'actuaries']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_revision_binds_verified_page_bytes_and_preserves_identity_and_qualification(): void
    {
        $records = $this->records();
        $this->assertCount(4, $records);
        foreach ($records as $record) {
            $expected = hash_file('sha256', $this->path($record->entityIdOrSlug, $record->locale));
            $this->assertSame($expected, $record->metadata['authority_revision']);
            $this->assertSame($expected, $record->attributes['authority_revision']);
            $this->assertTrue((new EffectivePublicUrlEvaluator)->evaluate($record)['effective_public']);
            $this->assertSame('career_runtime_publish_projection', $record->sourceAuthority);
            $this->assertSame('career_directory_authority', $record->entitySource);
            $this->assertSame('published_approved', $record->authorityStatus);
        }
    }

    public function test_single_page_current_field_change_refreshes_a_reused_source_without_other_page_or_clock_churn(): void
    {
        $source = app(CurrentPublicUrlAuthoritySource::class);
        $before = $this->revisions($source);
        Carbon::setTestNow('2030-01-01');
        $this->assertSame($before, $this->revisions($source));
        $page = $this->page('actors', 'en');
        $legacyBinding = $page['source_content_sha256'];
        $page['subject']['summary'] = 'New publicly owned summary.';
        $this->writePage('actors', 'en', $page);
        $this->refreshManifest();
        $after = $this->revisions($source);
        $this->assertNotSame($before['actors|en'], $after['actors|en']);
        $this->assertSame($legacyBinding, $this->page('actors', 'en')['source_content_sha256']);
        foreach (['actors|zh-CN', 'actuaries|en', 'actuaries|zh-CN'] as $key) {
            $this->assertSame($before[$key], $after[$key]);
        }
    }

    public function test_legacy_empty_and_unpublished_identities_remain_manifest_only(): void
    {
        $page = $this->page('actors', 'en');
        $page['blocks'] = [];
        $page['content_state'] = 'legacy';
        $this->writePage('actors', 'en', $page);
        $this->refreshManifest();
        $this->directory(['actors']); // Other valid files alone never grant publication.
        $records = app(CurrentPublicUrlAuthoritySource::class)->candidates();
        foreach ($records as $record) {
            if ($record->pageEntityType !== 'career_job') {
                continue;
            }
            $expected = $record->entityIdOrSlug === 'actors' && $record->locale === 'zh-CN';
            $this->assertSame($expected, (new EffectivePublicUrlEvaluator)->evaluate($record)['effective_public']);
        }
        $this->assertCount(1, $this->records());
    }

    public static function invalidPages(): iterable
    {
        yield 'file hash drift' => ['hash', 'CURRENT_CONTENT_V3_FILE_HASH_MISMATCH'];
        yield 'missing file' => ['missing', 'CURRENT_CONTENT_V3_FILE_MISSING'];
        yield 'locale drift' => ['locale', 'CURRENT_CONTENT_V3_FILE_IDENTITY_MISMATCH'];
        yield 'invalid schema' => ['schema', 'CURRENT_CONTENT_V3_INVALID'];
        yield 'stale compiled body claim' => ['body', 'PUBLIC_AUTHORITY_CAREER_BODY_MISMATCH'];
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_current_page_fails_closed_instead_of_falling_back_or_shrinking_the_denominator(string $change, string $code): void
    {
        $this->records(); // Populate the shared reader to exercise refresh on the next call.
        if ($change === 'missing') {
            unlink($this->path('actors', 'en'));
        } elseif ($change === 'hash') {
            file_put_contents($this->path('actors', 'en'), " \n", FILE_APPEND);
        } else {
            $page = $this->page('actors', 'en');
            if ($change === 'locale') {
                $page['locale'] = 'zh-CN';
            } elseif ($change === 'schema') {
                $page['contract_version'] = 'unknown';
            } else {
                $page['blocks'] = [];
            }
            $this->writePage('actors', 'en', $page);
            $this->refreshManifest($change === 'body');
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($code);
        $this->records();
    }

    private function records(): array
    {
        return array_values(array_filter(app(CurrentPublicUrlAuthoritySource::class)->candidates(), static fn ($record) => $record->sourceAuthority === 'career_runtime_publish_projection'));
    }

    private function revisions(CurrentPublicUrlAuthoritySource $source): array
    {
        $revisions = [];
        foreach ($source->candidates() as $record) {
            if ($record->sourceAuthority === 'career_runtime_publish_projection') {
                $revisions[$record->entityIdOrSlug.'|'.$record->locale] = $record->metadata['authority_revision'];
            }
        }

        return $revisions;
    }

    private function directory(array $slugs): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $pointer = (new \ReflectionMethod($cache, 'directoryActiveVersionKey'))->invoke($cache, $locale);
            $key = (new \ReflectionMethod($cache, 'directoryVersionPayloadKey'))->invoke($cache, $locale, 'fixture');
            Cache::forever($pointer, 'fixture');
            Cache::forever($key, ['items' => array_map(static fn ($slug) => [
                'slug' => $slug, 'canonical_path' => '/'.($locale === 'en' ? 'en' : 'zh').'/career/jobs/'.$slug,
                'indexable' => true, 'detail_ready' => true,
            ], $slugs)]);
        }
    }

    private function path(string $slug, string $locale): string
    {
        return $this->root.'/content_assets/career/current/careers/'.$slug.'/'.$locale.'.json';
    }

    private function page(string $slug, string $locale): array
    {
        return json_decode(file_get_contents($this->path($slug, $locale)), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writePage(string $slug, string $locale, array $page): void
    {
        file_put_contents($this->path($slug, $locale), CareerCurrentAuthorityPackage::encodePrettyCanonical($page));
    }

    private function refreshManifest(bool $claimBody = false): void
    {
        $files = [];
        foreach (['actors', 'actuaries'] as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $bytes = file_get_contents($this->path($slug, $locale));
                $files[] = [
                    'bytes' => strlen($bytes), 'canonical_slug' => $slug,
                    'legacy_projection_sha256' => str_repeat('b', 64), 'legacy_row_sha256' => str_repeat('c', 64),
                    'locale' => $locale, 'path' => 'careers/'.$slug.'/'.$locale.'.json',
                    'sha256' => hash('sha256', $bytes), 'source_content_sha256' => str_repeat('a', 64),
                    'body_qualification' => ['version' => CareerContentV3CanonicalReader::BODY_QUALIFICATION_VERSION,
                        'source_content_sha256' => str_repeat('a', 64),
                        'has_public_body' => $claimBody || $this->page($slug, $locale)['blocks'] !== []],
                ];
            }
        }
        $manifest = [
            'authority_path' => 'backend/content_assets/career/current', 'compiler_version' => CareerContentV3AuthorityPackage::COMPILER_VERSION,
            'contract_version' => CareerContentV3AuthorityPackage::CONTRACT_VERSION,
            'coverage' => ['slugs' => 2, 'locales' => 2, 'locale_pages' => 4, 'files' => 4, 'enhanced_locale_pages' => count(array_filter($files, fn ($entry) => $this->page($entry['canonical_slug'], $entry['locale'])['content_state'] === 'enhanced')), 'legacy_locale_pages' => count(array_filter($files, fn ($entry) => $this->page($entry['canonical_slug'], $entry['locale'])['content_state'] === 'legacy'))],
            'files' => $files, 'locales' => ['en', 'zh-CN'], 'schema_version' => CareerContentV3AuthorityPackage::SCHEMA_VERSION,
            'set_hashes' => [
                'legacy_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue(array_column($files, 'legacy_projection_sha256')),
                'legacy_versionless_projection_sha256' => str_repeat('b', 64),
                'locale_page_set_sha256' => CareerCurrentAuthorityPackage::hashValue(['actors|en', 'actors|zh-CN', 'actuaries|en', 'actuaries|zh-CN']),
                'slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue(['actors', 'actuaries']),
                'source_semantic_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue(array_column($files, 'source_content_sha256')),
            ], 'source_registry_sha256' => str_repeat('c', 64),
        ];
        $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($manifest);
        file_put_contents($this->root.'/content_assets/career/current/manifest.json', CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest));
    }
}
