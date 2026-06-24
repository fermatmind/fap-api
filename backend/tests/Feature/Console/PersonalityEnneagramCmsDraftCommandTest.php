<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityEnneagramCmsDraftCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_twenty_six_enneagram_drafts_without_writes(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:enneagram-cms-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(26, $payload['row_count']);
        $this->assertSame(2, $payload['hub_row_count']);
        $this->assertSame(6, $payload['center_row_count']);
        $this->assertSame(18, $payload['core_type_row_count']);
        $this->assertSame(26, $payload['would_create_asset_count']);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_creates_non_public_noindex_draft_assets_only(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:enneagram-cms-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(26, $payload['row_count']);
        $this->assertSame(26, $payload['created_asset_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(26, PersonalityPublicContentAsset::query()->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('sitemap_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
        $this->assertSame(26, PersonalityPublicContentAsset::query()->where('robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW)->count());
        $this->assertSame(26, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_REVIEW)->count());
    }

    public function test_second_write_is_idempotent_for_same_source_hash(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);

        $firstExitCode = Artisan::call('personality:enneagram-cms-draft', $options);
        $this->assertSame(0, $firstExitCode);

        $secondExitCode = Artisan::call('personality:enneagram-cms-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_asset_count']);
        $this->assertSame(26, $payload['skipped_existing_count']);
        $this->assertSame(26, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_requires_all_safety_flags_and_exact_operator_token(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:enneagram-cms-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--write' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString('--draft-only is required with --write', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_wing_instinct_tritype_and_private_routes_fail_closed_with_zero_writes(): void
    {
        $package = [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-PILOT-01',
            'recommendations' => [
                $this->recommendation('https://fermatmind.com/en/personality/enneagram/type-1-wing-9', 'en', 'wing'),
                $this->recommendation('https://fermatmind.com/en/personality/enneagram/instinctual-subtypes', 'en', 'instinctual_subtype'),
                $this->recommendation('https://fermatmind.com/zh/personality/enneagram/tritype', 'zh-CN', 'tritype'),
                $this->recommendation('https://fermatmind.com/en/personality/enneagram/type-1?token=secret', 'en', 'core_type'),
            ],
        ];
        [$packagePath, $qaPath] = $this->writeArtifacts($package, [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-QA-01',
            'page_results' => array_map(
                fn (array $recommendation): array => $this->qaRow((string) $recommendation['target_url']),
                $package['recommendations']
            ),
        ]);

        $exitCode = Artisan::call('personality:enneagram-cms-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_asset_count']);
        $this->assertContains('unsupported_enneagram_target_url', array_column($payload['errors'], 'code'));
        $this->assertContains('forbidden_private_route_pattern_present', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_existing_live_asset_blocks_draft_write_without_mutation(): void
    {
        PersonalityPublicContentAsset::query()->create([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'enneagram',
            'slug' => 'enneagram',
            'locale' => 'en',
            'title' => 'Live title',
            'summary' => 'Live summary',
            'content_sections_json' => [],
            'seo_json' => ['title' => 'Live title'],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => '/en/personality/enneagram'],
            'hreflang_json' => [],
            'faq_json' => [],
            'media_json' => [],
            'schema_json' => [],
            'method_boundary_json' => [],
            'evidence_notes_json' => [],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'review_state' => 'placeholder',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => 'existing',
            'source_hash' => 'existing',
        ]);
        [$packagePath, $qaPath] = $this->writeArtifacts($this->singleHubPackage(), $this->singleHubQa());

        $exitCode = Artisan::call('personality:enneagram-cms-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertContains('existing_live_or_foreign_asset_blocks_draft_write', array_column($payload['errors'], 'code'));
        $this->assertSame(1, PersonalityPublicContentAsset::query()->count());
        $this->assertSame('Live title', PersonalityPublicContentAsset::query()->first()?->title);
    }

    /**
     * @return array<string,mixed>
     */
    private function validPackage(): array
    {
        $recommendations = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $prefix = $locale === 'zh-CN' ? 'zh' : 'en';
            $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/enneagram", $locale, 'hub');
            foreach (['gut', 'heart', 'head'] as $center) {
                $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/enneagram/centers/{$center}", $locale, 'center');
            }
            for ($type = 1; $type <= 9; $type++) {
                $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/enneagram/type-{$type}", $locale, 'core_type');
            }
        }

        return [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-PILOT-01',
            'version' => 'enneagram.agent_pilot.v1',
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validQa(): array
    {
        return [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-QA-01',
            'final_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'page_results' => array_map(
                fn (array $recommendation): array => $this->qaRow((string) $recommendation['target_url']),
                $this->validPackage()['recommendations']
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function singleHubPackage(): array
    {
        return [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-PILOT-01',
            'recommendations' => [
                $this->recommendation('https://fermatmind.com/en/personality/enneagram', 'en', 'hub'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function singleHubQa(): array
    {
        return [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-QA-01',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/en/personality/enneagram'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendation(string $targetUrl, string $locale, string $entityType): array
    {
        return [
            'recommendation_id' => 'enneagram-agent:'.parse_url($targetUrl, PHP_URL_PATH),
            'target_url' => $targetUrl,
            'framework' => 'enneagram',
            'locale' => $locale,
            'entity_type' => $entityType,
            'recommendations' => [
                'title' => 'Enneagram SEO Title',
                'description' => 'Enneagram SEO description',
                'h1' => 'Enneagram H1',
                'quick_answer' => 'This is a reflective Enneagram draft, not a diagnosis or hiring screen.',
                'faq' => [
                    [
                        'question' => 'Is this diagnostic?',
                        'answer' => 'No. It is reflective educational content.',
                    ],
                ],
                'internal_links' => [],
                'differentiation_notes' => [
                    'Keep this page distinct from neighboring Enneagram content.',
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qaRow(string $targetUrl): array
    {
        return [
            'target_url' => $targetUrl,
            'decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'blockers' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array{0:string,1:string}
     */
    private function writeArtifacts(array $package, array $qa): array
    {
        $dir = storage_path('framework/testing/enneagram_cms_draft');
        File::ensureDirectoryExists($dir);
        $packagePath = $dir.'/package.json';
        $qaPath = $dir.'/qa.json';
        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($qaPath, json_encode($qa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$packagePath, $qaPath];
    }

    /**
     * @return array<string,mixed>
     */
    private function writeOptions(string $packagePath, string $qaPath): array
    {
        return [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--write' => true,
            '--json' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--operator-approved' => 'ENNEAGRAM-CMS-DRAFT-WRITER-CONTRACT-01',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded, Artisan::output());

        return $decoded;
    }
}
