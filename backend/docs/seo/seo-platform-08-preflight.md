# SEO-PLATFORM-08-PREFLIGHT：SEO Change / Experiment Ledger 实施合同

状态：`complete_preflight_docs_only`

证据截止：`2026-08-25T23:13:59Z`。本轮只读取当前 `fap-api` `origin/main`、Git/GitHub
历史、schema、服务、测试及 UI contract；没有建表、启用实验、修改 SEO 页面、写入 CMS/DB/cache、
执行 search submission 或生产写入。

结构化合同：`backend/docs/seo/generated/seo-platform-08-preflight.v1.json`。

GitHub 只读检索未发现标题或正文明确绑定 `SEO-PLATFORM-08` 的现行/历史 PR，因此没有可接续的
现有 PR。专项 title package 的历史 PR 仅用于核对 stage/promotion 事实，不恢复旧 PR 流程。

## 唯一当前结论

仓库已有可工作的产品/评分实验 primitives，但它们是 tenant-scoped 私有产品流程，不是公开 SEO
页面变更 ledger。专项 `zh-intp-a-seo-title` 文件只证明一种受限 title 变更曾具有独立 evidence 和
promotion 包；它不是通用 ledger，也不能证明当前线上实验状态。`SeoExperimentLedgerUiContract` 仍返回
`production_unproven`，页面不查 DB、不提供写动作、不渲染伪造行。

因此 `SEO-PLATFORM-08` 当前唯一能力状态为 `production_unproven`。可复用现有语义和只读 adapter，
但后续必须建立独立、公开 cohort 限定、可脱敏审计的 SEO Ledger；本 preflight 未实施该 runtime。

## 08-PF-A：Experiment Governance 复用审计

| 能力 | 当前用途与 authority | 边界 | 可复用 | 不可直接复用 / 语义差异 |
| --- | --- | --- | --- | --- |
| experiment registry / assigner | `config/fap_experiments.php` 定义 active variants；`ExperimentAssigner` 生成 sticky assignment，`experiment_assignments` 保存 anon/user assignment | tenant/private product data | key/variant、deterministic allocation 语义 | assignment 含 anon/user identity；SEO cohort 应由公开 authority selector 产生，禁止复制 assignment 行 |
| scoring model rollouts | `scoring_model_rollouts` 按 org、scale、model、variant 和 percent 路由评分模型 | tenant/private scoring authority | rollout percent、start/end、priority 的受控 rollout 概念 | authority 是评分模型，不是公开 URL、CMS revision 或 search surface |
| experiment guardrails | `experiment_guardrails` 保存 metric/operator/threshold/window/min sample/auto rollback | tenant rollout policy | metric、阈值、窗口、最小样本、触发时间 | 默认 auto rollback 操作评分 rollout；SEO 只能先生成 `rollback_required`，L3/L4 未授权 |
| rollout audits | `experiment_rollout_audits` 保存 org/rollout/key/action/status/reason/meta/actor | tenant/private audit | action、result、reason、actor reference、occurred time | 原始 `meta_json` 可能含私有漏斗上下文；SEO evidence 必须经过 allowlist 脱敏 |
| governance service | `ExperimentGovernanceService` 执行 approve/pause/rollback、guardrail upsert/evaluate 和 audit | owner/admin 写服务 | fail-closed transition、guardrail evaluation、idempotent audit 模式 | 直接调用会写评分 rollout；不得成为 SEO authority 或绕过未来 Policy Gateway |
| KPI evaluator | `ExperimentKpiEvaluator` 从 assignment/attempt/funnel 计算指标并可触发自动回滚 | tenant/private product funnel | 聚合 numerator/denominator、window、sample-state adapter 形状 | attempt/result/order/payment/user/anon 及低基数数据禁止进入 SEO ledger；不能把关联性当因果 |
| deploy events | `ops_deploy_events` 保存 env、revision、status、actor、meta 和 occurred_at | release evidence | exact deploy SHA、status、time；只读关联 | 不是内容 authority；原始 meta 必须 allowlist，deploy time 不能代替 change time |
| audit logs | `audit_logs` 保存 action/target/reason/result，同时包含 IP、UA、request id 和 meta | tenant/admin audit | sanitized action/result/reason 与不可逆 actor reference | IP、UA、request id、raw meta、私人 target 绝不进入 evidence bundle |
| 专项 SEO title experiment | `zh-intp-a-seo-title-v1.json` 与 production-promotion 包，Git 历史可追溯 stage/promotion | 单 locale、单 title surface 的专项 authority package | baseline/hypothesis/single-variable/evidence/negative guarantees 示例 | 不是通用 schema、当前生产 readback 或随机实验；不能外推排名/转化收益 |
| Experiment Ledger UI | `SeoExperimentLedgerUiContract::unavailableSnapshot()`；UI 无 DB、wire action 或 rows | read-only fail-closed placeholder | unavailable/null 表达和字段 vocabulary | 状态列表仅展示早期 UI copy；没有 unified read model，当前为 `production_unproven` |

