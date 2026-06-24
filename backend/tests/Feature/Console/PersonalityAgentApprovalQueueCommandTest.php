<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityAgentApprovalQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_pending_approval_items_without_writes(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', [
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
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(2, $payload['planned_item_count']);
        $this->assertSame(0, $payload['blocked_item_count']);
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_write_creates_pending_human_approval_queue_items_only(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertSame(2, $payload['planned_item_count']);
        $this->assertSame(2, $payload['created_item_count']);
        $this->assertSame(1, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(2, DB::table('personality_agent_approval_items')->count());

        $this->assertSame('pending_review', (string) DB::table('personality_agent_approval_batches')->value('status'));
        $this->assertSame(
            ['pending'],
            DB::table('personality_agent_approval_items')->distinct()->pluck('approval_state')->all()
        );
        $this->assertSame(0, DB::table('personality_agent_approval_items')->whereNotNull('approved_at')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->whereNotNull('rejected_at')->count());
    }

    public function test_second_write_is_idempotent_for_same_package_and_qa_hashes(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);

        $firstExit = Artisan::call('personality:agent-approval-queue', $options);
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:agent-approval-queue', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_item_count']);
        $this->assertSame(2, $payload['skipped_existing_item_count']);
        $this->assertSame(1, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(2, DB::table('personality_agent_approval_items')->count());
    }

    public function test_write_fails_closed_without_operator_approval_token(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--operator-approved'] = 'WRONG';

        $exitCode = Artisan::call('personality:agent-approval-queue', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString(
            '--operator-approved=PERSONALITY-AGENT-HUMAN-APPROVAL-QUEUE-01 is required',
            (string) ($payload['errors'][0]['message'] ?? '')
        );
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_failed_qa_and_private_routes_do_not_enter_approval_queue(): void
    {
        $package = $this->validPackage();
        $package['recommendations'][] = $this->recommendation(
            'mbti64-private',
            'https://fermatmind.com/en/results/lookup',
            'en'
        );
        [$packagePath, $qaPath] = $this->writeArtifacts($package, [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-QA-01',
            'final_decision' => 'CONDITIONAL_REQUIRES_EDITORIAL_REPAIR',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/en/personality/enfj-a', 'PASS_READY_FOR_CMS_DRAFT'),
                $this->qaRow('https://fermatmind.com/zh/personality/intp-a', 'NO_GO_BLOCKED_BY_QA', ['claim risk']),
                $this->qaRow('https://fermatmind.com/en/results/lookup', 'PASS_READY_FOR_CMS_DRAFT'),
            ],
        ]);

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(1, $payload['planned_item_count']);
        $this->assertSame(2, $payload['blocked_item_count']);
        $this->assertSame(1, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(1, DB::table('personality_agent_approval_items')->count());
        $this->assertSame(
            'https://fermatmind.com/en/personality/enfj-a',
            (string) DB::table('personality_agent_approval_items')->value('target_url')
        );
    }

    public function test_enneagram_dry_run_accepts_hub_centers_and_core_type_public_asset_paths(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validEnneagramPackage(), $this->validEnneagramQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('enneagram', $payload['framework']);
        $this->assertSame(3, $payload['planned_item_count']);
        $this->assertSame(0, $payload['blocked_item_count']);
        $this->assertSame(0, $payload['created_item_count']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertEquals(
            ['personality_public_content_asset'],
            array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['page_type'],
                $payload['items']
            )))
        );
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    public function test_enneagram_write_creates_pending_items_without_cms_or_search_side_effects(): void
    {
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validEnneagramPackage(), $this->validEnneagramQa());

        $exitCode = Artisan::call('personality:agent-approval-queue', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('enneagram', $payload['framework']);
        $this->assertSame(3, $payload['planned_item_count']);
        $this->assertSame(3, $payload['created_item_count']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);

        $this->assertSame(1, DB::table('personality_agent_approval_batches')->where('framework', 'enneagram')->count());
        $this->assertSame(3, DB::table('personality_agent_approval_items')->where('framework', 'enneagram')->count());
        $this->assertSame(
            ['pending'],
            DB::table('personality_agent_approval_items')->distinct()->pluck('approval_state')->all()
        );
        $this->assertSame(
            ['personality_public_content_asset'],
            DB::table('personality_agent_approval_items')->distinct()->pluck('page_type')->all()
        );
    }

    public function test_enneagram_wing_instinct_and_tritype_urls_fail_closed(): void
    {
        $package = [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-PILOT-01',
            'version' => 'enneagram.agent_pilot.v1',
            'status' => 'pass_ready_for_qa_gates',
            'recommendations' => [
                $this->enneagramRecommendation(
                    'enneagram-agent:/en/personality/enneagram/type-1-wing-9',
                    'https://fermatmind.com/en/personality/enneagram/type-1-wing-9',
                    'en',
                    'wing'
                ),
                $this->enneagramRecommendation(
                    'enneagram-agent:/en/personality/enneagram/instinctual-subtypes',
                    'https://fermatmind.com/en/personality/enneagram/instinctual-subtypes',
                    'en',
                    'instinctual_subtype'
                ),
                $this->enneagramRecommendation(
                    'enneagram-agent:/zh/personality/enneagram/tritype',
                    'https://fermatmind.com/zh/personality/enneagram/tritype',
                    'zh-CN',
                    'tritype'
                ),
            ],
        ];
        [$packagePath, $qaPath] = $this->writeArtifacts($package, [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-QA-01',
            'final_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/en/personality/enneagram/type-1-wing-9', 'PASS_READY_FOR_APPROVAL_QUEUE'),
                $this->qaRow('https://fermatmind.com/en/personality/enneagram/instinctual-subtypes', 'PASS_READY_FOR_APPROVAL_QUEUE'),
                $this->qaRow('https://fermatmind.com/zh/personality/enneagram/tritype', 'PASS_READY_FOR_APPROVAL_QUEUE'),
            ],
        ]);

        $exitCode = Artisan::call('personality:agent-approval-queue', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(0, $payload['planned_item_count']);
        $this->assertSame(3, $payload['blocked_item_count']);
        $this->assertContains('no_queueable_qa_passed_items', array_column($payload['errors'], 'code'));
        $this->assertSame(0, DB::table('personality_agent_approval_batches')->count());
        $this->assertSame(0, DB::table('personality_agent_approval_items')->count());
    }

    /**
     * @return array<string,mixed>
     */
    private function validPackage(): array
    {
        return [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-01',
            'version' => 'mbti64.agent_expansion_88_recommendations.v1',
            'status' => 'pass_ready_for_qa_gates',
            'recommendations' => [
                $this->recommendation(
                    'mbti64-agent-expansion-88:/en/personality/enfj-a',
                    'https://fermatmind.com/en/personality/enfj-a',
                    'en'
                ),
                $this->recommendation(
                    'mbti64-agent-expansion-88:/zh/personality/intp-a',
                    'https://fermatmind.com/zh/personality/intp-a',
                    'zh-CN'
                ),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validEnneagramPackage(): array
    {
        return [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-PILOT-01',
            'version' => 'enneagram.agent_pilot.v1',
            'status' => 'pass_ready_for_qa_gates',
            'recommendations' => [
                $this->enneagramRecommendation(
                    'enneagram-agent:/en/personality/enneagram',
                    'https://fermatmind.com/en/personality/enneagram',
                    'en',
                    'hub'
                ),
                $this->enneagramRecommendation(
                    'enneagram-agent:/zh/personality/enneagram/centers/gut',
                    'https://fermatmind.com/zh/personality/enneagram/centers/gut',
                    'zh-CN',
                    'center'
                ),
                $this->enneagramRecommendation(
                    'enneagram-agent:/en/personality/enneagram/type-1',
                    'https://fermatmind.com/en/personality/enneagram/type-1',
                    'en',
                    'core_type'
                ),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validEnneagramQa(): array
    {
        return [
            'artifact' => 'ENNEAGRAM-PUBLIC-PROFILE-AGENT-QA-01',
            'final_decision' => 'PASS_READY_FOR_APPROVAL_QUEUE',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/en/personality/enneagram', 'PASS_READY_FOR_APPROVAL_QUEUE'),
                $this->qaRow('https://fermatmind.com/zh/personality/enneagram/centers/gut', 'PASS_READY_FOR_APPROVAL_QUEUE'),
                $this->qaRow('https://fermatmind.com/en/personality/enneagram/type-1', 'PASS_READY_FOR_APPROVAL_QUEUE'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validQa(): array
    {
        return [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-QA-01',
            'final_decision' => 'PASS_READY_FOR_CMS_DRAFT',
            'page_results' => [
                $this->qaRow('https://fermatmind.com/en/personality/enfj-a', 'PASS_READY_FOR_CMS_DRAFT'),
                $this->qaRow('https://fermatmind.com/zh/personality/intp-a', 'PASS_READY_FOR_CMS_DRAFT'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function enneagramRecommendation(string $id, string $targetUrl, string $locale, string $entityType): array
    {
        return [
            'recommendation_id' => $id,
            'target_url' => $targetUrl,
            'framework' => 'enneagram',
            'locale' => $locale,
            'entity_type' => $entityType,
            'recommendations' => [
                'title' => 'Enneagram title',
                'description' => 'Enneagram description',
                'h1' => 'Enneagram H1',
                'quick_answer' => 'Reflective Enneagram quick answer.',
                'faq' => [],
                'internal_links' => [],
                'differentiation_notes' => [],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendation(string $id, string $targetUrl, string $locale): array
    {
        return [
            'recommendation_id' => $id,
            'target_url' => $targetUrl,
            'framework' => 'mbti64',
            'locale' => $locale,
            'current_surface' => [
                'title' => 'Current title',
                'description' => 'Current description',
                'h1' => 'Current H1',
            ],
            'recommendations' => [
                'title' => ['recommended' => 'Recommended title | FermatMind'],
                'description' => ['recommended' => 'Recommended description.'],
                'h1' => ['recommended' => 'Recommended H1'],
                'quick_answer' => ['recommended' => 'Recommended quick answer.'],
                'faq' => [],
                'internal_links' => [],
                'differentiation_notes' => [],
            ],
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function qaRow(string $targetUrl, string $decision, array $blockers = []): array
    {
        return [
            'target_url' => $targetUrl,
            'decision' => $decision,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array{0:string,1:string}
     */
    private function writeArtifacts(array $package, array $qa): array
    {
        $dir = storage_path('framework/testing/personality-agent-approval-queue');
        File::ensureDirectoryExists($dir);
        $packagePath = $dir.'/package.json';
        $qaPath = $dir.'/qa.json';
        File::put($packagePath, (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($qaPath, (string) json_encode($qa, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
            '--operator-approved' => 'PERSONALITY-AGENT-HUMAN-APPROVAL-QUEUE-01',
            '--json' => true,
        ];
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
