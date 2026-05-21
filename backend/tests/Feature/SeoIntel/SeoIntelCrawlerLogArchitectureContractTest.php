<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogArchitectureContractTest extends TestCase
{
    #[Test]
    public function contract_file_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/crawler-log-architecture-contract.md'));
        $this->assertSame('crawler-log-architecture-contract.v1', $this->artifact()['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-00', $this->artifact()['task'] ?? null);
        $this->assertSame('aggregate_search_bot_observability_only', $this->artifact()['purpose'] ?? null);
    }

    #[Test]
    public function forbidden_persistent_fields_include_raw_identifiers_and_payloads(): void
    {
        $fields = $this->artifact()['forbidden_persistent_fields'] ?? [];

        foreach ([
            'ip_address',
            'remote_addr',
            'raw_user_agent',
            'raw_request_uri',
            'raw_query_string',
            'cookie',
            'headers',
            'authorization',
            'session_id',
            'token',
            'api_key',
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'raw_payload',
            'raw_log_line',
        ] as $field) {
            $this->assertContains($field, $fields);
        }
    }

    #[Test]
    public function safety_flags_block_raw_persistence_production_reads_and_submission(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'no_raw_persistence',
            'no_production_log_read',
            'no_scheduler',
            'no_url_truth_creation',
            'no_url_truth_authority',
            'no_search_channel_queue_creation',
            'no_issue_queue_auto_write',
            'no_search_submission',
            'no_external_search_api_call',
            'no_metabase_exposure',
            'production_canary_requires_human_approval',
        ] as $flag) {
            $this->assertTrue((bool) ($artifact[$flag] ?? false), $flag.' must be true');
        }
    }

    #[Test]
    public function bot_family_allowlist_and_ua_claim_only_verification_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('ua_claim_only', $artifact['bot_verification_state'] ?? null);
        $this->assertFalse((bool) ($artifact['dns_reverse_verification_in_v1'] ?? true));

        foreach ([
            'googlebot',
            'bingbot',
            'baiduspider',
            'so360',
            'sogou',
            'shenma',
            'yandex',
            'duckduckbot',
            'applebot',
            'bytespider',
            'petalbot',
            'facebook_external_hit',
            'twitterbot',
            'linkedinbot',
            'unknown_bot',
            'non_bot',
            'unknown_user_agent',
        ] as $family) {
            $this->assertContains($family, $artifact['bot_family_allowlist'] ?? []);
        }
    }

    #[Test]
    public function private_path_denylist_and_route_families_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            '/take',
            '/result',
            '/results',
            '/order',
            '/orders',
            '/checkout',
            '/pay',
            '/payment',
            '/share',
            '/report-private',
            '/report_private',
            '/me',
            '/account',
            '/admin',
            '/ops',
            '/api',
        ] as $path) {
            $this->assertContains($path, $artifact['private_path_denylist'] ?? []);
        }

        foreach ([
            'private_flow',
            'unknown_public_path',
            'blocked_private_path',
            'api',
            'ops',
            'static_asset',
        ] as $family) {
            $this->assertContains($family, $artifact['route_family_values'] ?? []);
        }
    }

    #[Test]
    public function url_truth_boundary_forbids_authority_drift(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'url_truth',
            'search_channel_queue',
            'cms_authority',
            'canonical_truth',
            'indexability_truth',
        ] as $forbiddenAuthority) {
            $this->assertContains($forbiddenAuthority, $artifact['not_authority_for'] ?? []);
        }

        $this->assertTrue((bool) ($artifact['no_url_truth_creation'] ?? false));
        $this->assertTrue((bool) ($artifact['no_url_truth_authority'] ?? false));
        $this->assertTrue((bool) ($artifact['no_search_channel_queue_creation'] ?? false));
    }

    #[Test]
    public function production_canary_approval_phrase_and_next_task_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'I explicitly approve CRAWLER-LOG-04 production canary for source <log_path> with max_lines=1000 and no raw persistence.',
            $artifact['production_canary_approval_phrase'] ?? null,
        );
        $this->assertSame('CRAWLER-LOG-01', $artifact['next_task'] ?? null);
        $this->assertContains('CRAWLER-LOG-04-CANARY', $artifact['pr_split'] ?? []);
    }

    #[Test]
    public function docs_lock_contract_terms_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-architecture-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/crawler-log-architecture-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'crawler log is aggregate search bot observability',
            'not url truth',
            'not search channel queue',
            'not cms authority',
            'no production logs are allowed in crawler-log-00',
            'ua_claim_only',
            'strip query string completely',
            'private paths and unknown paths must not store raw path text',
            'i explicitly approve crawler-log-04 production canary',
            'next task: `crawler-log-01`',
            '"next_task": "crawler-log-01"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-architecture-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
