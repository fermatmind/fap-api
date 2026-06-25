<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityBigFivePublicProfileAgentPromote;
use App\Models\PersonalityPublicContentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityBigFivePublicProfileAgentPromoteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_thirty_four_big_five_promotions_without_writes(): void
    {
        $packagePath = $this->writePackage($this->validPackage());
        $this->seedDrafts($packagePath);

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
        $this->assertSame(34, $payload['row_count']);
        $this->assertSame(17, $payload['logical_entity_count']);
        $this->assertSame(['en' => 17, 'zh-CN' => 17], $payload['locale_counts']);
        $this->assertSame(2, $payload['hub_row_count']);
        $this->assertSame(10, $payload['domain_row_count']);
        $this->assertSame(20, $payload['polarity_row_count']);
        $this->assertSame(2, $payload['facet_hub_row_count']);
        $this->assertSame(34, $payload['would_promote_count']);
        $this->assertSame(0, $payload['promoted_count']);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
        $this->assertSame(34, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_REVIEW)->count());
    }

    public function test_write_promotes_thirty_four_drafts_to_content_ready_without_index_search_side_effects(): void
    {
        $packagePath = $this->writePackage($this->validPackage());
        $this->seedDrafts($packagePath);

        $exitCode = $this->callPromote($this->writeOptions($packagePath));

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
        $this->assertSame(34, $payload['promoted_count']);
        $this->assertSame(34, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
        $this->assertSame(34, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)->count());
        $this->assertSame(34, PersonalityPublicContentAsset::query()->where('robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('sitemap_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
    }

    public function test_second_write_is_idempotent_when_assets_are_already_content_ready(): void
    {
        $packagePath = $this->writePackage($this->validPackage());
        $this->seedDrafts($packagePath);
        $this->assertSame(0, $this->callPromote($this->writeOptions($packagePath)));

        $secondExitCode = $this->callPromote($this->writeOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['promoted_count']);
        $this->assertSame(34, $payload['skipped_existing_count']);
        $this->assertSame(34, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)->count());
    }

    public function test_write_requires_all_safety_flags_and_exact_operator_token(): void
    {
        $packagePath = $this->writePackage($this->validPackage());
        $this->seedDrafts($packagePath);

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

    public function test_source_or_recommendation_hash_mismatch_fails_closed_without_partial_promotion(): void
    {
        $packagePath = $this->writePackage($this->singleHubPackage());
        $this->seedDrafts($packagePath);
        PersonalityPublicContentAsset::query()->update(['source_hash' => 'different-source']);

        $exitCode = $this->callPromote($this->writeOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['promoted_count']);
        $this->assertContains('source_hash_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
    }

    public function test_missing_unsupported_or_private_target_fails_closed_without_writes(): void
    {
        $package = [
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-PILOT-01',
            'recommendations' => [
                $this->recommendation('https://fermatmind.com/en/personality/big-five/openness', 'en', 'domain'),
                $this->recommendation('https://fermatmind.com/en/personality/big-five/facet-adventurousness', 'en', 'facet'),
                $this->recommendation('https://fermatmind.com/zh/personality/big-five/high-openness?token=secret', 'zh-CN', 'polarity'),
            ],
        ];
        $packagePath = $this->writePackage($package);
        $this->seedDrafts($packagePath, onlyFirst: true);

        $exitCode = $this->callPromote($this->writeOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertContains('unsupported_big_five_target_url', array_column($payload['errors'], 'code'));
        $this->assertContains('forbidden_private_route_pattern_present', array_column($payload['errors'], 'code'));
        $this->assertContains('missing_draft_asset', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function callPromote(array $options): int
    {
        Artisan::registerCommand($this->app->make(PersonalityBigFivePublicProfileAgentPromote::class));

        return Artisan::call('personality:big-five-public-profile-agent-promote', $options);
    }

    private function seedDrafts(string $packagePath, bool $onlyFirst = false): void
    {
        $raw = (string) File::get($packagePath);
        $package = json_decode($raw, true);
        $this->assertIsArray($package);
        $sourceSha256 = hash('sha256', $raw);

        foreach (array_values($package['recommendations'] ?? []) as $index => $recommendation) {
            if ($onlyFirst && $index > 0) {
                break;
            }

            $this->assertIsArray($recommendation);
            $identity = $this->identityForRecommendation($recommendation);
            if ($identity === null) {
                continue;
            }

            $recommendationJson = json_encode($recommendation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $recommendationSha256 = hash('sha256', (string) $recommendationJson);
            PersonalityPublicContentAsset::query()->create([
                'org_id' => 0,
                'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                'entity_type' => $identity['entity_type'],
                'entity_key' => $identity['entity_key'],
                'slug' => $identity['slug'],
                'locale' => $identity['locale'],
                'title' => 'Big Five H1',
                'summary' => 'This is reflective Big Five educational content.',
                'content_sections_json' => [],
                'seo_json' => ['title' => 'Big Five SEO Title'],
                'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
                'canonical_json' => ['path' => $identity['path']],
                'hreflang_json' => [],
                'faq_json' => [],
                'media_json' => [],
                'schema_json' => [],
                'method_boundary_json' => [],
                'evidence_notes_json' => [
                    [
                        'source_type' => 'agent_recommendation',
                        'source' => 'big_five_agent_public_profile_draft_v1',
                        'package_sha256' => $sourceSha256,
                        'qa_sha256' => 'test-qa-sha',
                        'recommendation_sha256' => $recommendationSha256,
                    ],
                ],
                'internal_links_json' => [],
                'is_public' => false,
                'index_eligible' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_REVIEW,
                'review_state' => 'agent_draft_pending_review',
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                'source_package' => 'big_five_agent_public_profile_draft_v1',
                'source_hash' => $sourceSha256,
            ]);
        }
    }

    /**
     * @return array{path:string,locale:string,entity_type:string,entity_key:string,slug:string}|null
     */
    private function identityForRecommendation(array $recommendation): ?array
    {
        $path = (string) parse_url((string) ($recommendation['target_url'] ?? ''), PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        $prefix = str_starts_with($path, '/zh/') ? 'zh' : 'en';
        $locale = $prefix === 'zh' ? 'zh-CN' : 'en';
        if (preg_match('#^/(?:en|zh)/personality/big-five$#', $path) === 1) {
            return ['path' => $path, 'locale' => $locale, 'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB, 'entity_key' => 'big-five', 'slug' => 'big-five'];
        }

        if (preg_match('#^/(?:en|zh)/personality/big-five/facets$#', $path) === 1) {
            return ['path' => $path, 'locale' => $locale, 'entity_type' => PersonalityPublicContentAsset::ENTITY_FACET_HUB, 'entity_key' => 'facets', 'slug' => 'big-five/facets'];
        }

        if (preg_match('#^/(?:en|zh)/personality/big-five/(?<slug>[a-z-]+)$#', $path, $matches) !== 1) {
            return null;
        }

        $slug = strtolower((string) $matches['slug']);
        $entityType = (string) ($recommendation['entity_type'] ?? '');
        if (! in_array($entityType, [
            PersonalityPublicContentAsset::ENTITY_DOMAIN,
            PersonalityPublicContentAsset::ENTITY_POLARITY,
        ], true)) {
            return null;
        }

        return ['path' => $path, 'locale' => $locale, 'entity_type' => $entityType, 'entity_key' => $slug, 'slug' => 'big-five/'.$slug];
    }

    /**
     * @return array<string,mixed>
     */
    private function validPackage(): array
    {
        $recommendations = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $prefix = $locale === 'zh-CN' ? 'zh' : 'en';
            $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five", $locale, 'hub');
            $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five/facets", $locale, 'facet_hub');
            foreach (['openness', 'conscientiousness', 'extraversion', 'agreeableness', 'neuroticism'] as $domain) {
                $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five/{$domain}", $locale, 'domain');
            }
            foreach (['openness', 'conscientiousness', 'extraversion', 'agreeableness', 'neuroticism'] as $domain) {
                $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five/high-{$domain}", $locale, 'polarity');
                $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five/low-{$domain}", $locale, 'polarity');
            }
        }

        return [
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-PILOT-01',
            'version' => 'big_five.agent_pilot.v1',
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function singleHubPackage(): array
    {
        return [
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-PILOT-01',
            'recommendations' => [
                $this->recommendation('https://fermatmind.com/en/personality/big-five', 'en', 'hub'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendation(string $targetUrl, string $locale, string $entityType): array
    {
        return [
            'recommendation_id' => 'big-five-agent:'.parse_url($targetUrl, PHP_URL_PATH),
            'target_url' => $targetUrl,
            'framework' => 'big_five',
            'locale' => $locale,
            'entity_type' => $entityType,
            'recommendations' => [
                'title' => 'Big Five SEO Title',
                'description' => 'Big Five SEO description',
                'h1' => 'Big Five H1',
                'quick_answer' => 'This is reflective Big Five educational content, not diagnosis or hiring screening.',
                'faq' => [
                    [
                        'question' => 'Is this diagnostic?',
                        'answer' => 'No. It is reflective educational content.',
                    ],
                ],
                'internal_links' => [],
                'differentiation_notes' => [
                    'Keep this page distinct from neighboring Big Five content.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function writePackage(array $package): string
    {
        $dir = storage_path('framework/testing/big_five_public_profile_agent_promote');
        File::ensureDirectoryExists($dir);
        $packagePath = $dir.'/package.json';
        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $packagePath;
    }

    /**
     * @return array<string,mixed>
     */
    private function writeOptions(string $packagePath): array
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
            '--operator-approved' => 'BIG-FIVE-CMS-PROMOTION-CONTRACT-01',
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
