# SEO-PLATFORM-09-PREFLIGHT：统一决策卡与优先级合同

状态：`complete_preflight_docs_only`
证据截止：`2026-08-26T00:10:24Z`
仓库基线：`1853f0e58608452ca13b95d93e63b6cd31fe76be`

## 唯一当前结论

底层 Issue Queue、Opportunity Queue、根因 cluster 与 priority read model 已存在，且
`SEO-PLATFORM-01/03` 保存了 2026-08-24 的 authenticated aggregate production
readback；它们可以作为后续实现的输入，但不能被称为 `SEO-PLATFORM-09` 的统一决策系统。

`SeoWorkbenchUiContract` 仍明确返回空 `decisions`、空指标和
`MEASUREMENT_HOLD`。仓库没有统一 decision-card authority、每周 selector、决策生命周期
materializer 或生产 weekly receipt。因此当前唯一状态是：

```text
underlying queues / cluster / scorer: production evidence exists
SEO-PLATFORM-09 unified decision read model: production_unproven
current weekly decision: MEASUREMENT_HOLD
```

本扫描未执行 DB、CMS、queue、scheduler、cache、production 或 search 写入。GitHub 对精确
任务 ID 的当前 open PR 与 open issue 搜索均为 0；历史任务记录不构成绑定关系。

## 现有能力盘点

| 能力 | authority / 调用方 | 当前状态 | 可复用 | 关键缺口 |
| --- | --- | --- | --- | --- |
| Issue Queue | `seo_issue_queue`；`SeoIssueQueueReadService`、`SeoIssueClusterReadService` | `production_healthy`（已有受控回读） | issue lifecycle、脱敏 evidence | 不是 decision-card authority |
| Detector Opportunity | `seo_detector_opportunities`；materializer、Opportunity reader | `code_only` | `cluster_uid`、revision、freshness、reopen | 本扫描无当前生产 materialization receipt |
| Cluster read model | `SeoIssueClusterReadService`；Ops/API/export | `production_healthy` | 根因聚合、稳定 tie-break | legacy fallback key 未直接消费 persisted `cluster_uid` |
| Priority scorer | `SeoIssuePriorityScorer`；cluster reader | `production_healthy` | impact/confidence/effort primitives | 无 business value、risk、freshness 与 nullable score |
| Opportunity Queue | `SeoOpportunityQueueReadService`；私有 Ops API/UI | `production_healthy` | GSC quality gate、detector/GSC source state | 两类 candidate 尚无统一 eligibility |
| Legacy aggregator | `OpportunityAggregator`；两个 CLI runner | `code_only` | artifact normalization、确定性排序 | subject-based dedupe 不等于 root-cause cluster |
| Priority Queue runner | `SeoAgentPriorityQueueSchedulerCommand`；manual/external trigger | `deployed_disabled` | bounded envelope、negative guarantees | 未进入 Laravel scheduler，不是 decision authority |
| Workbench placeholder | `SeoWorkbenchUiContract`；Workbench Blade | `production_unproven` | 3 张默认、最多 5 张、fail closed | 无生产 decision snapshot |
| Generic Action Queue | `OpsActionQueueWidget`；通用 Ops 首页 | `not_required` | 无 | 只处理 failed jobs、approval 与 tenant commerce；名称相似但不是 SEO authority |

这里保留两项关键事实：

1. 当前 issue cluster key 是 `detector + root_cause + page_family + authority_revision`；同一根因
   不应按 URL 数量复制决策事项。
2. 现有 scorer 在 GSC quality gate 失败时将 GSC points 置 0。这个行为适用于其当前技术
   read model，但不能被 #9 继承为“缺失业务测量等于真实 0”。

## `seo.decision_card.v1`

一张决策卡代表一个当前有效 `cluster_uid`，而不是一条 URL、issue、query 或 artifact。
`cluster_uid` 缺失或不合法时 fail closed 为 `MEASUREMENT_HOLD`，禁止生成 fallback card。

最小字段分为六组：

- identity：decision ID、cluster、detector、root cause、family、locale；
- revision：authority revision 以及实现后需要绑定的 runtime/cache/release revision；
- evidence：证据状态、新鲜度、first/last observed、affected unique public URLs；
- priority：measurement state、measurement-independent、business priority、risk、cost、nullable score；
- action：highest allowed action、next step、owner；
- lifecycle：expires、status、close reason、selection revision。

