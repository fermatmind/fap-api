# SEO-PLATFORM-11A Inventory Preflight

状态：`draft_unfrozen`。这是 #11 的静态前置盘点，不是 Council、Role、Prompt、Policy 或 Skill 的正式冻结与启用。

## 证据边界

| 项 | 固定值 |
| --- | --- |
| evidence cutoff | `2026-08-26T00:30:59Z` |
| `fap-api` evidence SHA | `d80ac79119d54358c2a613be8e82c917cf823fde` |
| `fap-web` evidence SHA | `90bcaf7e6b1a53d679d56df6de643b6ae8725410` |
| 唯一合同 authority | `fap-api/backend/docs/seo` |
| `fap-web` 角色 | 只读证据源，不提交、不授权 |

当前树扫描是调用事实的唯一依据；Git 历史只可证明删除、替代或 superseded，不可覆盖当前注册和调用事实。机器可读清单位于 `generated/seo-platform-11a-inventory-preflight.v1.json`。

## 可复现清单

扫描规则不把命令数量作为未来常量：每次重跑均匹配 `backend/app/Console/Commands/SeoAgent*.php`。本次固定 SHA 上观察到 35 个命令、5 个 `backend/app/Services/SeoAgent` 服务。命令目录由 `Kernel::commands()` 自动加载；其中 32 个还显式列在 `$commands`，`l5a-candidate-review`、`l5a-cms-draft-write-canary` 和 `replace-article-covers` 仍可由目录自动发现。Laravel scheduler 只包含 `seo:warm-sitemap-source-cache`，没有 `seo-agent:*` 调度。

`fap-web` 在固定 SHA 上观察到：6 个直接相关 Skill 目录、`docs/seo/agent/**` 47 条路径、`scripts/seo/**` 138 条路径、Agent 相关 contract test 44 条。`package.json` 暴露四个只读合同检查、一个 code PR writer，以及 sitemap/runtime/live check 和 `seo:push-baidu` 等确定性入口；没有统一 SEO Council runner 或统一 Admission 服务。

所有清单项统一记录 repository、path、type、entrypoint、callers、input、output、write permission、model invocation、scheduler、authority、test evidence、capability state 和 evidence state。分类只能是：

- `retained_agent`
- `bounded_capability`
- `deterministic_tool`
- `review_mode`
- `contract_only`
- `historical_or_superseded`
- `retire_candidate`

### 当前能力结论

| 能力面 | 分类 | 当前事实 | 未来方向 |
| --- | --- | --- | --- |
| `SeoAgent*` 命令面 | `bounded_capability` | 35 个确定性 CLI，读、审、artifact、CMS 与 search 写语义混合 | 逐项 adapter、merge、disable；不立即 retire |
| 只读 scanners / aggregator / compiler | `deterministic_tool` | 不调用模型；产生读模型或 artifact | 优先接入只读 Tool Adapter |
| QA / readiness / readback | `review_mode` | 只输出判断证据 | 接入 review adapter |
| `seo-agent:run` 与 weekly runner | `retire_candidate` | 重复编排，weekly 是 run 的包装 | 合并到未来唯一 Admission，不立即退役 |
| priority scheduler / weekly writer | `retire_candidate` | 外部 cron 语义，未进入 Laravel scheduler，包含 CMS/search 写链 | 保持禁用，等待正式 Policy |
| Ops Agent Council | `contract_only` | capability 为空、`production_unproven`、L0 read-only | 保持占位，不注入模拟 Agent 数据 |
| `fap-web` SEO Skills | `bounded_capability` | Codex/operator runbook，存在混合写语义 | 仅路由和证据，不取得后端 authority |
| `fap-web` Agent 合同 | `historical_or_superseded` | 现行、HOLD 与旧人工 approval 叙述混合 | 只作证据，正式 Policy 冻结后再迁移 |
| `fap-web` deterministic tools | `deterministic_tool` | 138 条脚本；多数检查/生成，`push-baidu` 是外部写例外 | 分拆只读 adapter；外部写保持禁用 |

## 全部 `SeoAgent*` 命令处置

