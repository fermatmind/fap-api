# Content Ops Playbook（运营只改 JSON 的内容迭代手册）

> 目标：让内容同学/运营在**不改代码**的前提下，通过改 JSON 完成：
> - 替换推荐、修文案、调库存、上线新卡
> - 临时热修（Overrides）
> - 严格验收（verify_mbti / CI）确保**不回退 GLOBAL/en**、不 silent fallback

---

## 0. 先读这个：不可变（Immutable）清单

**本仓库的不可变规则已固定在：** `docs/content_immutable.md`

运营/内容同学在改任何 JSON 前必须了解：
- 固定 section 列表（报告结构）
- 固定 kind 列表（系统认可的卡片类型）
- 固定 ID 命名规则（上线后永不改）

> 如果需要新增 section/kind/ID 规则：必须走“工程变更流程”，不可直接在内容 JSON 里“自创字段/自创规则”。

---

## 1. 内容包分层设计（运营只改 JSON）

### 1.1 分层目标
把内容系统拆成 4 层，做到“改动影响可控、回滚容易、验收明确”。

### 1.2 四层模型（谁能改、改什么）

#### Layer 0：Contract / Schema（工程固定，运营只读）
**职责：** 定义内容包合同（manifest contract）、schema 校验、加载顺序、能力开关。
**文件举例：**
- `manifest.json`
- `schemas/*.json`（如有）
**允许改动：** ❌（工程 PR）
**风险等级：** 极高（改错会影响全链路加载）

#### Layer 1：Content（运营可改：卡片内容、文案、图片引用）
**职责：** 纯内容库（卡片/reads/identity 等），不涉及选择逻辑。
**文件举例：**
- `report_cards_*.json`
- `report_recommended_reads.json`（reads：同文件包含 rules + items）
- `identity_layers.json`
**允许改动：** ✅（运营改 JSON）
**风险等级：** 中（可通过自检 + verify_mbti 拦截）

#### Layer 2：Selection（运营可改但必须可验证：池/规则/优先级）
**职责：** 定义“选哪些卡、怎么选、选多少、兜底策略”。
**文件举例：**
- `report_highlights_pools.json`
- `report_highlights_rules.json`
- `report_recommended_reads.json`（reads：同文件包含 rules + items）
**允许改动：** ✅（运营改 JSON，但必须过验收）
**风险等级：** 高（容易导致缺卡、随机兜底、回退风险）

#### Layer 3：Overrides（运营可改但必须有刹车：线上热修）
**职责：** 热修替换/禁用某些卡或规则，快速止血；必须可追踪、可回滚。
**文件举例：**
- `report_overrides.json` 或 `overrides/*.json`
**允许改动：** ✅（运营改 JSON，但需审批/记录）
**风险等级：** 极高（可能绕过规则逻辑，必须严格约束）

---

## 2. 文件职责与“谁能改”矩阵

> 下面的路径以你的内容包结构为准，按实际仓库填充。

| 文件/目录 | 层级 | 负责人 | 可改内容 | 禁止改动 | 验收要求 |
|---|---|---|---|---|---|
| `manifest.json` | L0 | 工程 | 合同字段、加载顺序 | 任意删字段、随意改 schema_version | `fap:self-check` 必须过 |
| `identity_layers.json` | L1 | 内容 | 文案、展示字段、排序 | id、key 的语义、结构字段 | `verify_mbti` 内容断言过 |
| `report_cards_*.json` | L1 | 内容 | 卡片文案、tag、引用素材 | 卡片 id、kind、必填字段缺失 | `verify_mbti` 规则断言过 |
| `report_highlights_pools.json` | L2 | 内容+产品 | 池分组、权重、标签 | 破坏 schema、空池 | `verify_mbti` 必须过 |
| `report_highlights_rules.json` | L2 | 内容+产品 | 规则优先级、选择条件、min/max | 规则语义字段乱写 | `verify_mbti` 必须过 |
| `overrides/*.json` | L3 | 内容+负责人 | 热修替换/禁用 | 覆盖范围越权、长期挂着不下线 | 强制记录+回滚方案 |

---

## 3. Section → 内容来源 → 规则来源（必须写清楚）

