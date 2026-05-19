<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelDashMvpOnlineFinalCloseoutTest extends TestCase
{
    #[Test]
    public function final_closeout_markdown_and_artifact_exist(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-dash-mvp-online-final-closeout.md'));
        $this->assertFileExists($this->artifactPath());

        $artifact = $this->artifact();

        $this->assertSame('seo_dash_mvp_online_with_ops_entry_verified', $artifact['final_decision'] ?? null);
    }

    #[Test]
    public function final_closeout_locks_verified_counts_and_metabase_boundary(): void
    {
        $artifact = $this->artifact();
        $counts = $artifact['current_counts'] ?? [];
        $metabase = $artifact['metabase'] ?? [];

        $this->assertSame(7, $counts['seo_urls'] ?? null);
        $this->assertSame(7, $counts['seo_url_entities'] ?? null);
        $this->assertSame(5, $counts['seo_issue_queue'] ?? null);
        $this->assertSame(10, $counts['dashboard_cards'] ?? null);

        $this->assertTrue((bool) ($metabase['private_only'] ?? false));
        $this->assertSame('seo_intel', $metabase['datasource'] ?? null);
        $this->assertSame('seo_intel_metabase_readonly', $metabase['readonly_account'] ?? null);
        $this->assertTrue((bool) ($metabase['write_deny_verification_passed'] ?? false));
        $this->assertTrue((bool) ($metabase['public_sharing_disabled'] ?? false));
        $this->assertTrue((bool) ($metabase['anonymous_links_absent'] ?? false));
        $this->assertFalse((bool) ($metabase['public_exposure'] ?? true));
    }

    #[Test]
    public function ops_portal_route_is_verified_without_metabase_exposure(): void
    {
        $opsPortal = $this->artifact()['ops_portal'] ?? [];

        $this->assertTrue((bool) ($opsPortal['ops_seo_route_verified'] ?? false));
        $this->assertSame('/ops/seo', $opsPortal['route'] ?? null);
        $this->assertTrue((bool) ($opsPortal['auth_required'] ?? false));
        $this->assertFalse((bool) ($opsPortal['metabase_exposed'] ?? true));
        $this->assertFalse((bool) ($opsPortal['iframe_present'] ?? true));
        $this->assertFalse((bool) ($opsPortal['reverse_proxy_present'] ?? true));
        $this->assertFalse((bool) ($opsPortal['raw_credentials_exposed'] ?? true));
    }

    #[Test]
    public function scheduler_live_search_crawler_research_publish_and_pseo_are_not_completed(): void
    {
        $notCompleted = $this->artifact()['not_completed'] ?? [];

        foreach ([
            'scheduler',
            'live_search',
            'crawler_log',
            'research_publish',
            'pseo',
        ] as $blocked) {
            $this->assertContains($blocked, $notCompleted);
        }
    }

    #[Test]
    public function hard_stops_preserve_security_source_and_claim_boundaries(): void
    {
        $hardStops = $this->artifact()['hard_stops'] ?? [];

        foreach ([
            'no public Metabase',
            'no business DB in Metabase',
            'no Node2 local DB',
            'no unrestricted SQL for operators',
            'no claim expansion for RIASEC / Big Five / Career',
        ] as $hardStop) {
            $this->assertContains($hardStop, $hardStops);
        }
    }

    #[Test]
    public function markdown_records_operating_status_and_next_task(): void
    {
        $markdown = strtolower((string) file_get_contents(base_path('docs/seo/seo-dash-mvp-online-final-closeout.md')));

        foreach ([
            'seo dash mvp is minimally online',
            '`/ops/seo`: production access verified behind ops auth',
            'completing phase 5 means the seo main operating loop is ready for full operational iteration',
            'phase 6 is growth expansion',
            'final decision: `seo_dash_mvp_online_with_ops_entry_verified`',
            'next task: `research-publish-01`',
        ] as $required) {
            $this->assertStringContainsString($required, $markdown);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $decoded = json_decode((string) file_get_contents($this->artifactPath()), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function artifactPath(): string
    {
        return base_path('docs/seo/generated/seo-dash-mvp-online-final-closeout.v1.json');
    }
}