下面 35 行与 JSON 一一对应。`disable` 表示未来 Council 不得调用，不改变命令当前代码或注册；`merge` 和 `retire` 都是迁移建议而非本次动作。

| 命令 | 分类 | 建议 | 证据与迁移依赖 |
| --- | --- | --- | --- |
| `article-cms-publish-canary` | `bounded_capability` | `disable` | 发布写；等待 CMS authority 与双阶段 Policy |
| `article-draft-claim-risk-qa` | `review_mode` | `tool_adapter` | 只读 claim QA |
| `article-draft-preview-runtime-qa` | `review_mode` | `tool_adapter` | 只读 preview/readback |
| `article-post-publish-propagation-dry-run` | `review_mode` | `tool_adapter` | URL Truth/search 仅规划 |
| `article-release` | `review_mode` | `tool_adapter` | no-write release gate |
| `auto-rollback-guard` | `bounded_capability` | `retain` | stop-the-line 安全能力；Council 使用仍需 Phase 2 |
| `cms-draft-package-dry-run` | `deterministic_tool` | `tool_adapter` | artifact-only |
| `cms-draft-payload-repair-canary` | `bounded_capability` | `disable` | 追加 CMS draft revision |
| `cms-draft-readback-qa` | `review_mode` | `tool_adapter` | post-action readback |
| `cms-draft-write` | `bounded_capability` | `disable` | 显式模式可写 CMS draft |
| `cms-faq-gap-scan` | `deterministic_tool` | `tool_adapter` | 只读 scanner |
| `cms-publish-auto-canary` | `bounded_capability` | `disable` | AutoApprovalPolicy 可准入发布 |
| `cms-publish-canary` | `bounded_capability` | `disable` | ContentPage 发布写 |
| `cms-tdk-gap-scan` | `deterministic_tool` | `tool_adapter` | 只读 scanner |
| `codex-review-runner` | `review_mode` | `tool_adapter` | 确定性 verdict，不调用 Codex/model API |
| `compile-mode-c-package` | `deterministic_tool` | `tool_adapter` | 确定性 compiler |
| `gsc-batch-draft-qa-support` | `review_mode` | `tool_adapter` | 只读批量 QA |
| `gsc-cohort-handoff` | `deterministic_tool` | `tool_adapter` | GSC artifact 转换，不写 CMS |
| `gsc-draft-publish-gate-readiness` | `review_mode` | `tool_adapter` | 只读 publish readiness |
| `gsc-opportunity-auto-draft` | `deterministic_tool` | `tool_adapter` | 仅 review/dry-run artifacts |
| `gsc-post-publish-feedback` | `review_mode` | `tool_adapter` | GSC 只读反馈 |
| `gsc-remaining-candidate-batch-plan` | `review_mode` | `tool_adapter` | 建议批次，不执行 |
| `l5a-candidate-review` | `review_mode` | `tool_adapter` | 只读候选审查；L5 激活不在本范围 |
| `l5a-cms-draft-write-canary` | `bounded_capability` | `disable` | CMS draft 写；等待正式 Policy |
| `l5a-contentpage-publish-canary` | `bounded_capability` | `disable` | CMS publish；等待正式 Policy |
| `l5a-indexnow-submit-canary` | `bounded_capability` | `disable` | 外部 IndexNow 写；等待 search authority |
| `opportunity-aggregate` | `deterministic_tool` | `tool_adapter` | 接入 #9 decision read model/scorer |
| `post-publish-indexnow-auto` | `bounded_capability` | `disable` | 外部 IndexNow 写 |
| `post-publish-search-submit` | `bounded_capability` | `disable` | 写 Search Channel queue，虽不直接外发 |
| `priority-queue-scheduler` | `retire_candidate` | `disable` | 重复外部 orchestrator 且含写链；未来合并 |
| `replace-article-covers` | `bounded_capability` | `disable` | CMS/Media Library 写 |
| `run` | `retire_candidate` | `merge` | readonly L4 名称与编排重复；并入单一 Admission |
| `runtime-seo-qa-scan` | `deterministic_tool` | `tool_adapter` | 只读 runtime QA |
| `weekly-draft-write-auto` | `retire_candidate` | `disable` | 重复 weekly orchestrator 且写 CMS draft |
| `weekly-readonly-runner` | `retire_candidate` | `merge` | `run` 的 weekly 包装；并入单一 Admission |

