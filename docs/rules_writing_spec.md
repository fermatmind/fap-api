# Rules Writing Spec（规则编写规范：只改 JSON 也能可验证）

目标：让内容/运营在**不改代码**的前提下，改动 rules（选择逻辑/优先级/配额）仍然做到：
- 可理解：规则意图清晰、字段含义统一
- 可验证：`verify_mbti` / `ci_verify_mbti` 一票否决缺卡/回退/乱选
- 可回滚：任何规则变更都能快速 revert
- 可扩容：先 MVP 可用，再按数据逐步补库

> 本文只写“怎么写规则、怎么验收”。不可变项（section/kind/ID 命名）请先读：`docs/content_immutable.md`
> 库存门槛/MVP 口径请读：`docs/content_inventory_spec.md`

---

## 0. 适用范围（Scope）

- REGION：`CN_MAINLAND`
- LOCALE：`zh-CN`
- 内容包示例：`content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/`
- 规则主要分两类：
  1) **Highlights 规则**（拆文件）：`report_highlights_policy.json` / `report_highlights_rules.json` / `report_highlights_pools.json`
  2) **章节卡片规则**（通用选择）：`report_rules.json` / `report_section_policies.json` / `report_select_rules.json`
  3) **Reads 规则**（同文件）：`report_recommended_reads.json`（rules+items 同文件）

---

## 1. 分层与职责边界（必须遵守）

### 1.1 Layer 规则（复述，防越权）
- L1 Content：只写卡片内容/素材引用，不写选择逻辑
- L2 Selection：rules / pools / policies（**本文重点**）
- L3 Overrides：热修止血（另见：`docs/overrides_hotfix_spec.md`）

### 1.2 规则允许做什么 / 不允许做什么
✅ 可以：
- 调整“选几张、优先级、兜底策略、桶配额（quota）”
- 添加新规则分支（以可验证为前提）
- 调整池（pools）的分组/权重/标签匹配（以可统计为前提）

🚫 禁止：
- 自创 section/kind/字段（违反 contract/immutable）
- 依赖“随机兜底”掩盖缺库（会导致不可解释）
- 通过规则变更让系统回退到 `GLOBAL/en` 或 `_deprecated`
- 引入“规则隐式回退”：看似通过但实际走 generated_/fallback

---

## 2. 命名与可读性规范（让规则能被人读懂）

### 2.1 每条规则必须写清 4 件事（在 JSON 允许的字段里表达）
1) **目的（intent）**：这条规则要解决什么（例如“确保 action 必出”、“优先 axis 定向”）
2) **范围（scope）**：作用在什么 section/kind 或哪个 selector 上
3) **条件（condition）**：命中条件是什么（轴、role、strategy、topic、阈值等）
4) **结果（effect）**：命中后选多少、从哪选、失败怎么兜底

> 如果 schema 不支持注释字段，必须在 PR 描述里写清楚这 4 件事。

### 2.2 规则 ID / key 命名（建议）
- 规则 id：`rule.<domain>.<purpose>.vN`
  - 例：`rule.highlights.ensure_action.v1`
- 池 id：`pool.<domain>.<group>.vN`
  - 例：`pool.highlights.axis_templates.v1`
- policy id：`policy.<domain>.<name>.vN`

规则只增不改（vN 递增），旧规则要么下线要么保留（看 schema 是否支持 enable/disabled）。

---

## 3. 约束：必须与库存口径对齐（写规则前先看库存是否够）

### 3.1 Highlights（templates 口径）强约束
- templates 的真实维度是 `dim × side × level`（见 inventory spec）
- rules 中如果声明 `min_level=clear`，运营验收必须把“缺模板”定义为 FAIL
- pools/rules **不能把缺模板** 推给 generated_ 或不可解释 fallback

### 3.2 Reads（bucket + tags 口径）强约束
- reads 的选择是**跨 bucket 汇总**，不能把某个 bucket 永久写死为“空也没关系”
- bucket_quota 的调整必须与供给（supply）对齐：
  - by_strategy quota=2 → 每个 strategy bucket 建议 ≥2 条
  - by_role quota=2 → 每个 role bucket 建议 ≥2 条
  - by_top_axis quota=1 → 每个 axis bucket 建议 ≥1 条
