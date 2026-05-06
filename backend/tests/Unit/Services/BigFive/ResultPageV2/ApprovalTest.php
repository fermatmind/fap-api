<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class ApprovalTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/governance/release_approval_v0_1';

    private const REQUIRED_EVIDENCE_FIELDS = [
        'approver',
        'timestamp',
        'release_note',
        'accepted_risk',
        'rollback_note',
        'rendered_qa_evidence',
        'release_evidence_archive',
        'governance_decision_log',
    ];

    public function test_release_approval_evidence_package_exists_without_production_enablement(): void
    {
        $this->assertFileExists(base_path(self::BASE_PATH.'/README.md'));
        $this->assertFileExists(base_path(self::BASE_PATH.'/manifest.json'));
        $this->assertFileExists(base_path(self::BASE_PATH.'/big5_v2_release_approval_evidence_v0_1.json'));
        $this->assertFileExists(base_path(self::BASE_PATH.'/big5_v2_governance_decision_log_v0_1.json'));

        $manifest = $this->jsonFile('manifest.json');

        $this->assertSame('big5_v2_release_approval_evidence', $manifest['package'] ?? null);
        $this->assertSame('production_governance_evidence', $manifest['mode'] ?? null);
        $this->assertProductionDisabled($manifest);
    }

    public function test_approval_evidence_contains_required_fields(): void
    {
        $approval = $this->jsonFile('big5_v2_release_approval_evidence_v0_1.json');

        foreach (self::REQUIRED_EVIDENCE_FIELDS as $field) {
            $this->assertArrayHasKey($field, $approval, $field);
            $this->assertNotSame('', $approval[$field], $field);
            $this->assertNotSame([], $approval[$field], $field);
        }

        $this->assertSame('pending_human_production_approver', $approval['approver']['name'] ?? null);
        $this->assertSame('2026-05-06T00:00:00Z', $approval['timestamp'] ?? null);
        $this->assertSame('NO-GO', $approval['production_decision']['status'] ?? null);
        $this->assertTrue((bool) ($approval['production_decision']['human_approval_required'] ?? false));
    }

    public function test_approval_evidence_preserves_production_disabled_state(): void
    {
        foreach ([
            'manifest.json',
            'big5_v2_release_approval_evidence_v0_1.json',
            'big5_v2_governance_decision_log_v0_1.json',
        ] as $fileName) {
            $document = $this->jsonFile($fileName);

            $this->assertProductionDisabled($document);
            $this->assertFalse((bool) ($document['approved_for_production'] ?? false), $fileName);
            $this->assertStringNotContainsString(
                '"production_use_allowed":true',
                $this->normalizedJson($fileName),
                $fileName,
            );
            $this->assertStringNotContainsString(
                '"ready_for_production":true',
                $this->normalizedJson($fileName),
                $fileName,
            );
        }
    }

    public function test_decision_log_records_no_go_review_only_decision(): void
    {
        $decisionLog = $this->jsonFile('big5_v2_governance_decision_log_v0_1.json');
        $decisions = (array) ($decisionLog['decisions'] ?? []);

        $this->assertCount(1, $decisions);
        $this->assertSame('NO-GO', $decisions[0]['decision'] ?? null);
        $this->assertSame('pending_human_production_approver', $decisions[0]['approver'] ?? null);
        $this->assertTrue((bool) ($decisionLog['invariants']['production_remains_disabled'] ?? false));
        $this->assertFalse((bool) ($decisionLog['invariants']['runtime_behavior_changed'] ?? true));
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertProductionDisabled(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
    }

    private function normalizedJson(string $fileName): string
    {
        $json = file_get_contents(base_path(self::BASE_PATH.'/'.$fileName));
        $this->assertIsString($json, $fileName);
        $normalized = preg_replace('/\s+/', '', $json);
        $this->assertIsString($normalized, $fileName);

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path(self::BASE_PATH.'/'.$fileName)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
