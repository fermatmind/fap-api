<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailySecurityDriftEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use Tests\TestCase;

final class SeoPlatform12B03DailySecurityDriftTest extends TestCase
{
    public function test_private_negative_set_requires_one_hundred_percent_rejection(): void
    {
        $ready = app(Platform12DailySecurityDriftEvaluator::class)->evaluate($this->readyEvidence());
        $leak = $this->readyEvidence();
        $leak['private_routes']['rejected_count'] = 9;
        $denied = app(Platform12DailySecurityDriftEvaluator::class)->evaluate($leak);

        $this->assertSame('READY', $ready['state']);
        $this->assertSame(1000000, $ready['private_routes']['rejection_rate_ppm']);
        $this->assertSame('DENY', $denied['state']);
        $this->assertContains('PRIVATE_NEGATIVE_SET_LEAK', $denied['reason_codes']);
    }

    public function test_missing_source_cannot_erase_a_proven_private_fault_or_become_a_fake_incident(): void
    {
        $evidence = $this->readyEvidence();
        unset($evidence['query_security']);
        $evaluator = app(Platform12DailySecurityDriftEvaluator::class);
        $this->assertSame('HOLD', $evaluator->evaluate($evidence)['state']);
        $evidence['private_routes']['rejected_count'] = 9;
        $result = $evaluator->evaluate($evidence);
        $this->assertSame('DENY', $result['state']);
        $this->assertContains('PRIVATE_NEGATIVE_SET_LEAK', $result['reason_codes']);
    }

    public function test_stale_evidence_and_any_authority_hash_drift_hold(): void
    {
        $evidence = $this->readyEvidence();
        $evidence['evidence_freshness'] = ['total_count' => 5, 'fresh_count' => 3, 'expired_count' => 2];
        $evidence['drift']['tool'] = 'DRIFT';
        $receipt = app(Platform12DailySecurityDriftEvaluator::class)->evaluate($evidence);

        $this->assertSame('HOLD', $receipt['state']);
        $this->assertContains('AUTHORITY_HASH_DRIFT_HOLD', $receipt['reason_codes']);
        $this->assertContains('STALE_EVIDENCE_HOLD', $receipt['reason_codes']);
        $this->assertSame(3, $receipt['evidence_freshness']['model_evidence_count']);
        $this->assertFalse($receipt['evidence_freshness']['stale_evidence_model_allowed']);
    }

    public function test_injection_unauthorized_tool_pii_and_posture_violations_deny(): void
    {
        foreach ([
            ['injection' => ['prompt_state' => 'DETECTED', 'tool_metadata_state' => 'PASS']],
            ['tools' => ['requested_count' => 2, 'authorized_count' => 1]],
            ['query_security' => ['hmac_state' => 'VALID', 'key_version_state' => 'CURRENT', 'pii_state' => 'PRESENT']],
            ['posture' => ['retention_state' => 'VIOLATION', 'egress_state' => 'COMPLIANT']],
        ] as $mutation) {
            $evidence = array_replace($this->readyEvidence(), $mutation);
            $receipt = app(Platform12DailySecurityDriftEvaluator::class)->evaluate($evidence);
            $this->assertSame('DENY', $receipt['state']);
        }
    }

    public function test_evaluator_is_sanitized_read_only_and_cannot_auto_repair_authority(): void
    {
        $evidence = $this->readyEvidence();
        $evidence['injection']['raw_prompt'] = 'hidden system prompt';
        $receipt = app(Platform12DailySecurityDriftEvaluator::class)->evaluate($evidence);
        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);

        $this->assertSame('READY', $receipt['state']);
        $this->assertFalse($receipt['automatic_repair_allowed']);
        $this->assertFalse($receipt['authority_mutation_allowed']);
        $this->assertFalse($receipt['permission_mutation_allowed']);
        $this->assertTrue($receipt['read_only']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertStringNotContainsString('hidden system prompt', $encoded);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash'), $receipt['receipt_hash']);
    }

    public function test_catalog_declares_zero_budget_daily_security_evaluator_without_registration(): void
    {
        $contracts = app(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $mission = collect($catalog['missions'])->firstWhere('mission_id', 'seo.platform12.daily_private_policy_evidence_drift');

        $this->assertIsArray($mission);
        $this->assertSame('daily:ALL:06:30', $mission['natural_slot']);
        $this->assertSame(2, $mission['max_attempts']);
        $this->assertSame('none', $mission['failure_policy']['retry_strategy']);
        $this->assertSame(0, array_sum($mission['budgets']));
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertSame($catalog, app(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());
        $this->assertStringNotContainsString(
            'seo.platform12.daily_private_policy_evidence_drift',
            (string) file_get_contents(base_path('routes/console.php')),
        );
    }

    /** @return array<string,mixed> */
    private function readyEvidence(): array
    {
        return [
            'evaluated_at' => '2026-09-04T02:20:00Z',
            'private_routes' => ['tested_count' => 10, 'rejected_count' => 10],
            'query_security' => ['hmac_state' => 'VALID', 'key_version_state' => 'CURRENT', 'pii_state' => 'ABSENT'],
            'drift' => ['role' => 'MATCH', 'binding' => 'MATCH', 'policy' => 'MATCH', 'tool' => 'MATCH', 'schema' => 'MATCH', 'prompt' => 'MATCH'],
            'evidence_freshness' => ['total_count' => 5, 'fresh_count' => 5, 'expired_count' => 0],
            'injection' => ['prompt_state' => 'PASS', 'tool_metadata_state' => 'PASS'],
            'tools' => ['requested_count' => 2, 'authorized_count' => 2],
            'posture' => ['retention_state' => 'COMPLIANT', 'egress_state' => 'COMPLIANT'],
        ];
    }
}