输出永久禁止保存 raw query、原始私人 URL、用户/attempt/result/order/payment/token 标识、
原始 User-Agent 或可逆敏感 hash。

## 优先级与 `MEASUREMENT_HOLD`

版本化候选合同为 `seo.priority.candidate.v1`，本次只定义输入、门禁和稳定排序，不宣称权重
已经在生产生效。

| 输入 | authority | 缺失或过期 |
| --- | --- | --- |
| impact scope | affected unique public URLs 与 family scope | HOLD |
| evidence strength | `verified/observed/inferred/unknown/blocked` | HOLD |
| business value | Business Surface Matrix 的 L1/L2/L3/trust/conditional | HOLD |
| risk | 有直接证据的 severity 与 blast radius | HOLD |
| estimated fix cost | bounded/manual/template/engineering/external | HOLD |
| evidence freshness | source `observed_at` 与其声明的 `max_age` | HOLD |
| measurement state | completeness、lag、quality 与 comparability gate | HOLD |

缺失、过期或 quality-failed 的 measurement 必须返回：

```json
{
  "priority_score": null,
  "ranking_eligible": false,
  "state": "MEASUREMENT_HOLD"
}
```

真实 0 只允许来自通过 gate 的已观测数值。确定性技术故障只有同时具备 fresh direct
evidence、公开 authority binding 和 `measurement_independent=true` 时，才可脱离业务指标
进入排序；P0/P1 始终要求直接证据。

稳定排序顺序：direct-evidence P0/P1、eligible、impact、evidence、business value、risk、
低 cost、freshness，最后用 `cluster_uid ASC` 消除并列不确定性。具体数值权重必须在 runtime
实现前完成 sensitivity validation；本合同不把候选权重伪装成已生效生产策略。

## 每周最多五张

- selection cadence 使用 ISO week，并绑定唯一 `selection_revision`；
- 默认展示目标仍为 3，但硬上限为 5，合法范围是 0–5；
- 不足 5 张时不补空卡，不纳入 held 项凑数；
- 同一 cluster 只保留一张 current card，新证据合并到该卡；
- weekly selection 是只读决策快照，不授予 CMS、search 或执行权限。

## 去重、过期与关闭

1. 相同 `cluster_uid` 的新证据更新 current card；重复卡标记 `superseded`。
2. 过期由各 source 声明的 `max_age` 决定；缺少 freshness authority 直接进入 held。
3. 单次 detector 未观测、GSC 暂缺或 source 失败不得自动关闭。
4. 自动关闭必须同时满足：所有 backing issue/opportunity 已 resolved/closed；同一 cluster 的
   最新 detector 或 public readback 直接证明 recovery condition；证据 fresh 且绑定当前
   authority/runtime revisions。
5. P0/P1 没有直接 recovery evidence 时保持打开。

生命周期为：`candidate → selected → in_progress → recovery_pending → closed`，任一 measurement
门禁失败可进入 `held`；重复事项进入 `superseded`。非法转换 fail closed。

## 最小实施交接

后续 `SEO-PLATFORM-09` 只需增加五个最小 runtime 边界：

1. unified decision read model；
2. versioned nullable-score priority evaluator；
3. bounded weekly selector；
4. idempotent cluster dedupe/lifecycle materializer；
5. read-only Workbench adapter。

直接复用 persisted detector `cluster_uid`/revisions、cluster evidence grouping、
`GscDataQualityGate`、Business Surface Matrix 与 Workbench 最大五张边界。现有 scorer 只有在
修正 nullable-score 和补齐 business/risk/freshness 输入后才能作为统一 scorer。

实施验收必须覆盖：rerun 幂等、同 cluster 单卡、held 时 null 而非 0、最多五张、P0/P1
直接证据、verified recovery 才关闭、Workbench 无写控件及无私人路径/raw query。

## Deferred / 真实未知

- 当前 weekly decision receipt：`unknown`；不得宣称已有真实决策卡。
- scheduler receipt：`production_unproven`；manual/external trigger 文案不证明定时运行。
- priority weight calibration：`unknown`；必须在后续实现中做 sensitivity validation。
- schema、runtime scorer、selector、scheduler、materialization 与真实决策创建均不属于本任务。