> 固定 section 列表见 `docs/content_immutable.md`
> 这里把每个 section 的“内容来源文件 + 选择规则文件 + 是否允许 overrides”写死。

### 3.1 Sections（固定）
- `identity_card`
- `strengths`
- `blindspots`
- `actions`
- `relationships`
- `career`
- `stress_recovery`
- `growth_plan`
- `reads`

### 3.2 映射表（请按你的实际文件名填写）
| section | 内容来源（L1） | 选择规则（L2） | overrides（L3）允许？ | overrides 范围 |
|---|---|---|---|---|
| identity_card | `report_identity_cards.json`（identity 卡片库）, `identity_layers.json`（层级/展示结构） | （通常无） | ✅ | 只允许改文案/展示字段/排序；禁止改 id 与结构契约字段 |
| strengths | `report_highlights_templates.json`（模板/物料）, `report_highlights_pools.json`（池配置） | `report_highlights_rules.json`, `report_highlights_policy.json` | ✅ | 允许替换/禁用 highlight 目标 id；禁止改 schema/contract |
| blindspots | `report_highlights_templates.json`（模板/物料）, `report_highlights_pools.json`（池配置） | `report_highlights_rules.json`, `report_highlights_policy.json` | ✅ | 同上 |
| actions | `report_highlights_templates.json`（模板/物料）, `report_highlights_pools.json`（池配置） | `report_highlights_rules.json`, `report_highlights_policy.json` | ✅ | 同上 |
| relationships | `report_cards_relationships.json`, `report_cards_fallback_relationships.json` | `report_rules.json`, `report_section_policies.json`, `report_select_rules.json` | ✅ | 只允许替换推荐卡/禁用卡；不改 section 结构字段 |
| career | `report_cards_career.json`, `report_cards_fallback_career.json` | `report_rules.json`, `report_section_policies.json`, `report_select_rules.json` | ✅ | 同上 |
| stress_recovery | `report_cards_growth.json`, `report_cards_fallback_growth.json` | `report_rules.json`, `report_section_policies.json`, `report_select_rules.json` | ✅ | 同上（当前内容包里通常归在 growth 章节里做“压力与恢复”相关卡） |
| growth_plan | `report_cards_growth.json`, `report_cards_fallback_growth.json` | `report_rules.json`, `report_section_policies.json`, `report_select_rules.json` | ✅ | 同上 |
| reads | `report_recommended_reads.json` | `report_recommended_reads.json`（同文件内包含 rules + items） | ✅ | 允许调序/替换/禁用（在同一文件内改 rules/items） |

> 注：
> 1) reads 使用单文件 `report_recommended_reads.json`，**同一份 JSON 同时包含选择规则（rules）+ 物料（items）**；不像 highlights 会拆成 rules/pools/templates。
> 2) 你当前内容包的“章节卡片库”实际是 4 份：traits / relationships / career / growth（对应 `report_cards_traits.json`、`report_cards_relationships.json`、`report_cards_career.json`、`report_cards_growth.json`），其章节选择逻辑主要落在 `report_rules.json` + `report_section_policies.json` + `report_select_rules.json`。

---

## Step 2 库存量规范（Inventory Spec）

> 目的：把“每个 section 需要多少卡、按哪些维度分层、最低库存（MVP）是多少”写成**可验收**的规范，让内容同学只改 JSON 也能稳定迭代。

本 Step 的输出建议沉淀为独立文档：`docs/content_inventory_spec.md`（后续会补）。

本 Step 会做三件事：
1. 把目标量按 **section × kind × 维度**拆成清单（先写规则，不急着填满内容）
2. 定义“最小可用库存（MVP）”：先保证 **highlights templates + reads** 在 `CN_MAINLAND/zh-CN` 下**无回退跑通**
3. 给运营一个可执行的补库节奏：先补通用/兜底，再补 role/axis，最后做精细化扩容

（下一步你会在这里补：库存量表格模板 + MVP 门槛 + 补库优先级。）

### 2.1 目标量拆分规则（只写规则，不要求一次填满）

> 核心：库存按 **section × kind × 维度**拆分。先保证“每个格子都有最低可用数量”，再逐步扩容。

