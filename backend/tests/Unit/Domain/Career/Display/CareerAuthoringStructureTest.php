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

    public function test_composed_display_reference_covers_its_exact_source_items(): void
    {
        $page = $this->page('actors');
        $ref = ['source' => 'display', 'path' => ['components', 'fermat_decision_card', 'summary']];
        $composition = $page['display']['components']['fermat_decision_card']['summary'];
        $ids = array_column($composition['$join'], '$item');
        $items = CareerAuthoringStructure::items($page);
        $expected = implode($composition['separator'], array_map(static fn (string $id): string => $items[$id]['data']['paragraphs'][0], $ids));
        self::assertSame($expected, CareerAuthoringStructure::reference($page, $ref));
        $inventory = CareerAuthoringStructure::inventory($page, [['status' => 'mapped', 'ref' => $ref]]);
        foreach ($ids as $id) {
            self::assertSame('mapped', $inventory['item:'.$id]['status']);
        }
        self::assertSame('pending_mapping', $inventory['item:actors.profile.work-context-block.3']['status']);
    }

    public function test_partial_composed_field_does_not_claim_all_inputs_are_mapped(): void
    {
        $page = $this->page('actors');
        $page['display']['components']['fermat_decision_card']['summary']['separator'] = "\n";
        $ref = ['source' => 'display', 'path' => ['components', 'fermat_decision_card', 'summary'], 'parts' => [["\n", 0, 2]]];
        $slots = [['status' => 'mapped', 'ref' => $ref]];
        $ids = array_column($page['display']['components']['fermat_decision_card']['summary']['$join'], '$item');
        $partial = CareerAuthoringStructure::inventory($page, $slots);
        foreach ($ids as $id) {
            self::assertSame('pending_mapping', $partial['item:'.$id]['status']);
        }
        $ref['parts'][0][1] = 1;
        $slots[] = ['status' => 'mapped', 'ref' => $ref];
        $complete = CareerAuthoringStructure::inventory($page, $slots);
        foreach ($ids as $id) {
            self::assertSame('mapped', $complete['item:'.$id]['status']);
        }
    }

    public function test_composed_authoring_reference_rejects_invalid_display_input(): void
    {
        $page = $this->page('actors');
        $page['display']['components']['fermat_decision_card']['summary']['$join'][0]['$item'] = 'missing-item';
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        CareerAuthoringStructure::reference($page, ['source' => 'display', 'path' => ['components', 'fermat_decision_card', 'summary']]);
    }

    public function test_formal_chinese_inventory_has_completed_structural_migration(): void
    {
        $paths = glob(dirname(__DIR__, 5).'/content_assets/career/current/careers/*/zh-CN.json');
        self::assertCount(1046, $paths);
        foreach ($paths as $path) {
            $page = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('authoring_structure', $page, basename(dirname($path)));
            CareerAuthoringStructure::assert($page);
        }
    }

    public function test_layout_covers_actual_cells_cards_questions_and_component_string_splits(): void
    {
        $page = $this->migrate($this->page());
        $slots = $page['authoring_structure']['slots'];
        self::assertCount(1716, $slots);
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
        self::assertArrayHasKey('career_snapshot_secondary_locale.bls_table.01.explanation.notes.01', $slots);
        self::assertArrayHasKey('career_snapshot_secondary_locale.bls_table.01.explanation.sources.02.href', $slots);
        self::assertArrayHasKey('career_snapshot_primary_locale.salary.china_ref.sources.01.label', $slots);
        foreach (['interface.profile.responsibilities_heading', 'interface.quick_decision.suit_heading', 'interface.china_salary.pay_level_heading', 'interface.us_salary.tiers.01.interpretation', 'interface.fit.directions_heading'] as $id) {
            self::assertSame('unfilled', $slots[$id]['status']);
            self::assertNull($slots[$id]['ref']);
        }
        self::assertSame(['overview', 'quick-decision', 'profile', 'direction-comparison', 'ai-impact', 'china-salary', 'us-salary', 'fit', 'risk', 'path', 'market-signals', 'sources'], $page['authoring_structure']['module_order']);
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
            self::assertCount(1716, $new['authoring_structure']['slots']);
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

    public function test_source_identity_can_be_referenced_in_multiple_modules(): void
    {
        $page = $this->page();
        $sources = array_values(array_filter(CareerAuthoringStructure::items($page), static fn (array $item): bool => $item['type'] === 'sources'))[0];
        $ref = ['source' => 'item', 'id' => $sources['id'], 'path' => ['data', 'entries', ['id' => $sources['data']['entries'][0]['id']], 'name']];
        foreach (['career_risk_cards.source_links.01.label', 'career_path_block.source_links.01.label'] as $key) {
            $page['authoring_structure']['slots'][$key] = ['status' => 'mapped', 'ref' => $ref];
        }
        $page['authoring_structure']['inventory'] = CareerAuthoringStructure::inventory($page, $page['authoring_structure']['slots']);
        CareerAuthoringStructure::assert($page);
        self::assertSame($sources['data']['entries'][0]['name'], CareerAuthoringStructure::reference($page, $ref));
    }

    public function test_interface_alias_reuses_existing_entry_heading(): void
    {
        $page = $this->page('actors');
        self::assertSame($page['authoring_structure']['slots']['career_path_block.entry_heading']['ref'], $page['authoring_structure']['slots']['interface.path.entry_decisions_heading']['ref']);
        CareerAuthoringStructure::assert($page);
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
        // Construct the pre-display state; the formal actor pilot now has display.
        $source = $this->page('actors');
        unset($source['display'], $source['authoring_structure']);
        $page = $this->migrate($source);
        self::assertArrayNotHasKey('display', $page);
        $id = array_key_first(array_filter($page['authoring_structure']['inventory'], static fn (array $entry): bool => $entry['status'] === 'pending_mapping'));
        self::assertNotNull($id);
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
