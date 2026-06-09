<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class GlobalEnZhContentPagesControlledPublishRuntime01Test extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_in_artisan_list(): void
    {
        Artisan::call('list', ['--no-ansi' => true]);

        $this->assertStringContainsString('content-pages:publish-controlled', Artisan::output());
    }

    public function test_dry_run_succeeds_without_writing_in_controlled_fixture(): void
    {
        $this->seedControlledTargets();

        $output = $this->runPublishCommand(['--dry-run' => true]);

        $this->assertTrue($output['ok'] ?? false);
        $this->assertTrue($output['dry_run'] ?? false);
        $this->assertFalse($output['writes_committed'] ?? true);
        $this->assertSame(5, $output['would_publish_count'] ?? null);
        $this->assertSame(0, $output['would_create_count'] ?? null);
        $this->assertFalse($output['search_channel_action_attempted'] ?? true);

        foreach (self::targetKeys() as $key) {
            $page = $this->contentPage($key);
            $this->assertSame(ContentPage::STATUS_DRAFT, (string) $page->status);
            $this->assertFalse((bool) $page->is_public);
            $this->assertNull($page->published_at);
        }
    }

    public function test_execute_publishes_only_target_fixture_records_without_creation(): void
    {
        $this->seedControlledTargets();
        $protected = ContentPage::query()->withoutGlobalScopes()->create($this->pageAttributes('about', [
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
            'source_doc' => 'protected existing published record',
        ]));
        $beforeCount = ContentPage::query()->withoutGlobalScopes()->count();

        $output = $this->runPublishCommand(['--execute' => true]);

        $this->assertTrue($output['ok'] ?? false);
        $this->assertFalse($output['dry_run'] ?? true);
        $this->assertTrue($output['writes_committed'] ?? false);
        $this->assertSame(self::targetKeys(), $output['published_keys'] ?? null);
        $this->assertSame($beforeCount, ContentPage::query()->withoutGlobalScopes()->count());

        foreach (self::targetKeys() as $key) {
            $page = $this->contentPage($key);
            $this->assertSame(ContentPage::STATUS_PUBLISHED, (string) $page->status);
            $this->assertTrue((bool) $page->is_public);
            $this->assertFalse((bool) $page->is_indexable);
            $this->assertNotNull($page->published_at);
            $this->assertSame((int) $page->working_revision_id, (int) $page->published_revision_id);
        }

        $protected->refresh();
        $this->assertSame('about', (string) $protected->slug);
        $this->assertSame(ContentPage::STATUS_PUBLISHED, (string) $protected->status);
        $this->assertTrue((bool) $protected->is_indexable);
    }

    public function test_execute_refuses_missing_target_records(): void
    {
        $this->seedControlledTargets(['policies']);

        $output = $this->runPublishCommand(['--execute' => true], expectedExitCode: 1);

        $this->assertFalse($output['ok'] ?? true);
        $this->assertSame(0, $output['would_create_count'] ?? -1);
        $this->assertContains('missing_target_record', $this->errorCodes($output));
        $this->assertSame(4, ContentPage::query()->withoutGlobalScopes()->count());
    }

    public function test_execute_refuses_extra_keys(): void
    {
        $this->seedControlledTargets();

        $output = $this->runPublishCommand([
            '--execute' => true,
            '--keys' => implode(',', [...self::targetKeys(), 'about']),
        ], expectedExitCode: 1);

        $this->assertFalse($output['ok'] ?? true);
        $this->assertContains('extra_keys_not_allowed', $this->errorCodes($output));
        $this->assertSame(ContentPage::STATUS_DRAFT, (string) $this->contentPage('brand')->status);
    }

    public function test_execute_refuses_foundation_overclaim_fixture(): void
    {
        $this->seedControlledTargets();
        $foundation = $this->contentPage('foundation');
        $foundation->forceFill([
            'content_md' => "# Public-Benefit Mission and Governance\n\nFermatMind is a registered foundation with a planned public-benefit shareholding arrangement.",
        ])->save();

        $output = $this->runPublishCommand(['--execute' => true], expectedExitCode: 1);

        $this->assertFalse($output['ok'] ?? true);
        $this->assertContains('foundation_overclaim_detected', $this->errorCodes($output));
        $this->assertSame(ContentPage::STATUS_DRAFT, (string) $this->contentPage('foundation')->status);
    }

    public function test_execute_is_idempotent(): void
    {
        $this->seedControlledTargets();

        $first = $this->runPublishCommand(['--execute' => true]);
        $countAfterFirst = ContentPage::query()->withoutGlobalScopes()->count();
        $publishedRevisionIds = ContentPage::query()
            ->withoutGlobalScopes()
            ->whereIn('slug', self::targetKeys())
            ->pluck('published_revision_id', 'slug')
            ->all();

        $second = $this->runPublishCommand(['--execute' => true]);

        $this->assertTrue($first['ok'] ?? false);
        $this->assertTrue($second['ok'] ?? false);
        $this->assertSame([], $second['published_keys'] ?? null);
        $this->assertSame(self::targetKeys(), $second['skipped_keys'] ?? null);
        $this->assertSame($countAfterFirst, ContentPage::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            $publishedRevisionIds,
            ContentPage::query()->withoutGlobalScopes()->whereIn('slug', self::targetKeys())->pluck('published_revision_id', 'slug')->all(),
        );
    }

    public function test_help_service_scope_dry_run_succeeds_without_writing_twelve_rows(): void
    {
        $this->seedHelpServiceTargets();

        $output = $this->runHelpServicePublishCommand(['--dry-run' => true]);

        $this->assertTrue($output['ok'] ?? false);
        $this->assertSame('help-service', $output['scope'] ?? null);
        $this->assertTrue($output['dry_run'] ?? false);
        $this->assertFalse($output['writes_committed'] ?? true);
        $this->assertSame(12, $output['target_count'] ?? null);
        $this->assertSame(12, $output['would_publish_count'] ?? null);
        $this->assertSame(0, $output['would_create_count'] ?? null);
        $this->assertSame(['zh-CN', 'en'], $output['target_locales'] ?? null);
        $this->assertContains('zh-CN:help-unlock-failure', $output['target_keys'] ?? []);
        $this->assertContains('en:help-data-deletion', $output['target_keys'] ?? []);
        $this->assertFalse($output['search_channel_action_attempted'] ?? true);
        $this->assertFalse($output['url_submission_attempted'] ?? true);

        foreach (self::helpServiceTargetKeys() as $key) {
            foreach (['zh-CN', 'en'] as $locale) {
                $page = $this->contentPageForLocale($key, $locale);
                $this->assertSame(ContentPage::STATUS_DRAFT, (string) $page->status);
                $this->assertFalse((bool) $page->is_public);
                $this->assertFalse((bool) $page->is_indexable);
                $this->assertNull($page->published_at);
                $this->assertSame('support@fermatmind.com', (string) $page->support_contact);
                $this->assertSame('help_service_policy.v1', (string) $page->policy_version);
                $this->assertSame('Unknown', (string) $page->reviewer);
                $this->assertFalse((bool) $page->schema_enabled);
                $this->assertCount(4, $page->faq_items ?? []);
            }
        }
    }

    public function test_help_service_scope_execute_publishes_only_twelve_existing_rows(): void
    {
        $this->seedHelpServiceTargets();
        $protected = ContentPage::query()->withoutGlobalScopes()->create($this->pageAttributes('help-faq', [
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'template' => 'help',
            'locale' => 'en',
            'status' => ContentPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
            'source_doc' => 'protected Help FAQ record',
        ]));
        $beforeCount = ContentPage::query()->withoutGlobalScopes()->count();

        $output = $this->runHelpServicePublishCommand(['--execute' => true]);

        $this->assertTrue($output['ok'] ?? false);
        $this->assertFalse($output['dry_run'] ?? true);
        $this->assertTrue($output['writes_committed'] ?? false);
        $this->assertSame(12, $output['target_count'] ?? null);
        $this->assertSame(12, count((array) ($output['published_keys'] ?? [])));
        $this->assertContains('zh-CN:help-payment-refund', $output['published_keys'] ?? []);
        $this->assertContains('en:help-result-recovery', $output['published_keys'] ?? []);
        $this->assertSame($beforeCount, ContentPage::query()->withoutGlobalScopes()->count());

        foreach (self::helpServiceTargetKeys() as $key) {
            foreach (['zh-CN', 'en'] as $locale) {
                $page = $this->contentPageForLocale($key, $locale);
                $this->assertSame(ContentPage::STATUS_PUBLISHED, (string) $page->status);
                $this->assertTrue((bool) $page->is_public);
                $this->assertFalse((bool) $page->is_indexable);
                $this->assertNotNull($page->published_at);
                $this->assertSame((int) $page->working_revision_id, (int) $page->published_revision_id);
            }
        }

        $protected->refresh();
        $this->assertSame('help-faq', (string) $protected->slug);
        $this->assertSame(ContentPage::STATUS_DRAFT, (string) $protected->status);
        $this->assertFalse((bool) $protected->is_public);
    }

    public function test_help_service_scope_refuses_single_locale_option(): void
    {
        $this->seedHelpServiceTargets();

        $output = $this->runHelpServicePublishCommand([
            '--execute' => true,
            '--locale' => 'en',
        ], expectedExitCode: 1);

        $this->assertFalse($output['ok'] ?? true);
        $this->assertContains('unsupported_locale', $this->errorCodes($output));
        $this->assertSame(ContentPage::STATUS_DRAFT, (string) $this->contentPageForLocale('help-unlock-failure', 'en')->status);
    }

    public function test_help_service_scope_refuses_extra_keys(): void
    {
        $this->seedHelpServiceTargets();

        $output = $this->runHelpServicePublishCommand([
            '--execute' => true,
            '--keys' => implode(',', [...self::helpServiceTargetKeys(), 'help-faq']),
        ], expectedExitCode: 1);

        $this->assertFalse($output['ok'] ?? true);
        $this->assertContains('extra_keys_not_allowed', $this->errorCodes($output));
        $this->assertSame(ContentPage::STATUS_DRAFT, (string) $this->contentPageForLocale('help-payment-refund', 'zh-CN')->status);
    }

    public function test_science_zh_scope_dry_run_previews_five_existing_rows_without_writes(): void
    {
        $this->seedScienceZhTargets();

        $output = $this->runScienceZhPublishCommand(['--dry-run' => true]);

        $this->assertTrue($output['ok'] ?? false);
        $this->assertSame('science-zh', $output['scope'] ?? null);
        $this->assertTrue($output['dry_run'] ?? false);
        $this->assertFalse($output['writes_committed'] ?? true);
        $this->assertSame(5, $output['target_count'] ?? null);
        $this->assertSame(5, $output['would_publish_count'] ?? null);
        $this->assertSame(0, $output['would_create_count'] ?? null);
        $this->assertSame(['zh-CN'], $output['target_locales'] ?? null);
        $this->assertSame(array_map(static fn (string $key): string => 'zh-CN:'.$key, self::scienceZhTargetKeys()), $output['target_keys'] ?? null);
        $this->assertFalse($output['sitemap_llms_footer_explicit_enablement'] ?? true);
        $this->assertContains('method-boundaries', $output['forbidden_keys'] ?? []);

        foreach (self::scienceZhTargetKeys() as $key) {
            $page = $this->contentPageForLocale($key, 'zh-CN');
            $this->assertSame(ContentPage::STATUS_DRAFT, (string) $page->status);
            $this->assertFalse((bool) $page->is_public);
            $this->assertFalse((bool) $page->is_indexable);
            $this->assertFalse((bool) $page->publish_allowed);
            $this->assertNull($page->operator_approved_at);
            $this->assertNull($page->published_at);
        }
    }

    public function test_science_zh_scope_execute_sets_public_readiness_without_indexing_or_creation(): void
    {
        $this->seedScienceZhTargets();
        ContentPage::query()->withoutGlobalScopes()->create($this->scienceZhPageAttributes('method-boundaries', [
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
            'source_doc' => 'protected method-boundaries existing record',
            'publish_allowed' => true,
            'review_state' => 'approved',
            'legal_review_required' => false,
            'science_review_required' => false,
            'operator_approved_at' => now()->subDay(),
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
        ]));
        $beforeCount = ContentPage::query()->withoutGlobalScopes()->count();

        $output = $this->runScienceZhPublishCommand(['--execute' => true]);

        $this->assertTrue($output['ok'] ?? false);
        $this->assertFalse($output['dry_run'] ?? true);
        $this->assertTrue($output['writes_committed'] ?? false);
        $this->assertSame(array_map(static fn (string $key): string => 'zh-CN:'.$key, self::scienceZhTargetKeys()), $output['published_keys'] ?? null);
        $this->assertSame($beforeCount, ContentPage::query()->withoutGlobalScopes()->count());

        foreach (self::scienceZhTargetKeys() as $key) {
            $page = $this->contentPageForLocale($key, 'zh-CN');
            $this->assertSame(ContentPage::STATUS_PUBLISHED, (string) $page->status);
            $this->assertTrue((bool) $page->is_public);
            $this->assertFalse((bool) $page->is_indexable);
            $this->assertNotNull($page->published_at);
            $this->assertTrue((bool) $page->publish_allowed);
            $this->assertSame('approved', (string) $page->review_state);
            $this->assertFalse((bool) $page->legal_review_required);
            $this->assertFalse((bool) $page->science_review_required);
            $this->assertSame('passed', (string) $page->claim_gate_status);
            $this->assertSame([], $page->forbidden_claims ?? []);
            $this->assertTrue((bool) $page->operator_approval_required);
            $this->assertNotNull($page->operator_approved_at);
            $this->assertFalse((bool) $page->schema_enabled);
            $this->assertFalse((bool) $page->faq_schema_eligible);
            $this->assertTrue($page->passesPublicReadinessGate());
            $this->assertSame((int) $page->working_revision_id, (int) $page->published_revision_id);

            $this->getJson('/api/v0.5/content-pages/'.$key.'?locale=zh-CN&org_id=0')
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('page.slug', $key)
                ->assertJsonPath('page.locale', 'zh-CN')
                ->assertJsonPath('page.is_indexable', false)
                ->assertJsonPath('page.publish_allowed', true)
                ->assertJsonPath('page.claim_gate_status', 'passed');
        }

        $protected = $this->contentPageForLocale('method-boundaries', 'zh-CN');
        $this->assertSame(ContentPage::STATUS_PUBLISHED, (string) $protected->status);
        $this->assertTrue((bool) $protected->is_indexable);
    }

    public function test_science_zh_scope_refuses_method_boundaries_key(): void
    {
        $this->seedScienceZhTargets();

        $output = $this->runScienceZhPublishCommand([
            '--execute' => true,
            '--keys' => implode(',', [...self::scienceZhTargetKeys(), 'method-boundaries']),
        ], expectedExitCode: 1);

        $this->assertFalse($output['ok'] ?? true);
        $this->assertContains('extra_keys_not_allowed', $this->errorCodes($output));
        $this->assertSame(ContentPage::STATUS_DRAFT, (string) $this->contentPageForLocale('science', 'zh-CN')->status);
    }

    public function test_science_zh_scope_refuses_private_url_patterns(): void
    {
        $this->seedScienceZhTargets(overridesByKey: [
            'science' => [
                'content_md' => "# 测评科学\n\n查看 /zh/result/private-token?token=secret 后继续。",
            ],
        ]);

        $output = $this->runScienceZhPublishCommand(['--execute' => true], expectedExitCode: 1);

        $this->assertFalse($output['ok'] ?? true);
        $this->assertContains('private_url_pattern_present', $this->errorCodes($output));
        $this->assertSame(ContentPage::STATUS_DRAFT, (string) $this->contentPageForLocale('science', 'zh-CN')->status);
    }

    public function test_generated_json_report_exists_and_parses(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-controlled-publish-runtime-01.v1.json');

        $this->assertFileExists(base_path('docs/seo/global-en-zh-content-pages-controlled-publish-runtime-01.md'));
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true);

        $this->assertIsArray($generated);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-PUBLISH-RUNTIME-01', $generated['task'] ?? null);
        $this->assertSame('content-pages:publish-controlled', $generated['command_name'] ?? null);
        $this->assertArrayHasKey('final_decision', $generated);
        $this->assertArrayHasKey('next_task', $generated);
    }

    public function test_generated_help_service_runtime_report_exists_and_parses(): void
    {
        $generatedPath = base_path('docs/help/generated/help-content-pages-controlled-publish-runtime-01.v1.json');

        $this->assertFileExists(base_path('docs/operations/help-content-pages-controlled-publish-runtime-2026-06-08.md'));
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true);

        $this->assertIsArray($generated);
        $this->assertSame('HELP-CONTENT-PAGES-CONTROLLED-PUBLISH-RUNTIME-01', $generated['task'] ?? null);
        $this->assertSame('content-pages:publish-controlled', $generated['command_name'] ?? null);
        $this->assertSame('help-service', $generated['runtime_scope'] ?? null);
        $this->assertSame(12, $generated['target_count'] ?? null);
        $this->assertFalse((bool) ($generated['publish_executed_in_this_pr'] ?? true));
        $this->assertSame('HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R2-01', $generated['next_task'] ?? null);
    }

    public function test_generated_science_zh_controlled_publish_readiness_report_exists_and_parses(): void
    {
        $generatedPath = base_path('docs/seo/generated/science-contentpage-zh-controlled-publish-readiness-01.v1.json');

        $this->assertFileExists(base_path('docs/seo/science-contentpage-zh-controlled-publish-readiness-01.md'));
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true);

        $this->assertIsArray($generated);
        $this->assertSame('SCIENCE-CONTENTPAGE-ZH-CONTROLLED-PUBLISH-READINESS-01', $generated['task'] ?? null);
        $this->assertSame('science-zh', $generated['runtime_scope'] ?? null);
        $this->assertSame('zh-CN', $generated['target_locale'] ?? null);
        $this->assertSame(self::scienceZhTargetKeys(), $generated['target_pages'] ?? null);
        $this->assertFalse((bool) ($generated['publish_executed_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($generated['production_cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($generated['is_indexable_after_publish'] ?? true));
    }

    /**
     * @param  list<string>  $skip
     */
    private function seedControlledTargets(array $skip = []): void
    {
        foreach (self::targetKeys() as $key) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            ContentPage::query()->withoutGlobalScopes()->create($this->pageAttributes($key));
        }
    }

    private function seedHelpServiceTargets(): void
    {
        foreach ($this->helpServiceSourceRows() as $row) {
            ContentPage::query()->withoutGlobalScopes()->create($this->helpServicePageAttributes($row));
        }
    }

    /**
     * @param  list<string>  $skip
     * @param  array<string, array<string, mixed>>  $overridesByKey
     */
    private function seedScienceZhTargets(array $skip = [], array $overridesByKey = []): void
    {
        foreach (self::scienceZhTargetKeys() as $key) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            ContentPage::query()->withoutGlobalScopes()->create($this->scienceZhPageAttributes($key, $overridesByKey[$key] ?? []));
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pageAttributes(string $key, array $overrides = []): array
    {
        $title = $key === 'foundation' ? 'Public-Benefit Mission and Governance' : ucfirst($key).' Page';
        $body = $key === 'foundation'
            ? "# Public-Benefit Mission and Governance\n\nThis page describes a planned public-benefit shareholding arrangement and public-benefit governance path."
            : "# {$title}\n\nApproved English Wave 1 content page body.";

        return $overrides + [
            'org_id' => 0,
            'slug' => $key,
            'path' => '/'.$key,
            'kind' => $key === 'policies' ? ContentPage::KIND_POLICY : ContentPage::KIND_COMPANY,
            'page_type' => $key === 'policies' ? 'policy' : 'company',
            'title' => $title,
            'summary' => 'Approved summary for '.$key.'.',
            'template' => $key === 'policies' ? 'policy' : ($key === 'foundation' ? 'foundation' : 'company'),
            'animation_profile' => 'none',
            'locale' => 'en',
            'translation_group_id' => 'content-page-'.$key,
            'source_locale' => 'zh-CN',
            'translation_status' => ContentPage::TRANSLATION_STATUS_APPROVED,
            'published_at' => null,
            'source_doc' => 'global-en-zh-content-pages-cms-draft-update-01 from human revision packages',
            'is_public' => false,
            'is_indexable' => false,
            'review_state' => 'approved',
            'headings_json' => [$title],
            'content_md' => $body,
            'content_html' => '',
            'seo_title' => $title,
            'meta_description' => 'Approved meta description for '.$key.'.',
            'seo_description' => 'Approved SEO description for '.$key.'.',
            'canonical_path' => '/'.$key,
            'status' => ContentPage::STATUS_DRAFT,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function helpServicePageAttributes(array $row): array
    {
        return [
            'org_id' => 0,
            'slug' => (string) $row['slug'],
            'path' => (string) $row['path'],
            'kind' => ContentPage::KIND_HELP,
            'page_type' => (string) ($row['pageType'] ?? 'support_static'),
            'title' => (string) $row['title'],
            'kicker' => (string) ($row['kicker'] ?? 'Help'),
            'summary' => (string) $row['summary'],
            'template' => 'help',
            'animation_profile' => (string) ($row['animationProfile'] ?? 'editorial'),
            'locale' => (string) $row['locale'],
            'translation_group_id' => (string) ($row['translationGroupId'] ?? ('content-page-'.$row['slug'])),
            'source_locale' => (string) ($row['sourceLocale'] ?? 'zh-CN'),
            'translation_status' => (string) ($row['translationStatus'] ?? 'source'),
            'published_at' => null,
            'updated_at' => (string) ($row['updatedAt'] ?? '2026-06-04'),
            'effective_at' => null,
            'source_doc' => (string) $row['sourceDoc'],
            'is_public' => false,
            'is_indexable' => false,
            'review_state' => 'owner_review',
            'headings_json' => (array) ($row['headings'] ?? []),
            'content_md' => (string) $row['contentMd'],
            'content_html' => (string) ($row['contentHtml'] ?? ''),
            'seo_title' => (string) $row['seoTitle'],
            'meta_description' => (string) $row['metaDescription'],
            'seo_description' => (string) $row['seoDescription'],
            'canonical_path' => (string) $row['canonicalPath'],
            'support_contact' => 'support@fermatmind.com',
            'policy_version' => 'help_service_policy.v1',
            'reviewer' => 'Unknown',
            'schema_enabled' => false,
            'faq_items' => (array) $row['faq_items'],
            'status' => ContentPage::STATUS_DRAFT,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function scienceZhPageAttributes(string $key, array $overrides = []): array
    {
        $titles = [
            'science' => '测评科学',
            'method-boundaries' => '方法边界',
            'item-design-notes' => '题目设计说明',
            'reliability-validity' => '信度效度',
            'data-privacy' => '数据说明',
            'common-misconceptions' => '常见误区',
        ];
        $pageTypes = [
            'science' => 'science',
            'method-boundaries' => 'methodology',
            'item-design-notes' => 'methodology',
            'reliability-validity' => 'methodology',
            'data-privacy' => 'privacy',
            'common-misconceptions' => 'boundary',
        ];
        $title = $titles[$key] ?? $key;

        return $overrides + [
            'org_id' => 0,
            'slug' => $key,
            'path' => '/'.$key,
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => $pageTypes[$key] ?? 'methodology',
            'title' => $title,
            'kicker' => '测评科学',
            'summary' => $title.'的中文内容页草稿。',
            'template' => 'policy',
            'animation_profile' => 'editorial',
            'locale' => 'zh-CN',
            'translation_group_id' => 'content-page-'.$key,
            'source_locale' => 'zh-CN',
            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
            'published_at' => null,
            'source_doc' => 'science-contentpage-gpt55-review-draft-2026-06-08/pages/'.$key.'.md',
            'is_public' => false,
            'is_indexable' => false,
            'review_state' => 'science_review',
            'owner' => 'seo_content',
            'legal_review_required' => true,
            'science_review_required' => true,
            'headings_json' => [$title],
            'content_md' => "# {$title}\n\n这是 {$title} 的中文 CMS 草稿内容，用于受控发布验证。",
            'content_html' => '',
            'seo_title' => $title,
            'meta_description' => $title.'的中文说明。',
            'seo_description' => $title.'的中文说明。',
            'canonical_path' => '/'.$key,
            'support_contact' => null,
            'policy_version' => null,
            'reviewer' => 'Unknown',
            'schema_enabled' => false,
            'faq_items' => [],
            'publish_allowed' => false,
            'operator_approval_required' => true,
            'operator_approved_at' => null,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'faq_schema_eligible' => false,
            'schema_eligibility_reviewed_at' => null,
            'status' => ContentPage::STATUS_DRAFT,
        ];
    }

    private function contentPage(string $key): ContentPage
    {
        return ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', $key)
            ->where('locale', 'en')
            ->firstOrFail();
    }

    private function contentPageForLocale(string $key, string $locale): ContentPage
    {
        return ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', $key)
            ->where('locale', $locale)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runPublishCommand(array $options = [], int $expectedExitCode = 0): array
    {
        $buffer = new BufferedOutput;
        $exitCode = Artisan::call('content-pages:publish-controlled', $options + [
            '--locale' => 'en',
            '--keys' => implode(',', self::targetKeys()),
            '--json' => true,
        ], $buffer);

        $output = $buffer->fetch();

        $this->assertSame($expectedExitCode, $exitCode, $output);

        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded, $output);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runHelpServicePublishCommand(array $options = [], int $expectedExitCode = 0): array
    {
        return $this->runPublishCommand($options + [
            '--scope' => 'help-service',
            '--locale' => 'all',
            '--keys' => implode(',', self::helpServiceTargetKeys()),
        ], $expectedExitCode);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runScienceZhPublishCommand(array $options = [], int $expectedExitCode = 0): array
    {
        return $this->runPublishCommand($options + [
            '--scope' => 'science-zh',
            '--locale' => 'zh-CN',
            '--keys' => implode(',', self::scienceZhTargetKeys()),
        ], $expectedExitCode);
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function errorCodes(array $output): array
    {
        return array_values(array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            (array) ($output['errors'] ?? []),
        ));
    }

    /**
     * @return list<string>
     */
    private static function targetKeys(): array
    {
        return ['brand', 'charter', 'foundation', 'careers', 'policies'];
    }

    /**
     * @return list<string>
     */
    private static function helpServiceTargetKeys(): array
    {
        return [
            'help-unlock-failure',
            'help-payment-refund',
            'help-result-recovery',
            'help-privacy-data',
            'help-use-boundaries',
            'help-data-deletion',
        ];
    }

    /**
     * @return list<string>
     */
    private static function scienceZhTargetKeys(): array
    {
        return [
            'science',
            'item-design-notes',
            'reliability-validity',
            'data-privacy',
            'common-misconceptions',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function helpServiceSourceRows(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path('docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json')), true);

        $this->assertIsArray($decoded);

        return array_values(array_filter(
            $decoded,
            static fn (mixed $row): bool => is_array($row),
        ));
    }
}