#### 维度定义（固定用这些维度来分库存）
- 通用（generic）：不依赖角色/轴，适合多数人群
- Role（role）：按 MBTI 16 型（或你的 role 分组）定向
- Axis（axis）：按轴向（EI/SN/TF/JP/AT）及其 state/delta 分层（由 rules 决定怎么用）
- Strategy（strategy，仅 reads）：按 reads 的“策略象限”分层（来自 tags `strategy:<KEY>`；允许 KEY：EA/ET/IA/IT）
- Topic（topic，仅 reads）：按内容主题分层（来自 tags `topic:<slug>`；以 `report_recommended_reads.json` 的 `catalog.topics` 为准）
- Fallback（fallback）：兜底卡（必须可用、可控、可验收；兜底不是随机凑数）

---

### 2.2 目标量清单（按“真实文件维度”拆分）

> 说明：下面是“库存目标”的**规则表**，先落规范，后续内容同学只要按表补库即可。
> 本 Step 以“真实资产维度”为准：templates（dim×side×level）、reads（items+rules）、章节 cards（cards+fallback）。
> 「strength/blindspot/action」属于 **report.highlights 的结果分类层**，不在 templates 库存口径里混算，统一挪到 Step 3（未来阶段）。

#### A) Highlights Templates（templates 维度：dim × side × level）

适用文件：`report_highlights_templates.json`

**目标量（建议值，可迭代）：**
- 对每个 dim（EI/SN/TF/JP/AT）
- 对每个 side（E/I, S/N, T/F, J/P, A/T）
- 对 level≥clear 的三档（clear / strong / very_strong）：建议每档至少 1 条模板
  - 也就是：每个 dim×side：clear≥1、strong≥1、very_strong≥1（目标量）
- 低等级（very_weak/weak/moderate）可作为“安全占位”，不计入 MVP 门槛，但可作为未来扩容提升体验。

> 为什么这样拆：你的 templates rules 已声明 `min_level=clear`，运营化必须把“缺模板”当成 FAIL（即使引擎 allow_empty）。

#### B) Reads（阅读/建议内容）
适用文件：`report_recommended_reads.json`（同文件包含 rules + items）

> 重要：reads 的库存不是一个平铺数组，而是“按 bucket 分组”的内容库 + 策略配置。
> 你当前的真实结构是：
> - `.items.by_role.<KEY>[]`
> - `.items.by_strategy.<KEY>[]`
> - `.items.by_top_axis["axis:<DIM>:<SIDE>"][]`
> - `.items.by_type.<TYPE>[]`（可选扩容）
> - `.items.fallback[]`
>
> 因此运营侧的库存盘点口径必须写死：**跨 bucket 汇总后去重（按 id）**，再按 tags 前缀统计覆盖。

##### 目标量（建议值，可迭代）

> 目标量分两层：
> 1) **覆盖（coverage）**：确保每个允许的 key 都有内容，避免某个 bucket 永远选不到
> 2) **供给（supply）**：确保每个 bucket 的配额（quota）能被稳定满足
>
> 以你当前 rules 为准：
> - `bucket_quota.by_role = 2`
> - `bucket_quota.by_strategy = 2`
> - `bucket_quota.by_top_axis = 1`
> - `bucket_quota.fallback = remaining`

**覆盖（coverage）目标：**
- strategy 覆盖：`strategy:EA / strategy:ET / strategy:IA / strategy:IT`（4/4）
- role 覆盖：`role:NT / role:NF / role:SJ / role:SP`（4/4）
- axis 覆盖：`axis:<DIM>:<SIDE>` 共 10 个（EI:E, EI:I, SN:S, SN:N, TF:T, TF:F, JP:J, JP:P, AT:A, AT:T）
- topic 覆盖：以 `catalog.topics` 为准（当前至少 5 个：relationships / communication / career / growth / stress）

**供给（supply）目标（与 quota 对齐）：**
- 对每个 `role:<KEY>`：至少 2 条（保证 by_role 能填满配额）
- 对每个 `strategy:<KEY>`：至少 2 条（保证 by_strategy 能填满配额）
- 对每个 `axis:<DIM>:<SIDE>`：至少 1 条（保证 by_top_axis 能填满配额）
- fallback：至少 5 条（保证 remaining 永远不会“兜底不够用”）
- 全库（去重后）总量：建议 ≥ 50 条（可逐步扩容；先达成 MVP 门槛即可）

