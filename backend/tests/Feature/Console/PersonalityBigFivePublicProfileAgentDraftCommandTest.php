<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityBigFivePublicProfileAgentDraftCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_thirty_four_big_five_drafts_without_writes(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:big-five-public-profile-agent-draft', [
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
        $this->assertSame(34, $payload['row_count']);
        $this->assertSame(17, $payload['logical_entity_count']);
        $this->assertSame(['en' => 17, 'zh-CN' => 17], $payload['locale_counts']);
        $this->assertSame(2, $payload['hub_row_count']);
        $this->assertSame(10, $payload['domain_row_count']);
        $this->assertSame(20, $payload['polarity_row_count']);
        $this->assertSame(2, $payload['facet_hub_row_count']);
        $this->assertSame(34, $payload['would_create_revision_count']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_requires_approved_approval_queue_items(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:big-five-public-profile-agent-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertContains('approved_approval_queue_item_required', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_creates_non_public_noindex_draft_assets_after_approval_only(): void
    {
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->approvePackageRows($packagePath, $qaPath, $package, $qa);

        $exitCode = Artisan::call('personality:big-five-public-profile-agent-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(34, $payload['row_count']);
        $this->assertSame(34, $payload['created_revision_count']);
        $this->assertSame(0, $payload['updated_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertSame(34, PersonalityPublicContentAsset::query()->where('framework', 'big_five')->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('sitemap_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
        $this->assertSame(34, PersonalityPublicContentAsset::query()->where('robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW)->count());
        $this->assertSame(34, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_REVIEW)->count());
    }

    public function test_second_write_is_idempotent_for_same_package_qa_and_recommendation_hashes(): void
    {
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->approvePackageRows($packagePath, $qaPath, $package, $qa);
        $options = $this->writeOptions($packagePath, $qaPath);

        $firstExitCode = Artisan::call('personality:big-five-public-profile-agent-draft', $options);
        $this->assertSame(0, $firstExitCode);

        $secondExitCode = Artisan::call('personality:big-five-public-profile-agent-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(0, $payload['updated_revision_count']);
        $this->assertSame(34, $payload['skipped_existing_count']);
        $this->assertSame(34, PersonalityPublicContentAsset::query()->count());
    }

    public function test_source_hash_change_updates_existing_draft_overlay_without_live_publication(): void
    {
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->approvePackageRows($packagePath, $qaPath, $package, $qa);
        $this->assertSame(0, Artisan::call('personality:big-five-public-profile-agent-draft', $this->writeOptions($packagePath, $qaPath)));

        $updatedPackage = $this->validPackage('Updated Big Five SEO description');
        [$updatedPackagePath, $updatedQaPath] = $this->writeArtifacts($updatedPackage, $qa, 'updated-package.json', 'updated-qa.json');
        $this->approvePackageRows($updatedPackagePath, $updatedQaPath, $updatedPackage, $qa);

        $exitCode = Artisan::call('personality:big-five-public-profile-agent-draft', $this->writeOptions($updatedPackagePath, $updatedQaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(34, $payload['updated_revision_count']);
        $this->assertSame(34, PersonalityPublicContentAsset::query()->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
    }

    public function test_write_requires_all_safety_flags_and_exact_operator_token(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:big-five-public-profile-agent-draft', [
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

    public function test_failed_qa_and_private_routes_fail_closed_with_zero_writes(): void
    {
        $package = [
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-PILOT-01',
            'recommendations' => [
                $this->recommendation('https://fermatmind.com/en/personality/big-five/openness', 'en', 'domain'),
                $this->recommendation('https://fermatmind.com/en/personality/big-five/facet-adventurousness', 'en', 'facet'),
                $this->recommendation('https://fermatmind.com/zh/personality/big-five/high-openness?token=secret', 'zh-CN', 'polarity'),
            ],
        ];
        $qa = [
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-QA-01',
            'decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'evaluations' => [
                $this->qaRow('https://fermatmind.com/en/personality/big-five/openness', 'failed', ['claim_safety_gate']),
                $this->qaRow('https://fermatmind.com/en/personality/big-five/facet-adventurousness'),
                $this->qaRow('https://fermatmind.com/zh/personality/big-five/high-openness?token=secret'),
            ],
        ];
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->approvePackageRows($packagePath, $qaPath, $package, $qa);

        $exitCode = Artisan::call('personality:big-five-public-profile-agent-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertContains('qa_pass_required', array_column($payload['errors'], 'code'));
        $this->assertContains('unsupported_big_five_target_url', array_column($payload['errors'], 'code'));
        $this->assertContains('forbidden_private_route_pattern_present', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_existing_live_asset_blocks_draft_write_without_mutation(): void
    {
        PersonalityPublicContentAsset::query()->create([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Live title',
            'summary' => 'Live summary',
            'content_sections_json' => [],
            'seo_json' => ['title' => 'Live title'],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => '/en/personality/big-five'],
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
        $package = $this->singleHubPackage();
        $qa = $this->singleHubQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->approvePackageRows($packagePath, $qaPath, $package, $qa);

        $exitCode = Artisan::call('personality:big-five-public-profile-agent-draft', $this->writeOptions($packagePath, $qaPath));

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
    private function validPackage(string $description = 'Big Five SEO description'): array
    {
        $recommendations = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $prefix = $locale === 'zh-CN' ? 'zh' : 'en';
            $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five", $locale, 'hub', $description);
            $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five/facets", $locale, 'facet_hub', $description);
            foreach (['openness', 'conscientiousness', 'extraversion', 'agreeableness', 'neuroticism'] as $domain) {
                $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five/{$domain}", $locale, 'domain', $description);
            }
            foreach (['openness', 'conscientiousness', 'extraversion', 'agreeableness', 'neuroticism'] as $domain) {
                $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five/high-{$domain}", $locale, 'polarity', $description);
                $recommendations[] = $this->recommendation("https://fermatmind.com/{$prefix}/personality/big-five/low-{$domain}", $locale, 'polarity', $description);
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
    private function validQa(): array
    {
        return [
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-QA-01',
            'decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'evaluations' => array_map(
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
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-PILOT-01',
            'recommendations' => [
                $this->recommendation('https://fermatmind.com/en/personality/big-five', 'en', 'hub'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function singleHubQa(): array
    {
        return [
            'artifact' => 'BIG-FIVE-PUBLIC-PROFILE-AGENT-QA-01',
            'evaluations' => [
                $this->qaRow('https://fermatmind.com/en/personality/big-five'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendation(string $targetUrl, string $locale, string $entityType, string $description = 'Big Five SEO description'): array
    {
        return [
            'recommendation_id' => 'big-five-agent:'.parse_url($targetUrl, PHP_URL_PATH),
            'target_url' => $targetUrl,
            'framework' => 'big_five',
            'locale' => $locale,
            'entity_type' => $entityType,
            'recommendations' => [
                'title' => 'Big Five SEO Title',
                'description' => $description,
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
     * @return array<string,mixed>
     */
    private function qaRow(string $targetUrl, string $decision = 'PASS_READY_FOR_APPROVAL_QUEUE', array $blockers = []): array
    {
        return [
            'target_url' => $targetUrl,
            'qa_status' => $decision === 'PASS_READY_FOR_APPROVAL_QUEUE' ? 'pass' : $decision,
            'decision' => $decision,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array{0:string,1:string}
     */
    private function writeArtifacts(array $package, array $qa, string $packageName = 'package.json', string $qaName = 'qa.json'): array
    {
        $dir = storage_path('framework/testing/big_five_public_profile_agent_draft');
        File::ensureDirectoryExists($dir);
        $packagePath = $dir.'/'.$packageName;
        $qaPath = $dir.'/'.$qaName;
        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($qaPath, json_encode($qa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$packagePath, $qaPath];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     */
    private function approvePackageRows(string $packagePath, string $qaPath, array $package, array $qa): void
    {
        $packageSha256 = hash_file('sha256', $packagePath);
        $qaSha256 = hash_file('sha256', $qaPath);
        $batchId = (int) DB::table('personality_agent_approval_batches')->insertGetId([
            'framework' => 'big_five',
            'source_artifact' => (string) ($package['artifact'] ?? ''),
            'source_artifact_path' => $packagePath,
            'source_package_sha256' => $packageSha256,
            'qa_artifact' => (string) ($qa['artifact'] ?? ''),
            'qa_artifact_path' => $qaPath,
            'qa_sha256' => $qaSha256,
            'status' => 'approved_for_draft_writer',
            'planned_item_count' => count($package['recommendations'] ?? []),
            'queued_item_count' => count($package['recommendations'] ?? []),
            'blocked_item_count' => 0,
            'safety_holds_json' => json_encode([]),
            'summary_json' => json_encode(['test_fixture' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $qaRows = [];
        foreach (($qa['evaluations'] ?? $qa['page_results'] ?? []) as $row) {
            if (is_array($row) && isset($row['target_url'])) {
                $qaRows[(string) $row['target_url']] = $row;
            }
        }

        foreach ($package['recommendations'] ?? [] as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }
            $recommendationJson = json_encode($recommendation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            DB::table('personality_agent_approval_items')->insert([
                'batch_id' => $batchId,
                'framework' => 'big_five',
                'target_url' => $targetUrl,
                'path' => (string) parse_url($targetUrl, PHP_URL_PATH),
                'locale' => (string) ($recommendation['locale'] ?? ''),
                'page_type' => 'personality_public_content_asset',
                'recommendation_id' => (string) ($recommendation['recommendation_id'] ?? ''),
                'recommendation_sha256' => hash('sha256', (string) $recommendationJson),
                'qa_decision' => (string) ($qaRows[$targetUrl]['qa_status'] ?? $qaRows[$targetUrl]['decision'] ?? 'pass'),
                'approval_state' => 'approved',
                'approved_at' => now(),
                'rejected_at' => null,
                'blocked_reason' => null,
                'safety_holds_json' => json_encode([]),
                'recommendation_json' => (string) $recommendationJson,
                'qa_json' => json_encode($qaRows[$targetUrl] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
            '--operator-approved' => 'BIG-FIVE-CMS-DRAFT-WRITER-CONTRACT-01',
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
