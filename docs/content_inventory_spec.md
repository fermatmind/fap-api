# Content Inventory Spec（内容库存量规范）

目标：把“库存是否够用”写成可验收的规范，让运营/内容同学在不改代码的前提下，只改 JSON 也能稳定迭代，并且：
- CN_MAINLAND/zh-CN 不回退 GLOBAL/en
- 不 silent fallback
- 不因为“缺模板/缺桶”导致空结果、乱选、不可解释兜底


============================================================
0. 适用范围（Scope）
============================================================

本规范当前只覆盖：
- REGION：CN_MAINLAND
- LOCALE：zh-CN
- 目标内容包（示例）：MBTI-CN-v0.3
- 重点资产：
  - Highlights：report_highlights_templates.json（库存口径以 templates 为准）
  - Reads：report_recommended_reads.json（按 bucket 分组的内容库 + rules）
  - 章节卡片库：report_cards_*.json + report_cards_fallback_*.json（避免章节空）

注：strength/blindspot/action 属于 report.highlights 的结果分类层，不是 templates 文件维度；
不在本规范的 highlights 库存口径里混算（未来阶段另立“结果分类层库存规范”）。


============================================================
1. 真实资产与库存口径（写死）
============================================================

1.1 Highlights Templates（真实维度：dim × side × level）
--------------------------------------------

文件：report_highlights_templates.json
真实结构：templates.<DIM>.<SIDE>.<LEVEL>

- DIM 固定集合：EI / SN / TF / JP / AT
- SIDE 固定映射：
  - EI：E / I
  - SN：S / N
  - TF：T / F
  - JP：J / P
  - AT：A / T
- LEVEL（用于库存门槛的集合）：clear / strong / very_strong

依据：templates rules 已声明 min_level=clear 且 allowed_levels 包含 clear/strong/very_strong；
引擎层 allow_empty: true 可能允许空，但运营化验收必须把“缺模板”定义为 FAIL。


1.2 Reads（真实维度：bucket + tags 覆盖）
--------------------------------------------

文件：report_recommended_reads.json（同文件包含 rules + items + catalog）

Reads 不是一个平铺数组，而是按 bucket 分组的内容库：
- .items.by_role.<KEY>[]
- .items.by_strategy.<KEY>[]
- .items.by_top_axis["axis:<DIM>:<SIDE>"][]
- .items.by_type.<TYPE>[]（可选扩容）
- .items.fallback[]

库存统计口径（固定写死）：
1) 跨所有 bucket 汇总 items
2) 过滤 object
3) 去重（按 id）得到 reads.total_unique
4) 再按 tags 前缀统计覆盖与供给

tags 口径：
- strategy：strategy:<KEY>（允许 KEY：EA / ET / IA / IT）
- role：role:<KEY>（允许 KEY：NT / NF / SJ / SP）
- axis：axis:<DIM>:<SIDE>（共 10 个：EI:E, EI:I, SN:S, SN:N, TF:T, TF:F, JP:J, JP:P, AT:A, AT:T）
- topic：topic:<slug>（来源：catalog.topics[]）


1.3 章节卡片库（真实维度：cards + fallback）
--------------------------------------------

文件（示例）：
- 主库：report_cards_traits.json / report_cards_relationships.json / report_cards_career.json / report_cards_growth.json
- 兜底库：report_cards_fallback_traits.json / report_cards_fallback_relationships.json / report_cards_fallback_career.json / report_cards_fallback_growth.json

目标：避免章节空、避免 fallback 被迫承担全部内容。


============================================================
2. MVP 库存门槛（硬闸，达不到 = FAIL）
============================================================

2.1 Highlights Templates MVP（dim×side：level≥clear 至少命中 1）
--------------------------------------------

对每个 DIM 的每个 SIDE，在 {clear,strong,very_strong} 中至少命中 1 条模板：
- 通过判定：templates.<DIM>.<SIDE>.clear|strong|very_strong 任意存在一个对象（并含基础字段，如 id/title/text）
- 失败判定：任一 DIM 的任一 SIDE 在 clear|strong|very_strong 全缺失 → FAIL


2.2 Reads MVP（去重后总量 + fallback + strategy 覆盖）
--------------------------------------------

MVP 最低门槛（写死）：
- reads.total_unique ≥ 7（跨 bucket 汇总去重，按 id）
- reads.fallback ≥ 2（.items.fallback 至少 2 条）
- reads.non_empty_strategy_buckets ≥ 2（by_strategy 非空桶数量至少 2；输出形如 EA,ET,IA,IT）

