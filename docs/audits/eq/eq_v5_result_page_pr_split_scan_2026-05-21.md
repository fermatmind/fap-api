# EQ 结果页 v5 升级全链路扫描与 PR 拆分报告

扫描日期：2026-05-21
扫描类型：只读全链路扫描 + PR train 拆分规划
后端仓库：`/Users/rainie/Desktop/GitHub/fap-api`
前端仓库：`/Users/rainie/Desktop/GitHub/fap-web`
报告文件：`/Users/rainie/Desktop/GitHub/fap-api/docs/audits/eq/eq_v5_result_page_pr_split_scan_2026-05-21.md`

## 1. Executive Summary

- 扫描开始时两个仓库均为干净工作区：`fap-api git status --short` 无输出，`fap-web git status --short` 无输出。
- 当前 EQ_60 实际题库是完整 60 题：`zh-CN` 60 题、`en` 60 题，四维 `SA/ER/EM/RM` 各 15 题，反向题 16 道。
- 当前后端注册信息仍存在题数文案不一致：`ScaleRegistrySeeder.php` 的 EQ_60 `content_i18n` 仍写 `questions: 50`，但题库、start API、测试均是 60。
- 当前 `ScalesController.php` 的 EQ questions fallback `dimension_codes` 存在 `SE` typo：fallback 为 `['SA','ER','SE','RM']`，正常 pack 返回 `EM`，但 fallback 分支仍有风险。
- 当前 EQ_60 后端仍是付费/解锁模型：`view_policy.free_sections = ['intro','summary']`，`blur_others = true`，`upgrade_sku = SKU_EQ_60_FULL_299`，`commercial_json.report_unlock_sku = SKU_EQ_60_FULL_299`。
- 当前 EQ_60 content pack 的真实 section 是 `disclaimer_top/quality_notice/global_overview/self_awareness/emotion_regulation/empathy/relationship_management/cross_quadrant_insight/action_plan_14d/methodology/disclaimer_bottom`，与 registry 的 `intro/summary` 不匹配。
- 当前 EQ_60 report pack 有 102 个 blocks，其中 74 个 free、28 个 paid；`cross_quadrant_insight` 和 `action_plan_14d` 仍是 paid。
- 当前 report payload 是 `eq_60.report.v2` section/block 结构，带 `quality/scores/report/report_tags`，但没有 v5 所需的 `report_state`、`measurement_type`、`scores.dimensions`、`dimension_summary`、`interpretation`、`next_module`、`methodology.report_version`。
- 当前 report-access payload 是统一访问投影结构，默认 result 存在但 projection 缺失时会返回 `access_state=locked`、`report_state=ready`；EQ 没有 Big Five/Enneagram/RIASEC 那样的免费 ready override。
- 当前 `ReportGatekeeper` 和 `AccessResolver` 即使在 `paywall_mode=free_only/off` 下，也没有把 EQ 明确纳入全量免费 full access 白名单；只改 registry 不足以完成“所有 EQ 结果免费”。
- 当前前端没有 `components/result/eq/**`，EQ_60 走 `RichResultReport` 通用 renderer 或 `/result` fallback，不具备 v5 的 EQ 专属解释路径。
- 当前 `ResultClient` 对非 MBTI 的 locked report-access 不会加载 report；EQ 若 report-access 仍 locked，会降级到 `/attempts/{id}/result`，无法进入 v5 报告渲染。
- 当前 `RichResultReport` 的通用 gate 会按 `locked/free_sections/modules_allowed/access_level` 过滤和锁定 section；即便后端把部分 section 标 free，只要 access/module 策略不一致仍可能误锁。
- 当前前端通用 renderer 会显示部分原始 tags；过滤前缀只覆盖 `axis/state/type/role/strategy/borderline`，不覆盖 EQ 当前的 `profile:*`、`quality_level:*`、`bucket:*`、`focus:*`。
- 建议先做 `PR-EQ-V5-01`：后端 EQ 免费报告合同修正。没有这个 PR，前端 v5 renderer 会被 locked/report-access/free_sections 旧合同反复击穿。

## 2. Current Backend State

### 2.1 Preflight

扫描前按要求执行：

```bash
cd /Users/rainie/Desktop/GitHub/fap-api
git status --short

cd /Users/rainie/Desktop/GitHub/fap-web
git status --short
```

结果：两个命令均无输出。扫描开始时没有未提交改动。

### 2.2 Content Pack 与题库状态

当前存在两套 EQ content pack 目录：

- `backend/content_packs/EQ_60/v1`
- `backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1`

两套目录结构一致，均包含：

- `compiled/golden_cases.compiled.json`
- `compiled/landing.compiled.json`
- `compiled/manifest.json`
- `compiled/options.compiled.json`
- `compiled/policy.compiled.json`
- `compiled/questions.compiled.json`
- `compiled/report.compiled.json`
- `raw/blocks/free_blocks.json`
- `raw/blocks/paid_blocks.json`
- `raw/golden_cases.csv`
- `raw/landing_i18n.json`
- `raw/options_eq60_bilingual.json`
- `raw/policy.json`
- `raw/questions_eq60_bilingual.csv`
- `raw/report_layout.json`
- `raw/variables_allowlist.json`

`EQ_60/v1/compiled/questions.compiled.json` 当前状态：

| 项 | 当前值 |
| --- | --- |
| zh-CN 题数 | 60 |
| en 题数 | 60 |
| 维度 | `SA`, `ER`, `EM`, `RM` |
| 每维题数 | 15 |
| 反向题数 | 16 |
| 反向题 ID | `5,10,11,15,18,20,22,23,29,30,31,42,44,52,53,57` |

结论：题库、维度、反向题和选项锚点整体完整。题库本身不需要在 v5.0 PR 中修改。

### 2.3 Policy、常模与质量规则

`EQ_60/v1/compiled/policy.compiled.json` 当前关键内容：

- `dimension_codes`: `SA/ER/EM/RM`
- `dimension_map`: 四维各 15 题
- `validity_rules`:
  - speeding: C `<120s`, D `<75s`
  - longstring: C `>=25`, D `>=35`
  - extreme_rate: C `>=0.85`
  - neutral_rate: C `>=0.7`
  - inconsistency: C `>=18`, D `>=24`
- `bootstrap_norms.status`: `PROVISIONAL`
- `bootstrap_norms.version`: `bootstrap_v1`
- `bootstrap_norms.group`: `locale_all_18-60`
- 标准分均值/标准差：100/15，clamp 55-145
- 当前等级命名：`baseline/developing/competent/proficient/exceptional`

v5 需要的等级命名是 `foundational/developing/stable/proficient/integrated`。建议不要在 PR-EQ-V5-01 改 scoring 语义，而是在 composer 层做 v5 band label 映射，或在专门 scoring semantics PR 中处理。

### 2.4 当前 Report Sections

`EQ_60/v1/compiled/report.compiled.json` 当前 section：

| Section | access_level | module_code |
| --- | --- | --- |
| `disclaimer_top` | free | `eq_core` |
| `quality_notice` | free | `eq_core` |
| `global_overview` | free | `eq_core` |
| `self_awareness` | free | `eq_core` |
| `emotion_regulation` | free | `eq_core` |
| `empathy` | free | `eq_core` |
| `relationship_management` | free | `eq_core` |
| `cross_quadrant_insight` | paid | `eq_cross_insights` |
| `action_plan_14d` | paid | `eq_growth_plan` |
| `methodology` | free | `eq_core` |
| `disclaimer_bottom` | free | `eq_core` |

