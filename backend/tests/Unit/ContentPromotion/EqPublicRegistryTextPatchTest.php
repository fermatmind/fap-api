<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\EqPublicRegistryTextPatch;
use PHPUnit\Framework\TestCase;

final class EqPublicRegistryTextPatchTest extends TestCase
{
    public function test_changes_only_selected_public_text_and_keeps_locale_and_score_authority(): void
    {
        $content = ['en' => ['why_choose' => ['intro' => 'Old'], 'score_system' => ['method' => 'protected']], 'zh' => ['why_choose' => ['intro' => '中文']]];
        $after = (new EqPublicRegistryTextPatch)->apply($content, $this->candidate('/en/why_choose/intro', 'Reviewed'));
        self::assertSame('Reviewed', $after['en']['why_choose']['intro']);
        self::assertSame($content['en']['score_system'], $after['en']['score_system']);
        self::assertSame($content['zh'], $after['zh']);
        self::assertSame('Old', $content['en']['why_choose']['intro']);
    }

    public function test_ordered_parent_replacement_uses_final_reviewed_link_label(): void
    {
        $content = ['en' => ['faq' => [['related_links' => [['label' => 'Old', 'href' => '#choose-version']]]]]];
        $candidate = $this->candidate('/en/faq/0/related_links/0/label', 'Intermediate');
        $candidate['registry_operations'][] = ['op' => 'replace', 'path' => '/en/faq/0/related_links', 'value' => [['label' => 'Final', 'href' => '#choose-version']]];
        $after = (new EqPublicRegistryTextPatch)->apply($content, $candidate);
        self::assertSame([['label' => 'Final', 'href' => '#choose-version']], $after['en']['faq'][0]['related_links']);
    }

    public function test_missing_target_does_not_create_a_different_registry_shape(): void
    {
        $this->expectExceptionMessage('eq_registry_text_prestate_missing');
        (new EqPublicRegistryTextPatch)->apply(['en' => []], $this->candidate('/en/why_choose/intro', 'Reviewed'));
    }

    public function test_other_locale_and_private_scoring_are_outside_the_delta(): void
    {
        foreach (['/zh/why_choose/intro', '/en/score_system/method', '/en/faq/0/q'] as $path) {
            try {
                (new EqPublicRegistryTextPatch)->apply([], $this->candidate($path, 'Invalid'));
                self::fail('Out of scope path admitted');
            } catch (\DomainException $error) {
                self::assertSame('eq_registry_text_scope_invalid', $error->getMessage());
            }
        }
    }

    private function candidate(string $path, mixed $value): array
    {
        return ['page_id' => 'EQ-01', 'identity' => ['org_id' => 0, 'locale' => 'en', 'slug' => 'eq-test-emotional-intelligence-assessment'], 'registry_operations' => [['op' => 'replace', 'path' => $path, 'value' => $value]]];
    }
}