##### Strategy / Topic 字典（按真实 JSON）

**Strategy（策略象限，固定允许值）：**
- `strategy:EA`：外放笃定（更倾向外向表达 + 稳定推进）
- `strategy:ET`：外放敏感（更倾向外向表达 + 对反馈更敏感）
- `strategy:IA`：内收笃定（更倾向内向沉淀 + 稳定节奏）
- `strategy:IT`：内收敏感（更倾向内向自省 + 对风险更敏感）

**Topic（主题，来自 `catalog.topics`）：**
- `topic:relationships`
- `topic:communication`
- `topic:career`
- `topic:growth`
- `topic:stress`

##### 规则约束（运营必须遵守）
- reads 的分组与统计以 tags 为准（前缀必须在 `rules.tag_prefixes_allowed` 里）
- `by_role.<KEY>` 下的每条 item：必须带 `role:<KEY>`
- `by_strategy.<KEY>` 下的每条 item：必须带 `strategy:<KEY>`
- `by_top_axis["axis:<DIM>:<SIDE>"]` 下的每条 item：必须带同一个 `axis:<DIM>:<SIDE>`
- fallback 下的 item：必须是“通用可用兜底”，不依赖 role/strategy/axis 才能成立
---

#### C) 其他 sections（traits / relationships / career / stress_recovery / growth_plan / identity_card）
> 这些 section 不走 templates 维度，而是“章节卡片库 + 章节选择规则”。

统一约束（适用于本段所有 section）：
- 维度仍按：generic / role / axis / fallback
- fallback 必须来自对应的 `report_cards_fallback_*.json`（不得用随机生成兜底）
- 章节选择逻辑的规则来源统一归到：`report_rules.json` + `report_section_policies.json` + `report_select_rules.json`
  - （如果未来某 section 拆出专用 rules 文件，再在 3.2 映射表里单独标注）

##### C-1) Traits（对应 strengths/blindspots/actions 之外的“traits 章节卡片库”）
| section | 内容来源（L1 真实文件） | fallback 物料（L1 真实文件） | 选择规则（L2 真实文件） | 通用（generic） | role | axis（每轴） | fallback |
|---|---|---|---|---:|---:|---:|---:|
| traits | `report_cards_traits.json` | `report_cards_fallback_traits.json` | `report_rules.json` + `report_section_policies.json` + `report_select_rules.json` | 10 | 4 | 3 | 5 |

##### C-2) Relationships
| section | 内容来源（L1 真实文件） | fallback 物料（L1 真实文件） | 选择规则（L2 真实文件） | 通用（generic） | role | axis（每轴） | fallback |
|---|---|---|---|---:|---:|---:|---:|
| relationships | `report_cards_relationships.json` | `report_cards_fallback_relationships.json` | `report_rules.json` + `report_section_policies.json` + `report_select_rules.json` | 10 | 4 | 3 | 5 |

##### C-3) Career
| section | 内容来源（L1 真实文件） | fallback 物料（L1 真实文件） | 选择规则（L2 真实文件） | 通用（generic） | role | axis（每轴） | fallback |
|---|---|---|---|---:|---:|---:|---:|
| career | `report_cards_career.json` | `report_cards_fallback_career.json` | `report_rules.json` + `report_section_policies.json` + `report_select_rules.json` | 10 | 4 | 3 | 5 |

##### C-4) Growth / Stress Recovery / Growth Plan（你当前都落在 growth 卡片库里）
> 说明：你内容包里目前是用 `report_cards_growth.json` 承载 growth / stress_recovery / growth_plan 三类主题内容，
> 未来如果拆库（例如 stress_recovery 单独文件），再把这里拆成独立行即可。

| section | 内容来源（L1 真实文件） | fallback 物料（L1 真实文件） | 选择规则（L2 真实文件） | 通用（generic） | role | axis（每轴） | fallback |
|---|---|---|---|---:|---:|---:|---:|
| growth_plan | `report_cards_growth.json` | `report_cards_fallback_growth.json` | `report_rules.json` + `report_section_policies.json` + `report_select_rules.json` | 10 | 4 | 3 | 5 |
| stress_recovery | `report_cards_growth.json` | `report_cards_fallback_growth.json` | `report_rules.json` + `report_section_policies.json` + `report_select_rules.json` | 10 | 4 | 3 | 5 |

