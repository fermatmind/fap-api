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
- `reads_*.json`
- `identity_layers.json`
**允许改动：** ✅（运营改 JSON）
**风险等级：** 中（可通过自检 + verify_mbti 拦截）

#### Layer 2：Selection（运营可改但必须可验证：池/规则/优先级）
**职责：** 定义“选哪些卡、怎么选、选多少、兜底策略”。  
**文件举例：**
- `report_highlights_pools.json`
- `report_highlights_rules.json`
- `reads_pools.json`
- `reads_rules.json`
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
| identity_card | `identity_layers.json` | （通常无） | ✅/❌ | 只允许改文案/排序，不改 id |
| strengths | `report_cards_strength.json`（示例） | `report_highlights_rules.json` | ✅ | 允许替换卡 id / 禁用某卡 |
| blindspots | `report_cards_blindspot.json`（示例） | `report_highlights_rules.json` | ✅ | 同上 |
| actions | `report_cards_action.json`（示例） | `report_highlights_rules.json` | ✅ | 同上 |
| relationships | `report_cards_relationships.json`（示例） | `section_rules_relationships.json`（示例） | ✅ | 只允许替换推荐卡，不改结构 |
| career | `report_cards_career.json`（示例） | `section_rules_career.json`（示例） | ✅ | 同上 |
| stress_recovery | `report_cards_stress.json`（示例） | `section_rules_stress.json`（示例） | ✅ | 同上 |
| growth_plan | `report_cards_growth.json`（示例） | `section_rules_growth.json`（示例） | ✅ | 同上 |
| reads | `reads_cards.json`（示例） | `reads_rules.json`（示例） | ✅ | 允许调序/替换/禁用 |

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