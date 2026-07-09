<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityEnneagramCmsPublishGate;
use App\Models\PersonalityPublicContentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityEnneagramCmsPublishGateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_thirteen_zh_cn_publish_gate_without_writes(): void
    {
        $packagePath = $this->writePackage($this->zh13Package());
        $this->seedContentReadyAssets();

        $exitCode = $this->callPublishGate([
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_performed']);
        $this->assertSame(13, $payload['row_count']);
        $this->assertSame(1, $payload['hub_row_count']);
        $this->assertSame(3, $payload['center_row_count']);
        $this->assertSame(9, $payload['core_type_row_count']);
        $this->assertSame(13, $payload['would_publish_count']);
        $this->assertSame(0, $payload['published_count']);

        // Verify no writes occurred
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)->count());
        $this->assertSame(13, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
    }

    public function test_write_publishes_thirteen_content_ready_assets_to_live_published_state(): void
    {
        $packagePath = $this->writePackage($this->zh13Package());
        $this->seedContentReadyAssets();

        $exitCode = $this->callPublishGate($this->writeOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertTrue($payload['publish_performed']);
        $this->assertSame(13, $payload['published_count']);

        // Verify state changes
        $this->assertSame(13, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)->count());
        $this->assertSame(13, PersonalityPublicContentAsset::query()->where('robots', PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW)->count());
        $this->assertSame(13, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
        $this->assertSame(13, PersonalityPublicContentAsset::query()->where('sitemap_eligible', true)->count());
        $this->assertSame(13, PersonalityPublicContentAsset::query()->where('is_public', true)->count());

        // LLMs must NOT be released
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
    }

    public function test_write_fails_without_operator_token(): void
    {
        $packagePath = $this->writePackage($this->zh13Package());
        $this->seedContentReadyAssets();

        $exitCode = Artisan::call('personality:enneagram-cms-publish-gate', [
            '--package' => $packagePath,
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('operator-approved', Artisan::output());

        // No mutation
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)->count());
    }

    public function test_write_fails_with_wrong_operator_token(): void
    {
        $packagePath = $this->writePackage($this->zh13Package());
        $this->seedContentReadyAssets();

        $exitCode = $this->callPublishGate(array_merge($this->writeOptions($packagePath), [
            '--operator-approved' => 'WRONG-TOKEN',
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
    }

    public function test_idempotent_skip_already_published_assets(): void
    {
        $packagePath = $this->writePackage($this->zh13Package());
        $this->seedContentReadyAssets();

        // First write
        $this->callPublishGate($this->writeOptions($packagePath));
        $this->assertSame(13, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)->count());

        // Second write (idempotent)
        $exitCode = $this->callPublishGate($this->writeOptions($packagePath));
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(0, $payload['published_count']);
        $this->assertSame(13, $payload['skipped_existing_count']);
        $this->assertSame(13, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)->count());
    }

    public function test_en_locale_rows_are_rejected(): void
    {
        $pkg = [
            'artifact' => 'TEST',
            'framework' => 'enneagram',
            'recommendations' => [
                $this->recommendation('https://fermatmind.com/en/personality/enneagram', 'en', 'hub'),
            ],
        ];
        $packagePath = $this->writePackage($pkg);

        $exitCode = $this->callPublishGate([
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('locale_not_supported_for_publish', array_column($payload['errors'], 'code'));
    }

    public function test_non_content_ready_assets_are_rejected(): void
    {
        $packagePath = $this->writePackage($this->zh13Package());
        // Create assets in draft state, not content_ready
        $identities = [
            ['entity_type' => 'hub', 'entity_key' => 'enneagram', 'locale' => 'zh-CN'],
            ['entity_type' => 'core_type', 'entity_key' => 'type-1', 'locale' => 'zh-CN'],
        ];
        foreach ($identities as $id) {
            PersonalityPublicContentAsset::query()->create([
                'org_id' => 0,
                'framework' => 'enneagram',
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
                'robots' => 'noindex,nofollow',
                'is_public' => false,
                'index_eligible' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'title' => 'Test: '.$id['entity_key'],
                'summary' => 'Test summary',
                ...$id,
                'slug' => $id['entity_key'],
            ]);
        }

        $exitCode = $this->callPublishGate([
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertContains('asset_not_content_ready', array_column($payload['errors'], 'code'));
    }

    // ---- helpers ----

    /**
     * @param  array<string,mixed>  $options
     */
    private function callPublishGate(array $options): int
    {
        Artisan::registerCommand($this->app->make(PersonalityEnneagramCmsPublishGate::class));

        return Artisan::call('personality:enneagram-cms-publish-gate', $options);
    }

    private function seedContentReadyAssets(): void
    {
        // Create content_ready assets matching zh13 package
        $entities = [
            ['entity_type' => 'hub', 'entity_key' => 'enneagram'],
            ['entity_type' => 'center', 'entity_key' => 'gut'],
            ['entity_type' => 'center', 'entity_key' => 'heart'],
            ['entity_type' => 'center', 'entity_key' => 'head'],
            ...array_map(fn (int $t): array => ['entity_type' => 'core_type', 'entity_key' => 'type-'.$t], range(1, 9)),
        ];

        foreach ($entities as $entity) {
            PersonalityPublicContentAsset::query()->create([
                'org_id' => 0,
                'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                'entity_type' => $entity['entity_type'],
                'entity_key' => $entity['entity_key'],
                'slug' => $entity['entity_key'],
                'locale' => 'zh-CN',
                'title' => 'Test: '.$entity['entity_key'],
                'summary' => 'Test summary',
                'is_public' => true,
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
                'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
                'index_eligible' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'canonical_json' => ['path' => '/zh/personality/enneagram/'.($entity['entity_type'] === 'core_type' ? $entity['entity_key'] : ($entity['entity_type'] === 'center' ? 'centers/'.$entity['entity_key'] : ''))],
                'seo_json' => ['title' => 'SEO Title'],
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function writeOptions(string $packagePath): array
    {
        return [
            '--package' => $packagePath,
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--operator-approved' => 'ENNEAGRAM-ZH13-CMS-PUBLISH-GATE-01',
            '--json' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function zh13Package(): array
    {
        $recommendations = [];
        $recommendations[] = $this->recommendation('https://fermatmind.com/zh/personality/enneagram', 'zh-CN', 'hub');
        foreach (['gut', 'heart', 'head'] as $center) {
            $recommendations[] = $this->recommendation("https://fermatmind.com/zh/personality/enneagram/centers/{$center}", 'zh-CN', 'center');
        }
        for ($type = 1; $type <= 9; $type++) {
            $recommendations[] = $this->recommendation("https://fermatmind.com/zh/personality/enneagram/type-{$type}", 'zh-CN', 'core_type');
        }

        return [
            'artifact' => 'ENNEAGRAM-ZH13-CMS-PACKAGE-NORMALIZE-01',
            'framework' => 'enneagram',
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendation(string $targetUrl, string $locale, string $entityType): array
    {
        return [
            'target_url' => $targetUrl,
            'framework' => 'enneagram',
            'locale' => $locale,
            'entity_type' => $entityType,
            'recommendations' => [
                'title' => 'SEO Title',
                'description' => 'SEO description',
                'h1' => 'H1',
                'quick_answer' => 'Not a diagnosis or hiring screen.',
                'faq' => [['q' => 'Is this diagnostic?', 'a' => 'No.']],
                'internal_links' => [],
            ],
        ];
    }

    private function writePackage(array $package): string
    {
        $path = sys_get_temp_dir().'/enneagram-publish-gate-test-package-'.bin2hex(random_bytes(4)).'.json';
        File::put($path, (string) json_encode($package));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Artisan output is not valid JSON: '.$output);

        return $decoded;
    }
}
