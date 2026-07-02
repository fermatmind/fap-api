<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbti64CrossTypeComparisonAssetsDryRunCommandTest extends TestCase
{
    private const SOURCE_DIR = 'docs/seo/import-packages/mbti-cross-type-comparison-content-assets-draft-20260702';

    public function test_dry_run_plans_cross_type_comparison_assets_without_writes_or_publish_side_effects(): void
    {
        $exitCode = Artisan::call('personality:mbti64-cross-type-comparison-assets-dry-run', [
            '--source-dir' => self::SOURCE_DIR,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertSame(6, $payload['assets_found']);
        $this->assertSame(6, $payload['valid_count']);
        $this->assertSame(6, $payload['comparison_count']);
        $this->assertSame(6, $payload['rows_would_stage']);
        $this->assertSame('mbti.cross_type_comparison.authority.v1', $payload['authority_contract']['contract_version']);
        $this->assertSame('mbti.cross_type_comparison.readmodel.v1', $payload['readmodel_contract']['contract_version']);
        $this->assertSame('backend_authority.mbti64_cross_type_comparison', $payload['authority_contract']['storage']);
        $this->assertFalse($payload['authority_contract']['public_api_enabled']);
        $this->assertFalse($payload['readmodel_contract']['public_api_enabled']);

        $slugs = array_column($payload['rows'], 'slug');
        sort($slugs);
        $this->assertSame([
            'enfp-vs-entp',
            'entj-vs-intj',
            'estj-vs-entj',
            'infj-vs-infp',
            'intj-vs-intp',
            'isfp-vs-infp',
        ], $slugs);
        $this->assertSame($slugs, $payload['available_slugs']);
        $this->assertSame($slugs, $payload['expected_available_slugs']);
        $this->assertSame([
            [
                'slug' => 'istj-vs-isfj',
                'status' => 'pending_asset',
                'expected_asset' => 'ISTJ_vs_ISFJ_Content_Asset_Package.zip',
                'reason' => 'requested_cross_type_asset_not_available',
            ],
        ], $payload['pending_missing_slugs']);
        $this->assertSame($payload['pending_missing_slugs'], $payload['authority_contract']['pending_missing_slugs']);
        $this->assertNotContains('istj-vs-isfj', $payload['available_slugs']);

        foreach ($payload['rows'] as $row) {
            $this->assertSame('zh-CN', $row['locale']);
            $this->assertNotSame($row['left_type'], $row['right_type']);
            $this->assertSame('backend_authority.mbti64_cross_type_comparison', $row['target']['storage']);
            $this->assertFalse($row['target']['public_api_enabled']);
            $this->assertSame('mbti.cross_type_comparison.authority.v1', $row['authority_contract_version']);
            $this->assertSame('mbti.cross_type_comparison.readmodel.v1', $row['readmodel_contract_version']);
            $this->assertSame($row['slug'], $row['projection']['slug']);
            $this->assertSame('mbti_cross_type', $row['projection']['comparison_type']);
            $this->assertSame('zh-CN', $row['projection']['locale']);
            $this->assertSame($row['left_type'], $row['projection']['left_type']);
            $this->assertSame($row['right_type'], $row['projection']['right_type']);
            $this->assertSame('draft', $row['projection']['review_status']);
            $this->assertSame('draft', $row['projection']['publish_status']);
            $this->assertSame('pending_review', $row['projection']['indexability_status']);
            $this->assertIsString($row['projection']['title']);
            $this->assertIsString($row['projection']['seo_title']);
            $this->assertIsString($row['projection']['seo_description']);
            $this->assertIsString($row['projection']['summary']);
            $this->assertGreaterThanOrEqual(5, $row['projection']['section_count']);
            $this->assertGreaterThanOrEqual(4, $row['projection']['faq_count']);
            $this->assertGreaterThanOrEqual(3, $row['projection']['internal_link_count']);
            $this->assertTrue($row['projection']['claim_boundary_present']);
            $this->assertGreaterThan(0, $row['projection']['source_notes_count']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['projection']['source_sha256']);
            $this->assertFalse($row['projection']['public_api_enabled']);
            $this->assertFalse($row['projection']['is_public']);
            $this->assertFalse($row['projection']['is_indexable']);
            $this->assertFalse($row['draft_state_after_import']['is_public']);
            $this->assertFalse($row['draft_state_after_import']['is_indexable']);
        }
    }

    public function test_dry_run_fails_closed_for_indexable_or_private_route_cross_type_asset(): void
    {
        $dir = storage_path('framework/testing/mbti-cross-type-invalid-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($dir.'/comparisons');
        $asset = json_decode((string) File::get(base_path(self::SOURCE_DIR.'/comparisons/FermatMind_INTJ_vs_INTP_CMS_READY.json')), true, 512, JSON_THROW_ON_ERROR);
        $asset['indexability_status'] = 'indexable';
        $asset['internal_links'][] = [
            'href' => '/zh/result/private?token=secret',
            'anchor_text' => 'Unsafe private result',
            'link_intent' => 'private result',
        ];
        File::put($dir.'/comparisons/Invalid_CMS_READY.json', json_encode($asset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $exitCode = Artisan::call('personality:mbti64-cross-type-comparison-assets-dry-run', [
            '--source-dir' => $dir,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['valid_count']);
        $this->assertContains('indexability_must_not_be_indexable', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertContains('forbidden_public_route_pattern_present', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertContains('forbidden_query_pattern_present', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