Block 统计：

| access_level | block 数 |
| --- | ---: |
| free | 74 |
| paid | 28 |

结论：当前内容资产仍按 free/paid blocks 组织，不符合“所有 EQ 结果免费，报告深度由模块完成度决定”的 v5 策略。

### 2.5 Scoring 与 Driver

`backend/app/Services/Assessment/Drivers/Eq60Driver.php` 当前行为：

- 要求答案数量等于 question index 数量，错误消息为 `EQ_60 requires exactly 60 answers.`
- 返回 `breakdownJson.score_result`
- 返回 `axisScoresJson.scores_json.dim_scores`
- 返回 `axisScoresJson.scores_pct`，含 `SA/ER/EM/RM/TOTAL`
- `normedJson` 保存 scorer 完整 payload

`backend/app/Services/Assessment/Scorers/Eq60ScorerV1NormedValidity.php` 当前输出：

- `scale_code = EQ_60`
- `engine_version = v1.0_normed_validity`
- `quality.level/flags/metrics`
- `norms.status/version/group/source`
- `scores.global`
- `scores.SA/ER/EM/RM`
- alias scores：`self_awareness/emotion_regulation/empathy/relationship_management`
- `report.primary_profile`
- `report.tags`
- `report_tags`

缺口：

- 没有 `scores.dimensions`
- 没有 `dimension_summary`
- 没有 `quality.confidence_label`
- 没有 `quality.explanation_asset_id`
- 没有 `report_state`
- 没有 `measurement_type`
- 没有 v5 `interpretation` 对象
- 没有 `core_formulation_id`
- 没有 `development_lever`
- 没有 `primary_mechanism_ids`
- 没有 `primary_scene_ids`
- 没有 `career_environment_ids`
- 没有 `action_prescription_id`
- 没有 `next_module`
- 没有 `methodology.report_version/content_version`

### 2.6 Report Composer 与 Report Payload

`backend/app/Services/Report/Eq60ReportComposer.php` 当前 `composeVariant` 返回：

```json
{
  "ok": true,
  "report": {
    "schema_version": "eq_60.report.v2",
    "scale_code": "EQ_60",
    "variant": "free|full|partial",
    "locale": "zh-CN|en",
    "sections": [],
    "compat": {
      "free_blocks": [],
      "paid_blocks": []
    },
    "quality": {},
    "scores": {},
    "report": {},
    "report_tags": [],
    "generated_at": "..."
  }
}
```

Composer 当前根据 `variant`、`module_code` 和 `modules_allowed` 跳过 paid section。若 `modules_allowed` 为空，EQ 默认允许 `[eq_core]`。这会继续隐藏 `cross_quadrant_insight` 和 `action_plan_14d`。

### 2.7 Gatekeeper、Access 与 Commerce

当前付费链路残留点：

- `ScaleRegistrySeeder.php` EQ_60:
  - `paywall_mode = full`
  - `view_policy.free_sections = ['intro','summary']`
  - `view_policy.blur_others = true`
  - `view_policy.upgrade_sku = SKU_EQ_60_FULL_299`
  - `commercial_json.price_tier = PAID`
  - `commercial_json.report_benefit_code = EQ_60_FULL`
  - `commercial_json.report_unlock_sku = SKU_EQ_60_FULL_299`
  - `content_i18n.questions = 50`
- `database/seed_data/skus_eq_60.json`:
  - `SKU_EQ_60_FULL_299`
  - `SKU_EQ_60_FULL`
  - `benefit_code = EQ_60_FULL`
  - `modules_included = eq_full/eq_cross_insights/eq_growth_plan`
- `ReportAccess`:
  - locked EQ 默认只允许 `eq_core`
  - full EQ offered modules 为 `eq_full/eq_cross_insights/eq_growth_plan`
- `ReportGatekeeper::shouldForceFreeOnly`:
  - 对 Big Five/RIASEC/Enneagram 有特殊免费逻辑
  - EQ 没有被明确作为 all-free full access scale
- `AccessResolver::resolveAccess`:
  - `forceFreeOnly` 时只把 Big Five/Enneagram/RIASEC 设为 full access
  - EQ 在 forceFreeOnly 下仍会被置为 `has_full_access=false`

结论：只改 registry 为 free-only 不够。必须同时修 `AccessResolver`/`ReportGatekeeper`/EQ composer 或新增 EQ 专用 all-free 分支，确保 report 与 report-access 都返回 ready/full/free-all。

### 2.8 Report-Access 当前结构

`AttemptReadController::reportAccess` 当前输出结构：

```json
{
  "ok": true,
  "attempt_id": "...",
  "access_state": "ready|locked|pending|...",
  "report_state": "ready|pending|unavailable|...",
  "pdf_state": "ready|missing|...",
  "reason_code": "...",
  "projection_version": 1,
  "actions": {
    "page_href": "...",
    "pdf_href": "...",
    "wait_href": "...",
    "history_href": "...",
    "lookup_href": "..."
  },
  "payload": {
    "unlock_stage": "locked|partial|full",
    "unlock_source": "none|invite|payment|mixed",
    "access_level": "free|partial|paid",
    "variant": "free|partial|full"
  },
  "invite_unlock_v1": {},
  "invite_unlock_diag_v1": {},
  "meta": {
    "produced_at": "...",
    "refreshed_at": "..."
  }
}
```

当前 fallback projection 如果 result 已存在但 projection 缺失，会返回：

- `access_state = locked`
- `report_state = ready`
- `reason_code = projection_missing_result_ready`
- `payload.fallback = true`
- `payload.result_exists = true`

Big Five/Enneagram/RIASEC 有 ready override；EQ 没有。

### 2.9 Identity 与 v1/v2

`config/scale_identity.php` 当前包含：

- `EQ_60 -> EQ_EMOTIONAL_INTELLIGENCE`
- `EQ_EMOTIONAL_INTELLIGENCE -> EQ_60`
- `pack_id_map_v1 EQ_60 -> EQ_60`
- `pack_id_map_v2 EQ_60 -> EQ_EMOTIONAL_INTELLIGENCE`
- `dir_version v1/v2 -> v1`

当前默认读写/API/content mode 仍偏 legacy。EQ_60 与 EQ_EMOTIONAL_INTELLIGENCE identity 有映射，但 content pack 双目录长期需要保持同步，否则 v2 mode 切换会有内容漂移风险。

### 2.10 后端必须回答清单

