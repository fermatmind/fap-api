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
use Tests\TestCase;

final class CareerCurrentIdentityTest extends TestCase
{
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
            self::assertContains($alias, $inventory['slugs']);
            self::assertContains($target, $inventory['slugs']);
        }
    }

    public function test_retained_aliases_resolve_names_without_changing_physical_identity(): void
    {
        $identity = app(CareerCurrentIdentity::class);
        $package = app(CareerContentV3AuthorityPackage::class)->load(base_path());
        self::assertSame(1046, $package['manifest']['coverage']['slugs']);
        self::assertSame(2092, $package['manifest']['coverage']['files']);
        foreach ($identity->aliases() as $alias => $target) {
            self::assertSame($target, $identity->canonicalSlug($alias));
            self::assertFalse($identity->isAlias($target));
            foreach ($identity->searchTerms($target) as $name) {
                self::assertSame($target, $identity->canonicalQuery($name));
            }
            foreach (['en', 'zh-CN'] as $locale) {
                $page = json_decode(file_get_contents(base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/careers/'.$alias.'/'.$locale.'.json')), true);
                self::assertSame($alias, $page['subject']['canonical_slug']);
                self::assertSame('legacy', $page['content_state']);
                self::assertSame([], $page['blocks']);
            }
        }
        self::assertSame('unmatched query', $identity->canonicalQuery('unmatched query'));
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
        self::assertSame('Drywall and Ceiling Tile Installers and Tapers', $public['titles']['canonical_en']);
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
        $manifest = $package->load(base_path())['manifest'];
        $slug = 'drywall-and-ceiling-tile-installers-and-tapers';
        $valid = $manifest['identity_scopes'][$slug];
        foreach ([
            [$slug => array_replace($valid, ['scope_type' => 'exact_official_occupation'])],
            [$slug => array_replace($valid, ['occupations' => [$valid['occupations'][0], $valid['occupations'][0]]])],
            [$slug => array_replace($valid, ['source_route_sha256' => 'invalid'])],
            ['missing-target' => $valid],
            ['insulation-workers' => $valid],
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
        $root = sys_get_temp_dir().'/career-alias-rollback-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $current = $root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $files->makeDirectory(dirname($current), 0700, true);
        $files->copyDirectory(base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH), $current);
        $files->copy(base_path(CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH), $root.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH);
        $paths = [$current.'/careers/librarians-and-media-collections-specialists/zh-CN.json', $current.'/manifest.json', $root.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH];
        try {
            $before = array_map('file_get_contents', $paths);
            $updater = app(CareerContentV3PageUpdater::class);
            foreach ([
                ['librarians-and-media-collections-specialists' => 'missing-target'],
                ['librarians-and-media-collections-specialists' => 'librarians-and-media-collections-specialists'],
                ['librarians-and-media-collections-specialists' => 'preschool-teachers', 'preschool-teachers' => 'librarians'],
                ['librarians-and-media-collections-specialists' => 'preschool-teachers', 'preschool-teachers' => 'librarians-and-media-collections-specialists'],
                ['actors' => 'librarians'],
            ] as $aliases) {
                try {
                    $updater->update($root, 'librarians-and-media-collections-specialists', 'zh-CN', true, $aliases);
                    self::fail('Invalid identity must fail closed');
                } catch (CareerCurrentAuthorityPackageFailure $failure) {
                    self::assertContains($failure->getMessage(), ['CURRENT_IDENTITY_ALIASES_INVALID', 'CURRENT_IDENTITY_ALIAS_BODY_NOT_EMPTY']);
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
