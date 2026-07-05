<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityBigFiveProductionContentAudit;
use App\Models\PersonalityPublicContentAsset;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PersonalityBigFiveProductionContentAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private const V2_SOURCE_PACKAGE = 'big-five-cms-import-draft-polished.v2';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(PersonalityBigFiveProductionContentAudit::class));
    }

    public function test_production_content_audit_passes_for_thirty_four_old_assets_and_no_v2_rows(): void
    {
        $this->seedOldProductionRows(34, missingBodyRows: 34);

        $beforeCount = PersonalityPublicContentAsset::query()->count();
        $exitCode = Artisan::call('personality:big-five-production-content-audit', [
            '--allow-testing' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(34, $payload['counts']['total_big_five_rows']);
        $this->assertSame(0, $payload['counts']['v2_source_package_rows']);
        $this->assertSame(34, $payload['counts']['renderable_body_missing_rows']);
        $this->assertSame(34, $payload['counts']['publicly_readable_rows']);
        $this->assertSame(17, $payload['counts']['locale_counts']['en']);
        $this->assertSame(17, $payload['counts']['locale_counts']['zh-CN']);
        $this->assertSame(0, $payload['counts']['schema_runtime_eligible_rows']);
        $this->assertTrue($payload['production_observations']['existing_asset_count_matches_expected']);
        $this->assertTrue($payload['production_observations']['existing_asset_count_per_locale_matches_expected']);
        $this->assertTrue($payload['production_observations']['v2_package_absent_from_production']);
        $this->assertTrue($payload['production_observations']['body_missing_rows_present']);
        $this->assertFalse($payload['release_safety']['writes_committed']);
        $this->assertFalse($payload['release_safety']['cms_write_attempted']);
        $this->assertFalse($payload['release_safety']['publish_attempted']);
        $this->assertFalse($payload['release_safety']['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['release_safety']['jsonld_runtime_release_attempted']);
        $this->assertSame($beforeCount, PersonalityPublicContentAsset::query()->count());
    }

    public function test_production_content_audit_fails_when_v2_rows_are_present(): void
    {
        $this->seedOldProductionRows(34, missingBodyRows: 10);
        $this->createAsset(position: 35, sourcePackage: self::V2_SOURCE_PACKAGE, renderableBody: true);

        $exitCode = Artisan::call('personality:big-five-production-content-audit', [
            '--allow-testing' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(35, $payload['counts']['total_big_five_rows']);
        $this->assertSame(1, $payload['counts']['v2_source_package_rows']);
        $this->assertContains('unexpected_existing_big_five_count', array_column($payload['errors'], 'code'));
        $this->assertContains('unexpected_existing_big_five_locale_count', array_column($payload['errors'], 'code'));
        $this->assertContains('unexpected_v2_package_presence', array_column($payload['errors'], 'code'));
    }

    public function test_production_content_audit_writes_json_and_markdown_evidence_without_database_writes(): void
    {
        $this->seedOldProductionRows(34, missingBodyRows: 3);

        $jsonOutput = 'storage/framework/testing/big-five-production-audit.json';
        $markdownOutput = 'storage/framework/testing/big-five-production-audit.md';
        $beforeCount = PersonalityPublicContentAsset::query()->count();

        $exitCode = Artisan::call('personality:big-five-production-content-audit', [
            '--allow-testing' => true,
            '--output' => $jsonOutput,
            '--markdown-output' => $markdownOutput,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($beforeCount, PersonalityPublicContentAsset::query()->count());
        $this->assertFileExists(base_path($jsonOutput));
        $this->assertFileExists(base_path($markdownOutput));

        $json = json_decode((string) file_get_contents(base_path($jsonOutput)), true, 512, JSON_THROW_ON_ERROR);
        $markdown = (string) file_get_contents(base_path($markdownOutput));

        $this->assertSame('BIG5-PRODUCTION-CONTENT-AUDIT-C', $json['artifact']);
        $this->assertSame(3, $json['counts']['renderable_body_missing_rows']);
        $this->assertStringContainsString('# Big Five Production Content Audit', $markdown);
        $this->assertStringContainsString('Writes committed: `false`', $markdown);
    }

    private function seedOldProductionRows(int $count, int $missingBodyRows): void
    {
        for ($position = 1; $position <= $count; $position++) {
            $this->createAsset(
                position: $position,
                sourcePackage: 'big-five-public-profile-agent-v1',
                renderableBody: $position > $missingBodyRows
            );
        }
    }

    private function createAsset(int $position, string $sourcePackage, bool $renderableBody): void
    {
        $locale = $position % 2 === 0 ? 'en' : 'zh-CN';
        $entityType = $position === 1 ? PersonalityPublicContentAsset::ENTITY_HUB : PersonalityPublicContentAsset::ENTITY_DOMAIN;
        $entityKey = $position === 1 ? 'big-five' : 'trait-'.$position;
        $slug = $position === 1 ? 'big-five' : 'big-five/trait-'.$position;
        $pathLocale = $locale === 'zh-CN' ? 'zh' : 'en';

        PersonalityPublicContentAsset::query()->create([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'Big Five Audit Page '.$position,
            'summary' => 'Read-only audit fixture.',
            'content_sections_json' => [[
                'key' => 'overview',
                'title' => 'Overview',
                'body_md' => $renderableBody ? 'A renderable production body section.' : '',
            ]],
            'seo_json' => [
                'title' => 'Big Five Audit Page '.$position,
                'description' => 'Read-only audit fixture.',
            ],
            'canonical_json' => [
                'path' => '/'.$pathLocale.'/personality/'.$slug,
            ],
            'hreflang_json' => [],
            'faq_json' => [],
            'media_json' => [],
            'schema_json' => [
                'runtime_jsonld_enabled' => false,
            ],
            'method_boundary_json' => [
                'claim_boundaries' => ['non_diagnostic', 'non_predictive'],
            ],
            'evidence_notes_json' => [],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'review_state' => 'legacy_production_content',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => $sourcePackage,
            'source_hash' => hash('sha256', $sourcePackage),
            'published_at' => null,
            'last_reviewed_at' => null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