| 问题 | 当前答案 |
| --- | --- |
| 1. 题库、选项、反向题、计分、常模、质量规则是否完整？ | 基本完整。60 题、四维各 15、反向题 16、质量规则和 provisional bootstrap norms 都存在。 |
| 2. 当前 EQ_60 report payload 结构是什么？ | `eq_60.report.v2`，section/block + `compat/free_blocks/paid_blocks` + `quality/scores/report/report_tags`。 |
| 3. 当前 report-access payload 结构是什么？ | 统一访问投影：`access_state/report_state/pdf_state/actions/payload/unlock_stage/unlock_source/access_level/variant`。非 v5 报告合同。 |
| 4. 当前 EQ report sections 是哪些？ | 11 个 section：9 free + 2 paid，见 2.4。 |
| 5. 哪些 section free/paid？ | paid 为 `cross_quadrant_insight`、`action_plan_14d`；其他 9 个 free。 |
| 6. `view_policy.free_sections` 是否仍与真实 EQ sections 不匹配？ | 是。registry 为 `intro/summary`，真实 section 无这两个 key。 |
| 7. 是否仍有 paid/locked/blur/SKU 逻辑影响 EQ？ | 是。registry、SKU seed、ReportAccess、AccessResolver、ReportGatekeeper、tests 均存在。 |
| 8. 所有 EQ 结果免费需要改哪些后端？ | registry/view_policy/commercial、ReportGatekeeper、AccessResolver、OfferResolver 或 EQ access bypass、Eq60ReportComposer、report-access projection、paywall/commerce tests。 |
| 9. Eq60ReportComposer 能否输出 v5 所需字段？ | 当前不能。只能输出 section/block 和 scorer 原始字段。 |
| 10. payload 是否有 `scores.dimensions` / `scores_pct` / `dimension_summary`？ | scorer 有 `scores.SA/ER/EM/RM`；driver axis 有 `scores_pct`；report payload 没有 v5 `scores.dimensions` 和 `dimension_summary`。 |
| 11. 是否有 quality/confidence 字段？ | 有 `quality.level/flags/metrics`；没有 `confidence_label`、`explanation_asset_id`。 |
| 12. 是否有 primary profile / tags / cross insight？ | 有 `report.primary_profile`、`report_tags` 和 cross insight tag rules，但命名是 `profile:*`，不是 v5 formulation。 |
| 13. 是否能稳定输出 v5 全字段？ | 不能。需 PR-EQ-V5-01 和 PR-EQ-V5-02。 |
| 14. 常模状态是否 provisional？ | 是。bootstrap norms 为 `PROVISIONAL/bootstrap_v1`。 |
| 15. 是否有 50/60 不一致？ | 是。registry `content_i18n.questions=50`，真实题库/start/tests 为 60。 |
| 16. fallback dimension 是否有 SE typo？ | 是。`ScalesController.php` fallback 为 `['SA','ER','SE','RM']`。 |
| 17. EQ_60 与 EQ_EMOTIONAL_INTELLIGENCE identity 是否一致？ | 映射存在，但双 pack 目录需要同步保障。 |
| 18. 测试覆盖能否保护 v5 payload contract？ | 不能。当前测试保护旧 free/paid、旧 scores、旧 tags。 |
| 19. 哪些内容应进入 content pack 而非前端硬编码？ | v5 所有 formulation、机制、场景、职业环境、行动处方、科学边界、SJT bridge 文案都应后端权威。 |
| 20. 后端需要拆几个 PR？ | 至少 2 个实施 PR：合同/免费策略 PR、内容资产层 PR；SJT 仅文档预留 PR。 |

## 3. Current Frontend State

### 3.1 ResultClient 结果页加载链路

`app/(localized)/[locale]/(app)/result/[id]/ResultClient.tsx` 当前顺序：

1. `fetchAttemptReportAccess`
2. normalize 为 `AttemptReportAccessView`
3. 如果 processing，轮询
4. 如果 unavailable，尝试 submission fallback
5. 如果不能进入 rich report，调用 `/attempts/{id}/result` fallback
6. 如果可进入 rich report，调用 `fetchAttemptReport`
7. IQ 走 `IqResultShell`
8. rich report 走 `RichResultReport`
9. RIASEC result fallback 走 `RiasecResultShell`
10. 最后走 `ResultSummary + DimensionBars`

关键点：`canLoadResultProjection(view, { allowLockedPreview })` 要求：

- `actions.pageHref` 存在
- `reportState === ready`
- `accessState === ready`，除非 `allowLockedPreview = true`

`allowLockedPreview` 当前只由 MBTI report-access 路径或 MBTI route hint 触发。EQ locked report-access 不会加载 report。

### 3.2 当前 EQ Renderer

当前没有以下目录或组件：

- `components/result/eq/**`
- `EQResultHero`
- `EQEvidenceSnapshot`
- `EQQualityBanner`
- `EQEmotionalMatrix`
- `EQMechanismCard`
- `EQRealitySceneCards`
- `EQCareerEnvironmentLens`
- `EQActionPrescription`
- `EQSJTBridgeCTA`
- `EQScientificBoundary`
- `EQSaveShareRelated`

EQ_60 被纳入 `RichResultScaleCode`，但没有专属分支。最终会走通用 `RichResultReport` 默认 JSX。

### 3.3 RichResultReport 当前门控与风险

`components/result/RichResultReport.tsx` 当前：

- 支持 `EQ_60` 作为 rich scale code。
- `FULL_ACCESS_MODULES_BY_SCALE.EQ_60` 包括 `full/eq_60_full/eq_full/report.full/report_full`。
- `isRestrictedRichResultVariant` 会在以下情况标记 free/restricted：
  - `reportData.locked === true`
  - `variant = free/preview/partial`
  - `access_level = free/preview/partial`
  - `modules_allowed` 不含 full module
- `resolveFreeSections` 读取 `reportData.view_policy.free_sections`。
- `shouldForceSectionLocked` 会把不在 `free_sections` 中的 section 强制改为 paid。
- `isBlockVisibleInGate` 会过滤 paid block 或 module 不在 allowed 内的 block。

结论：当前 EQ 如果继续带旧 `view_policy.free_sections=['intro','summary']`，通用 renderer 会把真实 EQ sections 判定为 locked/paid。

### 3.4 当前 Dimension 展示能力

Result fallback 的 `normalizeDimensions` 可以从：

- `result.dimensions`
- top-level `scores_pct`
- `breakdown_json.dimensions`
- `axis_scores_json.scores_pct`

生成 `DimensionBars`。

RichResultReport 的 `normalizeDimensionsFromScores` 只接收数值型 `scores_pct` 或 `scores` map。若 v5 后端输出对象型 `scores.dimensions.SA.percentile`，当前通用 renderer 不会正确读取。EQ v5 需要专属 renderer 或 API adapter。

### 3.5 API Types

`lib/api/v0_3.ts` 当前 `ReportResponse.report` 是宽松对象，包含：

- `quality?: Record<string, unknown>`
- `scores?: Record<string, unknown>`
- `scores_pct?: Record<string, unknown>`
- `dimensions?: Array<Record<string, unknown>>`
- `sections?: Big5ReportSection[] | Record<string, unknown>`
- `[key: string]: unknown`

这足以临时承载 v5 payload，但没有类型级保障。建议新增 `Eq60V5ReportPayload`、`Eq60V5ReportAccessPayload` 类型与 contract fixture。

### 3.6 Quiz Take 链路

`QuizTakeClient.tsx` 对 EQ 没有特殊 form code；走通用题目加载、答题、start、submit、跳转 result。`normalizeQuizQuestions.ts` 支持：

- question options 优先
- meta `option_anchors` fallback
- options format fallback

`iq-eq-result-regression.spec.ts` 已覆盖 “EQ uses option anchors when question options are empty”，但该 e2e 不覆盖真实 60 题、真实 report-access 或 v5 payload。

### 3.7 前端必须回答清单

