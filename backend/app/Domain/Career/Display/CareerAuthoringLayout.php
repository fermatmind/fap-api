<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

/** Builds positions from the formal accountant display, not from a copy of its body. */
final class CareerAuthoringLayout
{
    private array $slots = [];

    private array $page = [];

    private const COLUMNS = [
        'AI主要改变' => 'ai_change', '与会计师／审计师的关键区别' => 'distinction',
        '为什么重要' => 'importance', '人的控制点' => 'human_control', '人群' => 'audience',
        '仍由人负责' => 'human_responsibility', '任务' => 'task', '使用限制' => 'limitation',
        '含义' => 'meaning', '回答' => 'answer', '城市/区间' => 'city_range', '学历段' => 'education',
        '岗位方向' => 'role', '工作方向' => 'work_direction', '应对重点' => 'response',
        '当前变化' => 'current_change', '指标' => 'metric', '控制方式' => 'control', '数值' => 'value',
        '方向' => 'direction', '更适合什么选择' => 'choice', '月薪参考' => 'monthly_pay',
        '来源' => 'source', '核心工作与产出' => 'work_output', '概念' => 'concept', '步骤' => 'step',
        '研究对象' => 'study_scope', '类型' => 'type_label', '结论' => 'conclusion', '职业方向' => 'career',
        '行业' => 'industry', '说明' => 'explanation', '适用范围' => 'scope', '链接' => 'link',
        '问题' => 'question', '需求' => 'demand', '风险' => 'risk',
    ];

    // Existing UI-owned copy and derived salary cells also need explicit future authoring positions.
    // No UI strings or occupation facts are copied here; these positions start unfilled.
    private const INTERFACE_POSITIONS = [
        'interface.quick_decision.suit_heading',
        'interface.quick_decision.caution_heading',
        'interface.quick_decision.experiment_heading',
        'interface.profile.responsibilities_heading',
        'interface.profile.comparison.dimension_header',
        'interface.profile.comparison.primary_header',
        'interface.profile.comparison.secondary_header',
        'interface.direction_comparison.conclusion_heading',
        'interface.direction_comparison.table.caption',
        'interface.direction_comparison.table.direction_header',
        'interface.direction_comparison.table.work_output_header',
        'interface.direction_comparison.table.distinction_header',
        'interface.direction_comparison.table.choice_header',
        'interface.direction_comparison.evidence_label',
        'interface.ai_impact.heading',
        'interface.ai_impact.method_cards_label',
        'interface.ai_impact.tasks.heading',
        'interface.ai_impact.tasks.caption',
        'interface.ai_impact.tasks.direction_header',
        'interface.ai_impact.tasks.task_header',
        'interface.ai_impact.tasks.change_header',
        'interface.ai_impact.tasks.human_control_header',
        'interface.ai_impact.evidence.heading',
        'interface.ai_impact.evidence.source_link_label',
        'interface.ai_impact.evidence.study_scope_label',
        'interface.ai_impact.evidence.conclusion_label',
        'interface.ai_impact.evidence.limitation_label',
        'interface.ai_impact.differences.heading',
        'interface.ai_impact.differences.change_label',
        'interface.ai_impact.differences.human_responsibility_label',
        'interface.ai_impact.responsibility.heading',
        'interface.ai_impact.risks.heading',
        'interface.ai_impact.risks.control_label',
        'interface.ai_impact.actions.heading',
        'interface.ai_impact.questions.heading',
        'interface.ai_impact.questions.source_label',
        'interface.ai_impact.questions.source_link_label',
        'interface.ai_impact.sources.heading',
        'interface.china_salary.section_label',
        'interface.china_salary.official_wage_heading',
        'interface.china_salary.pay_level_heading',
        'interface.china_salary.scenarios.caption',
        'interface.china_salary.scenarios.role_header',
        'interface.china_salary.scenarios.pay_range_header',
        'interface.china_salary.scenarios.interpretation_header',
        'interface.china_salary.drivers_heading',
        'interface.china_salary.ai_pay_heading',
        'interface.china_salary.sources_label',
        'interface.us_salary.section_label',
        'interface.us_salary.tiers.position_header',
        'interface.us_salary.tiers.annual_header',
        'interface.us_salary.tiers.monthly_header',
        'interface.us_salary.tiers.interpretation_header',
        'interface.us_salary.industry.industry_header',
        'interface.us_salary.industry.interpretation_header',
        'interface.us_salary.sources_label',
        'interface.us_salary.tiers.01.label',
        'interface.us_salary.tiers.01.interpretation',
        'interface.us_salary.tiers.01.annual_range',
        'interface.us_salary.tiers.01.monthly_range',
        'interface.us_salary.tiers.02.label',
        'interface.us_salary.tiers.02.interpretation',
        'interface.us_salary.tiers.02.annual_range',
        'interface.us_salary.tiers.02.monthly_range',
        'interface.us_salary.tiers.03.label',
        'interface.us_salary.tiers.03.interpretation',
        'interface.us_salary.tiers.03.annual_range',
        'interface.us_salary.tiers.03.monthly_range',
        'interface.fit.assessments_heading',
        'interface.fit.interest_baseline_label',
        'interface.fit.interest_experience_heading',
        'interface.fit.interest_code_heading',
        'interface.fit.assessment_misuse_label',
        'interface.fit.directions_heading',
        'interface.fit.direction_match_label',
        'interface.fit.direction_caution_label',
        'interface.fit.related_career_label',
        'interface.fit.questions_heading',
        'interface.risk.scenario_label',
        'interface.risk.affected_roles_label',
        'interface.risk.consequence_label',
        'interface.risk.mitigation_label',
        'interface.risk.evidence_label',
        'interface.path.responsibilities_label',
        'interface.path.promotion_evidence_label',
        'interface.path.credential_boundary_label',
        'interface.path.next_step_label',
        'interface.path.competence_ladder_heading',
        'interface.path.entry_decisions_heading',
        'interface.outlook.evidence_heading',
        'interface.outlook.evidence_limitation_label',
        'interface.outlook.transitions_heading',
        'interface.outlook.transitions_intro',
        'interface.outlook.shared_capabilities_label',
        'interface.outlook.capability_gaps_label',
        'interface.outlook.detail_link_label',
        'interface.sources.heading',
        'interface.sources.original_source_link_label',
        'interface.sources.limitation_label',
        'interface.sources.faq_heading',
        'interface.sources.usage_boundary_heading',
        'interface.navigation.desktop_heading',
        'interface.navigation.mobile_heading',
        'interface.navigation.test_cta_label',
    ];

