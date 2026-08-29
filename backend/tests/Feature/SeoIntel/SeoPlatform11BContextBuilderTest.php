<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Context\SeoEvidenceContextBuilder;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use Tests\Feature\SeoIntel\Concerns\BuildsSeoEvidenceBundle;
use Tests\TestCase;

final class SeoPlatform11BContextBuilderTest extends TestCase
{
    use BuildsSeoEvidenceBundle;

    public function test_context_is_role_minimized_read_only_and_fail_closed(): void
    {
        config()->set('seo_agent_evidence.context_build_enabled', true);
        $builder = app(SeoEvidenceContextBuilder::class);
        $ready = $builder->build('mission:test', 'bounded_review', 'seo.expert.search_analytics_measurement', 'tests', 'zh-CN', [$this->evidenceBundle()]);
        $this->assertSame('READY', $ready['status']);
        $this->assertFalse($ready['execution_allowed']);
        $this->assertFalse($ready['model_invocation']);
        $this->assertSame([], $ready['tool_allowlist']);
        $this->assertArrayNotHasKey('query_display_masked', $ready['payload']);

        $private = $this->evidenceBundle(['payload' => ['query_hmac' => str_repeat('b', 64), 'raw_query' => 'personality test']]);
        $this->assertSame('EVIDENCE_HOLD', $builder->build('mission:test', 'bounded_review', 'seo.expert.search_analytics_measurement', 'tests', 'zh-CN', [$private])['status']);
        $held = $this->evidenceBundle(['source_capability_state' => 'unavailable']);
        $this->assertSame('SOURCE_CAPABILITY_UNAVAILABLE', $builder->build('mission:test', 'bounded_review', 'seo.expert.search_analytics_measurement', 'tests', 'zh-CN', [$held])['status']);
        $stale = $this->evidenceBundle(['freshness_state' => 'stale']);
        $this->assertSame('MEASUREMENT_HOLD', $builder->build('mission:test', 'bounded_review', 'seo.expert.search_analytics_measurement', 'tests', 'zh-CN', [$stale])['status']);
        $otherRevision = $this->evidenceBundle(['bundle_id' => 'bundle:other', 'authority_revision' => 'revision:other', 'payload' => ['query_hmac' => str_repeat('c', 64), 'query_hmac_key_version' => 'k1']]);
        $this->assertSame('EVIDENCE_HOLD', $builder->build('mission:test', 'bounded_review', 'seo.expert.search_analytics_measurement', 'tests', 'zh-CN', [$this->evidenceBundle(), $otherRevision])['status']);
        $this->assertSame('SOURCE_CAPABILITY_UNAVAILABLE', $builder->build('mission:test', 'career_candidate_generation', 'career.content_agent', 'career', 'zh-CN', [])['status']);
        $unknown = $builder->build('mission:test', 'bounded_review', 'seo.expert.unknown', 'tests', 'zh-CN', [$this->evidenceBundle()]);
        $this->assertSame('EVIDENCE_HOLD', $unknown['status']);
        $this->assertSame([], $unknown['payload']);
    }

    public function test_every_frozen_role_rejects_invalid_mission_family_and_locale_with_empty_payload(): void
    {
        config()->set('seo_agent_evidence.context_build_enabled', true);
        $builder = app(SeoEvidenceContextBuilder::class);
        $registry = app(SeoRoleCapabilityRegistry::class)->registry();
        foreach ($registry['roles'] as $role) {
            $mission = $role['allowed_missions'][0];
            $family = $role['page_family_scope'][0];
            $locale = $role['locale_scope'][0];
            foreach ([
                ['invalid_mission', $family, $locale],
                [$mission, 'private_excluded', $locale],
                [$mission, $family, 'fr'],
            ] as [$invalidMission, $invalidFamily, $invalidLocale]) {
                $context = $builder->build('mission:test', $invalidMission, $role['role_id'], $invalidFamily, $invalidLocale, [$this->evidenceBundle()]);
                $this->assertSame('EVIDENCE_HOLD', $context['status'], $role['role_id']);
                $this->assertSame([], $context['payload'], $role['role_id']);
                $this->assertFalse($context['execution_allowed']);
            }
        }
    }
}
