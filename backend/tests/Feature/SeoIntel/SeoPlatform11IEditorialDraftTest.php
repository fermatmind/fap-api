<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Platform11\EditorialDraftRunner;
use App\Services\SeoCouncil\Platform11\Platform11ContractRegistry;
use App\Services\SeoCouncil\Platform11\Platform11HCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11ICloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11MissionValidator;
use App\Services\SeoCouncil\Platform11\Post12L2DraftWriteAdapter;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform11IEditorialDraftTest extends TestCase
{
    public function test_editorial_contracts_are_frozen_artifact_only_and_l2_is_disabled(): void
    {
        $contracts = $this->app->make(Platform11ContractRegistry::class);
        $mode = $contracts->editorialMode();
        $l2 = $contracts->l2ManifestSchema();

        $this->assertSame([
            'seo.content_claim_entity_audit',
            'seo.editorial_cms_draft',
            'seo.internal_link_recommendation',
        ], $mode['capability_sequence']);
        $this->assertSame('seo.expert.content_entity_quality', $mode['role_id']);
        $this->assertTrue($mode['artifact_only']);
        $this->assertTrue($mode['dry_run_only']);
        $this->assertFalse($mode['cms_write']);
        $this->assertFalse($mode['publish']);
        $this->assertFalse($mode['allow_delegation']);
        $this->assertFalse($mode['execution_allowed']);
        $this->assertSame('IMPLEMENTED_WRITE_DISABLED', $l2['state']);
        $this->assertSame(0, $l2['active_manifest_count']);
        $this->assertSame(0, $l2['trusted_signing_key_count']);
        $this->assertContains('canonical', $l2['permanently_forbidden_fields']);
        $this->assertContains('search_submission', $l2['permanently_forbidden_fields']);
        $this->assertTrue($contracts->verifyGenerated());
    }

    public function test_draft_package_requires_page_necessity_claims_locale_value_and_public_links(): void
    {
        $runner = $this->app->make(EditorialDraftRunner::class);
        $base = $this->input();
        $cases = [
            [[...$base, 'page_necessity' => ''], 'PAGE_NECESSITY_MISSING'],
            [[...$base, 'source_claim_locale_map' => []], 'SOURCE_CLAIM_MISSING'],
            [[...$base, 'source_claim_locale_map' => [[...$base['source_claim_locale_map'][0], 'freshness_state' => 'stale']]], 'SOURCE_STALE'],
            [[...$base, 'translation_only' => true], 'LOCALE_VALUE_MISSING'],
            [[...$base, 'template_overlap_score' => 0.90], 'DUPLICATE_OR_TEMPLATE_OVERLAP'],
            [[...$base, 'scaled_content_score' => 0.90], 'SCALED_CONTENT_RISK'],
            [[...$base, 'internal_link_candidates' => [[...$base['internal_link_candidates'][0], 'visibility' => 'private']]], 'INTERNAL_LINK_AUTHORITY_DENIED'],
            [[...$base, 'internal_link_candidates' => [[...$base['internal_link_candidates'][0], 'redirect_only' => true]]], 'INTERNAL_LINK_AUTHORITY_DENIED'],
        ];
        foreach ($cases as [$input, $reason]) {
            $result = $runner->evaluate($input, $this->refs(), str_repeat('a', 64), str_repeat('b', 64));
            $this->assertSame('HOLD', $result['output']['status']);
            $this->assertSame($reason, $result['output']['hold_reason']);
            $this->assertFalse($result['output']['draft_emitted']);
            $this->assertNull($result['output']['draft_package']);
        }
    }

    public function test_valid_draft_is_hash_bound_and_never_writes(): void
    {
        $result = $this->app->make(EditorialDraftRunner::class)->evaluate(
            $this->input(),
            $this->refs(),
            str_repeat('a', 64),
            str_repeat('b', 64),
        );

        $this->assertSame('DRAFT_READY', $result['output']['status']);
        $this->assertTrue($result['output']['draft_emitted']);
        $this->assertNotEmpty($result['output']['draft_package']['page_necessity']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['output']['draft_package']['package_hash']);
        $this->assertSame('public_url_truth', $result['output']['draft_package']['internal_link_candidates'][0]['authority']);
        $this->assertSame(0, array_sum($result['receipt']['negative_metrics']));
        $this->assertSame(0, $result['receipt']['write_count']);
        $this->assertFalse($result['output']['cms_write']);
        $this->assertFalse($result['output']['publish']);
        $this->assertFalse($result['output']['execution_allowed']);
    }

    public function test_mission_rejects_prompt_permission_and_private_surface_expansion(): void
    {
        $validator = $this->app->make(Platform11MissionValidator::class);
        $valid = $this->request();
        $this->assertSame($valid, $validator->validate($valid));

        foreach (['cms_write', 'publish', 'canonical', 'robots', 'scoring', 'private_result', 'search_submission', 'prompt'] as $field) {
            try {
                $validator->validate([...$valid, 'mode_input' => [...$valid['mode_input'], $field => true]]);
                $this->fail($field.' expansion accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_all_three_capabilities_run_in_one_role_without_delegation(): void
    {
        $receipt = $this->app->make(ApiMissionAdapter::class)->submit($this->request());

        $this->assertSame('CANDIDATE_READY', $receipt['status']);
        $this->assertSame('seo.expert.content_entity_quality', $receipt['role_id']);
        $this->assertSame(1, $receipt['role_call_count']);
        $this->assertSame([
            'seo.content_claim_entity_audit',
            'seo.editorial_cms_draft',
            'seo.internal_link_recommendation',
        ], array_column($receipt['route_plan'], 'capability_id'));
        $this->assertSame([1, 2, 3], array_column($receipt['route_plan'], 'sequence'));
        $this->assertSame([false, false, false], array_column($receipt['route_plan'], 'allow_delegation'));
        $this->assertFalse($receipt['execution_allowed']);
    }

    public function test_l2_adapter_denies_every_manifest_and_closeout_is_ready_offline(): void
    {
        $adapter = $this->app->make(Post12L2DraftWriteAdapter::class);
        foreach ([
            [],
            ['signed' => true, 'expires_at' => 'expired', 'scope' => 'limited_cms_draft_fields'],
            ['signed' => true, 'expires_at' => 'future_verified_by_gateway', 'scope' => 'publish'],
            ['signed' => true, 'expires_at' => 'future_verified_by_gateway', 'scope' => 'limited_cms_draft_fields'],
        ] as $manifest) {
            $decision = $adapter->authorize($manifest);
            $this->assertSame('DENY', $decision['status']);
            $this->assertSame(0, $decision['write_count']);
            $this->assertFalse($decision['execution_allowed']);
        }

        $sha = str_repeat('c', 40);
        $h = $this->app->make(Platform11HCloseoutBuilder::class)->build($sha, 'ci_candidate');
        $receipt = $this->app->make(Platform11ICloseoutBuilder::class)->build($sha, 'ci_candidate', $h);
        $this->assertSame('OFFLINE_EVAL_READY', $receipt['closeout_state']);
        $this->assertSame($receipt['negative_probes']['total'], $receipt['negative_probes']['passed']);
        $this->assertSame(0, $receipt['negative_probes']['bypass_count']);
        $this->assertSame(0, $receipt['active_manifest_count']);
        $this->assertSame(0, $receipt['trusted_signing_key_count']);
        $this->assertFalse($receipt['ready_for_11J']);
        $this->assertFalse($receipt['execution_allowed']);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'schema_version' => 'seo.mission_request.v2', 'mission_id' => 'mission:11i:test', 'idempotency_key' => 'mission:11i:test',
            'mission_type' => 'bounded_review', 'family' => 'career', 'locale' => 'en', 'review_domain' => 'editorial_draft',
            'requested_role' => null, 'evidence_bundle_refs' => $this->refs(), 'autonomy' => 'L1',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'mode_input' => $this->input(),
        ];
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'owner_candidate_hash' => hash('sha256', 'owner'), 'locale' => 'en', 'title' => 'Evidence-backed career guide',
            'seo_title' => 'Evidence-backed career guide', 'meta_description' => 'A source-bound career guide.',
            'refresh_brief' => 'Refresh verified facts only.', 'direct_response' => 'Use the evidence to compare this path.',
            'faq_or_modules' => [['module_id' => 'overview', 'evidence_ref' => hash('sha256', 'module-evidence')]],
            'internal_link_candidates' => [['target_hash' => hash('sha256', 'public-target'), 'truth_status' => 'current_public', 'visibility' => 'published', 'indexability' => 'index', 'redirect_only' => false, 'locale' => 'en']],
            'source_claim_locale_map' => [['claim_id' => 'claim-1', 'source_ref' => hash('sha256', 'source'), 'evidence_ref' => hash('sha256', 'claim-evidence'), 'locale' => 'en', 'risk_level' => 'low', 'freshness_state' => 'fresh', 'statement_kind' => 'fact']],
            'schema_candidate' => ['type' => 'Article'], 'material_change' => true,
            'page_necessity' => 'This locale has a distinct public decision task and verified evidence.',
            'information_gain' => 'Adds a source-bound decision comparison absent from the existing owner.',
            'template_overlap_score' => 0.10, 'duplicate_similarity' => 0.10, 'translation_only' => false,
            'locale_specific_value' => 'Uses locale-specific employment terminology.', 'scaled_content_score' => 0.10,
            'authority_revision' => hash('sha256', 'authority'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function refs(): array
    {
        return array_map(static fn (string $type): array => [
            'bundle_id' => 'bundle:11i:'.$type, 'bundle_version' => 1, 'bundle_hash' => hash('sha256', $type),
            'evidence_type' => $type, 'status' => 'READY', 'authority_revision' => str_repeat('a', 64),
        ], ['intent_ownership', 'competitive_handoff', 'content_claim', 'entity', 'duplicate', 'lifecycle', 'url_truth']);
    }
}