没有命令建议立即 `retire`：当前证据不能同时证明替代路径已经启用且调用者为零。正式退役必须再次做引用扫描、运行证据和回滚窗口验证。

## 冲突清单

### 重复 Orchestrator

`seo-agent:run`、`seo-agent:weekly-readonly-runner`、`seo-agent:priority-queue-scheduler`、`seo-agent:weekly-draft-write-auto` 与 `fap-web` Skill/package runners 形成多入口编排。它们有不同的 evidence、写权限和调度假设，不可直接共同成为 Council。未来只保留一个 Admission 入口，领域工作下沉到确定性 adapters。

`backend/app/Services/Agent/AgentOrchestrator.php` 是 wellbeing 通知域，由 `AgentTickJob` 调用，不属于 SEO；名称相似不等于 SEO authority，保留并明确排除。

### 重复 Policy

`AutoApprovalPolicy`、Page Family `PolicyGuard/Registry`、`fap-web` Skill guardrails 和 `docs/seo/agent` 旧人工 approval 合同分别约束不同阶段。当前没有一个合同同时覆盖 actor、scope、authority、evidence、permission、cost、negative-set、post-action readback 与 rollback，因此不得将任一现有 Policy 提升为 Council 总 authority。

### 前后端 authority 冲突

`fap-web` Agent/Skill、code PR writer 与 `push-baidu` 可作为实现工具或证据源，但不得成为 CMS、内容、URL Truth、search submission 或执行授权 authority。任何前端入口未来都必须提交相同 Admission envelope，由后端明确的领域 authority 判定。

## 唯一 SEO Council 草案

以下全部是 `draft_unfrozen`：

1. 薄 Codex Skill 只做 intent routing、evidence packaging 和 Admission request；不得承载领域逻辑、写权限、Policy override 或直接执行。
2. CLI、Ops UI、scheduler、API 与 Codex Skill 使用同一 Admission envelope：`request_id`、actor、intent、scope、authority claim、evidence refs、requested actions、cost bound、negative set 和 idempotency key。
3. Phase 1 在执行前检查 actor、scope、authority、evidence、权限、成本、negative-set 与 allowed actions；任何缺项 fail closed。
4. Phase 2 在执行后验证 result hash、实际副作用、readback、canary、circuit breaker 和 rollback，再决定 accept/reject；执行成功不等于结果被接受。
5. L3、L4、runtime model invocation、Codex Skill activation 以及 Role/Prompt/Policy freeze 均保持禁用。

## 分阶段迁移

| 阶段 | 动作 | 退出条件 |
| --- | --- | --- |
| 0 | 保留当前实现和注册，不激活、不改 authority | 本清单可在固定 SHA 重现 |
| 1 | 将只读 scanners、reviewers、compiler、aggregator adapter 化 | adapters 的 I/O、negative-set 和失败关闭合同验收 |
| 2 | 等 #6–#10 生产合同稳定后冻结统一 Admission 和双阶段 Policy | authority、evidence、failure、rollback 合同验收 |
| 3 | 合并 runner，禁用危险直达入口 | 所有入口只进入同一 Admission 服务 |
| 4 | 仅激活单独授权的 bounded capability | canary/readback 通过；L3/L4 仍需另行授权 |
| 5 | 替代和零调用均有证明后退役旧 wrapper | 引用、运行证据与回滚窗口完整 |

## 验收与禁止项

- `docs_contracts_only=true`
- `read_only_gsc=true`
- `search_submission_allowed=false`
- `cms_write_allowed=false`
- 不创建 Orchestrator、数据库或 migration。
- 不调用模型，不启用 Skill，不激活 scheduler/queue，不退役命令。
- 不实现 L3/L4，不替换 SEO Operations 占位数据。
- 不冻结 Role/Prompt/Policy。

本合同只回答“现有什么、冲突在哪里、未来如何收敛”。它不授予任何 runtime、CMS、search、发布或生产 authority。