    private const METADATA = ['availability', 'key', 'id', 'fact_ref', 'fact_refs', 'source_refs', 'claim_refs', 'question_key', 'question_intent', 'column_keys', 'entry_surface', 'source_page_type', 'subject_kind', 'subject_key', 'target_action', 'test_slug'];

    public function baseline(array $page): array
    {
        if ($page['subject']['canonical_slug'] !== 'accountants-and-auditors' || $page['locale'] !== 'zh-CN') {
            throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_BASELINE_INVALID');
        }
        (new CareerPageDisplayResolver)->resolve($page);
        $this->slots = [];
        $this->page = $page;
        foreach ($page['display']['component_order'] as $component) {
            $this->walk($page['display']['components'][$component], $component, ['source' => 'display', 'path' => ['components', $component]]);
        }
        foreach ($page['display']['native_items'] as $native) {
            $item = CareerAuthoringStructure::items($page)[$native['id']];
            $position = $native['location'].'.'.str_replace('career.item.', '', $native['copy_key']);
            $this->add($position.'.title', isset($item['title']) ? ['source' => 'item', 'id' => $item['id'], 'path' => ['title']] : null);
            $this->walk($item['data'], $position, ['source' => 'item', 'id' => $item['id'], 'path' => ['data']]);
        }
        foreach ($page['display']['section_titles'] as $key => $value) {
            $this->add('sections.'.$key.'.title', ['source' => 'display', 'path' => ['section_titles', $key]]);
        }
        foreach (['name', 'summary'] as $key) {
            $this->add('identity.'.$key, ['source' => 'page', 'path' => ['subject', $key]]);
        }
        foreach (['title', 'description'] as $key) {
            $this->add('seo.'.$key, ['source' => 'page', 'path' => ['seo', $key, 'text']]);
        }
        foreach (['ai' => $page['hero']['ai'], ...array_column($page['hero']['metrics'], null, 'key')] as $key => $metric) {
            $path = $key === 'ai' ? ['hero', 'ai'] : ['hero', 'metrics', array_search($key, array_column($page['hero']['metrics'], 'key'), true)];
            $this->add('hero.'.$key.'.label', ['source' => 'page', 'path' => [...$path, 'label']]);
            foreach (['display_value', 'derivation', 'market', 'period', 'measure', 'occupation_scope'] as $field) {
                $this->add('hero.'.$key.'.'.$field, $metric['fact_ref'] === null ? null : ['source' => 'fact', 'id' => $metric['fact_ref'], 'path' => [$field]]);
            }
        }
        for ($index = 0; $index < 3; $index++) {
            $this->add('hero.badges.'.self::ordinal($index), isset($page['hero']['badges'][$index]) ? ['source' => 'page', 'path' => ['hero', 'badges', $index]] : null);
        }
        foreach (self::INTERFACE_POSITIONS as $position) {
            $this->add($position, null);
        }
        ksort($this->slots, SORT_STRING);

        return ['contract_version' => CareerAuthoringStructure::VERSION, 'module_order' => ['overview', ...array_values(array_filter(array_column($page['blocks'], 'id'), static fn (string $id): bool => ! in_array($id, ['navigation', 'source-register'], true)))], 'slots' => $this->slots];
    }