| 问题 | 当前答案 |
| --- | --- |
| 1. 当前 EQ 结果页走哪个 renderer？ | 通常走 `RichResultReport`；若 report-access locked 则可能降级 `/result` fallback。 |
| 2. 是否仍走 RichResultReport 通用渲染？ | 是。 |
| 3. 是否有 EQ 专属 renderer？ | 没有。 |
| 4. 是否可能出现 MBTI/Big Five fallback 误用？ | MBTI shell 不会直接误用到 EQ；但通用 renderer 的 MBTI/Big5 假设、paywall 文案、tag 处理会污染 EQ 呈现。 |
| 5. 能否正确展示 EQ 四维？ | fallback `DimensionBars` 可展示数值 map；rich renderer 对对象型 v5 dimensions 不足。 |
| 6. 能否消费对象型 scores？ | 当前通用 renderer 不能可靠消费 `scores.dimensions` 对象。 |
| 7. 是否依赖 `scores_pct`？ | fallback 和 generic dimensions 强依赖数值型 `scores_pct` 或数值 scores map。 |
| 8. 如何处理 locked/free_sections/blur？ | `ResultClient` 用 report-access accessState 决定是否 fetch report；`RichResultReport` 用 `locked/free_sections/modules_allowed/access_level` 锁 section/block。 |
| 9. 后端全 free 后前端是否仍会误锁？ | 如果 top-level `locked=false`、`variant=full`、`access_level=full`、modules include `eq_full` 或 renderer 特判 all-free，则不会；只改 section free 不够。 |
| 10. 是否展示 raw technical tags？ | 有风险。过滤不覆盖 `profile:*`、`quality_level:*`、`bucket:*`、`focus:*`。 |
| 11. 是否有 quality/confidence banner？ | 没有 EQ 专属质量/置信度 banner。 |
| 12. 是否能渲染 2x2 Emotional Matrix？ | 不能。 |
| 13. 是否能渲染 Core Insight Hero？ | 不能，只能 generic headline。 |
| 14. 是否能渲染 Action Prescription？ | 不能。 |
| 15. 是否能渲染 Career Environment Lens？ | 不能。 |
| 16. 是否能渲染 SJT Bridge CTA？ | 不能。 |
| 17. ResultClient 拉取 report-access/report 流程是否适合 EQ v5？ | 流程可复用，但 EQ 必须保证 report-access `ready`，或 ResultClient 新增 EQ all-free path。 |
| 18. API type 是否足以表达 v5 payload？ | 运行时可承载，类型不够严格。 |
| 19. e2e 是否覆盖真实 EQ report-access payload？ | 没有。 |
| 20. 前端需要拆几个 PR？ | 至少 2 个：EQ-specific renderer PR、contract/e2e fixture PR。 |

## 4. Current Payload Contract

### 4.1 当前 Report API 关键结构

当前 `/api/v0.3/attempts/{id}/report` 经 gatekeeper 返回：

```json
{
  "ok": true,
  "generating": false,
  "snapshot_error": false,
  "locked": true,
  "access_level": "free",
  "variant": "free",
  "unlock_stage": "locked",
  "unlock_source": "none",
  "upgrade_sku": "SKU_EQ_60_FULL_299",
  "upgrade_sku_effective": "SKU_EQ_60_FULL_299",
  "offers": [],
  "modules_allowed": ["eq_core"],
  "modules_offered": ["eq_full", "eq_cross_insights", "eq_growth_plan"],
  "modules_preview": ["eq_full", "eq_cross_insights", "eq_growth_plan"],
  "view_policy": {
    "free_sections": ["intro", "summary"],
    "blur_others": true
  },
  "norms": {},
  "quality": {},
  "report": {
    "schema_version": "eq_60.report.v2",
    "scale_code": "EQ_60",
    "variant": "free",
    "sections": [],
    "quality": {},
    "scores": {},
    "report": {},
    "report_tags": []
  }
}
```

注：上面是根据代码路径抽象出的合同形状；实际 `offers` 取决于 commerce seed/环境。

### 4.2 当前 Report-Access 关键结构

当前 `/api/v0.3/attempts/{id}/report-access` 返回访问投影而不是报告正文：

```json
{
  "ok": true,
  "attempt_id": "...",
  "access_state": "locked",
  "report_state": "ready",
  "pdf_state": "missing",
  "reason_code": "projection_missing_result_ready",
  "projection_version": 1,
  "actions": {
    "page_href": "...",
    "pdf_href": null,
    "wait_href": "...",
    "history_href": "...",
    "lookup_href": "..."
  },
  "payload": {
    "fallback": true,
    "result_exists": true,
    "unlock_stage": "locked",
    "unlock_source": "none",
    "access_level": "free",
    "variant": "free",
    "invite_unlock_v1": {},
    "invite_unlock_diag_v1": {}
  }
}
```

### 4.3 与 v5 目标 Payload 的差距

| v5 字段 | 当前状态 | 推荐生成方 |
| --- | --- | --- |
| `report_state=self_report` | 缺失 | Eq60ReportComposer |
| `measurement_type=self_report_trait_mixed_ei` | 缺失 | Eq60ReportComposer 固定合同 |
| `access.all_results_free` | 缺失 | Gatekeeper/report composer/report-access projection |
| `access.locked=false` | 当前可能 true | Gatekeeper + AccessResolver |
| `scores.global` | 有近似 `scores.global` | Scorer 生成，Composer 映射 label/band |
| `scores.dimensions` | 缺失 | Composer 从 scorer `scores.SA/ER/EM/RM` 映射 |
| `scores_pct` | driver axis 有，report 不稳定 | 可兼容保留，但 v5 不应依赖它 |
| `dimension_summary` | 缺失 | Composer 生成前端易消费 summary |
| `quality.level/flags` | 已有 | Scorer |
| `quality.confidence_label` | 缺失 | Composer 根据 level 映射 |
| `quality.explanation_asset_id` | 缺失 | Composer + content asset |
| `interpretation.core_formulation_id` | 旧 `primary_profile` 可映射 | Scorer/Composer；建议 composer 固化 v5 id |
| `strongest_dimension` | 缺失 | Scorer 或 Composer |
| `development_lever` | 缺失 | Composer 基于 score profile |
| `primary_mechanism_ids` | 缺失 | Composer rule selector |
| `primary_scene_ids` | 缺失 | Composer rule selector |
| `career_environment_ids` | 缺失 | Composer rule selector |
| `action_prescription_id` | 缺失 | Composer rule selector |
| `next_module` | 缺失 | Composer 固定 placeholder |
| `methodology.norm_status` | 有 `norms.status` | Composer 映射 |
| `methodology.scoring_version` | 有 `engine_version` | Scorer/Composer |
| `methodology.report_version` | 缺失 | Composer |
| `methodology.content_version` | 有 pack info | Composer |

### 4.4 哪些字段应由谁负责

| 字段类别 | 归属 |
| --- | --- |
| 原始分、反向计分、标准分、百分位、质量 flags、常模状态 | Scorer |
| v5 `scores.global/dimensions` 投影、label、band 映射、`dimension_summary` | Eq60ReportComposer |
| `report_state`、`measurement_type`、`access.all_results_free`、`methodology` | Eq60ReportComposer + Gatekeeper |
| `core_formulation_id`、`development_lever`、mechanism/scene/career/action ids | Composer rule layer；部分输入来自 scorer |
| 文案正文、定义、声明、处方、场景解释、SJT bridge copy | content pack / report assets |
| 2x2 matrix layout、排序展示、分享按钮状态 | 前端 derivation，不写回后端 |
| UI copy 微文案，如按钮 loading、空态 | 前端 i18n，但核心报告文案仍后端权威 |

## 5. Free Strategy Impact

当前策略变更：所有 EQ 结果全部免费，区别不是钱，而是数据完整度。

### 5.1 后端影响

必须取消或旁路：

