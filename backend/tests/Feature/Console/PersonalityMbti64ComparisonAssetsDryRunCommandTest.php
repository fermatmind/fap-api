<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbti64ComparisonAssetsDryRunCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_DIR = 'docs/seo/import-packages/mbti-comparison-content-assets-draft-20260702';

    public function test_dry_run_plans_intp_comparison_asset_without_writes_or_publish_side_effects(): void
    {
        $exitCode = Artisan::call('personality:mbti64-comparison-assets-dry-run', [
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
        $this->assertSame(1, $payload['assets_found']);
        $this->assertSame(1, $payload['valid_count']);
        $this->assertSame(1, $payload['comparison_count']);
        $this->assertSame(1, $payload['rows_would_stage']);
        $this->assertSame('intp-a-vs-intp-t', $payload['rows'][0]['slug']);
        $this->assertSame('INTP', $payload['rows'][0]['base_type_code']);
        $this->assertSame('zh-CN', $payload['rows'][0]['locale']);
        $this->assertSame('personality_profile_sections', $payload['rows'][0]['target']['table']);
        $this->assertSame('mbti64_comparison_a_vs_t', $payload['rows'][0]['target']['section_key']);
        $this->assertSame('mbti64_comparison_gpt_asset_draft_v1', $payload['rows'][0]['draft_overlay']['snapshot_key']);
        $this->assertArrayHasKey('core_difference_narrative', $payload['rows'][0]['draft_overlay']['content']);
        $this->assertArrayHasKey('work_and_career_comparison', $payload['rows'][0]['draft_overlay']['content']);
        $this->assertCount(8, $payload['rows'][0]['draft_overlay']['faq']);
        $this->assertGreaterThanOrEqual(3, count($payload['rows'][0]['draft_overlay']['internal_links']));
        $this->assertSame(0, DB::table('personality_profile_sections')->count());
    }

    public function test_dry_run_fails_closed_for_indexable_or_private_route_asset(): void
    {
        $dir = storage_path('framework/testing/mbti-comparison-invalid-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($dir.'/comparisons');
        $asset = json_decode((string) File::get(base_path(self::SOURCE_DIR.'/comparisons/FermatMind_INTP-A_vs_INTP-T_CMS_READY.json')), true, 512, JSON_THROW_ON_ERROR);
        $asset['indexability_status'] = 'indexable';
        $asset['internal_links'][] = [
            'href' => '/zh/result/private?token=secret',
            'anchor_text' => 'Unsafe private result',
            'link_intent' => 'private result',
        ];
        File::put($dir.'/comparisons/Invalid_CMS_READY.json', json_encode($asset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $exitCode = Artisan::call('personality:mbti64-comparison-assets-dry-run', [
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
        $this->assertSame(0, DB::table('personality_profile_sections')->count());
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