##### C-5) Identity Card（不纳入“库存量表”，但要纳入“完整性验收”）
- 主物料：`report_identity_cards.json`（identity 卡片库）
- 展示/层级：`identity_layers.json`
- 不按 generic/role/axis/fallback 计数；改动目标是字段完整、多语言完整、结构不破坏，并且 verify_mbti 不回退。

---

### 2.3 最小可用库存（MVP）定义（先保证不回退跑通）

> MVP 目标：先保证 **CN_MAINLAND / zh-CN** 下，内容包可以稳定生成报告、并且**绝不回退 GLOBAL/en**、不 silent fallback。
> 注意：本阶段的 “Highlights 库存”口径以 **templates 的真实维度（dim×side×level）** 为准，而不是 strength/blindspot/action 这类结果分类。

#### MVP 覆盖范围（必须做到）
- 环境：`REGION=CN_MAINLAND` + `LOCALE=zh-CN`
- 必需资产（至少）：
  - highlights：`report_highlights_templates.json` + `report_highlights_pools.json` + `report_highlights_rules.json` + `report_highlights_policy.json`
  - reads：`report_recommended_reads.json`（同文件包含 `rules + items`）
  - overrides：`report_overrides.json`（允许为空，但必须存在且可解析）
- 验收：`ci_verify_mbti.sh` 和 `verify_mbti.sh` 运行必须全绿（EXIT=0）

#### MVP 最低库存门槛（达不到 = FAIL）

##### A) Highlights Templates（templates 口径：dim × side × level≥clear）
以 `report_highlights_templates.json` 为准做库存盘点。

**固定口径（写死）：**
- templates 实际结构是：`templates.<DIM>.<SIDE>.<LEVEL>`（例如 `templates.EI.E.clear`）
- 你的 templates rules 已声明：
  - `min_level = clear`
  - `allowed_levels` 包含 `clear / strong / very_strong`（以及更低等级，但 MVP 只以 ≥clear 为准）
- 且当前 `allow_empty: true` 代表“引擎可能允许空”；但运营化要加刹车：**缺模板 = FAIL**（即使引擎允许空）。

**最低门槛：**
- 对每个 `dim`（EI / SN / TF / JP / AT）
- 对该 dim 的两个 `side`（固定映射如下）
- 在 `level ∈ { clear, strong, very_strong }` 的集合里：**每个 side 至少命中 1 条模板**
  - 也就是：`clear|strong|very_strong` 至少存在一个对象（并且包含最基本字段如 `id/title/text`）

> side 映射（固定）：
> - EI：E / I
> - SN：S / N
> - TF：T / F
> - JP：J / P
> - AT：A / T

> 说明：templates 只解决“轴向文案物料库是否齐全”。
> `report.highlights.kind`（例如 strength/blindspot/action）属于“生成结果分类”，由 pools/rules/引擎逻辑决定；它的数量范围与覆盖要求，继续由 `verify_mbti.sh` 的规则断言负责（不在这里混算）。

##### B) Reads（read）
以 `report_recommended_reads.json` 为准盘点：

**最低门槛：**
- reads.total_unique ≥ 7（跨所有 bucket 汇总去重，按 `id` 计数）
- fallback ≥ 2（`.items.fallback` 至少 2 条）
- strategy 覆盖 ≥ 2（至少覆盖 2 个不同的 `strategy:<KEY>`）

##### C) 章节卡片库（traits/relationships/career/growth）
> 这一项是“内容完整性”门槛：避免章节空、避免 fallback 被迫承担全部内容。

**最低门槛（每个主库）：**
- 主库（`report_cards_*.json`）items ≥ 5
- fallback（`report_cards_fallback_*.json`）items ≥ 2

#### MVP 的“一票否决”（出现任意一个 = FAIL）
以下任意信号出现，直接判定“不达标”（与 verify_mbti 的硬断言一致）：

- `GLOBAL/en`
- `fallback to GLOBAL`
- `content_packages/_deprecated`

判定范围（至少覆盖）：
- `verify_mbti` / `ci_verify_mbti` 的 stdout
- `backend/artifacts/verify_mbti/` 下的 `report.json` / `share.json` / `logs/*.log`

