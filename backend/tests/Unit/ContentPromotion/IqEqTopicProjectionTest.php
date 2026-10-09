<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\IqEqTopicPackage;
use App\Services\ContentPromotion\IqEqTopicProjection;
use DomainException;
use Tests\TestCase;

final class IqEqTopicProjectionTest extends TestCase
{
    public function test_bilingual_candidate_keeps_unowned_content_and_qualification(): void
    {
        foreach (app(IqEqTopicPackage::class)->read(base_path(), IqEqTopicPackage::SHA256) as $row) {
            $before = $this->state($row);
            $after = app(IqEqTopicProjection::class)->candidate($row, $before);
            self::assertSame($row['snapshot']['profile_patch']['title'], $after['profile']['title']);
            self::assertSame('', $after['profile']['excerpt']);
            self::assertSame($before['profile']['working_revision_id'], $after['profile']['working_revision_id']);
            self::assertSame($before['profile']['published_revision_id'], $after['profile']['published_revision_id']);
            self::assertSame($before['profile']['is_indexable'], $after['profile']['is_indexable']);
            self::assertSame($before['sections'][1], $after['sections'][1]);
            self::assertNull($after['sections'][0]['body_html']);
            self::assertSame($row['snapshot']['section_candidates'][0]['body_md'], $after['sections'][0]['body_md']);
            self::assertCount(7, $after['sections'][2]['payload_json']['items']);
            self::assertSame($before['seo']['canonical_url'], $after['seo']['canonical_url']);
            self::assertSame($before['seo']['robots'], $after['seo']['robots']);
            self::assertSame($before['seo']['jsonld_overrides_json']['url'], $after['seo']['jsonld_overrides_json']['url']);
            self::assertSame($before['entries'][2], $after['entries'][2]);
            foreach (['seo', 'og', 'twitter'] as $transport) {
                self::assertSame($row['snapshot']['seo_text_candidate']['title'], $after['seo'][$transport.'_title']);
                self::assertSame($row['snapshot']['seo_text_candidate']['description'], $after['seo'][$transport.'_description']);
            }
        }
    }

    public function test_existing_faq_replaces_copy_without_changing_layout(): void
    {
        $row = $this->row();
        $before = $this->state($row);
        $before['sections'][] = ['id' => 8, 'profile_id' => 5, 'section_key' => 'faq', 'is_enabled' => true,
            'sort_order' => 28, 'title' => 'Questions', 'body_html' => 'old html', 'payload_json' => ['items' => []]];
        $after = app(IqEqTopicProjection::class)->candidate($row, $before);
        self::assertCount(3, $after['sections']);
        self::assertSame(8, $after['sections'][2]['id']);
        self::assertSame(28, $after['sections'][2]['sort_order']);
        self::assertSame('Questions', $after['sections'][2]['title']);
        self::assertCount(7, $after['sections'][2]['payload_json']['items']);
    }

    public function test_hold_or_ambiguous_entry_never_becomes_a_publish_plan(): void
    {
        $row = $this->row();
        $before = $this->state($row);
        foreach (['profile', 'entry', 'duplicate', 'url', 'overview'] as $case) {
            $state = $before;
            if ($case === 'profile') {
                $state['profile']['is_public'] = false;
            }
            if ($case === 'entry') {
                $state['entries'][0]['is_enabled'] = false;
            }
            if ($case === 'duplicate') {
                $state['entries'][] = $state['entries'][0];
            }
            if ($case === 'url') {
                $state['entries'][0]['target_url_override'] = '/en/tests/unrelated';
            }
            if ($case === 'overview') {
                $state['sections'][0]['is_enabled'] = false;
            }
            try {
                app(IqEqTopicProjection::class)->candidate($row, $state);
                self::fail('Must refuse '.$case);
            } catch (DomainException $error) {
                self::assertStringStartsWith('iq_eq_topic_', $error->getMessage());
            }
        }
    }

    private function row(): array
    {
        return app(IqEqTopicPackage::class)->read(base_path(), IqEqTopicPackage::SHA256)[0];
    }

    private function state(array $row): array
    {
        return [
            'profile' => [...$row['identity'], 'id' => 5, 'topic_code' => 'iq-eq', 'title' => 'old', 'subtitle' => 'old', 'excerpt' => 'old',
                'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'published_at' => '2026-01-01 00:00:00',
                'working_revision_id' => 91, 'published_revision_id' => 90, 'cover_image_url' => 'https://example.invalid/image'],
            'sections' => [
                ['id' => 1, 'profile_id' => 5, 'section_key' => 'overview', 'is_enabled' => true, 'sort_order' => 10, 'body_html' => 'old'],
                ['id' => 2, 'profile_id' => 5, 'section_key' => 'why_it_matters', 'is_enabled' => true, 'sort_order' => 20, 'body_md' => 'Unowned callout'],
            ],
            'entries' => [
                ['id' => 1, 'profile_id' => 5, 'entry_type' => 'scale', 'group_key' => 'featured', 'target_key' => 'IQ_RAVEN', 'is_enabled' => true, 'title_override' => 'IQ'],
                ['id' => 2, 'profile_id' => 5, 'entry_type' => 'scale', 'group_key' => 'tests', 'target_key' => 'EQ_60', 'is_enabled' => true, 'title_override' => 'EQ'],
                ['id' => 3, 'profile_id' => 5, 'entry_type' => 'article', 'group_key' => 'iq_articles', 'target_key' => 'existing', 'is_enabled' => true],
            ],
            'seo' => ['profile_id' => 5, 'canonical_url' => 'https://fermatmind.com/zh/topics/iq-eq', 'robots' => 'index,follow',
                'og_image_url' => 'https://example.invalid/image', 'jsonld_overrides_json' => ['name' => 'old', 'url' => 'https://fermatmind.com/zh/topics/iq-eq']],
        ];
    }
}
