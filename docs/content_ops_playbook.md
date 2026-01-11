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
2. 定义“最小可用库存（MVP）”：先保证 `strength/blindspot/action/read` 在 `CN_MAINLAND/zh-CN` 下**无回退跑通**
3. 给运营一个可执行的补库节奏：先补通用/兜底，再补 role/axis，最后做精细化扩容

（下一步你会在这里补：库存量表格模板 + MVP 门槛 + 补库优先级。）

### 2.1 目标量拆分规则（只写规则，不要求一次填满）

> 核心：库存按 **section × kind × 维度**拆分。先保证“每个格子都有最低可用数量”，再逐步扩容。

#### 维度定义（固定用这些维度来分库存）
- 通用（generic）：不依赖角色/轴，适合多数人群
- Role（role）：按 MBTI 16 型（或你的 role 分组）定向
- Axis（axis）：按轴向（EI/SN/TF/JP/AT）及其 state/delta 分层（由 rules 决定怎么用）
- Strategy（strategy，仅 reads）：按阅读策略/主题路径分层（例如：沟通策略/情绪调节策略/成长策略）
- Fallback（fallback）：兜底卡（必须可用、可控、可验收；兜底不是随机凑数）

---

### 2.2 目标量清单（按 section × kind × 维度）

> 说明：下面是“库存目标”的**规则表**，先落规范，后续内容同学只要按表补卡即可。
> - 数量是“每个 section 的目标库存”
> - kind 若不适用可写 N/A（例如 identity_card 不一定有 highlight kind）
> - Axis“每轴若干”先给一个最低值（建议 3），后续按数据反馈扩容

#### A) Highlights 三类（strength / blindspot / action）
适用 section：`strengths / blindspots / actions`

| section | kind | 通用（generic） | role | axis（每轴） | fallback |
|---|---|---:|---:|---:|---:|
| strengths | strength | 10 | 4 | 3 | 5 |
| blindspots | blindspot | 10 | 4 | 3 | 5 |
| actions | action | 10 | 4 | 3 | 5 |

**补充说明（Highlights）**
- “role=4”不是指覆盖 16 型，而是指**每个 section 至少准备 4 张 role 定向卡**（先覆盖高频/关键类型）。后续扩容到 16 型全覆盖时，另开“扩容计划”。
- “axis=每轴 3”表示 EI/SN/TF/JP/AT 各至少 3 张可用卡（先保证能按规则挑到，不触发兜底）。
- fallback=5 必须是“可解释兜底”：写明兜底触发条件与使用范围（避免成为随机垃圾桶）。

---

#### B) Reads（阅读/建议内容）
适用 section：`reads`

| section | kind | 通用（generic） | role | strategy | axis | fallback |
|---|---|---:|---:|---:|---:|---:|
| reads | read | 10 | 4 | 6 | 3 | 5 |

**补充说明（Reads）**
- strategy=6：先按你最常用的 6 个策略主题分组（例如：沟通/情绪/关系/职业/压力/成长），每组至少 1 张。
- axis=3：同样每轴至少 3 张（或按你 reads 规则实际需要调整）。
- fallback=5：reads 的兜底必须“无敏感建议、无医疗承诺、无夸张结论”，且可以长期使用。

##### Strategy 六主题（固定字典，不随意加减）

> 目的：让 reads 的 strategy 维度“可写、可补库、可验收”。  
> 运营/内容新增 read 卡时必须选择其中一个 strategy_key；不得自创新 key（需要扩展时走工程评审）。

固定六主题（strategy_key → 含义 → 典型适用场景）：

1) `strategy.communication`（沟通协作）
- 适用：表达/倾听/冲突沟通/边界沟通/说服与对齐

2) `strategy.emotion`（情绪调节）
- 适用：焦虑/愤怒/低落/自责/情绪复盘/情绪急救

3) `strategy.relationship`（关系经营）
- 适用：亲密关系/朋友/同事/家庭关系/信任修复/依恋与安全感

4) `strategy.career`（职业发展）
- 适用：职业选择/成长路径/晋升与影响力/决策/领导力/工作习惯

5) `strategy.stress`（压力与恢复）
- 适用：压力识别/恢复节律/能量管理/睡眠与作息/过载预警