    public function migrate(array $page, array $baseline): array
    {
        if (isset($page['authoring_structure'])) {
            CareerAuthoringStructure::assert($page);

            return $page;
        }
        $structure = $baseline;
        $accountant = $page['subject']['canonical_slug'] === 'accountants-and-auditors';
        foreach ($structure['slots'] as $id => &$slot) {
            if ($accountant) {
                continue;
            }
            $ref = $slot['ref'];
            $slot = ['status' => 'unfilled', 'ref' => null];
            // Only identity, SEO and explicit hero metric bindings are shared contracts today.
            // The other careers' coarse prose does not identify the accountant's individual cells.
            if (is_array($ref) && $ref['source'] === 'page') {
                try {
                    $value = CareerAuthoringStructure::reference($page, $ref);
                    if (is_string($value) && trim($value) !== '') {
                        $slot = ['status' => 'mapped', 'ref' => $ref];
                    }
                } catch (CareerCurrentAuthorityPackageFailure) {
                    // An absent badge or subject summary remains explicitly unfilled.
                }
            }
            if (preg_match('/\Ahero\.(ai|us_pay|us_growth|us_employment|us_openings|china_pay)\.(display_value|derivation|market|period|measure|occupation_scope)\z/', $id, $matches)) {
                $metric = $matches[1] === 'ai' ? $page['hero']['ai'] : array_column($page['hero']['metrics'], null, 'key')[$matches[1]];
                if ($metric['availability'] === 'available') {
                    $ref = ['source' => 'fact', 'id' => $metric['fact_ref'], 'path' => [$matches[2]]];
                    $value = CareerAuthoringStructure::reference($page, $ref);
                    if (is_string($value) && trim($value) !== '') {
                        $slot = ['status' => 'mapped', 'ref' => $ref];
                    }
                }
            }
        }
        unset($slot);
        $structure['inventory'] = CareerAuthoringStructure::inventory($page, $structure['slots']);
        $page['authoring_structure'] = $structure;
        CareerAuthoringStructure::assert($page);

        return $page;
    }

