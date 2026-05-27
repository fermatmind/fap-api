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

    private function contentPage(string $key): ContentPage
    {
        return ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', $key)
            ->where('locale', 'en')
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
}