现有 `v0.4` governance API 需要 `FmTokenAuth` + owner/admin，并直接写 tenant rollout；命令
`ops:experiment-guardrails-evaluate` 也要求正 org id，读取私有漏斗并可自动 rollback。两者都不能被 SEO
UI、Agent 建议或公开 cohort 间接调用。

## 08-PF-B：SEO Ledger 最小记录

每条记录共 23 个必需语义字段；字段允许在早期状态为显式 `null + evidence_state/reason`，但不得遗漏或
用 0 代替 unavailable。

| 字段 | 语义 / 必填时点 | authority 来源 | 隐私 |
| --- | --- | --- | --- |
| `ledger_id` | 创建时；canonical payload 的 UUID/opaque id | ledger service | public-safe opaque |
| `change_type` | 创建时；受版本化 enum 约束 | policy | public-safe |
| `hypothesis`, `rationale` | draft 时；可证伪陈述和理由分离 | actor + evidence refs | sanitized |
| `source` | detector/opportunity/root-cause id、version、evidence state | #7 / Issue / Opportunity | opaque refs only |
| `public_url_cohort` | authority selector、cohort digest、count；不得保存 raw URL/query | URL Truth + Page Family | public aggregate |
| `page_family`, `locale` | 必须已分类且 authority 明确 | Page Family Policy | public-safe |
| `authority_revision` | evidence_ready 前 | CMS/repository authority adapter | public-safe digest/revision |
| `baseline_window` | 起止、freshness、sample count、hold reason | metrics adapter | aggregate |
| `primary_metric` | 单一预注册 metric、formula、direction、minimum sample | policy + source | aggregate |
| `guardrail_metrics` | metric/operator/threshold/window/min sample | policy | aggregate |
| `observation_window` | canary 前冻结；含 lag/grace | policy | public-safe |
| `change_revision` | 实施后 exact CMS revision + deploy SHA，未知保持 null | CMS + deploy events | public-safe |
| `canary_scope`, `blast_radius` | canary_ready 前；签名 cohort 上限 | policy decision | aggregate |
| `public_runtime_readback` | canary/expanded 后；状态、revision、freshness | #7 read model | sanitized aggregate |
| `gsc_funnel_evidence_state` | `verified/observed/unknown/blocked`、lag、sample；不得含 raw query/private row | read-only GSC + aggregate adapter | aggregate only |
| `rollback_plan` | approved 前；target revision、trigger、owner、readback | authority + deploy | sanitized |
| `owner_actor` | owner role + opaque actor ref | auth/audit adapter | no identity payload |
| `approval_policy_decision` | decision id、policy version、scope、result/reason | future Policy Gateway | sanitized |
| `current_state` | 状态机唯一当前状态 | ledger transition service | public-safe |
| `close_reason` | closed/rejected/rolled_back 时必填 | actor + evidence | sanitized |

同一 change 必须由 `source → ledger_id → authority_revision → change_revision/deploy_sha → observation →
rollback/close` 串联。真实观测值 0 必须同时带 numerator、denominator、窗口和 fresh receipt；缺失、过期、
样本不足或 source 未连接统一为 `MEASUREMENT_HOLD`，数值为 null。

## 08-PF-C：状态机与权限

| 状态 | 进入条件 / 必须证据 | actor 与允许动作 | 停止、回退或关闭 |
| --- | --- | --- | --- |
| `draft` | 字段骨架、公开 scope 候选 | L1/owner：编辑、废弃 | 私有/不明 scope → rejected |
| `evidence_ready` | source、authority、baseline freshness 与 privacy guard 通过 | L1/owner：提交 policy review | stale/不足 → held 或 draft |
| `policy_review` | 完整证据 bundle、deterministic denials 通过 | owner/future gateway：评审 | deny → rejected；补证据 → held |
| `approved` | 签名 policy decision、rollback、metric/window、blast radius | owner/future gateway：批准准备 | decision 过期/authority 漂移 → held |
| `canary_ready` | exact candidate revision、bounded cohort、readback plan | L2：只准备，不写线上 | scope/revision 漂移 → held |
| `canary_running` | 未来 L3 gate 明确授权并产生 exact write receipt | future L3 only：启动/停止 canary | guardrail/P0/P1 → rollback_required |
| `observing` | canary readback 对齐，窗口开始 | L0：只读观察；owner 可 hold | 数据不足/过期 → held；失败 → rollback_required |
| `expanded` | 未来 L4 gate、完整 canary window、无 open guardrail | future L4 only：受限扩量 | guardrail/P0/P1 → rollback_required |
| `held` | measurement/policy/runtime/authority 阻塞理由 | L0/L1/owner：补证据、保持 | 条件恢复回最近安全前置态；不能写 |
| `rollback_required` | 直接 guardrail/P0/P1/readback mismatch | future gateway/owner：执行既定 rollback | 未有 rollback receipt 不得称 rolled_back |
| `rolled_back` | target revision 已恢复且 public readback 对齐 | L0/owner：观察、关闭 | 恢复不完整保持 rollback_required/held |
| `closed` | observation complete，结果为 kept/no-effect/inconclusive，证据齐 | owner：只读归档 | terminal；更改需新 ledger |
| `rejected` | deterministic denial 或 policy deny，原因齐 | owner/gateway：只读归档 | terminal；重新提议需新 ledger |

