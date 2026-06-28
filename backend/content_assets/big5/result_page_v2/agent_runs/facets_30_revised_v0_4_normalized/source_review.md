# Big Five 30 Facets Revised Content Assets v0.4

## Editorial Review Summary

本版是 30 Facets 内容资产的第二轮编辑优化。上一版虽然通过了基础安全检查，但仍有明显模板感：标题、summary、短文、cta 过于同构，正文经常以相同的“不是独立结论”结构展开。v0.4 重新按每个 facet 的实际含义写作，降低机器拼接感，同时保留测量边界。

## Editing Strategy

1. **逐 facet 重写，不套同一开头。**  
   O/C/E/A/N 五组各自保留不同语气：开放性更偏探索与理解，尽责性更偏执行与秩序，外倾性更偏能量与表达，宜人性更偏关系与边界，情绪性更偏压力信号与恢复。

2. **保留 domain 关系，但不把 facet 写成独立定论。**  
   每条都说明该 facet 属于哪个父级 domain，并自然提醒它是细部线索，需要和整体画像及真实场景一起看。

3. **增强现实场景。**  
   每条都加入学习、工作、关系、压力或自我管理中的表现，不只解释概念。

4. **去掉明显 AI 味。**  
   减少“高低两端”“更可靠的读法”“行动上可以先尝试”等重复骨架；不同条目的 action 也改成更贴合该 facet 的具体动作。

5. **保持候选资产边界。**  
   未生成最终结果页 payload，未写前端 copy，未接 CMS/SEO/production，所有资产仍为 staging-only。

## Scope

- Section: `03_facets_30`
- Revised content assets: 30
- Output version: `v0_4`
- Runtime use: `staging_only`
- Production use allowed: `false`
- Ready for runtime / production: `false / false`

## Files

- `big5_facets_30_revised_content_assets_v0_4.jsonl`
- `big5_facets_30_revised_content_assets_v0_4.json`
- `big5_facets_30_revised_batch_v0_4.json`
- `big5_facets_30_revised_content_assets_v0_4_index.csv`
- `big5_facets_30_revised_content_assets_v0_4_qa_scan.json`

## QA Summary

```json
{
  "content_asset_count": 30,
  "all_30_facets_covered": true,
  "forbidden_hit_count": 0,
  "runtime_use_all_staging_only": true,
  "production_use_allowed_true_count": 0,
  "ready_for_runtime_true_count": 0,
  "ready_for_production_true_count": 0,
  "body_length_min": 243,
  "body_length_max": 274,
  "body_length_outside_240_420": [],
  "duplicate_title_count": 0,
  "duplicate_body_count": 0
}
```

## Risk Boundary

This package is not:

- final result page payload
- frontend copy
- CMS content
- SEO runtime content
- production import
- runtime rollout

## Codex Follow-up Required

Codex should run:

1. Schema validation.
2. Selector contract validation.
3. Slot/facet mapping check.
4. Public-visible forbidden token scan.
5. Rendered hygiene scan for result page and PDF.
6. Body quality metadata recalculation.
7. Staging import only.

## Notes for Codex

The `body_quality` metadata inside each asset was intentionally preserved because this task only modified user-visible text fields. If importer enforces `body_quality.body_chars`, recalculate it in the staging import PR.