### 2.3.1 MVP Check（可执行清单）

> 目标：把“库存是否达标”变成**可执行的 yes/no 检查**。
> 环境固定为：`REGION=CN_MAINLAND` + `LOCALE=zh-CN`（不允许自动回退）。

#### A. 必须存在的文件（缺任何一个 = FAIL）
以下文件必须在当前内容包目录存在（例如：`content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/`）：

**Highlights（必需）**
- `report_highlights_templates.json`
- `report_highlights_pools.json`
- `report_highlights_rules.json`
- `report_highlights_policy.json`

**章节卡片库（建议也纳入内容自检，缺失会导致章节空/走兜底）**
- `report_cards_traits.json` + `report_cards_fallback_traits.json`
- `report_cards_relationships.json` + `report_cards_fallback_relationships.json`
- `report_cards_career.json` + `report_cards_fallback_career.json`
- `report_cards_growth.json` + `report_cards_fallback_growth.json`

**Reads（必需）**
- `report_recommended_reads.json`（同文件包含 `rules + items`）

**Overrides（如果启用热修能力则必需）**
- `report_overrides.json`（空文件也可以，但必须存在且可解析）

---

#### B. MVP 最低库存门槛（达不到 = FAIL）

##### B-1) Highlights Templates（按 dim × side × level≥clear 盘点）
**MVP 最低门槛（达不到 = FAIL）：**
- 对每个 `dim`（EI / SN / TF / JP / AT）
- 对该 dim 的两个 `side`（E/I, S/N, T/F, J/P, A/T）
- 在 `level ∈ { clear, strong, very_strong }` 的集合里：每个 side 至少命中 1 条模板

**失败判定（刹车）：**
- 任一 dim 的任一 side 在 `clear|strong|very_strong` 里 **全都缺失** → 直接 FAIL

##### B-2) Reads（read）
**最低门槛：**
- reads.total_unique ≥ 7（跨所有 bucket 汇总去重，按 `id` 计数）
- fallback ≥ 2（`.items.fallback` 至少 2 条）
- strategy 覆盖 ≥ 2（至少覆盖 2 个不同的 `strategy:<KEY>`）

##### B-3) 章节卡片库（traits/relationships/career/growth）
**最低门槛（每个主库）：**
- 主库（`report_cards_*.json`）items ≥ 5
- fallback（`report_cards_fallback_*.json`）items ≥ 2

---

#### C. 一票否决（出现任意一个 = FAIL）
以下任意信号出现，直接判定“不达标”（与 verify_mbti 的硬断言一致）：

- `GLOBAL/en`
- `fallback to GLOBAL`
- `content_packages/_deprecated`

**判定范围（至少要覆盖）：**
- `verify_mbti` / `ci_verify_mbti` 的 stdout 日志
- `backend/artifacts/verify_mbti/` 下的 `report.json` / `share.json` / `logs/*.log`

---

#### D. MVP Check 的验收输出（建议写入每次 PR comment）
每次内容变更 PR（仅改 JSON）至少给出：
- ✅ 当前环境：`CN_MAINLAND/zh-CN`
- ✅ 文件存在性检查：A 全通过
- ✅ MVP 门槛：B 全通过（列出各类实际计数）
- ✅ 一票否决：C 未命中（可贴 grep 结果）
- ✅ verify_mbti / CI：EXIT=0

##### 可复制命令（MVP Check Snippet）

> 目的：下一次只要复制粘贴这段命令，就能复现 PR comment 里的 MVP 统计输出。
> 环境默认按当前内容包：`CN_MAINLAND/zh-CN`。