    private function walk(mixed $node, string $position, array $ref): void
    {
        if (is_array($node) && isset($node['$item'])) {
            $item = CareerAuthoringStructure::items($this->page)[$node['$item']];
            $path = ['data', $item['type'] === 'prose' ? 'paragraphs' : 'entries'];
            if ($item['type'] === 'prose') {
                $path[] = 0; // The existing display contract requires exactly one paragraph.
            }
            $this->walk(CareerAuthoringStructure::reference($this->page, ['source' => 'item', 'id' => $item['id'], 'path' => $path]), $position, ['source' => 'item', 'id' => $item['id'], 'path' => $path]);

            return;
        }
        if (is_array($node) && isset($node['$link'])) {
            $this->add($position, ['source' => 'item', 'id' => $node['$link'], 'path' => ['data', 'entries', ['id' => $node['entry']], $node['field']]]);

            return;
        }
        if (is_array($node) && isset($node['$fact'])) {
            $this->add($position, ['source' => 'fact', 'id' => $node['$fact'], 'path' => [$node['field']]]);

            return;
        }
        if (is_array($node) && isset($node['$subject'])) {
            $this->add($position, ['source' => 'page', 'path' => ['subject', $node['$subject']]]);

            return;
        }
        if (! is_array($node)) {
            if (is_string($node) || $node === null) {
                $this->splitOrAdd($node, $position, $ref);
            }

            return;
        }
        if (isset($node['column_keys'], $node['rows'])) {
            foreach ($node['column_keys'] as $columnIndex => $column) {
                $this->add($position.'.headers.'.$column, isset($node['column_labels'][$columnIndex]) ? [...$ref, 'path' => [...$ref['path'], 'column_labels', $columnIndex]] : null);
                foreach ($node['rows'] as $rowIndex => $row) {
                    $this->walk($row[$columnIndex], $position.'.rows.'.self::ordinal($rowIndex).'.'.$column, [...$ref, 'path' => [...$ref['path'], 'rows', $rowIndex, $columnIndex]]);
                }
            }

            return;
        }
        if (isset($node['id'], $node['values']) && str_starts_with($position, 'entry_decisions.')) {
            foreach ($node['values'] as $index => $value) {
                $this->walk($value, $position.'.'.($index === 0 ? 'title' : 'description.'.self::ordinal($index - 1)), [...$ref, 'path' => [...$ref['path'], 'values', $index]]);
            }

            return;
        }
        foreach ($node as $key => $value) {
            if (in_array($key, self::METADATA, true)) {
                continue;
            }
            $segment = is_int($key) ? self::ordinal($key) : (self::COLUMNS[$key] ?? $key);
            if (preg_match('/\A[a-z0-9_.-]+\z/i', $segment) !== 1) {
                throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_POSITION_UNKNOWN');
            }
            if (isset(self::COLUMNS[$key])) {
                $this->add($position.'.headers.'.$segment, [...$ref, 'key' => $key]);
            }
            $selector = is_int($key) && is_array($value) && isset($value['id']) ? ['id' => $value['id']] : $key;
            $this->walk($value, $position.'.'.$segment, [...$ref, 'path' => [...$ref['path'], $selector]]);
        }
    }

    private function splitOrAdd(?string $value, string $position, array $ref): void
    {
        if (str_starts_with($position, 'responsibilities_block.') && is_string($value)) {
            foreach (['category', 'title', 'description'] as $index => $field) {
                $this->add($position.'.'.$field, [...$ref, 'parts' => [['｜', $index, 3]]]);
            }
        } elseif (preg_match('/\Acareer_quick_answers_block.items.01.table.rows.0[12].value\z/', $position) && is_string($value)) {
            $count = count(explode('→', $value));
            for ($index = 0; $index < $count; $index++) {
                $this->add($position.'.steps.'.self::ordinal($index), [...$ref, 'parts' => [['→', $index, $count]]]);
            }
        } elseif ($position === 'work_context_block' && is_string($value)) {
            $lines = explode("\n", $value);
            foreach ($lines as $index => $line) {
                foreach (['label', 'description'] as $part => $field) {
                    $this->add($position.'.'.self::ordinal($index).'.'.$field, [...$ref, 'parts' => [["\n", $index, count($lines)], ['｜', $part, 2]]]);
                }
            }
        } elseif (is_string($value) && (
            preg_match('/\Acareer_snapshot_primary_locale\.salary\.(china_ref|china_intl|sources_note|edu)\z/', $position)
            || $position === 'career_snapshot_secondary_locale.authority_sources'
            || preg_match('/\Acareer_snapshot_secondary_locale\.bls_table\.[0-9]+\.explanation\z/', $position)
        )) {
            // The salary component splits these exact fields into note / source label / URL.
            $entries = explode('；', $value);
            foreach ($entries as $index => $entry) {
                $parts = [['；', $index, count($entries)]];
                if (str_contains($entry, '｜')) {
                    foreach (['label', 'href'] as $part => $field) {
                        $this->add($position.'.sources.'.self::ordinal($index).'.'.$field, [...$ref, 'parts' => [...$parts, ['｜', $part, 2]]]);
                    }
                } else {
                    $this->add($position.'.notes.'.self::ordinal($index), [...$ref, 'parts' => $parts]);
                }
            }
        } else {
            $this->add($position, $value === null ? null : $ref);
        }
    }

    private function add(string $position, ?array $ref): void
    {
        if (isset($this->slots[$position])) {
            throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_POSITION_DUPLICATE');
        }
        $value = $ref === null ? null : CareerAuthoringStructure::reference($this->page, $ref);
        $filled = is_string($value) && trim($value) !== '';
        $this->slots[$position] = ['status' => $filled ? 'mapped' : 'unfilled', 'ref' => $filled ? $ref : null];
    }

    private static function ordinal(int $index): string
    {
        return str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    }
}
