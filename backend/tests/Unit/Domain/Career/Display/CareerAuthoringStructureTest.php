<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerAuthoringLayout;
use App\Domain\Career\Display\CareerAuthoringStructure;
use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerContentV3FactResolver;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerPageProjector;
use PHPUnit\Framework\TestCase;

final class CareerAuthoringStructureTest extends TestCase
{
    private function page(string $slug = 'accountants-and-auditors'): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 5).'/content_assets/career/current/careers/'.$slug.'/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function baseline(): array
    {
        return (new CareerAuthoringLayout)->baseline($this->page());
    }

    private function migrate(array $page): array
    {
        return (new CareerAuthoringLayout)->migrate($page, $this->baseline());
    }

    public function test_layout_covers_actual_cells_cards_questions_and_component_string_splits(): void
    {
        $page = $this->migrate($this->page());
        $slots = $page['authoring_structure']['slots'];
        self::assertCount(1587, $slots);
        self::assertSame(array_keys(CareerAuthoringStructure::schema()['slots']), array_keys($slots));
        foreach ([
            'responsibilities_block.01.title', 'responsibilities_block.06.description',
            'career_quick_answers_block.items.01.table.rows.01.value.steps.01',
            'career_quick_answers_block.items.02.table.rows.07.secondary_value',
            'career_quick_answers_block.items.03.table.rows.06.value',
            'faq_block.items.01.question', 'faq_block.items.01.answer',
            'entry_decisions.career.item.invalid',
        ] as $id) {
            if ($id === 'entry_decisions.career.item.invalid') {
                self::assertArrayNotHasKey($id, $slots);

                continue;
            }
            self::assertSame('mapped', $slots[$id]['status'], $id);
            self::assertIsString(CareerAuthoringStructure::reference($page, $slots[$id]['ref']));
        }
        self::assertSame('会计确认与计量', CareerAuthoringStructure::reference($page, $slots['responsibilities_block.01.title']['ref']));
        self::assertArrayHasKey('entry_decisions.entry-role-comparison.headers.role', $slots);
        self::assertArrayHasKey('entry_decisions.entry-role-comparison.rows.01.initial_tasks', $slots);
        self::assertArrayHasKey('entry_decisions.employer-evidence.entries.01.title', $slots);
        self::assertArrayHasKey('entry_decisions.seven-day-trial.entries.07.title', $slots);
    }

    public function test_all_formal_chinese_pages_migrate_losslessly_and_idempotently(): void
    {
        $root = dirname(__DIR__, 5).'/content_assets/career/current';
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $baseline = $this->baseline();
        $layout = new CareerAuthoringLayout;
        $package = new CareerContentV3AuthorityPackage;
        $projector = new CareerPageProjector(new CareerContentV3CanonicalReader($package), $package, new CareerContentV3FactResolver);
        $states = ['enhanced' => 0, 'legacy' => 0];
        foreach ($manifest['files'] as $entry) {
            if ($entry['locale'] !== 'zh-CN') {
                continue;
            }
            $old = json_decode(file_get_contents($root.'/'.$entry['path']), true, 512, JSON_THROW_ON_ERROR);
            $new = $layout->migrate($old, $baseline);
            $states[$old['content_state']]++;
            self::assertSame(CareerAuthoringStructure::publicContent($old), CareerAuthoringStructure::publicContent($new), $entry['path']);
            self::assertSame($projector->project($old), $projector->project($new), $entry['path']);
            self::assertSame($new, $layout->migrate($new, $baseline));
            self::assertCount(1587, $new['authoring_structure']['slots']);
            self::assertSame(CareerAuthoringStructure::inventory($new, $new['authoring_structure']['slots']), $new['authoring_structure']['inventory']);
        }
        self::assertSame(['enhanced' => 144, 'legacy' => 902], $states);
    }

    public function test_ambiguous_actor_prose_is_retained_pending_and_not_bound_by_paragraph_order(): void
    {
        $original = $this->page('actors');
        unset($original['authoring_structure']);
        $original['blocks'][0]['items'][0]['data']['paragraphs'][] = '附加段落保留在原内容单元。';
        $page = $this->migrate($original);
        self::assertSame($original['blocks'], $page['blocks']);
        self::assertSame('unfilled', $page['authoring_structure']['slots']['responsibilities_block.01.title']['status']);
        self::assertSame('pending_mapping', $page['authoring_structure']['inventory']['item:'.$original['blocks'][0]['items'][0]['id']]['status']);
        self::assertSame('29.05美元/小时', CareerAuthoringStructure::reference($page, $page['authoring_structure']['slots']['hero.us_pay.display_value']['ref']));
        self::assertSame('unfilled', $page['authoring_structure']['slots']['hero.ai.display_value']['status']);
    }

    public function test_invalid_reference_is_rejected(): void
    {
        $page = $this->migrate($this->page());
        $page['authoring_structure']['slots']['responsibilities_block.01.title']['ref']['id'] = 'missing-item';
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        CareerContentV3Contract::assert($page);
    }

    public function test_missing_position_is_rejected_even_when_display_is_inactive(): void
    {
        $page = $this->migrate($this->page('actors'));
        unset($page['authoring_structure']['slots']['responsibilities_block.01.title']);
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        CareerContentV3Contract::assert($page);
    }

    public function test_duplicate_body_binding_is_rejected(): void
    {
        $page = $this->migrate($this->page());
        $page['authoring_structure']['slots']['responsibilities_block.02.title'] = $page['authoring_structure']['slots']['responsibilities_block.01.title'];
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        CareerAuthoringStructure::assert($page);
    }

    public function test_incomplete_content_cannot_be_marked_complete_or_enabled_by_authoring_state(): void
    {
        $page = $this->migrate($this->page('actors'));
        self::assertArrayNotHasKey('display', $page);
        $id = array_key_first($page['authoring_structure']['inventory']);
        $page['authoring_structure']['inventory'][$id]['status'] = 'mapped';
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        CareerAuthoringStructure::assert($page);
    }

    public function test_new_internal_field_is_filtered_from_canonical_and_page_readers(): void
    {
        $package = new CareerContentV3AuthorityPackage;
        $page = $this->migrate($this->page());
        $projector = new CareerPageProjector(new CareerContentV3CanonicalReader($package), $package, new CareerContentV3FactResolver);
        self::assertStringNotContainsString('authoring_structure', CareerCurrentAuthorityPackage::encodeCanonical($projector->project($page)));
        self::assertArrayNotHasKey('authoring_structure', CareerAuthoringStructure::publicContent($page));
        $reader = new CareerContentV3CanonicalReader($package, dirname(__DIR__, 5));
        self::assertArrayNotHasKey('authoring_structure', $reader->page('accountants-and-auditors', 'zh-CN'));
    }
}
