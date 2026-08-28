<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Support\SeoContentPublishingUiContract;
use App\Filament\Ops\Support\SeoOperationsUiState;
use Tests\TestCase;

final class SeoUxImpl05ContentPublishingTest extends TestCase
{
    public function test_missing_platform_10_contract_withholds_revision_preview_and_lifecycle_values(): void
    {
        $snapshot = SeoContentPublishingUiContract::unavailableSnapshot();

        $this->assertSame(SeoOperationsUiState::PRODUCTION_UNPROVEN, $snapshot['state']);
        $this->assertSame(['article', 'career_guide', 'career_job'], $snapshot['authority_types']);
        $this->assertSame(['draft', 'review', 'canary', 'publish'], $snapshot['lifecycle']);
        $this->assertNull($snapshot['selected_revision']);
        $this->assertNull($snapshot['saved_at']);
        $this->assertNull($snapshot['review_state']);
        $this->assertNull($snapshot['material_lastmod']);
        $this->assertCount(5, $snapshot['seo_checks']);
        $this->assertCount(4, $snapshot['field_groups']);
    }

    public function test_content_workspace_links_existing_authorities_without_copying_an_editor_or_write_action(): void
    {
        $page = (string) file_get_contents(resource_path('views/filament/ops/pages/seo-operations.blade.php'));
        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-content-publishing-workspace.blade.php'));

        $this->assertStringContainsString('ops-content-publishing-workspace', $page);
        $this->assertStringContainsString('ArticleResource::getUrl()', $workspace);
        $this->assertStringContainsString('CareerGuideResource::getUrl()', $workspace);
        $this->assertStringContainsString('CareerJobResource::getUrl()', $workspace);
        $this->assertStringContainsString('$canReadAuthority = ContentAccess::canRead()', $workspace);
        $this->assertStringContainsString('$authorityUrls = $canReadAuthority', $workspace);
        $this->assertStringContainsString('@if ($canReadAuthority)', $workspace);
        $this->assertStringContainsString('data-authority-access', $workspace);
        $this->assertStringContainsString('ContentLifecycleReadService::class', $workspace);
        $this->assertStringContainsString('data-write-state="read_only"', $workspace);
        $this->assertStringContainsString('material_lastmod', $workspace);
        $this->assertStringContainsString('candidate.status', $workspace);
        $this->assertStringNotContainsString("getUrl('create')", $workspace);
        $this->assertStringNotContainsString("getUrl('edit'", $workspace);
        $this->assertStringNotContainsString('<form', $workspace);
        $this->assertStringNotContainsString('<input', $workspace);
        $this->assertStringNotContainsString('wire:click', $workspace);
        $this->assertStringNotContainsString('wire:model', $workspace);
        $this->assertStringNotContainsString('canonical_path', $workspace);
        $this->assertStringNotContainsString('backfill', $workspace);
        $this->assertStringNotContainsString('noindex', $workspace);
    }

    public function test_available_snapshot_projects_revision_review_fingerprint_lastmod_and_candidate(): void
    {
        $snapshot = SeoContentPublishingUiContract::snapshot([
            'state' => SeoOperationsUiState::PRODUCTION_PROVEN,
            'rows' => [[
                'revision' => ['value' => 'article-r1'],
                'recorded_at' => '2026-08-28T01:00:00+00:00',
                'review' => ['state' => 'evidence_bound'],
                'material_lastmod' => '2026-08-27T01:00:00+00:00',
                'candidate' => ['status' => 'candidate'],
            ]],
            'pagination' => ['page' => 1, 'last_page' => 1],
            'boundaries' => ['read_only' => true],
        ]);

        $this->assertSame(SeoOperationsUiState::PRODUCTION_PROVEN, $snapshot['state']);
        $this->assertSame('article-r1', $snapshot['selected_revision']);
        $this->assertSame('evidence_bound', $snapshot['review_state']);
        $this->assertSame('2026-08-27T01:00:00+00:00', $snapshot['material_lastmod']);
        $this->assertSame('candidate', $snapshot['candidate_state']);
        $this->assertTrue($snapshot['boundaries']['read_only']);
    }

    public function test_existing_resources_keep_backend_read_and_write_permission_gates(): void
    {
        foreach (['ArticleResource.php', 'CareerGuideResource.php', 'CareerJobResource.php'] as $resource) {
            $source = (string) file_get_contents(app_path('Filament/Ops/Resources/'.$resource));

            $this->assertStringContainsString('public static function canViewAny(): bool', $source);
            $this->assertStringContainsString('return self::canRead();', $source);
            $this->assertStringContainsString('public static function canCreate(): bool', $source);
            $this->assertStringContainsString('return self::canWrite();', $source);
            $this->assertStringContainsString('public static function canDelete($record): bool', $source);
        }
    }

    public function test_content_publishing_copy_is_complete_in_both_locales(): void
    {
        foreach (['en', 'zh_CN'] as $locale) {
            $translations = require lang_path($locale.'/ops.php');
            $copy = data_get($translations, 'custom_pages.seo_operations.content_publishing');

            $this->assertIsArray($copy);
            $this->assertSame(['article', 'career_guide', 'career_job'], array_keys($copy['authority']['types']));
            $this->assertSame(['canonical', 'hreflang', 'structured_visible', 'private_url', 'metadata'], array_keys($copy['checks']['items']));
            $this->assertSame(['desktop', 'tablet', 'mobile'], array_keys($copy['preview']['devices']));
            $this->assertSame(['draft', 'review', 'canary', 'publish'], array_keys($copy['release']['stages']));
            $this->assertStringContainsString('lastmod', strtolower($copy['release']['lastmod_rule']));
        }
    }
}
