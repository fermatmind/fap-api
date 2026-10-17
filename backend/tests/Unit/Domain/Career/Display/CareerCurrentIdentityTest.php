<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3PageUpdater;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;
use App\Domain\Career\Display\CareerCurrentIdentity;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\CareerCurrentPackageFixture;
use Tests\TestCase;

final class CareerCurrentIdentityTest extends TestCase
{
    public function test_selected_current_names_replace_legacy_titles_without_changing_scope(): void
    {
        $identity = app(CareerCurrentIdentity::class);
        $reader = app(\App\Domain\Career\Display\CareerContentV3CanonicalReader::class);
        foreach ([
            'agents-and-business-managers-of-artists-performers-and-athletes',
            'airline-pilots-copilots-and-flight-engineers',
            'animal-control-workers',
        ] as $slug) {
            self::assertNull($identity->definition($slug));
            foreach (['en', 'zh-CN'] as $locale) {
                $title = $reader->subjectName($slug, $locale);
                $old = [
                    'identity' => ['canonical_slug' => $slug, 'occupation_uuid' => 'retained-id'],
                    'title' => 'Old transport name',
                    'titles' => ['canonical_en' => 'Old name', 'canonical_zh' => '旧名称', 'short_title_zh' => '旧简称', 'search_h1_zh' => '旧搜索名'],
                    'soc_code' => 'unchanged-code',
                    'ontology' => ['crosswalks' => [['source_code' => 'retained-crosswalk']]],
                    'truth_layer' => ['jobs' => 42],
                    'matched_jobs' => [['canonical_slug' => $slug, 'title' => 'Old recommendation']],
                    'content_v3' => ['subject' => ['name' => 'Retained body']],
                ];
                $projected = $identity->projectPayload($old, $locale);
                self::assertSame($title, $projected['title']);
                self::assertSame($reader->subjectName($slug, 'en'), $projected['titles']['canonical_en']);
                foreach (['canonical_zh', 'short_title_zh', 'search_h1_zh'] as $key) {
                    self::assertSame($reader->subjectName($slug, 'zh-CN'), $projected['titles'][$key]);
                }
                self::assertSame($title, $projected['matched_jobs'][0]['title']);
                foreach (['identity', 'soc_code', 'ontology', 'truth_layer', 'content_v3'] as $key) {
                    self::assertSame($old[$key], $projected[$key]);
                }
                self::assertSame('Old transport name', $old['title']);
                self::assertSame($projected, $identity->projectPayload($projected, $locale));
                self::assertSame($slug, $identity->canonicalQuery($title));
            }
        }
        $unselected = ['canonical_slug' => 'actors', 'title' => 'Unselected name'];
        self::assertSame($unselected, $identity->projectPayload($unselected, 'zh-CN'));
        self::assertSame('未匹配职业', $identity->canonicalQuery('未匹配职业'));
    }

    public function test_selected_name_authority_failure_does_not_return_a_legacy_title(): void
    {
        $reader = $this->createStub(\App\Domain\Career\Display\CareerContentV3CanonicalReader::class);
        $reader->method('authority')->willReturn(['manifest' => []]);
        $reader->method('subjectName')->willThrowException(new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_MISSING'));
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        (new CareerCurrentIdentity($reader))->projectPayload(['canonical_slug' => 'animal-control-workers', 'title' => '动物工人'], 'zh-CN');
    }

    public function test_public_inventory_binds_storage_and_aliases_to_current_manifest(): void
    {
        $identity = app(CareerCurrentIdentity::class);
        $inventory = $identity->inventory();
        self::assertSame(1046, $inventory['storage_count']);
        self::assertSame(2092, $inventory['file_count']);
        self::assertCount(1046, array_unique($inventory['slugs']));
        self::assertSame(hash_file('sha256', base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json')), $inventory['manifest_sha256']);
        self::assertSame($identity->aliases(), (array) $inventory['aliases']);
        foreach ((array) $inventory['aliases'] as $alias => $target) {
            self::assertNotContains($alias, $inventory['slugs']);
            self::assertContains($target, $inventory['slugs']);
        }
    }

    public function test_all_fixed_slugs_keep_independent_bilingual_identity(): void
    {
        $identity = app(CareerCurrentIdentity::class);
        $reader = app(\App\Domain\Career\Display\CareerContentV3CanonicalReader::class);
        $inventory = $identity->inventory();
        foreach ($inventory['slugs'] as $slug) {
            self::assertFalse($identity->isAlias($slug), $slug);
            self::assertSame($slug, $identity->canonicalSlug($slug));
        }
        foreach (['preschool-teachers', 'preschool-teachers-except-special-education'] as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                self::assertSame($slug, $reader->page($slug, $locale)['subject']['canonical_slug']);
            }
        }
        self::assertSame('unmatched query', $identity->canonicalQuery('unmatched query'));
    }

    public function test_historical_url_aliases_outside_the_fixed_inventory_remain_valid(): void
    {
        $package = app(CareerContentV3AuthorityPackage::class);
        $manifest = $package->manifestIndex(base_path())['manifest'];
        $manifest['identity_aliases'] = ['old-preschool-teacher-url' => 'preschool-teachers'];
        $package->validateIdentityAliases($manifest, base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH));
        $reader = $this->createStub(\App\Domain\Career\Display\CareerContentV3CanonicalReader::class);
        $reader->method('authority')->willReturn(['manifest' => $manifest]);
        $identity = new CareerCurrentIdentity($reader);
        self::assertSame('preschool-teachers', $identity->canonicalSlug('old-preschool-teacher-url'));
        self::assertTrue($identity->isAlias('old-preschool-teacher-url'));
        self::assertFalse($identity->isAlias('preschool-teachers'));
        self::assertContains('old-preschool-teacher-url', $identity->searchTerms('preschool-teachers'));
    }

    public function test_shared_official_crosswalk_codes_do_not_merge_fixed_slugs(): void
    {
        $package = app(CareerContentV3AuthorityPackage::class);
        $manifest = $package->manifestIndex(base_path())['manifest'];
        $definition = [
            'scope_type' => 'exact_official_occupation',
            'occupations' => [['code' => '25-2011.00', 'title' => 'Preschool Teachers, Except Special Education']],
            'source_route_sha256' => hash('sha256', 'shared-official-reference'),
        ];
        $manifest['identity_scopes']['preschool-teachers'] = $definition;
        $manifest['identity_scopes']['preschool-teachers-except-special-education'] = $definition;
        $package->validateIdentityAliases($manifest, base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH));
        $reader = $this->createStub(\App\Domain\Career\Display\CareerContentV3CanonicalReader::class);
        $reader->method('authority')->willReturn(['manifest' => $manifest]);
        $identity = new CareerCurrentIdentity($reader);
        foreach (['preschool-teachers', 'preschool-teachers-except-special-education'] as $slug) {
            self::assertSame($slug, $identity->canonicalSlug($slug));
            self::assertFalse($identity->isAlias($slug));
            self::assertSame($definition, $identity->definition($slug));
        }
    }

