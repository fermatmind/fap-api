<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerContentV3FactResolver;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerPageProjector;
use PHPUnit\Framework\TestCase;

final class CareerPageDisplayResolverTest extends TestCase
{
    private function source(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function project(array $source): array
    {
        $package = new CareerContentV3AuthorityPackage;

        return (new CareerPageProjector(new CareerContentV3CanonicalReader($package), $package, new CareerContentV3FactResolver))->project($source);
    }

    public function test_formal_file_projects_its_original_components_and_hero_values(): void
    {
        $source = $this->source();
        $page = $this->project($source);
        $facts = array_column($source['fact_register']['facts'], null, 'fact_id');
        self::assertSame($facts[$source['hero']['ai']['fact_ref']]['display_value'], $page['hero']['ai']['fact']['display_value']);
        self::assertSame($source['hero']['badges'], $page['hero']['badges']);
        foreach ($source['hero']['metrics'] as $index => $metric) {
            self::assertSame($facts[$metric['fact_ref']]['display_value'], $page['hero']['metrics'][$index]['fact']['display_value']);
        }
        self::assertCount(22, $page['display']['components']);
        self::assertCount(10, $page['display']['components']['faq_block']['items']);
        self::assertCount(4, $page['display']['components']['source_card']['secondary_links']);
        self::assertArrayNotHasKey('display', $page['content']);
        self::assertStringNotContainsString('"$item"', json_encode($page['display']));
    }

    public function test_formal_actor_file_projects_complete_content_without_internal_authoring_fields(): void
    {
        $source = json_decode(file_get_contents(dirname(__DIR__, 5).'/content_assets/career/current/careers/actors/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
        $page = $this->project($source);
        self::assertSame($source['source_content_sha256'], $page['source_content_sha256']);
        self::assertSame('actors', $page['subject']['canonical_slug']);
        self::assertSame('zh-CN', $page['locale']);
        self::assertSame($source['blocks'], $page['content']['blocks']);
        self::assertCount(22, $page['display']['components']);
        self::assertCount(10, $page['display']['components']['faq_block']['items']);
        self::assertSame('missing', $page['hero']['ai']['availability']);
        self::assertCount(3, $page['hero']['badges']);
        self::assertStringNotContainsString('authoring_structure', json_encode($page));
        self::assertStringNotContainsString('"$item"', json_encode($page['display']));
        self::assertStringNotContainsString('"$join"', json_encode($page['display']));
    }

    public function test_editing_one_formal_file_field_updates_display_without_database_or_frontend_copy(): void
    {
        $source = $this->source();
        $reference = $source['display']['components']['definition_block']['$item'];
        foreach ($source['blocks'] as &$block) {
            foreach ($block['items'] as &$item) {
                if ($item['id'] === $reference) {
                    $item['data']['paragraphs'][0] = 'Single-file content edit';
                }
                if ($item['id'] === 'faq-block-1') {
                    $item['data']['entries'][0]['question'] = 'Single-file question edit?';
                }
            }
            unset($item);
        }
        unset($block);
        $page = $this->project($source);
        self::assertSame('Single-file content edit', $page['display']['components']['definition_block']);
        self::assertSame('Single-file question edit?', $page['display']['components']['faq_block']['items'][0]['question']);
    }

    public function test_same_file_composition_keeps_original_text_and_identity(): void
    {
        $source = $this->source();
        $before = $this->project($source)['display']['components']['definition_block'];
        $reference = $source['display']['components']['definition_block'];
        $source['display']['components']['definition_block'] = ['$join' => ['工作定义', $reference], 'separator' => '｜'];
        self::assertSame('工作定义｜'.$before, $this->project($source)['display']['components']['definition_block']);
        self::assertSame($reference['$item'], $source['display']['components']['definition_block']['$join'][1]['$item']);
    }

    public function test_composition_cannot_hide_missing_duplicate_or_non_text_content(): void
    {
        foreach (['missing', 'duplicate', 'non_text', 'unknown_key', 'separator'] as $case) {
            $source = $this->source();
            $ref = $source['display']['components']['definition_block'];
            $join = ['$join' => [$ref], 'separator' => ''];
            if ($case === 'missing') {
                $join['$join'][0]['$item'] = 'missing-item';
            } elseif ($case === 'duplicate') {
                $join['$join'][] = $ref;
            } elseif ($case === 'non_text') {
                $join['$join'][] = null;
            } elseif ($case === 'unknown_key') {
                $join['unreviewed'] = true;
            } else {
                $join['separator'] = [];
            }
            $source['display']['components']['definition_block'] = $join;
            try {
                $this->project($source);
                self::fail('Invalid composition accepted: '.$case);
            } catch (CareerCurrentAuthorityPackageFailure $exception) {
                self::assertSame('CAREER_PAGE_DISPLAY_INVALID', $exception->getMessage());
            }
        }
    }

    public function test_list_composition_keeps_every_boundary_in_the_visible_note(): void
    {
        $source = $this->source();
        $before = $this->project($source)['display']['components']['boundary_notice'];
        $reference = $source['display']['components']['boundary_notice'];
        $source['display']['components']['boundary_notice'] = ['使用边界'];
        $note = $source['display']['components']['source_card']['note'];
        $source['display']['components']['source_card']['note'] = ['$join' => [$note, $reference], 'separator' => "\n"];
        $result = $this->project($source)['display']['components']['source_card']['note'];
        self::assertStringEndsWith(implode("\n", $before), $result);
        $source['display']['components']['source_card']['note']['$join'][] = $reference;
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->project($source);
    }

    public function test_display_sources_follow_the_single_source_register(): void
    {
        $source = $this->source();
        $register = null;
        foreach ($source['blocks'] as $block) {
            foreach ($block['items'] as $item) {
                if ($item['type'] === 'sources') {
                    $register = ['block' => $block['id'], ...$item];
                    break 2;
                }
            }
        }
        self::assertNotNull($register);
        $entry = $register['data']['entries'][0];
        $ref = ['$source' => $entry['id'], 'item' => $register['id'], 'block' => $register['block'], 'copy_key' => $register['copy_key'], 'field' => 'name'];
        $source['display']['components']['source_card']['registry_label'] = $ref;
        self::assertSame($entry['name'], $this->project($source)['display']['components']['source_card']['registry_label']);
        foreach (['unknown_source', 'forbidden_field', 'wrong_item', 'unknown_key'] as $case) {
            $bad = $source;
            $badRef = $ref;
            if ($case === 'unknown_source') {
                $badRef['$source'] = 'absent';
            } elseif ($case === 'forbidden_field') {
                $badRef['field'] = 'details';
            } elseif ($case === 'wrong_item') {
                $badRef['item'] = 'absent';
            } else {
                $badRef['fallback'] = 'No fallback';
            }
            $bad['display']['components']['source_card']['registry_label'] = $badRef;
            try {
                $this->project($bad);
                self::fail('Invalid source accepted: '.$case);
            } catch (CareerCurrentAuthorityPackageFailure $exception) {
                self::assertSame('CAREER_PAGE_DISPLAY_INVALID', $exception->getMessage());
            }
        }
    }

    public function test_existing_entry_renderer_notice_types_are_preserved(): void
    {
        foreach (['career.item.entry-work-sample-data', 'career.item.recruitment-sample'] as $copyKey) {
            $source = $this->source();
            $item = ['id' => 'additional-local-notice', 'type' => 'notice', 'copy_key' => $copyKey, 'availability' => 'available', 'data' => ['paragraphs' => ['Original multi-paragraph brief.', 'Original scene and instructions.']]];
            foreach ($source['blocks'] as &$block) {
                if ($block['id'] === 'path') {
                    $block['items'][] = $item;
                }
            }
            unset($block);
            $source['display']['native_items'][] = ['id' => $item['id'], 'block' => 'path', 'copy_key' => $copyKey, 'type' => 'notice', 'location' => 'entry_decisions'];
            $source['authoring_structure']['inventory'] = \App\Domain\Career\Display\CareerAuthoringStructure::inventory($source, $source['authoring_structure']['slots']);
            $page = $this->project($source);
            $path = array_values(array_filter($page['content']['blocks'], static fn (array $block): bool => $block['id'] === 'path'))[0];
            $retained = array_values(array_filter($path['items'], static fn (array $entry): bool => $entry['id'] === $item['id']))[0];
            self::assertSame($item['data'], $retained['data']);
        }
    }

    public function test_interface_labels_come_from_the_same_file(): void
    {
        $source = $this->source();
        $key = 'interface.profile.responsibilities_heading';
        $source['display']['interface'] = [$key => '职责核对'];
        self::assertSame('职责核对', $this->project($source)['display']['interface'][$key]);
        $source['display']['interface'][$key] = '工作任务';
        self::assertSame('工作任务', $this->project($source)['display']['interface'][$key]);
    }

    public function test_unknown_interface_position_is_rejected(): void
    {
        $source = $this->source();
        $source['display']['interface'] = ['interface.profile.unknown' => '未知位置'];
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->project($source);
    }

    public function test_empty_interface_label_is_rejected(): void
    {
        $source = $this->source();
        $source['display']['interface'] = ['interface.profile.responsibilities_heading' => ' '];
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->project($source);
    }

    public function test_missing_reference_fails_instead_of_dropping_body(): void
    {
        $source = $this->source();
        $source['display']['components']['definition_block']['$item'] = 'unknown';
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->project($source);
    }

    public function test_duplicate_reference_fails_instead_of_repeating_body(): void
    {
        $source = $this->source();
        $source['display']['components']['hero']['quick_answer'] = $source['display']['components']['definition_block'];
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->project($source);
    }

    public function test_unmapped_public_body_fails_before_publication(): void
    {
        $source = $this->source();
        $source['blocks'][0]['items'][] = ['id' => 'unmapped', 'copy_key' => 'career.item.unmapped', 'type' => 'prose', 'availability' => 'available', 'data' => ['paragraphs' => ['Required reader content']]];
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->project($source);
    }

    public function test_reordered_source_items_keep_explicit_display_fields(): void
    {
        $source = $this->source();
        $before = $this->project($source)['display'];
        foreach ($source['blocks'] as &$block) {
            $block['items'] = array_reverse($block['items']);
        }
        unset($block);
        self::assertSame($before, $this->project($source)['display']);
    }
}
