<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelIssueSeverityGovernanceContractTest extends TestCase
{
    #[Test]
    public function issue_governance_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/issue-severity-dedupe-mute-sla-contract.md'));
        $this->assertSame('issue-severity-dedupe-mute-sla-contract.v1', $this->artifact()['version'] ?? null);
        $this->assertSame('SEO-OBS-GOV-03', $this->artifact()['task'] ?? null);
    }

    #[Test]
    public function severity_levels_and_legacy_mapping_are_locked(): void
    {
        $artifact = $this->artifact();
        $levels = collect($artifact['severity_levels'] ?? [])->pluck('label', 'code')->all();

        $this->assertSame('critical', $levels['P0'] ?? null);
        $this->assertSame('high', $levels['P1'] ?? null);
        $this->assertSame('medium', $levels['P2'] ?? null);
        $this->assertSame('info', $levels['P3'] ?? null);

        $this->assertSame('P0', $artifact['backward_compatible_mapping']['critical'] ?? null);
        $this->assertSame('P1', $artifact['backward_compatible_mapping']['high'] ?? null);
        $this->assertSame('P2', $artifact['backward_compatible_mapping']['warning'] ?? null);
        $this->assertSame('P3', $artifact['backward_compatible_mapping']['info'] ?? null);
    }

    #[Test]
    public function required_future_fields_cover_dedupe_mute_owner_sla_reopen_and_occurrences(): void
    {
        $fields = $this->artifact()['required_future_fields'] ?? [];

        foreach ([
            'dedupe_key',
            'muted_until',
            'mute_reason',
            'muted_by',
            'owner_team',
            'sla_due_at',
            'sla_policy',
            'reopen_rule',
            'reopened_at',
            'last_seen_at',
            'occurrence_count',
            'closed_reason',
        ] as $field) {
            $this->assertContains($field, $fields);
        }
    }

    #[Test]
    public function p0_and_mute_rules_are_strict(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'claim_unsafe_public_indexable_page',
            'private_flow_leak_public_search_surface',
            'search_channel_submitted_non_canonical_private_url',
        ] as $class) {
            $this->assertContains($class, $artifact['p0_issue_classes'] ?? []);
        }

        $this->assertTrue((bool) ($artifact['mute_rule']['must_not_delete_history'] ?? false));
        $this->assertTrue((bool) ($artifact['mute_rule']['muted_issues_remain_auditable'] ?? false));
        $this->assertTrue((bool) ($artifact['mute_rule']['p0_must_never_be_silently_muted'] ?? false));
    }

    #[Test]
    public function dedupe_sla_owner_and_reopen_rules_are_safe(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('dedupe_key', $artifact['dedupe_rule']['field'] ?? null);
        $this->assertTrue((bool) ($artifact['dedupe_rule']['deterministic'] ?? false));
        $this->assertFalse((bool) ($artifact['sla_rule']['triggers_auto_fix'] ?? true));
        $this->assertFalse((bool) ($artifact['sla_rule']['triggers_auto_publish'] ?? true));
        $this->assertFalse((bool) ($artifact['sla_rule']['triggers_auto_submit'] ?? true));
        $this->assertTrue((bool) ($artifact['reopen_rule']['must_be_explicit'] ?? false));

        foreach (['seo_ops', 'cms_ops', 'engineering', 'content_review', 'digital_pr', 'unknown'] as $owner) {
            $this->assertContains($owner, $artifact['owner_team_values'] ?? []);
        }

        foreach (['raw_payload', 'raw_crawler_log_fields', 'email', 'token', 'cookie', 'order_id', 'payment_id', 'attempt_id'] as $input) {
            $this->assertContains($input, $artifact['dedupe_rule']['forbidden_inputs'] ?? []);
        }
    }

    #[Test]
    public function severity_contract_cannot_trigger_mutating_behaviors(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'auto_fix',
            'cms_publish',
            'cms_unpublish',
            'search_channel_submission',
            'search_channel_retry',
            'scheduler_activation',
            'collector_writes',
            'production_migration',
            'raw_crawler_log_read',
            'business_db_access',
            'metabase_exposure',
        ] as $behavior) {
            $this->assertContains($behavior, $artifact['forbidden_behaviors'] ?? []);
        }

        foreach ([
            'migration_added',
            'issue_mutation_code_added',
            'auto_fix_code_added',
            'cms_publish_code_added',
            'search_channel_mutation_added',
            'scheduler_enabled',
            'production_env_changed',
            'fap_web_modified',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact['safety_flags'][$flag] ?? true), $flag.' must be false');
        }
    }

    #[Test]
    public function docs_lock_contract_only_boundary_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/issue-severity-dedupe-mute-sla-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/issue-severity-dedupe-mute-sla-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'does not add a real migration',
            'mutate issues',
            'auto-fix issues',
            'auto-publish content',
            'dedupe_key must be deterministic',
            'mute must not delete issue history',
            'muted issues must remain auditable',
            'p0 must never be silently muted',
            'claim-unsafe public/indexable page',
            'private-flow leak into public/search surface',
            'search channel submitted non-canonical/private url',
            'issue severity must not trigger',
            'next task: `seo-obs-gov-04`',
            '"next_task": "seo-obs-gov-04"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/issue-severity-dedupe-mute-sla-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