- MVP 门槛：`total_unique≥7`、`fallback≥2`、`non_empty_strategy_buckets≥2`

---

## 4. 写规则的“黄金路径”（先稳后强）

### 4.1 P0（先跑通）
优先保证：
- 不回退：无 `GLOBAL/en` / `fallback to GLOBAL` / `_deprecated`
- 不缺卡：highlights 数量范围满足、kind 覆盖满足
- templates 覆盖 10/10 true（dim×side level≥clear 任意命中）
- reads 至少 2 个 strategy 非空桶

### 4.2 P1（提升稳定性）
- templates：同一 dim×side 至少 clear/strong/very_strong 三档各≥1（减少重复）
- reads：补齐 4 个 strategy（EA/ET/IA/IT）与 role/axis 覆盖
- 章节卡：开始补 role/axis 定向供给，减少 fallback 压力

### 4.3 P2（运营化扩容）
- 支持多版本 vN（同维度多条可选）
- 规则更细：按数据微调优先级、权重、阈值
- 低表现内容进入 fallback/下线

---

## 5. 规则改动的验收硬闸（必须过）

### 5.1 本地（建议每次都跑）
```bash
# 0) 清理尾随空格
sed -i '' -E 's/[[:space:]]+$//' content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/*.json
git diff --check

# 1) MVP 统计（templates + reads）
PACK_DIR="content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3"
bash backend/scripts/mvp_check.sh "$PACK_DIR"
echo "EXIT=$?"

# 2) 全链路硬闸（self-check + MVP gate + verify_mbti + overrides D）
bash backend/scripts/ci_verify_mbti.sh
```

### 5.2 CI（必须全绿）
- `fap:self-check` 通过（contract/assets/schema）
- `MVP check` 通过（templates+reads）
- `verify_mbti` 通过（包含 highlights contract 断言）
- 禁止信号不出现：`GLOBAL/en` / `fallback to GLOBAL` / `_deprecated`
- 需要看回溯：`backend/artifacts/verify_mbti/logs/mvp_check.log` + `summary.txt`

---

## 6. 常见“规则坑”与修复方式

### 6.1 调高 quota 但库存没补 → 结果不稳定/走兜底
**症状**：报告偶发缺卡、或出现 generated_/fallback
**修复**：先补库，再调 quota；或临时把 quota 降回可满足的水平。

### 6.2 条件过严 → 规则永远命不中
**症状**：某类定向（role/axis）几乎不出现
**修复**：把条件拆成两层：严条件 → 宽条件 → fallback；并确保每层都有供给。

### 6.3 隐式回退（看似 OK，实则回退 GLOBAL）
**症状**：verify_mbti grep 出 `GLOBAL/en` 或 `fallback to GLOBAL`
**修复**：先修 content pack / manifest / assets；规则层禁止引导回退路径。

### 6.4 用 overrides 代替规则长期配置
**症状**：overrides 文件越来越大且长期不撤
**修复**：把长期逻辑下沉到 L1/L2；overrides 只保留短期止血并设 expires_at。

---

## 7. PR 模板（建议复制到 PR 描述）

- 变更范围：哪些 rules/pools/policies 文件
- 意图（intent）：这次规则要解决什么
- 风险点：可能导致缺卡/回退/兜底的地方
- 验收证据：
  - `bash backend/scripts/ci_verify_mbti.sh`（EXIT=0）
  - `backend/artifacts/verify_mbti/logs/mvp_check.log`（PASS）
- 回滚方式：revert commit

---

## 8. 最小示例（写法风格示意，不绑定 schema）

> 你们最终字段以 schema/contract 为准。这里展示“信息该怎么组织”，以便运营读得懂。

- policy：写清“目标数量范围 / 必含 kind / 允许兜底”
- rule：写清“条件 + 来源池 + 配额 + 失败链路”
- pool：写清“标签/维度分组”，确保可统计

---

（完）