说明：MVP 阶段不强制 role/axis/topic 全覆盖，但建议尽早补齐，减少选取不稳定与兜底压力。


2.3 章节卡片库 MVP（每个主库 + 每个 fallback）
--------------------------------------------

对每个章节库（traits/relationships/career/growth）：
- 主库 items ≥ 5
- fallback items ≥ 2


2.4 一票否决（出现任意一个 = FAIL）
--------------------------------------------

- GLOBAL/en
- fallback to GLOBAL
- content_packages/_deprecated

判定范围至少覆盖：
- verify_mbti / ci_verify_mbti stdout
- backend/artifacts/verify_mbti/ 下的 report.json / share.json / logs/*.log


============================================================
3. 目标量（扩容建议，不是 MVP 硬闸）
============================================================

3.1 Highlights Templates（建议目标量）
--------------------------------------------

对每个 dim×side：
- clear ≥ 1
- strong ≥ 1
- very_strong ≥ 1

解释：能显著提高命中稳定性，减少“只有一条模板导致重复/疲劳”的体验问题。


3.2 Reads（建议目标量，与 quota 对齐）
--------------------------------------------

以当前 rules 为例（仅示意，最终以你 pack 内 rules 为准）：
- bucket_quota.by_role = 2
- bucket_quota.by_strategy = 2
- bucket_quota.by_top_axis = 1
- bucket_quota.fallback = remaining

覆盖（coverage）建议：
- strategy：EA/ET/IA/IT（4/4）
- role：NT/NF/SJ/SP（4/4）
- axis：10/10
- topic：以 catalog.topics 为准（至少 5 个）

供给（supply）建议：
- 每个 role KEY：≥ 2 条（满足 by_role 配额）
- 每个 strategy KEY：≥ 2 条（满足 by_strategy 配额）
- 每个 axis KEY：≥ 1 条（满足 by_top_axis 配额）
- fallback：≥ 5 条（保证 remaining 不缺）
- 去重后总量：建议 ≥ 50（可逐步扩容）


3.3 章节卡片库（建议目标量）
--------------------------------------------

对每个章节主库：
- items ≥ 10
- role ≥ 4（先覆盖高频类型/分组）
- axis 每轴 ≥ 1（先覆盖常见状态）
- fallback ≥ 5（长期可用、可解释）


============================================================
4. 可复制命令（MVP 统计输出）
============================================================

4.1 一键脚本（推荐）
--------------------------------------------
PACK_DIR="content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3"
bash backend/scripts/mvp_check.sh "$PACK_DIR"
echo "EXIT=$?"

4.2 期望输出格式（示例）
--------------------------------------------
- Highlights templates：10 行 DIM.SIDE=true（全部 true 才算通过）
- Reads：
  - reads.total_unique=...
  - reads.fallback=...
  - reads.non_empty_strategy_buckets=... => ...
- EXIT=0


============================================================
5. 验收 Checklist（PR 必填）
============================================================

每次内容变更 PR（仅改 JSON）建议在 PR comment 里贴：
1) 环境：CN_MAINLAND/zh-CN
2) bash backend/scripts/mvp_check.sh "$PACK_DIR" 输出（templates 10/10 true；reads 指标满足 MVP）
3) verify_mbti.sh / ci_verify_mbti.sh：EXIT=0
4) 一票否决信号 grep：未命中（GLOBAL/en / fallback to GLOBAL / _deprecated）


============================================================
6. 变更节奏（补库优先级）
============================================================

- P0（救命）：先把 templates 的 dim×side 在 level≥clear 补齐 + reads 至少 2 个 strategy 非空桶 + 章节不空
- P1（变强）：templates 每档都有（clear/strong/very_strong）+ reads 补齐 4 个 strategy 桶 + 章节开始补 role/axis
- P2（运营化）：按数据扩容（多版本 vN、补齐 role/axis/topic，淘汰低表现卡到 fallback/overrides）


============================================================
7. 未来阶段：结果分类层库存（占位）
============================================================

当你把 highlights 物料拆成可机器统计的 “kind + generic/axis/role/fallback” 后，再启用单独的 spec：
- report.highlights.kind = strength/blindspot/action 的覆盖与供给
- 与 verify_mbti.sh 的 kind/数量断言对齐
