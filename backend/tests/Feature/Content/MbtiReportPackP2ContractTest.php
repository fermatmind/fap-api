<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Tests\TestCase;

final class MbtiReportPackP2ContractTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function contentFiles(): array
    {
        return [
            'type_profiles.json',
            'report_identity_cards.json',
            'report_highlights_templates.json',
            'report_highlights_pools.json',
            'report_cards_traits.json',
            'report_cards_growth.json',
            'report_cards_career.json',
            'report_cards_relationships.json',
            'report_cards_fallback_traits.json',
            'report_cards_fallback_growth.json',
            'report_cards_fallback_career.json',
            'report_cards_fallback_relationships.json',
        ];
    }

    private function contentPath(string $file): string
    {
        return base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/'.$file);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        $path = $this->contentPath($file);
        $raw = file_get_contents($path);

        $this->assertNotFalse($raw, "Unable to read {$file}");

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) $raw, true);

        $this->assertIsArray($decoded, "Invalid JSON in {$file}");

        return $decoded;
    }

    public function test_all_modified_json_files_are_parseable(): void
    {
        foreach ($this->contentFiles() as $file) {
            $decoded = $this->readJson($file);
            $this->assertIsArray($decoded, "Decoded JSON should be array for {$file}");
        }
    }

    public function test_type_profiles_and_identity_cards_have_complete_copy(): void
    {
        $profiles = $this->readJson('type_profiles.json');
        $items = $profiles['items'] ?? null;

        $this->assertIsArray($items);
        $this->assertCount(32, $items);

        foreach ($items as $typeCode => $item) {
            $this->assertIsArray($item, "Profile for {$typeCode} must be an array");
            $this->assertNotSame('', trim((string) ($item['tagline'] ?? '')));
            $this->assertNotSame('', trim((string) ($item['short_summary'] ?? '')));
            $this->assertIsArray($item['keywords'] ?? null);
            $this->assertGreaterThanOrEqual(5, count($item['keywords']));
            $this->assertLessThanOrEqual(6, count($item['keywords']));
            foreach ($item['keywords'] as $keyword) {
                $this->assertNotSame('', trim((string) $keyword));
            }
        }

        $identityCards = $this->readJson('report_identity_cards.json');
        $cardItems = $identityCards['items'] ?? null;

        $this->assertIsArray($cardItems);
        $this->assertCount(32, $cardItems);

        foreach ($cardItems as $typeCode => $item) {
            $this->assertIsArray($item, "Identity card for {$typeCode} must be an array");
            $this->assertNotSame('', trim((string) ($item['subtitle'] ?? '')));
            $this->assertNotSame('', trim((string) ($item['tagline'] ?? '')));
            $this->assertNotSame('', trim((string) ($item['summary'] ?? '')));
            $this->assertNotSame('', trim((string) ($item['share_text'] ?? '')));
            $this->assertIsArray($item['tags'] ?? null);
            $this->assertNotEmpty($item['tags']);
            foreach ($item['tags'] as $tag) {
                $this->assertNotSame('', trim((string) $tag));
            }
        }
    }

    public function test_highlight_templates_and_card_access_mix_remain_valid(): void
    {
        $templates = $this->readJson('report_highlights_templates.json');
        $templateTree = $templates['templates'] ?? null;

        $this->assertIsArray($templateTree);

        foreach ($templateTree as $dim => $sideMap) {
            $this->assertIsArray($sideMap, "Template dimension {$dim} must be an array");
            foreach ($sideMap as $side => $levelMap) {
                $this->assertIsArray($levelMap, "Template side {$dim}/{$side} must be an array");
                foreach ($levelMap as $level => $entry) {
                    $this->assertIsArray($entry, "Template entry {$dim}/{$side}/{$level} must be an array");
                    $this->assertNotSame('', trim((string) ($entry['title'] ?? '')));
                    $this->assertNotSame('', trim((string) ($entry['text'] ?? '')));
                    $this->assertIsArray($entry['tips'] ?? null);
                    $this->assertNotEmpty($entry['tips']);
                    foreach ($entry['tips'] as $tip) {
                        $this->assertNotSame('', trim((string) $tip));
                    }
                }
            }
        }

        foreach ([
            'report_cards_traits.json',
            'report_cards_growth.json',
            'report_cards_career.json',
            'report_cards_relationships.json',
        ] as $file) {
            $items = $this->readJson($file)['items'] ?? null;
            $this->assertIsArray($items, "{$file} items should be an array");

            $levels = array_values(array_filter(array_map(
                static fn ($item) => is_array($item) ? (string) ($item['access_level'] ?? '') : '',
                $items
            )));

            $this->assertContains('preview', $levels, "{$file} should keep preview cards");
            $this->assertContains('paid', $levels, "{$file} should keep paid cards");
        }
    }

    public function test_fallback_cards_do_not_contain_placeholder_copy(): void
    {
        $bannedPhrases = [
            'General Tip',
            'Content fallback guidance',
            '通用建议',
            '默认提示',
            '占位文案',
        ];

        foreach ([
            'report_cards_fallback_traits.json',
            'report_cards_fallback_growth.json',
            'report_cards_fallback_career.json',
            'report_cards_fallback_relationships.json',
        ] as $file) {
            $items = $this->readJson($file)['items'] ?? null;
            $this->assertIsArray($items, "{$file} items should be an array");

            $haystack = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertNotFalse($haystack);

            foreach ($bannedPhrases as $phrase) {
                $this->assertStringNotContainsString($phrase, (string) $haystack, "{$file} still contains placeholder phrase {$phrase}");
            }
        }
    }
}