- EQ registry 的 `price_tier=PAID`
- EQ registry 的 `upgrade_sku/report_unlock_sku`
- EQ registry 的 `blur_others=true`
- EQ registry 的 `view_policy.free_sections=['intro','summary']`
- EQ report sections 的 paid access gating
- `ReportAccess::defaultModulesAllowedForLocked(EQ_60)` 作为免费结果唯一可见模块的行为
- `AccessResolver` 对 EQ forceFreeOnly 不授 full access 的行为
- `ReportGatekeeper` 对 EQ `unlock_stage=locked` 的默认推导
- EQ paywall/unlock tests

建议 v5.0 后端合同：

```json
{
  "locked": false,
  "access_level": "full",
  "variant": "full",
  "unlock_stage": "full",
  "unlock_source": "none",
  "upgrade_sku": null,
  "offers": [],
  "modules_allowed": ["eq_core", "eq_full", "eq_cross_insights", "eq_growth_plan"],
  "modules_preview": [],
  "view_policy": {
    "free_sections": ["*"],
    "blur_others": false
  },
  "report": {
    "access": {
      "all_results_free": true,
      "locked": false,
      "blur": false,
      "paywall": false
    }
  }
}
```

### 5.2 前端影响

必须保证：

- `ResultClient` 能在 EQ report-access ready/full 下加载 report。
- EQ-specific renderer 不读取 generic paywall offers。
- EQ-specific renderer 不显示 locked module card。
- EQ-specific renderer 不显示 Recommended unlocks。
- EQ-specific renderer 不显示 raw `profile:*`/`quality_level:*` tags。
- 缺少 v5 payload 时只做保守 fallback，不重新引入付费 CTA。

## 6. V5 Result Page Gap Analysis

| v5 模块 | 当前支持 | 缺口 | 建议 PR |
| --- | --- | --- | --- |
| Core Insight Hero | 无专属支持；generic headline 不足 | 需要 `core_formulation_id` + formulation asset | PR-01/02/03 |
| Evidence Snapshot | 分数和质量原始数据部分存在 | 缺 `scores.dimensions`、strongest、lever、confidence | PR-01/03 |
| Quality Banner | 有 quality level/flags | 缺 confidence label 和解释资产 | PR-01/02/03 |
| Emotional Matrix | 无 | 需要 2x2 renderer 和维度 summary | PR-01/03 |
| Pattern Mechanism | 旧 paid `cross_quadrant_insight` 有部分概念 | 缺 structured `primary_mechanism_ids` 和机制资产 | PR-02/03 |
| Reality Translation | 无 v5 structured assets | 缺 scene ids + scene cards | PR-02/03 |
| Career Environment Lens | 无 EQ 职业环境变量 | 缺 6 变量 low/medium/high assets | PR-02/03 |
| Action Prescription | 旧 paid `action_plan_14d` | v5 要单主处方 + 脚本 + 7 天练习，全免费 | PR-02/03 |
| SJT Bridge | 无 | 缺 `next_module` + CTA asset；不能说成 ability/MSCEIT | PR-01/02/03 |
| Scientific Boundary | 旧 disclaimer/methodology 有部分内容 | 缺 self-report、非诊断、非招聘、非能力测验、norm status 结构化资产 | PR-02/03 |
| Save/Share/Related | generic share/retake 有 | 缺 EQ 相关测试建议和 v5 布局 | PR-03 |

## 7. Content Asset Gap Analysis

### 7.1 当前资产机制

当前 EQ content pack 使用：

- `raw/blocks/free_blocks.json`
- `raw/blocks/paid_blocks.json`
- `raw/report_layout.json`
- `raw/variables_allowlist.json`
- compiled report block/tag selection

这个机制可以承载短块文案，但当前是 section/block 叙事，不是 v5 所需 asset id 层。v5 需要稳定 id 供前端确定性渲染。

### 7.2 8 个 Asset Pack 缺口

| Asset Pack | 当前已有 | 缺失 | 优先级 |
| --- | --- | --- | --- |
| Scientific Contract | 有 disclaimer/methodology 雏形 | self-report、非诊断、非招聘、非能力测验、常模状态、质量规则、版本说明结构化资产 | P0 |
| Score System | 有维度块和等级规则 | 综合指数名称、5 总分等级、4 维定义、4x5 维度等级解释、百分位说明 | P0 |
| Core Formulation | 有 `profile:*` tags | 10 个 formulation id/name/core_claim/evidence/strength/cost/lever/do_not_overread | P0 |
| Mechanism Map | 有旧 `cross_quadrant_insight` paid blocks | 5 组合 x 4 状态 = 20 组机制解释 | P1 |
| Reality Translation | 基本缺失 | 反馈、冲突、关系边界、团队协作、压力恢复、职业环境场景资产 | P0/P1 |
| Career Environment | 缺失 | 6 个环境变量 x low/medium/high | P1 |
| Action Prescription | 有旧 paid 14d plan | 12 个主处方，每个 today/script/7-day/watch-out | P0 |
| SJT Bridge | 缺失 | 16 题说明、补充什么、不是什么、完成后新增模块、按钮文案 | P1 |

### 7.3 是否新增 report_assets JSON

建议：新增 v5 asset layer，同时保留现有 report block 体系作为兼容层。

原因：

- 直接继续扩展 `free_blocks/paid_blocks` 会把 v5 的结构化 id、选择规则、前端组件合同混在旧 section 文案里。
- 彻底废弃现有 block 体系风险大，会影响 golden cases、content lint/compile、现有 composer。
- 最小风险路径是新增 `raw/report_assets/**` 和 `compiled/report_assets.compiled.json`，由 composer 选择 asset ids 并输出 v5 payload；旧 `sections/compat` 可短期保留给 fallback。

建议目标结构：

```text
backend/content_packs/EQ_60/v1/raw/report_assets/
  scientific_contract.json
  score_system.json
  core_formulations.json
  mechanism_map.json
  reality_translation.json
  career_environment.json
  action_prescriptions.json
  sjt_bridge.json
```

## 8. Frontend Component Gap Analysis

| 组件 | 复用/新建 | 是否需要后端 asset IDs | 合同测试 |
| --- | --- | --- | --- |
| `EQResultHero` | 新建 | 是：formulation asset | 必须 |
| `EQEvidenceSnapshot` | 新建，可复用基础 Card/Progress primitives | 部分：score labels 可后端给 | 必须 |
| `EQQualityBanner` | 新建 | 是：quality explanation asset | 必须 |
| `EQEmotionalMatrix` | 新建 | 不一定，依赖 payload scores + dimension summary | 必须 |
| `EQMechanismCard` | 新建 | 是：mechanism ids/assets | 必须 |
| `EQRealitySceneCards` | 新建 | 是：scene ids/assets | 必须 |
| `EQCareerEnvironmentLens` | 新建 | 是：environment ids/assets | 必须 |
| `EQActionPrescription` | 新建 | 是：prescription id/assets | 必须 |
| `EQSJTBridgeCTA` | 新建 | 是：SJT bridge asset | 必须 |
| `EQScientificBoundary` | 新建 | 是：scientific contract/methodology assets | 必须 |
| `EQSaveShareRelated` | 可复用 share/retake 逻辑，新建 EQ wrapper | related tests 来自后端或 stable map | 建议 |

i18n 风险：

