<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\Report\HighlightBuilder;

class HighlightBuilderBlindspotIdTemplatesDocTest extends TestCase
{
    public function test_blindspot_id_must_not_have_double_hl_prefix(): void
    {
        /** @var HighlightBuilder $hb */
        $hb = app(HighlightBuilder::class);

        $report = [
            'profile' => ['type_code' => 'ESTJ-A'],
            'scores_pct' => ['EI'=>64,'SN'=>55,'TF'=>71,'JP'=>62,'AT'=>52],
            'axis_states' => ['EI'=>'clear','SN'=>'borderline','TF'=>'clear','JP'=>'clear','AT'=>'borderline'],
        ];

        // ✅ 最小 doc：直接复制你 tinker 里的
        $doc = [
            'schema' => 'fap.report.highlights.templates.v1',
            'pools' => [
                'templates' => [
                    'EI' => ['E' => ['clear' => ['id'=>'hl.EI.E.clear','title'=>'表达推进','text'=>'你更习惯外放推进，把节奏带起来。']]],
                    'SN' => ['S' => ['borderline' => ['id'=>'hl.SN.S.border','title'=>'务实 落地','text'=>'你更看重现实约束与可落地步骤。']]],
                    'TF' => ['T' => ['clear' => ['id'=>'hl.TF.T.clear','title'=>'理性判断','text'=>'你会用标准与逻辑做决策。']]],
                    'JP' => ['J' => ['clear' => ['id'=>'hl.JP.J.clear','title'=>'结构掌控','text'=>'你倾向先定规则再执行。']]],
                    'AT' => ['A' => ['borderline' => ['id'=>'hl.AT.A.border','title'=>'自信 稳定','text'=>'整体更偏自信，但仍会受情境影响。']]],
                ],
                'rules' => [
                    'allow_empty' => true,
                    'min_level' => 'clear',
                    'min_delta' => 12,
                    'top_n' => 3,
                    'max_items' => 2,
                ],
            ],
        ];

        $out = $hb->buildFromTemplatesDoc($report, $doc, 3, 4, []);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('items', $out);
        $this->assertIsArray($out['items']);

        // 1) 数量必须在 [3,4]
        $this->assertGreaterThanOrEqual(3, count($out['items']));
        $this->assertLessThanOrEqual(4, count($out['items']));

        // 2) 核心防回归：禁止 hl.blindspot.hl.xxx
        $ids = array_values(array_filter(array_map(fn($x) => $x['id'] ?? null, $out['items'])));
        foreach ($ids as $id) {
            $this->assertStringNotContainsString('hl.blindspot.hl.', $id, "bad id found: {$id}");
        }

        // 3) 必须包含 blindspot/action
        $kinds = array_map(fn($x) => $x['kind'] ?? null, $out['items']);
        $this->assertTrue(in_array('blindspot', $kinds, true), 'must contain blindspot');
        $this->assertTrue(in_array('action', $kinds, true), 'must contain action');

        // =========================
        // ✅ 新增防回归（你要的两条）
        // =========================

        // A) 找到 blindspot item（只检查 blindspot）
        $blindspot = null;
        foreach ($out['items'] as $it) {
            if (is_array($it) && (($it['kind'] ?? null) === 'blindspot')) {
                $blindspot = $it;
                break;
            }
        }
        $this->assertIsArray($blindspot, 'blindspot item must exist');

        $blindspotId = (string)($blindspot['id'] ?? '');
        $this->assertNotSame('', $blindspotId, 'blindspot id must not be empty');

        // B) blindspot id 不允许包含 "borderline"
        $this->assertStringNotContainsString('borderline', $blindspotId, "blindspot id must not contain borderline: {$blindspotId}");

        // C) blindspot id 必须符合统一格式：hl.blindspot. 开头
        $this->assertStringStartsWith('hl.blindspot.', $blindspotId, "blindspot id must start with hl.blindspot.: {$blindspotId}");

        // D) 并且（此 case 是 AT 维度）必须包含 AT 的维度标识（兼容两种 id 风格）
        $hasAtToken = (str_contains($blindspotId, 'AT_') || str_contains($blindspotId, 'AT.'));
        $this->assertTrue($hasAtToken, "blindspot id must include AT_ or AT.: {$blindspotId}");
    }
}