    public function test_public_scope_overrides_stale_names_codes_and_statistics_without_mutating_input(): void
    {
        $slug = 'drywall-and-ceiling-tile-installers-and-tapers';
        $identity = app(CareerCurrentIdentity::class);
        $old = [
            'identity' => ['canonical_slug' => $slug, 'occupation_uuid' => 'retained-id'],
            'titles' => ['canonical_en' => 'Old installer', 'canonical_zh' => '旧安装工'],
            'ontology' => ['crosswalks' => [['source_system' => 'onet_soc_2019', 'source_code' => '47-2081.00']]],
            'truth_layer' => ['median_pay_usd_annual' => 1, 'jobs_2024' => 2, 'source_refs' => ['old']],
            'structured_data' => ['occupation' => ['name' => 'Old installer', 'estimatedSalary' => 1]],
        ];
        $public = $identity->projectPayload($old, 'zh-CN');
        self::assertSame('retained-id', $public['identity']['occupation_uuid']);
        self::assertSame('石膏板与吊顶板安装工及接缝处理工', $public['titles']['canonical_zh']);
        self::assertSame('Drywall and ceiling tile installers and tapers', $public['titles']['canonical_en']);
        self::assertSame(['47-2081.00', '47-2081', '47-2082.00', '47-2082'], array_column($public['ontology']['crosswalks'], 'source_code'));
        self::assertNull($public['truth_layer']['jobs_2024']);
        self::assertArrayNotHasKey('estimatedSalary', $public['structured_data']['occupation']);
        self::assertSame(1, $old['truth_layer']['median_pay_usd_annual']);
        self::assertSame($public, $identity->projectPayload($public, 'zh-CN'));
        self::assertSame($slug, $identity->canonicalQuery($public['titles']['canonical_zh']));
    }