- v5 报告核心文案必须由后端 content pack 提供 zh/en；前端不能硬编码解释文本。
- 前端可硬编码少量 UI chrome，如“分享”“重新测试”“继续”，但 SJT 说明、科学边界、行动脚本不得前端写死。
- 当前 frontend `lib/content.ts` 有 EQ 测试入口静态标题/描述，这是 catalog fallback，不应扩展为结果页内容权威。

## 9. Testing Gap Analysis

### 9.1 后端测试缺口

当前已有：

- `Eq60ContentGateTest`: lint/compile/questions/report sections
- `Eq60StartSubmitTest`: 60 题 start/submit + dim scores
- `Eq60SubmitQualityContractTest`: quality/norms/scores/version
- `Eq60GoldenCasesTest`: quality/profile/tags/sections
- `Eq60ReportPaywallTest`: locked/free vs unlocked/paid
- `Eq60PdfDeliveryTest`: PDF free variant
- `Eq60UnlockFlowTest`: paid webhook unlock
- unit scorer/driver validity/cross insight tests

缺失：

- v5 report payload contract test
- v5 report-access all-free contract test
- `scores.dimensions`/`dimension_summary` assertion
- `access.all_results_free` assertion
- no `locked/blur/paywall/SKU` assertion
- `report_state`/`measurement_type`/`next_module`/`methodology` assertion
- low confidence formulation assertion
- v5 asset id presence assertion

`Eq60ReportPaywallTest` 与 `Eq60UnlockFlowTest` 当前会阻止 all-free 策略，需要重写或改名为 free strategy tests。不要删除商业基础设施，只应确保 EQ 不再走 commerce 解锁。

### 9.2 前端测试缺口

当前已有：

- `iq-eq-result-regression.spec.ts`: EQ option anchors、fallback result、IQ 渲染
- `quiz-normalization.contract.test.ts`: EQ option anchors
- `scale-code-mode.contract.test.ts`: EQ v1/v2 mapping
- `result-client-view-state.contract.test.tsx`: report-access 流程，但 mock 不覆盖 EQ scale
- `rich-result-report.contract.test.tsx`: MBTI/Big5/Enneagram/RIASEC 多，EQ 基本缺失

缺失：

- EQ v5 payload fixture
- EQ report-access ready/full/all-free fixture
- EQ locked/blur/paywall absence contract
- EQ no raw tags contract
- EQ renderer component contract
- zh-CN/en 双语 snapshot
- quality A 与 C/D result path
- `high_empathy_low_recovery`、`balanced_integrated`、`low_confidence_result` cases
- e2e 覆盖真实 `/report-access` + `/report` payload，而非只 mock `/report*`

## 10. Risk Matrix

| Severity | 风险 | 当前证据 | 影响 | 处理 PR |
| --- | --- | --- | --- | --- |
| P0 | EQ 仍被 report-access 锁住 | EQ 无 ready override；fallback projection resultExists 时 access locked | 前端不能进入 v5 report | PR-01 |
| P0 | `free_sections` 与真实 sections 不匹配 | registry `intro/summary` vs report `disclaimer_top/...` | 通用 renderer 强制锁 section | PR-01 |
| P0 | paid/locked/blur/SKU 残留 | registry + SKU seed + ReportAccess + tests | 与“所有 EQ 免费”冲突 | PR-01 |
| P0 | 题数 50/60 不一致 | registry content_i18n 50，题库/API 60 | 商业化页面和信任受损 | PR-01 |
| P0 | v5 payload shape 缺失 | composer 仍 `eq_60.report.v2` block shape | 前端无法稳定渲染 v5 | PR-01 |
| P1 | raw tags 展示 | `profile:*` 等未被过滤 | 用户看到技术标签 | PR-03 |
| P1 | content authority 漂移 | 前端若硬编码 v5 文案会违反规则 | 后续 CMS/content pack 失控 | PR-02/03 |
| P1 | i18n 不完整 | 当前 v5 assets 不存在 | 英文商业化报告无法上线 | PR-02 |
| P1 | norm status 误读 | provisional 未结构化展示 | 科学边界风险 | PR-01/02/03 |
| P1 | SJT overclaim | 无 SJT 合同但要展示 bridge | 不能宣称 ability/MSCEIT | PR-01/02/05 |
| P2 | EQ_60/EQ_EMOTIONAL_INTELLIGENCE 双 pack 漂移 | 双目录存在 | v2 mode 内容不一致 | PR-02 |
| P2 | frontend generic renderer 样式不适合 EQ | 无 EQ renderer | 产品解释路径弱 | PR-03 |
| P2 | old paywall tests 与新策略冲突 | `Eq60ReportPaywallTest`, `Eq60UnlockFlowTest` | CI 阻塞 | PR-01 |
| P3 | PDF 仍显示 old free variant | PDF test expects free | 非当前阶段核心 | 后续 PDF PR |

## 11. Recommended PR Train

### PR-EQ-V5-01：后端 EQ 免费报告合同修正

- repo: `fap-api`
- branch: `codex/pr-eq-v5-01-backend-free-contract`
- depends_on: none
- scope:
  - `backend/database/seeders/ScaleRegistrySeeder.php`
  - `backend/database/seeders/CiScalesRegistrySeeder.php`（如 CI registry 仍覆盖 EQ commercial）
  - `backend/app/Http/Controllers/API/V0_3/ScalesController.php`
  - `backend/app/Services/Report/ReportGatekeeper.php`
  - `backend/app/Services/Report/Resolvers/AccessResolver.php`
  - `backend/app/Services/Report/Eq60ReportComposer.php`
  - `backend/tests/Feature/Report/Eq60ReportPaywallTest.php`
  - `backend/tests/Feature/V0_3/Eq60StartSubmitTest.php`
  - `backend/tests/Feature/V0_3/Eq60SubmitQualityContractTest.php`
  - `backend/tests/Feature/Content/Eq60GoldenCasesTest.php`
- must solve:
  - `questions: 50` -> 60
  - `SE` fallback typo -> `EM`
  - EQ all-free access contract
  - no EQ `locked/blur/paywall/SKU`
  - `scores.dimensions`
  - `dimension_summary`
  - `quality.confidence_label`
  - `report_state`
  - `measurement_type`
  - `methodology`
  - `next_module` placeholder
- non-goals:
  - 不改题库
  - 不改反向题
  - 不改常模算法
  - 不写完整 v5 文案资产
  - 不新增 SJT
- local checks:
  - `cd /Users/rainie/Desktop/GitHub/fap-api`
  - `git diff --check`
  - `php artisan test --filter=Eq60StartSubmitTest`
  - `php artisan test --filter=Eq60ReportPaywallTest`
  - `php artisan test --filter=Eq60SubmitQualityContractTest`
  - `php artisan test --filter=Eq60GoldenCasesTest`
- risk: P0
- acceptance criteria:
  - report-access for EQ returns `access_state=ready`, `report_state=ready`, `unlock_stage=full`, `unlock_source=none`
  - report returns `locked=false`, `variant=full`, no upgrade sku/offers
  - v5 minimal payload exists and old compatibility sections do not expose paid lock

### PR-EQ-V5-02：后端 EQ v5 内容资产层

- repo: `fap-api`
- branch: `codex/pr-eq-v5-02-backend-report-assets`
- depends_on: `PR-EQ-V5-01`
- scope:
  - `backend/content_packs/EQ_60/v1/raw/report_assets/**`
  - `backend/content_packs/EQ_60/v1/compiled/**`
  - `backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/report_assets/**`（若 v2 pack 保持镜像）
  - `backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/**`
  - `backend/app/Services/Content/Eq60PackLoader.php`（如需加载 assets）
  - `backend/app/Services/Report/Eq60ReportComposer.php`
  - `backend/tests/Feature/Content/Eq60ContentGateTest.php`
  - `backend/tests/Feature/Content/Eq60GoldenCasesTest.php`
  - `backend/tests/Feature/Report/Eq60ReportPaywallTest.php`
