<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class EnneagramPublicAuthorityV219MediaOgTest extends TestCase
{
    private const PACKAGE_DIR = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-media-og-19';

    private const LEDGER = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/page-claim-maps.json';

    public function test_exactly_fifty_eight_original_specifications_cover_the_frozen_identities(): void
    {
        $document = $this->readJson(self::PACKAGE_DIR.'/media-specifications.json');
        $specifications = collect($document['media_specifications']);
        $expected = collect($this->readJson(self::LEDGER)['page_maps'])->keyBy('identity_key');

        $this->assertSame('enneagram_public_authority_v2_media_specifications.v1', $document['schema_version']);
        $this->assertSame('backend_media_library', $document['authority']);
        $this->assertSame(58, $document['identity_count']);
        $this->assertCount(58, $specifications);
        $this->assertCount(58, $specifications->pluck('spec_id')->unique());
        $this->assertCount(58, $specifications->pluck('identity_key')->unique());
        $this->assertSame($expected->keys()->sort()->values()->all(), $specifications->pluck('identity_key')->sort()->values()->all());
        $this->assertSame(
            ['center' => 3, 'core_type' => 9, 'hub' => 1, 'instinctual_subtype' => 27, 'wing' => 18],
            $specifications->countBy('entity_type')->sortKeys()->all(),
        );
    }

    public function test_every_specification_is_original_specific_accessible_and_fail_closed(): void
    {
        $specifications = $this->readJson(self::PACKAGE_DIR.'/media-specifications.json')['media_specifications'];
        $concepts = [];
        $enAlts = [];
        $zhAlts = [];

        foreach ($specifications as $specification) {
            $key = $specification['identity_key'];
            $this->assertSame('original_editorial_concept_illustration', $specification['intended_media_type'], $key);
            $this->assertSame('new_original_commission_specification', $specification['source_provenance']['origin'], $key);
            $this->assertSame('unassigned', $specification['source_provenance']['creator'], $key);
            $this->assertNull($specification['source_provenance']['source_asset_id'], $key);
            $this->assertFalse($specification['source_provenance']['competitor_source_used'], $key);
            $this->assertFalse($specification['source_provenance']['ai_generation_executed'], $key);
            $this->assertSame('pending_manual_rights_review', $specification['rights_status'], $key);
            $this->assertFalse($specification['rights']['ownership_claimed'], $key);
            $this->assertNull($specification['rights']['license'], $key);
            $this->assertSame(['width' => 2400, 'height' => 1260], $specification['dimensions']['master'], $key);
            $this->assertSame('40:21', $specification['dimensions']['aspect_ratio'], $key);
            $this->assertSame(['width' => 1200, 'height' => 630, 'variant_key' => 'og_1200x630'], $specification['dimensions']['og_variant'], $key);
            $this->assertGreaterThanOrEqual(65, mb_strlen($specification['safe_visual_brief']['concept']), $key);
            $this->assertCount(4, $specification['safe_visual_brief']['prohibited_elements'], $key);
            $this->assertGreaterThanOrEqual(100, mb_strlen($specification['localized_alt']['en']), $key);
            $this->assertGreaterThanOrEqual(45, preg_match_all('/\p{Han}/u', $specification['localized_alt']['zh-CN']), $key);
            $this->assertSame('backend_media_library', $specification['media_library']['authority'], $key);
            $this->assertSame('not_uploaded', $specification['media_library']['upload_status'], $key);
            $this->assertNull($specification['media_library']['media_asset_id'], $key);
            $this->assertNull($specification['media_library']['media_asset_key'], $key);
            $this->assertNull($specification['media_library']['public_url'], $key);
            $this->assertSame('pending_manual_review', $specification['manual_rights_review']['status'], $key);
            $this->assertNull($specification['manual_rights_review']['reviewer'], $key);
            $this->assertFalse($specification['manual_rights_review']['approved'], $key);
            $concepts[] = $specification['safe_visual_brief']['concept'];
            $enAlts[] = $specification['localized_alt']['en'];
            $zhAlts[] = $specification['localized_alt']['zh-CN'];
        }

        $this->assertCount(58, array_unique($concepts));
        $this->assertCount(58, array_unique($enAlts));
        $this->assertCount(58, array_unique($zhAlts));
    }

    public function test_exactly_one_hundred_sixteen_localized_mappings_match_frozen_routes(): void
    {
        $document = $this->readJson(self::PACKAGE_DIR.'/localized-og-mappings.json');
        $mappings = collect($document['mappings'])->keyBy('mapping_id');
        $expected = collect($this->readJson(self::LEDGER)['page_maps'])
            ->keyBy(fn (array $map): string => $map['locale'].'|'.$map['identity_key']);
        $specifications = collect($this->readJson(self::PACKAGE_DIR.'/media-specifications.json')['media_specifications'])->keyBy('identity_key');

        $this->assertSame('enneagram_public_authority_v2_localized_media_mappings.v1', $document['schema_version']);
        $this->assertSame('backend_media_library', $document['authority']);
        $this->assertCount(116, $mappings);
        $this->assertSame($expected->keys()->sort()->values()->all(), $mappings->keys()->sort()->values()->all());
        $this->assertSame(['en' => 58, 'zh-CN' => 58], $mappings->countBy('locale')->sortKeys()->all());

        foreach ($mappings as $key => $mapping) {
            $map = $expected[$key];
            $specification = $specifications[$mapping['identity_key']];
            foreach (['identity_key', 'locale', 'entity_type', 'code', 'path'] as $field) {
                $this->assertSame($map[$field], $mapping[$field], "{$key}.{$field}");
            }
            $this->assertSame($specification['spec_id'], $mapping['original_spec_id'], $key);
            $this->assertSame($specification['localized_alt'][$mapping['locale']], $mapping['alt'], $key);
            $this->assertSame($specification['spec_id'], $mapping['og_mapping']['source_spec_id'], $key);
            $this->assertSame('og_1200x630', $mapping['og_mapping']['variant_key'], $key);
            $this->assertSame(['width' => 1200, 'height' => 630], $mapping['og_mapping']['dimensions'], $key);
            $this->assertNull($mapping['og_mapping']['media_asset_id'], $key);
            $this->assertNull($mapping['og_mapping']['media_asset_key'], $key);
            $this->assertNull($mapping['og_mapping']['public_url'], $key);
            $this->assertSame('pending_manual_review', $mapping['manual_rights_review']['status'], $key);
            $this->assertFalse($mapping['manual_rights_review']['approved'], $key);
            $this->assertTrue($mapping['release_truth']['draft_only'], $key);
            $this->assertFalse($mapping['release_truth']['publish_eligible'], $key);
        }

        $this->assertCount(116, $mappings->pluck('path')->unique());
        $this->assertCount(116, $mappings->pluck('alt')->unique());
    }

    public function test_og_and_media_library_authority_remain_null_until_external_approval(): void
    {
        foreach (['media-specifications.json', 'localized-og-mappings.json'] as $file) {
            $document = $this->readJson(self::PACKAGE_DIR.'/'.$file);
            $serialized = strtolower(json_encode($document, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('http://', $serialized, $file);
            $this->assertStringNotContainsString('https://', $serialized, $file);
            $this->assertStringNotContainsString('frontend/public', $serialized, $file);
            $this->assertSame([false], array_values(array_unique($document['execution_boundaries'])), $file);
        }
    }

    public function test_rights_and_release_mutations_are_detected_by_the_frozen_invariants(): void
    {
        $specification = $this->readJson(self::PACKAGE_DIR.'/media-specifications.json')['media_specifications'][0];
        $mapping = $this->readJson(self::PACKAGE_DIR.'/localized-og-mappings.json')['mappings'][0];

        $this->assertTrue($this->specificationIsFailClosed($specification));
        $this->assertTrue($this->mappingIsFailClosed($mapping));
        $specification['manual_rights_review']['approved'] = true;
        $mapping['og_mapping']['public_url'] = 'invented-production-url';
        $this->assertFalse($this->specificationIsFailClosed($specification));
        $this->assertFalse($this->mappingIsFailClosed($mapping));
    }

    public function test_qa_report_records_planning_only_media_truth(): void
    {
        $report = $this->readJson(self::PACKAGE_DIR.'/qa-report.json');
        $this->assertSame('pass_planning_only_pending_rights_and_upload', $report['status']);
        $this->assertSame(58, $report['counts']['identity_count']);
        $this->assertSame(58, $report['counts']['original_specification_count']);
        $this->assertSame(116, $report['counts']['localized_mapping_count']);
        $this->assertSame(0, $report['counts']['uploaded_asset_count']);
        $this->assertSame(0, $report['counts']['rights_approved_asset_count']);
        $this->assertSame(0, $report['counts']['public_url_count']);
        foreach ($report['checks'] as $check) {
            $this->assertTrue($check);
        }
        $this->assertSame([false], array_values(array_unique($report['execution_boundaries'])));
    }

    /** @param array<string, mixed> $specification */
    private function specificationIsFailClosed(array $specification): bool
    {
        return ($specification['rights_status'] ?? null) === 'pending_manual_rights_review'
            && ($specification['manual_rights_review']['status'] ?? null) === 'pending_manual_review'
            && ($specification['manual_rights_review']['approved'] ?? null) === false
            && ($specification['media_library']['upload_status'] ?? null) === 'not_uploaded'
            && array_key_exists('public_url', $specification['media_library'])
            && $specification['media_library']['public_url'] === null;
    }

    /** @param array<string, mixed> $mapping */
    private function mappingIsFailClosed(array $mapping): bool
    {
        return ($mapping['manual_rights_review']['status'] ?? null) === 'pending_manual_review'
            && ($mapping['manual_rights_review']['approved'] ?? null) === false
            && array_key_exists('media_asset_id', $mapping['og_mapping'])
            && $mapping['og_mapping']['media_asset_id'] === null
            && array_key_exists('media_asset_key', $mapping['og_mapping'])
            && $mapping['og_mapping']['media_asset_key'] === null
            && array_key_exists('public_url', $mapping['og_mapping'])
            && $mapping['og_mapping']['public_url'] === null
            && ($mapping['release_truth']['publish_eligible'] ?? null) === false;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents(base_path($path));
        $this->assertNotFalse($contents, $path);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
