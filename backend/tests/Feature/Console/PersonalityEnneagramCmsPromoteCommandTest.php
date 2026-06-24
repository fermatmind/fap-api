<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityEnneagramCmsPromote;
use App\Models\PersonalityPublicContentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityEnneagramCmsPromoteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_twenty_six_enneagram_promotions_without_writes(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->seedDrafts($packagePath, $qaPath);

        $exitCode = $this->callPromote([
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
        $this->assertFalse($payload['content_promotion_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(26, $payload['row_count']);
        $this->assertSame(2, $payload['hub_row_count']);
        $this->assertSame(6, $payload['center_row_count']);
        $this->assertSame(18, $payload['core_type_row_count']);
        $this->assertSame(26, $payload['would_promote_count']);
        $this->assertSame(0, $payload['promoted_count']);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
        $this->assertSame(26, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_REVIEW)->count());
    }

    public function test_write_promotes_twenty_six_drafts_to_live_content_ready_without_index_search_side_effects(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->seedDrafts($packagePath, $qaPath);

        $exitCode = $this->callPromote($this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertTrue($payload['content_promotion_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(26, $payload['promoted_count']);
        $this->assertSame(26, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
        $this->assertSame(26, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)->count());
        $this->assertSame(26, PersonalityPublicContentAsset::query()->where('robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('sitemap_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
    }

    public function test_second_write_is_idempotent_when_assets_already_match_live_content_ready_state(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->seedDrafts($packagePath, $qaPath);
        $this->assertSame(0, $this->callPromote($this->promoteWriteOptions($packagePath)));

        $secondExitCode = $this->callPromote($this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['promoted_count']);
        $this->assertSame(26, $payload['skipped_existing_count']);
        $this->assertSame(26, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)->count());
    }

    public function test_write_requires_all_safety_flags_and_exact_operator_token(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $this->seedDrafts($packagePath, $qaPath);

        $exitCode = $this->callPromote([
            '--package' => $packagePath,
            '--write' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString('--promote-live-content is required with --write', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
    }

    public function test_missing_or_foreign_source_draft_fails_closed_without_partial_promotion(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->singleHubPackage(), $this->singleHubQa());
        $this->seedDrafts($packagePath, $qaPath);
        PersonalityPublicContentAsset::query()->update(['source_hash' => 'different-source']);

        $exitCode = $this->callPromote($this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['promoted_count']);
        $this->assertContains('source_hash_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
    }

    public function test_private_or_unsupported_target_fails_closed_without_writes(): void
    {
        $package = [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-PILOT-01',
            'recommendations' => [
                $this->recommendation('https://fermatmind.com/en/personality/enneagram/type-1?token=secret', 'en', 'core_type'),
                $this->recommendation('https://fermatmind.com/en/personality/enneagram/type-1-wing-9', 'en', 'wing'),
            ],
        ];
        [$packagePath] = $this->writeArtifacts($package, [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-QA-01',
            'page_results' => [],
        ]);

        $exitCode = $this->callPromote($this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertContains('unsupported_enneagram_target_url', array_column($payload['errors'], 'code'));
        $this->assertContains('forbidden_private_route_pattern_present', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function callPromote(array $options): int
    {
        Artisan::registerCommand($this->app->make(PersonalityEnneagramCmsPromote::class));

        return Artisan::call('personality:enneagram-cms-promote', $options);
    }

    private function seedDrafts(string $packagePath, string $qaPath): void
    {
        $exitCode = Artisan::call('personality:enneagram-cms-draft', [
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
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());
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
        $dir = storage_path('framework/testing/enneagram_cms_promote');
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
    private function promoteWriteOptions(string $packagePath): array
    {
        return [
            '--package' => $packagePath,
            '--write' => true,
            '--json' => true,
            '--promote-live-content' => true,
            '--no-publish' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--operator-approved' => 'ENNEAGRAM-CMS-PROMOTION-CONTRACT-01',
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