- assets:
  - Scientific Contract
  - Score System
  - Core Formulation
  - Mechanism Map
  - Reality Translation
  - Career Environment
  - Action Prescription
  - SJT Bridge
- non-goals:
  - 不改前端
  - 不做 SJT 题库
  - 不做付费
  - 不改 scoring semantics
- local checks:
  - `cd /Users/rainie/Desktop/GitHub/fap-api`
  - `git diff --check`
  - `php artisan test --filter=Eq60ContentGateTest`
  - `php artisan test --filter=Eq60GoldenCasesTest`
  - `php artisan test --filter=Eq60ReportPaywallTest`
- risk: P1
- acceptance criteria:
  - composer 能按 v5 ids 输出 assets references/resolved copy
  - low confidence result 使用 `low_confidence_result`
  - all assets zh/en 完整

### PR-EQ-V5-03：前端 EQ 专属结果页 v5.0

- repo: `fap-web`
- branch: `codex/pr-eq-v5-03-frontend-eq-renderer`
- depends_on: `PR-EQ-V5-01`, `PR-EQ-V5-02`
- scope:
  - `components/result/eq/**`
  - `components/result/RichResultReport.tsx`
  - `app/(localized)/[locale]/(app)/result/[id]/ResultClient.tsx`
  - `lib/api/v0_3.ts`
  - `tests/contracts/eq-result-v5-renderer.contract.test.tsx`（新增）
  - `tests/e2e/iq-eq-result-regression.spec.ts`
- components:
  - `EQResultHero`
  - `EQEvidenceSnapshot`
  - `EQQualityBanner`
  - `EQEmotionalMatrix`
  - `EQMechanismCard`
  - `EQRealitySceneCards`
  - `EQCareerEnvironmentLens`
  - `EQActionPrescription`
  - `EQSJTBridgeCTA`
  - `EQScientificBoundary`
  - `EQSaveShareRelated`
- non-goals:
  - 不写报告正文硬编码
  - 不改题库/评分
  - 不引入付费 CTA
  - 不做 SJT 流程
- local checks:
  - `cd /Users/rainie/Desktop/GitHub/fap-web`
  - `pnpm typecheck`
  - `pnpm test:contract`
  - `pnpm exec playwright test tests/e2e/iq-eq-result-regression.spec.ts`
- risk: P1
- acceptance criteria:
  - EQ_60 不显示 locked/blur/paywall/unlock
  - 不显示 raw technical tags
  - v5 11 个模块基础可见
  - 缺 v5 payload 时保守 fallback，无 MBTI/Big5 误用

### PR-EQ-V5-04：前后端 EQ report-access contract fixture + e2e

- repo: `fap-api` + `fap-web`
- branch:
  - backend: `codex/pr-eq-v5-04-api-fixtures`
  - frontend: `codex/pr-eq-v5-04-web-fixtures-e2e`
- depends_on: `PR-EQ-V5-03`
- scope:
  - `fap-api/backend/tests/**`
  - `fap-web/tests/contracts/**`
  - `fap-web/tests/e2e/**`
  - `fap-web/tests/fixtures/eq/**`
- cases:
  - quality A 正常结果
  - quality C/D 低置信度结果
  - `high_empathy_low_recovery`
  - `balanced_integrated`
  - `low_confidence_result`
  - free all sections
  - no locked/no blur/no paywall
  - zh-CN/en
- non-goals:
  - 不改新功能，只加/收紧契约
  - 不做 SJT 题库
- local checks:
  - `cd /Users/rainie/Desktop/GitHub/fap-api`
  - `php artisan test --filter=Eq60ReportPaywallTest`
  - `php artisan test --filter=Eq60SubmitQualityContractTest`
  - `cd /Users/rainie/Desktop/GitHub/fap-web`
  - `pnpm typecheck`
  - `pnpm test:contract`
  - `pnpm exec playwright test tests/e2e/iq-eq-result-regression.spec.ts`
- risk: P1
- acceptance criteria:
  - fixture 与后端真实 payload 字段一致
  - e2e 使用 report-access + report 双接口
  - all-free/no-paywall 防回归

### PR-EQ-V5-05：EQ-SJT 16 题模块预留设计扫描/文档

- repo: `fap-api`
- branch: `codex/pr-eq-v5-05-sjt-design-doc`
- depends_on: `PR-EQ-V5-01`
- scope:
  - `docs/audits/eq/**`
  - `docs/product/eq/**`
- output:
  - EQ-SJT 16 题 content pack 结构草案
  - scenario domains
  - scoring rubric draft
  - applied strategy dimensions
  - integrated report payload draft
  - future PR train draft
- non-goals:
  - 不新增 SJT 题库
  - 不新增 SJT scorer
  - 不改前端流程
  - 不宣称 MSCEIT/ability test
- local checks:
  - `cd /Users/rainie/Desktop/GitHub/fap-api`
  - `git diff --check`
- risk: P2
- acceptance criteria:
  - 明确 EQ-SJT 是 situational judgment / ability-like supplement，不是认证能力测验
  - 明确 integrated report 只在 EQ-60 + EQ-SJT 后出现

## 12. Manifest Drafts

以下仅为草案，不写入 `docs/codex/pr-train.yaml`。

