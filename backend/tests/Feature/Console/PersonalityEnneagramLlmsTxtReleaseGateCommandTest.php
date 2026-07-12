<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\EnneagramCmsPublishGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalityEnneagramLlmsTxtReleaseGateCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DEPLOYED_SHA = 'c7b0bf55a464c2b416d7aa26c3284b4cd7f337b9';

    protected function tearDown(): void
    {
        config(['personality.enneagram_llms_txt_write_enabled' => false]);
        parent::tearDown();
    }

    public function test_exact_116_dry_run_is_read_only_and_returns_stable_cohort_hash(): void
    {
        $this->seedCohort();

        $payload = $this->gate()->llmsTxtRelease(self::DEPLOYED_SHA);

        $this->assertSame('dry_run_ready', $payload['status']);
        $this->assertSame(116, $payload['target_count']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['cohort_sha256']);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
        $this->assertSame([], $payload['issues']);
        $this->assertFalse($payload['negative_guarantees']['llms_full_release']);
        $this->assertFalse($payload['negative_guarantees']['search_queue_write_or_submit']);
    }

    public function test_write_requires_process_gate_exact_hash_and_token_then_changes_only_llms_flag(): void
    {
        $this->seedCohort();
        $cohortSha = $this->dryRun()['cohort_sha256'];
        $before = PersonalityPublicContentAsset::query()->orderBy('id')->get()->map(fn ($asset) => $asset->getAttributes())->all();

        $blocked = $this->write($cohortSha);
        $this->assertContains('process_write_gate_disabled', $blocked['issues']);

        config(['personality.enneagram_llms_txt_write_enabled' => true]);
        $payload = $this->write($cohortSha);

        $this->assertSame('released', $payload['status']);
        $this->assertSame(116, $payload['updated_count']);
        $this->assertSame(116, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
        $after = PersonalityPublicContentAsset::query()->orderBy('id')->get()->map(fn ($asset) => $asset->getAttributes())->all();
        foreach ($before as $index => $attributes) {
            $this->assertSame('0', (string) $attributes['llms_eligible']);
            unset($attributes['llms_eligible']);
            $afterAttributes = $after[$index];
            $this->assertSame('1', (string) $afterAttributes['llms_eligible']);
            unset($afterAttributes['llms_eligible']);
            $this->assertSame($attributes, $afterAttributes);
        }

        $this->assertSame('already_released', $this->write($cohortSha)['status']);
    }

    public function test_gate_fails_closed_for_count_partial_private_source_and_visible_evidence_defects(): void
    {
        $this->seedCohort();
        PersonalityPublicContentAsset::query()->firstOrFail()->delete();
        $this->assertBlockedWith('asset_count_mismatch');

        PersonalityPublicContentAsset::query()->delete();
        $this->seedCohort();
        PersonalityPublicContentAsset::query()->firstOrFail()->update(['llms_eligible' => true]);
        $this->assertBlockedWith('partial_llms_release_state');

        PersonalityPublicContentAsset::query()->update(['llms_eligible' => false]);
        $asset = PersonalityPublicContentAsset::query()->firstOrFail();
        $asset->canonical_json = ['path' => '/en/results/private'];
        $asset->source_hash = 'bad';
        $asset->content_sections_json = [];
        $asset->evidence_notes_json = [];
        $asset->save();
        $issues = implode('|', $this->gate()->llmsTxtRelease(self::DEPLOYED_SHA)['issues']);
        $this->assertStringContainsString('canonical_invalid_or_private', $issues);
        $this->assertStringContainsString('source_provenance_invalid', $issues);
        $this->assertStringContainsString('visible_content_incomplete', $issues);
        $this->assertStringContainsString('evidence_or_claim_boundary_incomplete', $issues);
    }

    public function test_workflow_is_manual_sha_bound_dry_run_before_write_and_does_not_release_llms_full(): void
    {
        $workflow = file_get_contents(base_path('../.github/workflows/enneagram-llms-txt-production-ops.yml'));
        $this->assertIsString($workflow);
        $this->assertStringContainsString('workflow_dispatch:', $workflow);
        $this->assertStringContainsString('release_sha:', $workflow);
        $this->assertStringContainsString('cohort_sha256:', $workflow);
        $this->assertStringContainsString('operation_mode:', $workflow);
        $this->assertStringContainsString('dry_run', $workflow);
        $this->assertStringContainsString('write', $workflow);
        $this->assertStringContainsString('llmsTxtRelease', $workflow);
        $this->assertStringContainsString('personality.enneagram_llms_txt_write_enabled', $workflow);
        $this->assertStringContainsString('llms_full_release', $workflow);
        $this->assertStringNotContainsString('llms-full.txt', $workflow);
        $this->assertStringNotContainsString('IndexNow', $workflow);
    }

    /** @return array<string, mixed> */
    private function dryRun(): array
    {
        return $this->gate()->llmsTxtRelease(self::DEPLOYED_SHA);
    }

    /** @return array<string,mixed> */
    private function write(string $cohortSha): array
    {
        return $this->gate()->llmsTxtRelease(
            self::DEPLOYED_SHA,
            true,
            $cohortSha,
            'ENNEAGRAM-LLMS-TXT-RELEASE-01:'.self::DEPLOYED_SHA.':'.$cohortSha,
        );
    }

    private function assertBlockedWith(string $issue): void
    {
        $this->assertContains($issue, $this->gate()->llmsTxtRelease(self::DEPLOYED_SHA)['issues']);
    }

    private function gate(): EnneagramCmsPublishGateService
    {
        return app(EnneagramCmsPublishGateService::class);
    }

    private function seedCohort(): void
    {
        foreach ($this->identities() as [$entityType, $entityKey, $suffix]) {
            foreach (['en', 'zh-CN'] as $locale) {
                $prefix = $locale === 'en' ? '/en' : '/zh';
                PersonalityPublicContentAsset::query()->create([
                    'org_id' => 0,
                    'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                    'entity_type' => $entityType,
                    'entity_key' => $entityKey,
                    'slug' => ltrim($suffix, '/'),
                    'locale' => $locale,
                    'title' => $entityKey.' title',
                    'summary' => 'A reflective summary with careful boundaries and practical context.',
                    'content_sections_json' => [[
                        'heading' => 'Pattern in context',
                        'body' => str_repeat('Observable pattern, context, alternative explanation, and reflection prompt. ', 3),
                    ]],
                    'seo_json' => [],
                    'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
                    'canonical_json' => ['path' => $prefix.$suffix],
                    'hreflang_json' => [],
                    'faq_json' => [['question' => 'How should this be used?', 'answer' => 'As a reflective hypothesis, not a diagnosis.']],
                    'media_json' => [],
                    'schema_json' => [],
                    'method_boundary_json' => ['summary' => 'This is not a diagnosis, fixed identity, hiring screen, or outcome prediction.'],
                    'evidence_notes_json' => [['source_id' => 'enneagram-source-01', 'limitation' => 'Interpret cautiously.']],
                    'internal_links_json' => [['label' => 'Enneagram hub', 'href' => $prefix.'/personality/enneagram']],
                    'is_public' => true,
                    'index_eligible' => true,
                    'sitemap_eligible' => true,
                    'llms_eligible' => false,
                    'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
                    'review_state' => 'published_no_llms',
                    'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                    'source_package' => 'enneagram-116-cms-v1',
                    'source_hash' => hash('sha256', $locale.'|'.$entityType.'|'.$entityKey),
                    'published_at' => now(),
                ]);
            }
        }
    }

    /** @return list<array{string, string, string}> */
    private function identities(): array
    {
        $rows = [[PersonalityPublicContentAsset::ENTITY_HUB, 'enneagram', '/personality/enneagram']];
        foreach (['gut', 'heart', 'head'] as $center) {
            $rows[] = [PersonalityPublicContentAsset::ENTITY_CENTER, $center, '/personality/enneagram/centers/'.$center];
        }
        foreach (range(1, 9) as $type) {
            $rows[] = [PersonalityPublicContentAsset::ENTITY_CORE_TYPE, 'type-'.$type, '/personality/enneagram/type-'.$type];
        }
        foreach (['1w9', '1w2', '2w1', '2w3', '3w2', '3w4', '4w3', '4w5', '5w4', '5w6', '6w5', '6w7', '7w6', '7w8', '8w7', '8w9', '9w8', '9w1'] as $wing) {
            $rows[] = [PersonalityPublicContentAsset::ENTITY_WING, $wing, '/personality/enneagram/wings/'.$wing];
        }
        foreach (range(1, 9) as $type) {
            foreach (['self-preservation', 'social', 'one-to-one'] as $instinct) {
                $rows[] = [PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE, 'type-'.$type.'/'.$instinct, '/personality/enneagram/type-'.$type.'/instincts/'.$instinct];
            }
        }

        return $rows;
    }
}