```bash
PACK_DIR="content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST"

# 1) Highlights templates 覆盖检查（dim×side：level≥clear 是否至少命中一个）
jq -r '
  .templates
  | to_entries[]
  | .key as $dim
  | .value
  | to_entries[]
  | .key as $side
  | [(.value.clear?!=null),(.value.strong?!=null),(.value.very_strong?!=null)]
  | any
  | "\($dim).\($side)=\(.)"
' "$PACK_DIR/report_highlights_templates.json" | sort

# 2) Reads 基本盘点（total_unique / fallback / 非空 strategy 桶）
jq -r '
  def arr_or_empty:
    if . == null then []
    elif (type=="array") then .
    else []
    end;

  def all_items:
    (
      (.items.by_type     | to_entries | map(.value | arr_or_empty) | add // []) +
      (.items.by_role     | to_entries | map(.value | arr_or_empty) | add // []) +
      (.items.by_strategy | to_entries | map(.value | arr_or_empty) | add // []) +
      (.items.by_top_axis | to_entries | map(.value | arr_or_empty) | add // []) +
      (.items.fallback    | arr_or_empty)
    )
    | map(select(type=="object"));

  def uniq_by_id: unique_by(.id);

  (all_items | uniq_by_id) as $U
  | "reads.total_unique=" + ($U|length|tostring),
    "reads.fallback=" + ((.items.fallback|arr_or_empty|length) | tostring),
    "reads.non_empty_strategy_buckets=" + (
      (.items.by_strategy
        | to_entries
        | map(select((.value|arr_or_empty|length) > 0))
        | map(.key)
      ) as $keys
      | ($keys|length|tostring) + " => " + ($keys|join(","))
    )
' "$PACK_DIR/report_recommended_reads.json"

---

### 2.4 补库优先级（固定补库节奏：先救命，再变强）

> 原则：任何补库都必须以“verify_mbti/CI 全绿”为前提。
> Step 2 的补库节奏以 **templates + reads + 章节 cards/fallback** 为主；
> strength/blindspot/action 属于“结果分类层”，统一放到 Step 3（未来阶段）做。

#### P0（必须先做，缺任何一个都不允许上线）
目标：保证 `CN_MAINLAND/zh-CN` 下，**模板库不缺口 + reads 可推荐 + 章节不空**，并且不回退 GLOBAL/en。

- Highlights Templates：先补齐 dim×side 在 `level≥clear` 的缺口
  - 每个 dim 的每个 side：`clear|strong|very_strong` 至少命中 1 条（缺任何一个 = FAIL）
- Reads：先保证 “不会只剩兜底”
  - items≥7、generic≥5、fallback≥2、strategy 覆盖≥2
- 章节卡片库：避免章节空
  - 每个主库 items≥5、fallback items≥2
- 验收：跑 `ci_verify_mbti.sh` / `verify_mbti.sh` 必须 EXIT=0；并且一票否决信号不出现

#### P1（强烈建议：开始提升“稳定性与可解释性”）
目标：让模板命中更稳定（减少空结果/低质量兜底），reads 更可运营（主题更全）。

- Highlights Templates：把 “≥clear 仅 1 条” 扩成更稳定覆盖
  - 建议每个 dim×side：clear≥1、strong≥1、very_strong≥1（至少做到“每档都有”）
- Reads：补齐 strategy 六主题通用 read
  - 六主题每个 ≥ 1（generic）
- 章节卡片库：开始补 axis/role 的最小覆盖
  - 每个主库：axis 每轴≥1（先补常见状态），role≥2（先覆盖高频类型/分组）
- 验收：CI 全绿；并能在 PR comment 里给出“模板覆盖统计 + reads 策略覆盖统计”

#### P2（运营化扩容：开始做“人群定向”）
目标：把内容供给做成可长期增长的“库存系统”，可按数据驱动扩容。

- Highlights Templates：按数据补齐更细颗粒度的模板版本
  - 同一 dim×side×level：增加多版本 `vN`（不改旧 id，只增新版本）
- Reads：补 role/axis 定向 read
  - role ≥ 4；axis 每轴 ≥ 1（按优先级）
- 章节卡片库：role 扩到 4；axis 每轴扩到 2~3（按数据反馈）
- 验收：仍以 verify_mbti/CI 为硬闸；任何回退信号一票否决

---

## Step 3（未来阶段）：结果分类层库存（report.highlights.kind）

> 这一段是“未来阶段/结果分类层”的库存规范：它描述的是 **report.highlights 结果里 kind=strength/blindspot/action** 的供给与定向。
> 重要：这不是 templates 文件的维度（templates 只有 dim×side×level），而是由 `report_highlights_pools.json` / `report_highlights_rules.json` / 引擎生成逻辑决定的“结果分类层”。

### 3.1 结果分类层目标量（未来阶段建议）
> 当你把 highlights 的物料进一步拆成 “kind + generic/axis/role/fallback” 并可机器统计时，再启用这一段。

| kind | 通用（generic） | role | axis（每轴） | fallback |
|---|---:|---:|---:|---:|
| strength | 10 | 4 | 3 | 5 |
| blindspot | 10 | 4 | 3 | 5 |
| action | 10 | 4 | 3 | 5 |

**说明（结果分类层）**
- 这张表的库存统计口径必须“可机器统计”（例如通过 id 规范、tag 规范或 pools 分组），否则运营会填错库。
- 结果分类层的覆盖要求（例如必须包含 blindspot+action、数量范围 3~4、禁止 generated_ 等）继续由 `verify_mbti.sh` 的规则断言负责。

---

## 4. 版本化与发布策略（只改 JSON 也能上线）

### 4.1 内容版本号原则
- 内容包版本：`MBTI-CN-vX.Y.Z`（示例），每次发布递增
- 卡片版本：卡片 id 里的 `vN` 只增不改（旧版不删除，最多下线）
- rules/pools 变更：建议跟随内容包版本一起发

### 4.2 允许的发布类型
- **常规发布**：内容更新 + rules 更新 + 验收通过 → 发布
- **热修发布（Overrides）**：仅 overrides 变更 → 必须有记录 + 过验收 → 上线 → 规定时间内撤销

---

## 5. 最小工作流（运营只改 JSON）

### 5.1 本地改内容（内容同学）
1. 新建分支（内容分支）
2. 改 L1（cards/reads/identity JSON）
3.（如需要）改 L2（rules/pools JSON）
4. 运行自检：`fap:self-check`
5. 运行验收：`verify_mbti`（本机/CI 任一方式）
6. 提 PR → 等 CI 绿 → 合并

### 5.2 热修（Overrides）
1. 新建 `hotfix/overrides-YYYYMMDD` 分支
2. 只改 L3 overrides 文件（禁止夹带 L1/L2 大改）
3. 跑 `verify_mbti`（必须）
4. PR 描述里写：原因、影响范围、回滚方式、预计撤销时间
5. 合并上线后，设置撤销提醒（到期必须撤）

---

## 6. 验收标准（运营知道“什么叫可上线”）

### 6.1 必过项（任何内容发布）
- `fap:self-check` 通过（manifest/schema/assets）
- `verify_mbti` 通过（内容生效/规则生效/覆写生效）
- 禁止信号不出现：`GLOBAL/en`、`fallback to GLOBAL`、`content_packages/_deprecated`（由脚本硬断言）

### 6.2 内容生效（Content）
- report/share 中的 `content_pack_id` 必须是目标 pack（不得回退）
- locale/region 必须匹配（正向断言）
- 不允许 silent fallback

### 6.3 规则生效（Rules）
- highlights 数量在范围内（如 3~4）
- kind 必须覆盖 blindspot + action（按你的标准）
- 禁止 id 双前缀、禁止 borderline 等（按你的标准）

### 6.4 覆写生效（Overrides）
- D-1/D-2/D-3 都必须过
- 覆写来源透明可追踪（log/marker）

---

## 7. 给内容同学的“文档目录”（建议落在 docs/）

> 你可以把这些文档逐步补齐（先写最小版，再迭代）。

- `docs/content_immutable.md`（已完成：不可变三件套）
- `docs/content_ops_playbook.md`（本文件：运营手册入口）
- `docs/content_inventory_spec.md`（库存量规范：目标量怎么落地）
- `docs/card_writing_spec.md`（卡片写作规范：写卡标准）
- `docs/rules_writing_spec.md`（规则编写规范：可改但必须可验证）
- `docs/overrides_hotfix_spec.md`（Overrides 热修规范：能改但要刹车）
- `docs/release_acceptance_checklist.md`（发布验收清单：可直接打勾）

---

## 8. 下一步（你现在该做什么）
1) 先把「3. Section → 来源 → 规则 → overrides」这张映射表补全（按你的真实文件名）
2) 然后再去写库存量规范（content_inventory_spec.md）
3) 最后再写写卡规范 / 写规则规范 / overrides 规范（逐步迭代）

> 原则：先把“结构和边界”写死，再谈“写作与库存”。