```yaml
- id: PR-EQ-V5-01
  title: "PR-EQ-V5-01: Backend EQ free report contract"
  repo: fap-api
  branch: codex/pr-eq-v5-01-backend-free-contract
  depends_on: []
  scope:
    - backend/database/seeders/ScaleRegistrySeeder.php
    - backend/database/seeders/CiScalesRegistrySeeder.php
    - backend/app/Http/Controllers/API/V0_3/ScalesController.php
    - backend/app/Services/Report/ReportGatekeeper.php
    - backend/app/Services/Report/Resolvers/AccessResolver.php
    - backend/app/Services/Report/Eq60ReportComposer.php
    - backend/tests/Feature/Report/Eq60ReportPaywallTest.php
    - backend/tests/Feature/V0_3/Eq60StartSubmitTest.php
    - backend/tests/Feature/V0_3/Eq60SubmitQualityContractTest.php
    - backend/tests/Feature/Content/Eq60GoldenCasesTest.php
  checks:
    - git diff --check
    - php artisan test --filter=Eq60StartSubmitTest
    - php artisan test --filter=Eq60ReportPaywallTest
    - php artisan test --filter=Eq60SubmitQualityContractTest
    - php artisan test --filter=Eq60GoldenCasesTest

- id: PR-EQ-V5-02
  title: "PR-EQ-V5-02: Backend EQ v5 report asset layer"
  repo: fap-api
  branch: codex/pr-eq-v5-02-backend-report-assets
  depends_on: [PR-EQ-V5-01]
  scope:
    - backend/content_packs/EQ_60/v1/raw/report_assets/**
    - backend/content_packs/EQ_60/v1/compiled/**
    - backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/report_assets/**
    - backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/compiled/**
    - backend/app/Services/Content/Eq60PackLoader.php
    - backend/app/Services/Report/Eq60ReportComposer.php
    - backend/tests/Feature/Content/Eq60ContentGateTest.php
    - backend/tests/Feature/Content/Eq60GoldenCasesTest.php
    - backend/tests/Feature/Report/Eq60ReportPaywallTest.php
  checks:
    - git diff --check
    - php artisan test --filter=Eq60ContentGateTest
    - php artisan test --filter=Eq60GoldenCasesTest
    - php artisan test --filter=Eq60ReportPaywallTest

- id: PR-EQ-V5-03
  title: "PR-EQ-V5-03: Frontend EQ result page v5 renderer"
  repo: fap-web
  branch: codex/pr-eq-v5-03-frontend-eq-renderer
  depends_on: [PR-EQ-V5-01, PR-EQ-V5-02]
  scope:
    - components/result/eq/**
    - components/result/RichResultReport.tsx
    - app/(localized)/[locale]/(app)/result/[id]/ResultClient.tsx
    - lib/api/v0_3.ts
    - tests/contracts/eq-result-v5-renderer.contract.test.tsx
    - tests/e2e/iq-eq-result-regression.spec.ts
  checks:
    - pnpm typecheck
    - pnpm test:contract
    - pnpm exec playwright test tests/e2e/iq-eq-result-regression.spec.ts

- id: PR-EQ-V5-04
  title: "PR-EQ-V5-04: EQ report-access contract fixtures and e2e"
  repo: fap-api+fap-web
  branch: codex/pr-eq-v5-04-contract-fixtures-e2e
  depends_on: [PR-EQ-V5-03]
  scope:
    - fap-api/backend/tests/**
    - fap-web/tests/contracts/**
    - fap-web/tests/e2e/**
    - fap-web/tests/fixtures/eq/**
  checks:
    - php artisan test --filter=Eq60ReportPaywallTest
    - php artisan test --filter=Eq60SubmitQualityContractTest
    - pnpm typecheck
    - pnpm test:contract
    - pnpm exec playwright test tests/e2e/iq-eq-result-regression.spec.ts

- id: PR-EQ-V5-05
  title: "PR-EQ-V5-05: EQ-SJT 16 scenario module design document"
  repo: fap-api
  branch: codex/pr-eq-v5-05-sjt-design-doc
  depends_on: [PR-EQ-V5-01]
  scope:
    - docs/audits/eq/**
    - docs/product/eq/**
  checks:
    - git diff --check
```

后续执行授权 prompt 草案：

```text
请授权将 PR-EQ-V5-01 写入 docs/codex/pr-train.yaml 和 docs/codex/pr-train-state.json，并按该 manifest scope 实施“后端 EQ 免费报告合同修正”。仅实施 PR-EQ-V5-01，不推进后续 PR。
```

## 13. State Drafts

以下仅为草案，不写入 `docs/codex/pr-train-state.json`。

```json
{
  "PR-EQ-V5-01": {
    "status": "planned_pending_user_authorization",
    "commit_sha": null,
    "pr_url": null,
    "checks": [],
    "failure_reason": null,
    "merged_at": null,
    "remote_branch_deleted": false,
    "local_cleanup_executed": false
  },
  "PR-EQ-V5-02": {
    "status": "planned_blocked_dependency",
    "depends_on": ["PR-EQ-V5-01"],
    "commit_sha": null,
    "pr_url": null,
    "checks": [],
    "failure_reason": "Requires PR-EQ-V5-01 backend contract to be merged first.",
    "merged_at": null,
    "remote_branch_deleted": false,
    "local_cleanup_executed": false
  },
  "PR-EQ-V5-03": {
    "status": "planned_blocked_dependency",
    "depends_on": ["PR-EQ-V5-01", "PR-EQ-V5-02"],
    "commit_sha": null,
    "pr_url": null,
    "checks": [],
    "failure_reason": "Requires backend v5 contract and assets before renderer implementation.",
    "merged_at": null,
    "remote_branch_deleted": false,
    "local_cleanup_executed": false
  },
  "PR-EQ-V5-04": {
    "status": "planned_blocked_dependency",
    "depends_on": ["PR-EQ-V5-03"],
    "commit_sha": null,
    "pr_url": null,
    "checks": [],
    "failure_reason": "Requires frontend renderer before cross-repo fixtures/e2e can be finalized.",
    "merged_at": null,
    "remote_branch_deleted": false,
    "local_cleanup_executed": false
  },
  "PR-EQ-V5-05": {
    "status": "planned_blocked_dependency",
    "depends_on": ["PR-EQ-V5-01"],
    "commit_sha": null,
    "pr_url": null,
    "checks": [],
    "failure_reason": "SJT design should reference finalized EQ-60 free report state.",
    "merged_at": null,
    "remote_branch_deleted": false,
    "local_cleanup_executed": false
  }
}
```

## 14. Open Questions

1. `EQ_60` v5 是否继续保留旧 `sections/compat` 给旧客户端，还是 v5 首版只支持新 renderer？
2. EQ PDF 是否随 v5 同步改为全免费，还是作为后续 PDF 专门 PR？
3. `EQ_EMOTIONAL_INTELLIGENCE/v1` 是否必须与 `EQ_60/v1` 在 PR-EQ-V5-02 中完全镜像，还是只保留 legacy pack？
4. v5 band 命名是否只在 report composer 映射，还是进入 scorer/policy 层替换旧 `competent/exceptional`？
5. `core_formulation_id` 的第一版触发规则是否接受基于现有 score thresholds + quality 的 deterministic rule，还是需要 psychometrics review 后再锁定？
6. `low_confidence_result` 是否覆盖 quality C 和 D，还是 D 才强制低置信 formulation、C 仅展示 caution？
7. Related tests 中是否固定展示 Big Five/RIASEC/MBTI，还是由后端返回 `related_tests`？
8. SJT bridge CTA 是否在 v5.0 上线时展示 disabled/planned 状态，还是只展示说明不放按钮？
9. 是否需要把 EQ commerce SKU 保留在 seed_data 但不被 runtime 使用，还是彻底移除 EQ SKU seed？

## 15. Final Recommendation

先执行 `PR-EQ-V5-01：后端 EQ 免费报告合同修正`。

原因：

- v5 最大阻塞不是 UI，而是 EQ 当前仍被旧 access/paywall 合同支配。
- 只要 report-access 仍可能返回 `locked`，前端 EQ v5 renderer 就可能根本拿不到 report。
- 只要 `view_policy.free_sections` 仍是 `intro/summary`，通用 renderer 和任何过渡 renderer 都存在误锁风险。
- 只要 payload 没有 `scores.dimensions`、`dimension_summary`、`quality.confidence_label`、`report_state`、`measurement_type`、`next_module`，前端只能继续做不稳定推断。

推荐执行顺序：

1. `PR-EQ-V5-01`：先把 EQ 从付费/locked 合同中拿出来，并输出 v5 最小 payload。
2. `PR-EQ-V5-02`：把 v5 解释内容资产放入后端 content pack / report_assets。
3. `PR-EQ-V5-03`：新增前端 EQ-specific result renderer。
4. `PR-EQ-V5-04`：用真实 report-access/report fixtures 锁住全链路。
5. `PR-EQ-V5-05`：只做 EQ-SJT 16 题模块设计文档，等待后续授权实施。

本次扫描没有实施任何 PR，没有修改业务代码、前端代码、后端代码、题库、评分、反向题、常模算法、SJT 题库，也没有修改 `docs/codex/pr-train.yaml` 或 `docs/codex/pr-train-state.json`。
