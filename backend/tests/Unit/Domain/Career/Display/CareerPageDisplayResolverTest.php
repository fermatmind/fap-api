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

    public function test_formal_file_contains_all_original_components_and_restored_hero_values(): void
    {
        $page = $this->project($this->source());
        self::assertSame('8/10', $page['hero']['ai']['fact']['display_value']);
        self::assertSame(['RIASEC · CEI', '企业财务 / 事务所审计', '合规责任 · 忙季高压 · 自动化升级'], $page['hero']['badges']);
        self::assertSame(['$83,680', '5%', '1,595,200', '115,300', '¥78,500'], array_column(array_column($page['hero']['metrics'], 'fact'), 'display_value'));
        self::assertCount(22, $page['display']['components']);
        self::assertCount(10, $page['display']['components']['faq_block']['items']);
        self::assertCount(4, $page['display']['components']['source_card']['secondary_links']);
        self::assertArrayNotHasKey('display', $page['content']);
        self::assertStringNotContainsString('"$item"', json_encode($page['display']));
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