6) `strategy.growth`（成长与习惯）
- 适用：目标拆解/行动计划/自控力/复盘/长期习惯与系统搭建

规则约束（必须遵守）：
- 每张 read 必须且只能选 1 个 `strategy_key`
- MVP 阶段：六主题每个至少 1 张通用 read（generic）
- 扩容阶段：每个主题逐步补齐 role/axis 定向 read（按优先级）

---

#### C) 其他 sections（traits / relationships / career / stress_recovery / growth_plan / identity_card）
> 这些 section 不走 highlights（strength/blindspot/action）那套规则，而是“章节卡片库 + 章节选择规则”。
> 这里不再用抽象的 section_card，而是按你内容包里真实存在的文件做库存规范，避免内容同学填错库。

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

> MVP 目标：先保证 **CN_MAINLAND / zh-CN** 下，`strength + blindspot + action + read` 这四类在 E2E 验收里**绝不回退 GLOBAL/en、且不靠随机兜底凑数**。

#### MVP 覆盖范围（必须做到）
- 环境：`REGION=CN_MAINLAND` + `LOCALE=zh-CN`
- kinds：`strength / blindspot / action / read`
- 验收：`ci_verify_mbti.sh` 和 `verify_mbti.sh` 运行必须全绿

#### MVP 最低库存门槛（每类最少）
- strength：通用 ≥ 5，fallback ≥ 2（role/axis 可以先不全，但建议至少有 1）
- blindspot：通用 ≥ 5，fallback ≥ 2
- action：通用 ≥ 5，fallback ≥ 2
- read：通用 ≥ 5，fallback ≥ 2，strategy ≥ 2

> 说明：这是“能跑通且不回退”的最低门槛。达到 MVP 后，再按 2.2 的目标量扩容。

#### MVP 的“禁止情况”（出现即视为不达标）
- verify_mbti / CI 日志出现 `GLOBAL/en`、`fallback to GLOBAL`、`content_packages/_deprecated`
- highlights/read 因缺卡而出现“随机生成/不可解释兜底”（你现有规则里如果有 generated_ 前缀，就必须被禁止或显式标注并验收）
- rules/pools 中出现空池、或 min/max 无法满足导致兜底吞掉错误

---

### 2.4 补库优先级（固定补库节奏：先救命，再变强）

> 原则：任何补库都必须以“verify_mbti/CI 全绿”为前提。  
> 先补“不会回退/不会随机兜底”的库存，再补“更精准的定向内容”。

#### P0（必须先做，缺任何一个都不允许上线）
目标：保证 `CN_MAINLAND/zh-CN` 下，strength/blindspot/action/read 全链路可选到内容，不触发 GLOBAL/en，不依赖随机生成。
- Highlights 三类：先补 `generic + fallback`
  - strength/blindspot/action：generic ≥ 5，fallback ≥ 2
- Reads：先补 `generic + fallback + strategy`
  - read：generic ≥ 5，fallback ≥ 2，strategy ≥ 2（至少覆盖 2 个主题）
- 验收：跑 `ci_verify_mbti.sh` 必须 EXIT=0

#### P1（强烈建议，开始提升“选择质量”）
目标：减少 fallback 触发频率，让规则命中更稳定、更可控。
- Highlights：补 axis（每轴至少 1~2 张）
  - EI/SN/TF/JP/AT：每轴 ≥ 1（优先补你最常见 state）
- Reads：补齐 strategy 六主题的通用 read
  - 六主题每个 ≥ 1 张（generic）

#### P2（运营化扩容：开始做“人群定向”）
目标：让内容更像 123test 那种“长期可迭代的内容供给”。
- Highlights：补 role 定向
  - 每个 kind 先做到 role ≥ 4（先覆盖高频类型/关键分组）
- Reads：补 role 定向 + axis 定向
  - role ≥ 4；axis 每轴 ≥ 1

#### P3（精细化：数据驱动补库）
目标：按线上数据（点击/完读/转化/投诉/收藏）做增量迭代。
- 把低表现卡下线到 fallback 或通过 overrides 临时替换
- 对高表现主题做“同主题多版本 vN”扩容（保持 id 规则不变，只增版本）
- 每次内容迭代必须产出：变更清单 + 验收截图/日志 + 回滚方案（如涉及 overrides）

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