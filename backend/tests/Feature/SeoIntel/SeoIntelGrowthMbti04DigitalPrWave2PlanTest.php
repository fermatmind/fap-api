<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti04DigitalPrWave2PlanTest extends TestCase
{
    #[Test]
    public function digital_pr_wave_two_plan_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-04-digital-pr-wave2-plan.md'));
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-04-digital-pr-wave2-plan.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-04', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-05', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function hrzone_hrec_and_target_categories_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertArrayHasKey('hrzone', $artifact['current_state'] ?? []);
        $this->assertArrayHasKey('hrec', $artifact['current_state'] ?? []);

        foreach (['hr_and_people_analytics_publications', 'workplace_psychology_and_career_education_publications', 'research_newsletter_editors'] as $category) {
            $this->assertContains($category, $artifact['candidate_target_categories'] ?? []);
        }
    }

    #[Test]
    public function required_tracking_fields_are_present(): void
    {
        foreach (['target_name', 'target_url', 'language', 'channel', 'sent_at', 'human_sender', 'response_status', 'follow_up_due_at', 'follow_up_sent_at', 'mention_url', 'mention_type', 'backlink_url', 'referral_source', 'referral_sessions', 'brand_lift_proxy', 'notes'] as $field) {
            $this->assertContains($field, $this->artifact()['tracking_fields'] ?? []);
        }
    }

    #[Test]
    public function outreach_rules_prevent_bulk_paid_or_scraped_outreach(): void
    {
        foreach (['human_only_sends', 'no_bulk_outreach', 'no_private_email_scraping', 'no_paid_backlinks', 'no_transactional_backlink_request', 'one_follow_up_max_after_5_to_7_days', 'pitch_must_preserve_mbti_salary_turnover_claim_boundary'] as $rule) {
            $this->assertContains($rule, $this->artifact()['outreach_rules'] ?? []);
        }
    }

    #[Test]
    public function observation_rules_do_not_create_truth(): void
    {
        foreach (['digital_pr_mention_is_observation_only', 'digital_pr_does_not_create_url_truth', 'referral_does_not_prove_backlink', 'unlinked_brand_mention_can_be_brand_lift_proxy_only'] as $rule) {
            $this->assertContains($rule, $this->artifact()['observation_rules'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_prevent_sends_scraping_link_buying_and_mutations(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_sends_no_scraping_no_backlink_requests_and_next_task(): void
    {
        $combined = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-04-digital-pr-wave2-plan.md')).'
'.(string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-04-digital-pr-wave2-plan.v1.json')));

        foreach (['does not send emails', 'does not send dms', 'does not scrape contact information', 'does not buy links', 'does not request transactional backlinks', 'seo-growth-mbti-05'] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-04-digital-pr-wave2-plan.v1.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