    public function test_nested_detail_headings_inherit_current_identity_in_both_languages(): void
    {
        $identity = app(CareerCurrentIdentity::class);
        foreach (array_keys($identity->scopes()) as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $payload = [
                    'identity' => ['canonical_slug' => $slug],
                    'display_surface_v1' => [
                        'presentation_v2' => ['hero' => ['title' => 'Old name']],
                        'page' => ['content' => ['hero' => ['title' => 'Old name']]],
                        'content_v3' => ['subject' => ['name' => 'Retained body']],
                    ],
                ];
                $payload['display_surface_v1']['subject'] = ['canonical_slug' => $slug, 'onet_code' => 'old', 'soc_code' => 'old'];
                $payload['display_surface_v1']['presentation_v1']['hero'] = ['onet_code' => 'old', 'soc_code' => 'old'];
                $projected = $identity->projectPayload($payload, $locale);
                self::assertSame($identity->name($slug, $locale), data_get($projected, 'display_surface_v1.presentation_v2.hero.title'));
                self::assertSame($identity->name($slug, $locale), data_get($projected, 'display_surface_v1.page.content.hero.title'));
                $members = $identity->definition($slug)['occupations'];
                $code = count($members) === 1 ? $members[0]['code'] : null;
                foreach (['subject', 'presentation_v1.hero'] as $container) {
                    self::assertSame($code, data_get($projected, 'display_surface_v1.'.$container.'.onet_code'));
                    self::assertSame($code === null ? null : substr($code, 0, 7), data_get($projected, 'display_surface_v1.'.$container.'.soc_code'));
                }
                self::assertSame($payload['display_surface_v1']['content_v3'], $projected['display_surface_v1']['content_v3']);
                self::assertSame($projected, $identity->projectPayload($projected, $locale));
            }
        }
        $unscoped = ['identity' => ['canonical_slug' => 'actors'], 'display_surface_v1' => ['presentation_v2' => ['hero' => ['title' => 'Actors']]]];
        self::assertSame($unscoped, $identity->projectPayload($unscoped, 'en'));
    }

    public function test_breadcrumb_identity_uses_the_full_current_combination(): void
    {
        $identity = app(CareerCurrentIdentity::class);
        $slug = 'drywall-and-ceiling-tile-installers-and-tapers';
        foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $segment) {
            $items = [
                ['position' => 1, 'name' => 'Career', 'item' => '/career'],
                ['position' => 2, 'name' => 'Drywall And Ceiling Tile Installers', 'item' => '/'.$segment.'/career/jobs/'.$slug],
            ];
            $public = $identity->projectPayload(['structured_data' => ['breadcrumb_list' => ['itemListElement' => $items]]], $locale);
            $actual = $public['structured_data']['breadcrumb_list']['itemListElement'];
            self::assertSame($items[0], $actual[0]);
            self::assertSame($identity->name($slug, $locale), $actual[1]['name']);
            self::assertSame($items[1]['item'], $actual[1]['item']);
            self::assertSame($public, $identity->projectPayload($public, $locale));
        }
    }

    public function test_invalid_scope_definitions_are_rejected(): void
    {
        $package = app(CareerContentV3AuthorityPackage::class);
        $manifest = $package->manifestIndex(base_path())['manifest'];
        $slug = 'drywall-and-ceiling-tile-installers-and-tapers';
        $valid = $manifest['identity_scopes'][$slug];
        foreach ([
            [$slug => array_replace($valid, ['scope_type' => 'exact_official_occupation'])],
            [$slug => array_replace($valid, ['occupations' => [$valid['occupations'][0], $valid['occupations'][0]]])],
            [$slug => array_replace($valid, ['source_route_sha256' => 'invalid'])],
            ['missing-target' => $valid],
        ] as $scopes) {
            try {
                $package->validateIdentityScopes(array_replace($manifest, ['identity_scopes' => $scopes]));
                self::fail('Invalid scope must not publish');
            } catch (CareerCurrentAuthorityPackageFailure $failure) {
                self::assertSame('CURRENT_IDENTITY_SCOPES_INVALID', $failure->getMessage());
            }
        }
    }

    public function test_invalid_alias_updates_restore_page_manifest_and_intent_exactly(): void
    {
        $root = CareerCurrentPackageFixture::create(base_path());
        $files = new Filesystem;
        $current = $root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $paths = [$current.'/careers/librarians-and-media-collections-specialists/zh-CN.json', $current.'/manifest.json', $root.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH];
        try {
            app(CareerContentV3PageUpdater::class)->update($root, 'librarians-and-media-collections-specialists', 'zh-CN', true);
            $before = array_map('file_get_contents', $paths);
            $updater = app(CareerContentV3PageUpdater::class);
            foreach ([
                ['librarians-and-media-collections-specialists' => 'missing-target'],
                ['librarians-and-media-collections-specialists' => 'librarians-and-media-collections-specialists'],
                ['librarians-and-media-collections-specialists' => 'preschool-teachers', 'preschool-teachers' => 'librarians'],
                ['librarians-and-media-collections-specialists' => 'preschool-teachers', 'preschool-teachers' => 'librarians-and-media-collections-specialists'],
                ['actors' => 'librarians'],
                ['preschool-teachers' => 'preschool-teachers-except-special-education'],
                ['old-url' => 'missing-target'],
                ['old-url' => 'older-url', 'older-url' => 'librarians'],
            ] as $aliases) {
                try {
                    $updater->update($root, 'librarians-and-media-collections-specialists', 'zh-CN', true, $aliases);
                    self::fail('Invalid identity must fail closed');
                } catch (CareerCurrentAuthorityPackageFailure $failure) {
                    self::assertContains($failure->getMessage(), ['CURRENT_IDENTITY_ALIASES_INVALID']);
                }
                self::assertSame($before, array_map('file_get_contents', $paths));
            }
            self::assertFalse($updater->update($root, 'librarians-and-media-collections-specialists', 'zh-CN', true, app(CareerCurrentIdentity::class)->aliases())['changed']);
            self::assertSame($before, array_map('file_get_contents', $paths));
        } finally {
            $files->deleteDirectory($root);
        }
    }
}
