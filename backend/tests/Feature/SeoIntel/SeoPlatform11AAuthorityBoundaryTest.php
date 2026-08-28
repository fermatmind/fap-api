<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Console\Commands\RetiredSeoAgentCommand;
use App\Services\Career\PageAssemblyAssets\CareerPageAssemblyImportService;
use App\Services\Career\PageAssemblyAssets\CareerPageAssemblyPreviewService;
use App\Services\Cms\ArticlePublishService;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueBoundedLiveExecutor;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueLiveSubmissionExecutor;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
use Tests\TestCase;

final class SeoPlatform11AAuthorityBoundaryTest extends TestCase
{
    public function test_career_has_one_candidate_only_profile_and_merger_is_release_authority_only(): void
    {
        $profiles = glob(base_path('../.agents/skills/*/agents/openai.yaml')) ?: [];
        $careerProfiles = array_filter($profiles, static function (string $profile): bool {
            return str_contains((string) file_get_contents($profile), 'FermatMind Career Content Agent');
        });
        $this->assertCount(1, $careerProfiles);

        $registry = app(SeoRoleCapabilityRegistry::class)->registry();
        $careerRoles = array_values(array_filter($registry['roles'], static fn (array $role): bool => $role['role_id'] === 'career.content_agent'));
        $this->assertCount(1, $careerRoles);
        $this->assertSame('bounded_capability', $careerRoles[0]['classification']);
        $this->assertSame('candidate_only', $careerRoles[0]['authority_ceiling']);

        $runner = (string) file_get_contents(base_path('../.agents/skills/fap-api-career-content-orchestrator/scripts/run_career_content_agent.py'));
        $this->assertStringNotContainsString('merge-current', $runner);
        $this->assertStringNotContainsString('CURRENT_MERGER', $runner);
        $merger = (string) file_get_contents(base_path('../.agents/skills/fap-api-career-release-authority/scripts/merge_career_content_candidates.php'));
        $this->assertStringContainsString('public const AGENT_INVOCABLE = false;', $merger);
    }

    public function test_write_and_release_capabilities_are_not_agent_invocable(): void
    {
        $this->assertFalse(RetiredSeoAgentCommand::AGENT_INVOCABLE);
        $this->assertFalse(CareerPageAssemblyPreviewService::AGENT_INVOCABLE);
        $this->assertFalse(CareerPageAssemblyImportService::AGENT_INVOCABLE);
        $this->assertFalse(SearchChannelQueueBoundedLiveExecutor::AGENT_INVOCABLE);
        $this->assertFalse(SearchChannelQueueLiveSubmissionExecutor::AGENT_INVOCABLE);
        $this->assertFalse(UrlTruthInventoryRecordWriter::AGENT_INVOCABLE);
        $this->assertFalse(ArticlePublishService::AGENT_INVOCABLE);

        $registry = app(SeoRoleCapabilityRegistry::class)->registry();
        $capabilities = collect($registry['capabilities'])->keyBy('capability_id');
        $this->assertSame('deterministic_tool', $capabilities['career.page_assembly_preview']['classification']);
        $this->assertSame('deterministic_tool', $capabilities['career.page_assembly_import']['classification']);
        $this->assertFalse($capabilities['career.current_merger']['agent_invocable']);
        $this->assertFalse($capabilities['seo.cms_writer']['agent_invocable']);
        $this->assertFalse($capabilities['seo.search_submission']['agent_invocable']);
        $this->assertFalse($capabilities['seo.url_truth_writer']['agent_invocable']);
    }
}