合法主路径为：
`draft → evidence_ready → policy_review → approved → canary_ready → canary_running → observing →
expanded → closed`。允许的安全旁路只有显式 `held`、`rollback_required → rolled_back → closed` 和
`draft/evidence_ready/policy_review → rejected`；任何未列 transition fail closed。

权限固定为：L0 只读；L1 仅建议/draft；L2 仅受控准备 candidate、证据和签名 scope。L3/L4 的 CMS、
URL Truth、production 或扩量写入保持 disabled，直到未来 Policy Gateway 和 Post-#12 Activation Gate
同时明确授权。记录存在、UI 操作、Agent 建议、owner 身份本身都不授予写能力。

确定性否决：private URL/entity、authority unknown、`unclassified` family、evidence 不足或过期、open
P0/P1、rollback 不可用、请求 search submission、blast radius 超出签名 scope。所有状态始终保持
`search_submission_allowed=false`。

## 08-PF-D：指标、隐私、证据与实施交接

数据源优先级：#7 unified Runtime SLO（尚未发布）用于 public health/readback；URL Truth 与 Page Family
用于 cohort/authority；Issue/Opportunity Queue 用 source id；CMS/repository authority 用内容 revision；
`ops_deploy_events` 用 exact deploy SHA；read-only GSC aggregate 用 delayed search observation；私有 funnel
只能经 k-anonymity/min-sample adapter 输出 aggregate state。任何 source 未连接均显式 unavailable。

Baseline 必须在 change 前冻结为可比 family/locale/cohort 和窗口。Primary metric 只允许一个；guardrail
可多项但必须预注册 direction、operator、threshold、window、minimum samples。Canary 必须有不重叠的
authority-defined control（若存在）、bounded scope、相同观察窗口、GSC lag/grace 和 stop condition。
没有随机化或可信外生分配时，只能报告关联性 observation；seasonality、新页冷启动、GSC 延迟、低样本、
control 污染、runtime/canonical/noindex incident 任一成立即 `MEASUREMENT_HOLD`，不得声称因果、排名、
转化或收益提升。

Evidence bundle 只允许：opaque ids、public family/locale、cohort digest/count、aggregate numerator/
denominator、metric state、时间窗口、authority/change/deploy revision、policy/audit result、sanitized
public readback。禁止 raw URL/query/response、attempt/result/report/order/payment/token、user/anon id、IP、
User-Agent、request id、低基数切片、内部拓扑和可逆敏感 hash。

后续最小实现顺序：

1. 建版本化 SEO ledger schema + privacy validator + deterministic transition/denial policy；
2. 增加 URL Truth/Page Family、#7 runtime、CMS revision、deploy event、GSC aggregate 的只读 adapters；
3. 建 append-only transition/audit service 与 sanitized evidence bundle，不复用私有 assignment 行；
4. 发布 read-only snapshot API/read model，UI 先只读绑定；
5. 在未来 Policy Gateway/Post-#12 gate 之前，只开放 L0–L2；L3/L4 保持 disabled。

聚焦测试必须覆盖 23 字段条件、13 状态及非法转换、八项 deterministic denial、private/low-sample
脱敏、null 与真实 0、GSC lag/seasonality hold、revision trace、canary scope、rollback readback、UI/API
parity，以及不存在 search submission/write capability。上线验收需要 exact-SHA CI、staging read-only
contract smoke、production sanitized snapshot/readback 和权限负向测试；本 preflight 不执行这些 runtime
上线步骤。

## Deferred / 外部非阻塞项

- #7 unified runtime read model 未发布，public runtime readback source 保持 `production_unproven`。
- Policy Gateway 与 Post-#12 Activation Gate 尚未提供 L3/L4 authority；写能力保持 disabled。
- GSC 当前只允许 read-only aggregate evidence；因延迟和非随机分配，默认只能作关联性观察。
- `read_only_gsc=true`；`search_submission_allowed=false`。
