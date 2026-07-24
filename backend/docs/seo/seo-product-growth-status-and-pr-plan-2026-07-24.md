# FermatMind SEO 产品增长系统：完成状态与 PR 规划

Date: 2026-07-24

Status: planning and evidence ledger only

## 1. 文档目的

本文件把 2026-07-23 至 2026-07-24 本轮 SEO / Career 对话中的已完成工作、生产证据、未完成边界和下一阶段 PR 计划放到同一份后端技术文档中。

本文件不等同于发布证明，也不执行以下动作：

- 不部署 staging 或 production；
- 不修改 CMS、数据库权威、publication、indexability、sitemap、`llms` 或 Search Channel；
- 不调用 GSC Request Indexing，不提交 Google、Baidu 或 IndexNow；
- 不把 Career 10K 本地资产发布到生产；
- 不修改 `docs/codex/pr-train.yaml` 或 `docs/codex/pr-train-state.json`。

所有状态必须使用下列标签，禁止把代码合并写成生产完成：

| 标签 | 含义 |
| --- | --- |
| `MERGED_CODE` | GitHub 已确认 PR 合并；不证明生产已部署。 |
| `PRODUCTION_EVIDENCE` | 有 exact SHA、run、receipt 或只读 readback 证明的生产事实。 |
| `LIVE_OBSERVATION` | 指定时间的公开 HTTP / sitemap 只读快照。 |
| `PLANNED` | 已规划但未实施。 |
| `HOLD` | 门禁未满足，不允许进入下一阶段。 |

## 2. 当前结论

费马测试最稳的 SEO 路线不是继续增加页面数量，而是建设一个产品型内容增长系统：

```text
稳定可访问
  -> URL 真相干净
  -> 一个搜索意图对应一个权威页面
  -> 内容引导真实测评
  -> 结果与行动闭环
  -> GSC + 产品漏斗验证
  -> 只扩张已经胜出的页面家族
```

当前 P0 是恢复核心测试详情页，不是扩页。

2026-07-24 09:27 CST 的公开只读复核显示：

- `robots.txt`、`sitemap.xml`、`llms.txt`、`/zh/tests` 和 `/zh/career` 返回 HTTP 200；
- `sitemap.xml` 当前有 881 个 `<loc>`；
- 其中 articles 111、career 387、personality 322、tests 16；
- sitemap 中 12 个 EN/ZH 核心测试详情 URL 全部返回 HTTP 500：
  - MBTI；
  - Big Five；
  - Enneagram；
  - RIASEC；
  - IQ；
  - EQ。

因此：

1. 暂停新的文章、职业、人格或测试页扩张；
2. 先完成核心测试详情 500 的共享根因诊断与修复；
3. sitemap 仍列出这些 500 URL，URL Truth 不能判定为健康；
4. 在核心测试 12/12 连续稳定 200 前，不执行 Career 搜索入口放大或文章恢复发布；
5. sitemap URL 数量不是增长 KPI。

Google 对持续 5xx 的公开说明：爬虫会降低抓取频率，持续失败的 URL 最终可能被移出索引：

- <https://developers.google.com/crawling/docs/troubleshooting/http-status-codes>

## 3. 时间快照不能混用

| 快照 | sitemap 总数 | 主要观察 | 用途 |
| --- | ---: | --- | --- |
| 2026-07-23 22:04–22:06 CST 操作者复核 | 522 | personality 约 322、articles 111、career 28、tests 16；核心测试详情 500 | 历史事故快照 |
| 2026-07-24 09:27 CST 本文只读复核 | 881 | personality 322、articles 111、career 387、tests 16；12 个核心测试详情全部 500 | 当前规划基线 |
| `CAREER-SEARCH-ENTRY-QUALITY-REAUDIT-01` 成功 artifact | 2612 | career detail 2092；当时 legacy jobs index EN/ZH 504 | 2026-07-22 Career re-audit 证据 |

三个快照来自不同时间，不能互相覆盖。522、881 和 2612 的变化本身就是需要治理的 sitemap / authority observability 问题。

后续 artifact 必须记录：

- UTC 和 Asia/Shanghai 时间；
- public web SHA（可取得时）；
- backend active SHA（可取得时）；
- sitemap body SHA256；
- 总 URL 和 family count；
- 500、404、redirect、noindex、canonical mismatch 数量；
- 数据来源和是否发生 fallback。

## 4. 本轮已经完成的工作

### 4.1 Career 搜索入口基础设施

以下状态由 GitHub merge 事实确认：

| 工作 | 状态 | PR / merge commit | 已完成边界 |
| --- | --- | --- | --- |
| `CAREER-JOBS-INDEX-LKG-RESILIENCE-01` | `MERGED_CODE` | fap-api #3213 / `31819becfcf8da0b3fd9ae4f301b011e031374f` | legacy jobs index active → LKG、冷缓存 503、双语原子 warm 合约。 |
| `CAREER-PILOT-REVIEW-EVIDENCE-BRIDGE-01` | `MERGED_CODE` | fap-api #3214 / `65765dde98a9ac96bf6a67ae514a6730bc7fab71` | exact bilingual content/SEO/claim target SHA 与 fail-closed reviewer evidence 投影。 |
| `CAREER-PUBLISH-TRACK-COVERAGE-RECONCILE-01` | `MERGED_CODE` | fap-api #3220 / `9aaf047f0cbb27e1b10440b9856a74d16e5e6ce0` | 1046-member publish-track 解析优先级和保守 reconciliation。 |
| full job-index warm preservation | `MERGED_CODE` | fap-api #3224 / `3c32e64327e23a5b60c5589bbb6acec7c8ebc563` | 防止 warm 只生成 1 item。 |
| candidate-runtime direct detail repair | `MERGED_CODE` | fap-api #3233 / `bea536e8f072b2c3cff8b61884655d67190217e8` | inactive candidate 自身代码同步写 v3 cache，零 queue dispatch。 |
| exact single-target diagnostics | `MERGED_CODE` | fap-api #3245 / `cc708372e721145e4820be708609bbf4f71f39f6` | 单 slug/locale typed warm failure evidence 与 5000ms offline budget。 |
| exact-active same-SHA inactive candidate fast path | `MERGED_CODE` | fap-api #3255 / `bba8a11756b45faaa0d652365167e5c8434ef82a` | exact current active SHA 可创建不同 inactive release path；保留 active revision、path isolation、zero activation。 |
| `CAREER-SEARCH-ENTRY-QUALITY-REAUDIT-01` | `MERGED_CODE` | fap-web #1798 / `aad94bdb186ddb0b0d4196c9fe506bff774b6e3d` | 重跑 1046 职业 search-entry re-audit；当前结论 `NO_GO`。 |

补充说明：

- 两仓库部分 PR-train state ledger 仍保留 merge 前状态；上表使用 GitHub `MERGED` 和 `origin/main` merge commit 作为事实，不把陈旧 ledger 当成未合并。
- 本文件不顺手修复跨仓库 ledger；该 bookkeeping 不阻塞本次文档规划。

### 4.2 已完成的受控生产证据

本轮完成过一次 exact 单目标 candidate-runtime warm 诊断：

- target：`command-and-control-center-specialists / en`；
- candidate SHA：`cc708372e721145e4820be708609bbf4f71f39f6`；
- staging run：`30021878766`；
- 结果：目标成功生成 cache pointer；
- 当时 coverage 从 361/2092 增长到 362/2092；
- 当时 remaining missing 为 1730，broken/excluded 为 0/0。

这只证明单目标路径可用，不证明剩余 1730 已补齐。

### 4.3 尚未完成的 Career 生产闭环

以下全部保持 `HOLD`：

- 新 exact-active same-SHA inactive candidate 的 materialize；
- 新 verify-only 和当前 coverage fingerprint；
- 剩余 detail cache 补齐到 2092/2092；
- production candidate activation；
- activation 后 EN/ZH jobs index warm；
- EN/ZH jobs API 1046/1046 只读 readback；
- 10 个候选职业的当前 reviewer evidence production bind；
- 10 slug / 20 URL exact pilot readiness artifact；
- Career Search Channel canary；
- IndexNow / Baidu provider preflight、enqueue、approve 或 live submit。

Google 不进入 submit executor。Google 只通过现有 sitemap 和 GSC 观察。

## 5. 原 9 个 SEO 任务状态

| # | 任务 | 当前状态 | 证据与下一步 |
| ---: | --- | --- | --- |
| 1 | `SEO-10K-GSC-LIVE-CANARY-RUN-01` | `PLANNED` | 现有只读 adapter 和最多 10 行 importer 已存在，但本 exact 线上任务没有执行证据。先完成任务 2，再运行独立受控操作。 |
| 2 | `SEO-10K-GSC-BOUNDED-BACKFILL-01` | `PLANNED` | 当前 importer 仍是最多 10 行 canary，不支持完整 page/query cohort。 |
| 3 | `SEO-10K-SITEMAP-FAMILY-OBSERVABILITY-01` | `PLANNED` | 当前 sitemap family count 可外部计算，但未提供稳定子 sitemap 观测面。 |
| 4 | `CAREER-SEARCH-ENTRY-QUALITY-REAUDIT-01` | `MERGED_CODE` | fap-web #1798；Tier A 0、Tier B 1、Tier D 1045，结论 `NO_GO`。 |
| 5 | `CAREER-SEARCH-ENTRY-TIER-CONTRACT-01` | `PLANNED` | 后端尚无明确、可消费的 `search_entry_tier` 权威字段。 |
| 6 | `CAREER-DIRECTORY-PAGINATION-FOLLOW-01` | `PLANNED` | exact task 尚未落地；必须保持筛选/分页 `noindex,follow` 和 root canonical。 |
| 7 | `CAREER-SEARCH-ENTRY-QUALITY-BATCH-01` | `PLANNED` | 50 职业内容/claim/FAQ/内链/review 批次未实施。 |
| 8 | `CAREER-SEARCH-ENTRY-BATCH-01-APPLY` | `HOLD` | 受控线上操作；任务 7 artifact、核心测试 200、Career 2092/2092 和 exact approval 均未满足。 |
| 9 | `SEO-10K-ARTICLE-RECOVERY-BATCH-01` | `PLANNED` | 5 篇现有文章恢复批次未实施；必须先取得完整 GSC page/query evidence 和 query-owner 结果。 |

完成度：

- 已完成：1/9；
- 未完成 PR：6 个；
- 未完成受控线上操作：2 个；
- 不得以“相关基础代码存在”替代 exact task 完成证据。

## 6. SEO 产品增长原则

### 6.1 SEO 可用性 SLO

业务优先级固定：

1. L1：MBTI；
2. L2：Big Five；
3. L3：RIASEC、Career、articles 和其他 tests。

核心入口必须观察：

- HTTP status；
- TTFB；
- SSR 可见正文；
- canonical；
- robots；
- backend/CMS API dependency 状态；
- 主 CTA 是否可用；
- LKG / minimal shell 状态。

高流量 CMS 页面只允许：

```text
CMS/API -> stale last-known-good -> minimal shell
```

minimal shell 不得伪装完整正文或自动取得 indexability；frontend 不得新增 CMS-backed editorial fallback。

### 6.2 URL Truth 与 query owner

进入 sitemap 的 URL 必须同时满足：

- HTTP 200；
- backend/CMS 已授权发布；
- indexable；
- self-canonical；
- 有真实可见正文；
- 有公开内部链接入口；
- 不属于订单、attempt、私人 report、找回或支付路径。

每个主要搜索意图必须只有一个 owner URL。canonical、hreflang、内部链接和 sitemap 必须指向同一 owner。

Google 参考：

- sitemap：<https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap>
- canonical：<https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls>
- 多语言：<https://developers.google.com/search/docs/advanced/crawling/managing-multi-regional-sites>

### 6.3 三个主题权威集群

第一阶段只建设：

- MBTI：模型入门、结果变化、类型边界、MBTI vs Big Five、结果解读；
- Big Five：OCEAN 五维、分数解释、去标签化、工作倾向、证据等级；
- RIASEC / Career：兴趣代码、60/140 版本、结果解释、career shortlist、现实验证。

每个集群遵循：

```text
Hub -> Pillar -> Supporting articles -> Test -> Result guide -> Method / Boundary
```

文章必须支持真实产品行动，不为 URL 数量而存在。每月 4–6 个 evergreen 资产是上限建议，不是发布配额。

Google 参考：

- people-first content：<https://developers.google.com/search/docs/fundamentals/creating-helpful-content>
- scaled content abuse：<https://developers.google.com/search/docs/essentials/spam-policies>

### 6.4 证据与 claim boundary

- 明确方法、版本、作者、编辑、review state 和更新时间；
- 引用公开来源，区分 evidence、limited evidence 和 `Unknown`；
- 解释测评能做什么、不能做什么；
- Career 只能作为探索线索，不能承诺精准匹配、录用、收入或成功；
- MBTI / Enneagram 不定义一个人；
- Big Five 不等于招聘预测；
- 临床敏感内容不承担普通获客任务；
- schema 只能描述用户可见内容，不能替代 reviewer evidence。

结构化数据规范：

- <https://developers.google.com/search/docs/appearance/structured-data/sd-policies>

### 6.5 SEO 产品漏斗

北极星路径：

```text
impression
  -> organic click
  -> test detail
  -> start_test
  -> complete_test
  -> view_result
  -> save / deeper interpretation
```

页面家族最少观察：

- non-brand impressions、clicks、CTR、average position；
- Top 3 / Top 10 query 数；
- 同一 query 对应多个 FermatMind URL 的比例；
- 每 1000 次 organic impressions 产生的 `start_test`；
- `start_test -> complete_test -> view_result` 转化率；
- 每个 indexed URL 带来的有效 `start_test`。

GSC 是搜索反馈，不是订单、支付或收入真相。业务事件继续以后端 analytics / attempt / result authority 为准。

### 6.6 D1 / D7 / D14 / D28

| 窗口 | 必查内容 | 允许动作 |
| --- | --- | --- |
| D1 | status、canonical、robots、SSR、schema、internal links | 修复硬故障；不判断排名输赢 |
| D7 | crawl、初步 impressions、query ownership | 记录早期信号；不批量扩页 |
| D14 | ranking、CTR、cannibalization、funnel trend | 形成保留/重写假设 |
| D28 | 稳定搜索与产品信号 | 人工决定保留、重写、合并、回滚或扩张 |

只有 D14 和 D28 均稳定、证据完整且人工批准的 family 才能扩张。

### 6.7 可引用原创资产

长期外部引用优先来自：

- 方法与边界说明；
- Career 数据来源、字段、更新周期和限制；
- 原创比较工具、decision checklist 和 result interpreter；
- 通过隐私审查的匿名聚合观察；
- 年度方法复盘或职业探索报告；
- 真实、可验证的 expert review 或合作。

禁止购买链接、低质量 guest-post network 或虚构背书。

## 7. PR 执行顺序

### Phase P0：恢复核心测试

#### P0-1 `SEO-CORE-TEST-DETAIL-500-INCIDENT-01`

- Repo：fap-web
- Proposed branch：`codex/seo-core-test-detail-500-incident-01`
- Proposed title：`SEO-CORE-TEST-DETAIL-500-INCIDENT-01 restore core test detail availability`
- Depends on：无
- Scope：
  - 对 sitemap 中 12 个 EN/ZH 核心测试详情建立共享路由回归矩阵；
  - 分离 metadata、page body、CMS landing surface、test lookup、rollout identity 和 SSR 失败阶段；
  - 修复共享根因；如果证据指向 fap-api，停止 frontend 推测性修复，创建单独 backend ad-hoc repair；
  - 保持 backend/CMS authority、LKG 和 minimal-shell 边界；
  - 不修改测试内容、indexability 或 sitemap membership。
- Acceptance：
  - 12/12 exact sitemap URL 连续三轮 HTTP 200；
  - HTML 有非 shell 的 H1、核心正文和 start CTA；
  - self-canonical、robots 和 hreflang 正确；
  - API 故障时 LKG 或 honest minimal shell，不返回伪完整内容；
  - MBTI / Big Five / Enneagram / RIASEC / IQ / EQ take flow 不回归。

这是下一项独立执行任务。任何扩张任务都依赖它。

### Phase P0：建立分级 SLO

#### P0-2 `SEO-CORE-ENTRY-SLO-OBSERVABILITY-01`

- Repo：fap-api
- Proposed branch：`codex/seo-core-entry-slo-observability-01`
- Proposed title：`SEO-CORE-ENTRY-SLO-OBSERVABILITY-01 add tiered SEO entry SLO`
- Depends on：`SEO-CORE-TEST-DETAIL-500-INCIDENT-01`
- Scope：
  - read-only probe L1/L2/L3 exact URL manifest；
  - status、TTFB、SSR marker、canonical、robots、CTA 和 upstream state；
  - 生成脱敏 artifact 和 ops read model；
  - L1 MBTI 失败优先于 L2/L3 告警；
  - 默认零外部写、零 CMS/DB authority mutation。
- Acceptance：
  - deterministic manifest 和 bounded concurrency；
  - private URL exclusion；
  - 5xx、thin shell、canonical/robots drift 分别分类；
  - failure artifact 不泄露 secret、raw header 或私人 URL。

### Phase P1：观测和 URL 真相

#### P1-1 `SEO-10K-GSC-BOUNDED-BACKFILL-01`

- Repo：fap-api
- Depends on：P0-1
- Outcome：在现有最多 10 行 canary 之上增加有上限、可恢复、幂等的 page/query/query×page read-model import。
- Hard boundary：不新增 Google credential flow，不 request indexing，不提交 sitemap，不触发 CMS 或 Search Channel。

#### P1-2 `SEO-10K-GSC-LIVE-CANARY-RUN-01`

- Type：受控线上操作，不是 PR。
- Depends on：P1-1 已部署、exact artifact preflight。
- Outcome：使用现有只读 adapter 生成脱敏 artifact，执行最多 10 行 canary 和 readback。
- Required separate confirmation：artifact SHA、row count、target table、active SHA 和 zero-CMS/search-submission boundary。

#### P1-3 `SEO-10K-SITEMAP-FAMILY-OBSERVABILITY-01`

- Repo：fap-web
- Depends on：P0-1
- Outcome：articles、careers、personality、tests 等稳定 family sitemap；root sitemap 的 URL union 不变。
- Hard boundary：不增加 URL，不改变 backend indexability authority，不在 frontend 发明 sitemap member。

#### P1-4 `SEO-QUERY-OWNER-URL-TRUTH-01`

- Repo：fap-api
- Depends on：P1-1、P1-3
- Outcome：为 priority query family 建立 backend-authoritative owner、alternate/supporting URL 和 conflict read model。
- Acceptance：canonical、hreflang、sitemap member 和 internal-link target 必须收敛到同一 owner；一条 query 多 owner 时 fail closed。

#### P1-5 `SEO-SEARCH-TO-RESULT-FUNNEL-01`

- Repo：fap-api
- Depends on：P1-1、P1-4
- Outcome：把 GSC landing-page aggregate 与现有 `start_test`、`complete_test`、`view_result` 日聚合按 privacy-safe canonical hash 和 date window 连接。
- Hard boundary：不把 GSC 当 purchase/revenue truth；不存 raw personal result、order、attempt 或 payment URL。

### Phase P2：恢复已有资产

#### P2-1 `SEO-10K-ARTICLE-RECOVERY-BATCH-01`

- Repo：fap-api
- Depends on：P1-2、P1-4、P1-5
- Outcome：只修复本周期损失最大的 5 篇现有文章，不创建新 URL。
- Gate：exact GSC page/query evidence、query owner、claim/source review、dry-run package、manual approval。
- Observation：D1/D7/D14/D28；D28 前不扩到第二批。

### Phase P2：Career 小批量

Career 路径还额外依赖以下生产门禁：

```text
current verify-only
  -> inactive candidate
  -> detail coverage 2092/2092
  -> production activation
  -> EN/ZH jobs index warm
  -> 1046/1046 API readback
```

#### P2-2 `CAREER-SEARCH-ENTRY-TIER-CONTRACT-01`

- Repo：fap-api
- Depends on：merged re-audit、merged reviewer bridge、merged publish-track reconciliation，以及上面的 production readback。
- Outcome：增加明确的 `search_entry_tier` 权威字段，区分 public visibility 与 search-entry eligibility。
- Hard boundary：`robots_indexable=true` 不能单独进入扩张队列；`review_needed`、hold 和 stale reviewer evidence 均 fail closed。

#### P2-3 `CAREER-DIRECTORY-PAGINATION-FOLLOW-01`

- Repo：fap-web
- Depends on：P0-1、P1-4
- Outcome：分页和筛选状态使用 `noindex,follow`，canonical 回到 directory root。
- Hard boundary：不把筛选页加入 sitemap、llms 或独立 query owner。

#### P2-4 `CAREER-SEARCH-ENTRY-QUALITY-BATCH-01`

- Repo：fap-api
- Depends on：P2-2
- Outcome：第一批只处理 50 个优先职业，补齐双语可见内容、来源、claim boundary、FAQ、内链和 review state。
- Hard boundary：不一次处理 1046，不自动 publish，不改变 sitemap、indexability 或 Search Channel。

#### P2-5 `CAREER-SEARCH-ENTRY-BATCH-01-APPLY`

- Type：受控线上操作，不是 PR。
- Depends on：P2-4 exact package。
- Outcome：dry-run、manual review、受控 publish、cache/sitemap readback。
- Required separate confirmation：package SHA、target-set SHA、exact 50 slugs、actor、write boundary。

#### P2-6 `CAREER-SEARCH-ENTRY-PILOT-READINESS-01`

- Repo：fap-web
- Depends on：P2-5 成功 readback。
- Outcome：从已批准条目确定性选择 10 slugs / 20 EN/ZH canonical URL；不足 10 则 `HOLD`。

#### P2-7 `CAREER-SEARCH-CHANNEL-CANARY-01`

- Repo：fap-api
- Depends on：P2-6
- Outcome：默认 dry-run 的 Career 专用 canary gate；Google 不进入 executor。
- Separate operations：IndexNow 和 Baidu 各自 provider preflight、enqueue、approve 和 live submit 均需独立 exact confirmation。

### Phase P3：只扩张胜出的集群

P3 暂不创建 exact PR-train item。只有 P1 数据完整且某一 family 的 D14/D28 同时通过后，才为该 family 创建一个独立 backend CMS content-package PR：

1. MBTI；
2. Big Five；
3. RIASEC / Career。

每个 PR 只能处理一个 cluster 和一个 bounded batch；先 dry-run / review package，再单独受控 import/publish。10K Career factory 继续保持本地资产，不直接发布。

## 8. Proposed manifest entries

以下条目是未来执行所需的精确规划输入。本 docs-only PR 不写入任何仓库的 train ledger。

`base` 均为执行时最新 `main`；`github_checks_required=true`、`squash=true`、`auto_merge=true`。

```yaml
- id: SEO-CORE-TEST-DETAIL-500-INCIDENT-01
  repo: fap-web
  branch: codex/seo-core-test-detail-500-incident-01
  base: main
  title: "SEO-CORE-TEST-DETAIL-500-INCIDENT-01 restore core test detail availability"
  depends_on: []
  scope:
    - Diagnose and repair the shared 500 affecting the 12 EN/ZH core test detail URLs.
    - Add exact route, metadata, SSR, authority dependency, canonical, robots, hreflang, and CTA regression coverage.
  allowed_paths:
    - app/(localized)/[locale]/tests/[slug]/**
    - lib/content/**
    - lib/cms/**
    - lib/seo/**
    - tests/contracts/**
    - docs/seo/**
  validation:
    - pnpm vitest run tests/contracts/seo-core-test-detail-availability.contract.test.ts
    - pnpm lint
    - pnpm typecheck
    - pnpm test:contract
    - pnpm build
    - git diff --check
  do_not:
    - Add frontend editorial fallback content.
    - Change CMS content, publication, indexability, sitemap membership, llms, or Search Channel.
    - Mix a backend repair into the frontend PR.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: SEO-CORE-ENTRY-SLO-OBSERVABILITY-01
  repo: fap-api
  branch: codex/seo-core-entry-slo-observability-01
  base: main
  title: "SEO-CORE-ENTRY-SLO-OBSERVABILITY-01 add tiered SEO entry SLO"
  depends_on: [SEO-CORE-TEST-DETAIL-500-INCIDENT-01]
  scope:
    - Add read-only L1/L2/L3 public-entry probes and sanitized artifacts.
    - Classify status, TTFB, SSR, canonical, robots, CTA, and upstream state.
  allowed_paths:
    - backend/app/Console/Commands/**
    - backend/app/Services/SeoIntel/**
    - backend/config/**
    - backend/tests/Feature/Seo/**
    - backend/docs/seo/**
  validation:
    - php artisan test tests/Feature/Seo/SeoCoreEntrySloObservabilityTest.php
    - vendor/bin/pint --test app/Console/Commands app/Services/SeoIntel tests/Feature/Seo
    - php artisan route:list --path=api --except-vendor
    - composer validate --strict
    - composer audit
    - git diff --check
  do_not:
    - Write CMS, DB authority, sitemap, llms, Search Channel, or external search systems.
    - Probe private result, order, attempt, recovery, or payment URLs.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: SEO-10K-GSC-BOUNDED-BACKFILL-01
  repo: fap-api
  branch: codex/seo-10k-gsc-bounded-backfill-01
  base: main
  title: "SEO-10K-GSC-BOUNDED-BACKFILL-01 add bounded resumable GSC import"
  depends_on: [SEO-CORE-TEST-DETAIL-500-INCIDENT-01]
  scope:
    - Extend sanitized GSC import beyond the existing ten-row canary with explicit batch, cursor, resume, idempotency, and readback receipts.
  allowed_paths:
    - backend/app/Console/Commands/SeoIntelGsc*
    - backend/app/Services/SeoIntel/Gsc*
    - backend/tests/Feature/SeoIntel/**
    - backend/docs/seo/gsc-*
  validation:
    - php artisan test tests/Feature/SeoIntel/GscReadModelBoundedBackfillTest.php
    - vendor/bin/pint --test app/Console/Commands/SeoIntelGscReadModelBoundedBackfillCommand.php app/Services/SeoIntel tests/Feature/SeoIntel/GscReadModelBoundedBackfillTest.php
    - composer validate --strict
    - composer audit
    - git diff --check
  do_not:
    - Add credentials, scheduler activation, Request Indexing, sitemap submission, CMS writes, or Search Channel.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: SEO-10K-SITEMAP-FAMILY-OBSERVABILITY-01
  repo: fap-web
  branch: codex/seo-10k-sitemap-family-observability-01
  base: main
  title: "SEO-10K-SITEMAP-FAMILY-OBSERVABILITY-01 add sitemap family observability"
  depends_on: [SEO-CORE-TEST-DETAIL-500-INCIDENT-01]
  scope:
    - Add stable articles, careers, personality, tests, and other read-only sitemap families while preserving the root URL union.
  allowed_paths:
    - app/sitemap*
    - lib/seo/sitemap*
    - tests/contracts/*sitemap*
    - docs/seo/**
  validation:
    - pnpm vitest run tests/contracts/seo-sitemap-family-observability.contract.test.ts
    - pnpm lint
    - pnpm typecheck
    - pnpm test:contract
    - pnpm build
    - git diff --check
  do_not:
    - Add or remove public URLs.
    - Infer publication or indexability in frontend code.
    - Change llms, CMS, Search Channel, or external search systems.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: SEO-QUERY-OWNER-URL-TRUTH-01
  repo: fap-api
  branch: codex/seo-query-owner-url-truth-01
  base: main
  title: "SEO-QUERY-OWNER-URL-TRUTH-01 bind one intent to one canonical owner"
  depends_on:
    - SEO-10K-GSC-BOUNDED-BACKFILL-01
    - SEO-10K-SITEMAP-FAMILY-OBSERVABILITY-01
  scope:
    - Add backend-authoritative query-family owner, supporting URL, and conflict read models.
  allowed_paths:
    - backend/app/Models/Seo/**
    - backend/app/Services/SeoIntel/**
    - backend/app/Console/Commands/SeoIntel*
    - backend/database/migrations/**
    - backend/tests/Feature/SeoIntel/**
    - backend/docs/seo/**
  validation:
    - php artisan test tests/Feature/SeoIntel/SeoQueryOwnerUrlTruthTest.php
    - vendor/bin/pint --test app/Models/Seo app/Services/SeoIntel app/Console/Commands tests/Feature/SeoIntel/SeoQueryOwnerUrlTruthTest.php
    - php artisan migrate --force
    - php artisan route:list --path=api --except-vendor
    - composer validate --strict
    - composer audit
    - git diff --check
  do_not:
    - Auto-change canonical, publication, indexability, sitemap, CMS content, or Search Channel.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: SEO-SEARCH-TO-RESULT-FUNNEL-01
  repo: fap-api
  branch: codex/seo-search-to-result-funnel-01
  base: main
  title: "SEO-SEARCH-TO-RESULT-FUNNEL-01 connect search landing to product outcomes"
  depends_on:
    - SEO-10K-GSC-BOUNDED-BACKFILL-01
    - SEO-QUERY-OWNER-URL-TRUTH-01
  scope:
    - Join privacy-safe GSC page aggregates to canonical start_test, complete_test, and view_result daily metrics.
  allowed_paths:
    - backend/app/Services/Analytics/**
    - backend/app/Services/SeoIntel/**
    - backend/app/Console/Commands/**
    - backend/tests/Feature/Analytics/**
    - backend/tests/Feature/SeoIntel/**
    - backend/docs/seo/**
  validation:
    - php artisan test tests/Feature/Analytics/SeoSearchToResultFunnelTest.php
    - vendor/bin/pint --test app/Services/Analytics app/Services/SeoIntel app/Console/Commands tests/Feature/Analytics/SeoSearchToResultFunnelTest.php
    - composer validate --strict
    - composer audit
    - git diff --check
  do_not:
    - Treat GSC as purchase or revenue truth.
    - Store private result, attempt, order, recovery, or payment URLs.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: SEO-10K-ARTICLE-RECOVERY-BATCH-01
  repo: fap-api
  branch: codex/seo-10k-article-recovery-batch-01
  base: main
  title: "SEO-10K-ARTICLE-RECOVERY-BATCH-01 recover five existing article owners"
  depends_on:
    - SEO-10K-GSC-BOUNDED-BACKFILL-01
    - SEO-QUERY-OWNER-URL-TRUTH-01
    - SEO-SEARCH-TO-RESULT-FUNNEL-01
  scope:
    - Produce a dry-run and review package for the five existing articles with the largest verified loss.
  allowed_paths:
    - backend/content_packs/**
    - backend/app/Console/Commands/Seo*
    - backend/app/Services/SeoOps/**
    - backend/tests/Feature/SeoOps/**
    - backend/docs/seo/**
  validation:
    - php artisan test tests/Feature/SeoOps/Seo10kArticleRecoveryBatchTest.php
    - vendor/bin/pint --test app/Console/Commands app/Services/SeoOps tests/Feature/SeoOps/Seo10kArticleRecoveryBatchTest.php
    - composer validate --strict
    - composer audit
    - git diff --check
  do_not:
    - Create new URLs, publish, submit search URLs, or bypass manual review.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: CAREER-SEARCH-ENTRY-TIER-CONTRACT-01
  repo: fap-api
  branch: codex/career-search-entry-tier-contract-01
  base: main
  title: "CAREER-SEARCH-ENTRY-TIER-CONTRACT-01 add career search-entry authority"
  depends_on:
    - CAREER-PILOT-REVIEW-EVIDENCE-BRIDGE-01
    - CAREER-PUBLISH-TRACK-COVERAGE-RECONCILE-01
    - CAREER-SEARCH-ENTRY-QUALITY-REAUDIT-01
  scope:
    - Add explicit search_entry_tier authority separate from public visibility and robots_indexable.
  allowed_paths:
    - backend/app/Services/Career/**
    - backend/app/Http/Resources/**
    - backend/app/Http/Controllers/API/V0_5/Cms/Career*
    - backend/tests/Feature/Career/**
    - backend/docs/career/**
    - backend/docs/seo/**
  validation:
    - php artisan test tests/Feature/Career/CareerSearchEntryTierContractTest.php
    - vendor/bin/pint --test app/Services/Career app/Http/Resources app/Http/Controllers/API/V0_5/Cms tests/Feature/Career/CareerSearchEntryTierContractTest.php
    - php artisan route:list --path=api --except-vendor
    - composer validate --strict
    - composer audit
    - git diff --check
  do_not:
    - Promote review_needed or hold entries.
    - Change publication, indexability, sitemap, llms, CMS, Search Channel, or held slugs.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: CAREER-DIRECTORY-PAGINATION-FOLLOW-01
  repo: fap-web
  branch: codex/career-directory-pagination-follow-01
  base: main
  title: "CAREER-DIRECTORY-PAGINATION-FOLLOW-01 preserve crawlable directory links"
  depends_on:
    - SEO-CORE-TEST-DETAIL-500-INCIDENT-01
    - SEO-QUERY-OWNER-URL-TRUTH-01
  scope:
    - Set pagination and filter states to noindex,follow with canonical to the directory root.
  allowed_paths:
    - app/(localized)/[locale]/career/**
    - lib/career/**
    - lib/seo/**
    - tests/contracts/*career*
    - docs/seo/**
  validation:
    - pnpm vitest run tests/contracts/career-directory-pagination-follow.contract.test.ts
    - pnpm lint
    - pnpm typecheck
    - pnpm test:contract
    - pnpm build
    - git diff --check
  do_not:
    - Add filtered URLs to sitemap or llms.
    - Add frontend career content fallback.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: CAREER-SEARCH-ENTRY-QUALITY-BATCH-01
  repo: fap-api
  branch: codex/career-search-entry-quality-batch-01
  base: main
  title: "CAREER-SEARCH-ENTRY-QUALITY-BATCH-01 prepare first fifty career entries"
  depends_on: [CAREER-SEARCH-ENTRY-TIER-CONTRACT-01]
  scope:
    - Produce an exact bilingual review package for no more than fifty deterministic career candidates.
  allowed_paths:
    - backend/content_packs/**
    - backend/app/Console/Commands/Career*
    - backend/app/Services/Career/**
    - backend/tests/Feature/Career/**
    - backend/docs/career/**
    - backend/docs/seo/**
  validation:
    - php artisan test tests/Feature/Career/CareerSearchEntryQualityBatchTest.php
    - vendor/bin/pint --test app/Console/Commands app/Services/Career tests/Feature/Career/CareerSearchEntryQualityBatchTest.php
    - composer validate --strict
    - composer audit
    - git diff --check
  do_not:
    - Process all 1046, publish, change indexability, sitemap, llms, Search Channel, or held slugs.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: CAREER-SEARCH-ENTRY-PILOT-READINESS-01
  repo: fap-web
  branch: codex/career-search-entry-pilot-readiness-01
  base: main
  title: "CAREER-SEARCH-ENTRY-PILOT-READINESS-01 select bounded career canary"
  depends_on: [CAREER-SEARCH-ENTRY-QUALITY-BATCH-01]
  scope:
    - Select exactly ten approved slugs and twenty EN/ZH URLs with deterministic evidence.
  allowed_paths:
    - scripts/seo/**
    - tests/contracts/*career*
    - docs/seo/**
  validation:
    - pnpm vitest run tests/contracts/career-search-entry-pilot-readiness.contract.test.ts
    - pnpm lint
    - pnpm typecheck
    - git diff --check
  do_not:
    - Lower gates when fewer than ten qualify.
    - Change runtime, CMS, sitemap, llms, Search Channel, or production.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}

- id: CAREER-SEARCH-CHANNEL-CANARY-01
  repo: fap-api
  branch: codex/career-search-channel-canary-01
  base: main
  title: "CAREER-SEARCH-CHANNEL-CANARY-01 add bounded career search canary gate"
  depends_on: [CAREER-SEARCH-ENTRY-PILOT-READINESS-01]
  scope:
    - Add a default-dry-run exact-artifact gate for ten slugs and twenty URLs.
  allowed_paths:
    - backend/app/Console/Commands/Career*
    - backend/app/Services/SearchChannel/**
    - backend/tests/Feature/Career/**
    - backend/tests/Feature/SearchChannel/**
    - backend/docs/career/**
    - backend/docs/seo/**
  validation:
    - php artisan test tests/Feature/Career/CareerSearchChannelCanaryTest.php
    - vendor/bin/pint --test app/Console/Commands app/Services/SearchChannel tests/Feature/Career/CareerSearchChannelCanaryTest.php
    - composer validate --strict
    - composer audit
    - git diff --check
  do_not:
    - Execute external submission in the PR.
    - Put Google into a submit executor.
    - Partially enqueue a drifting bilingual batch.
  merge_policy: {github_checks_required: true, squash: true, auto_merge: true}
```

`SEO-10K-GSC-LIVE-CANARY-RUN-01` 和 `CAREER-SEARCH-ENTRY-BATCH-01-APPLY` 是受控生产操作，不写成代码 PR manifest。

### Initial state template

每个未来 PR 在所属仓库执行时，initial state 必须使用：

```json
{
  "status": "planned",
  "commit_sha": null,
  "pr_url": null,
  "merged_at": null,
  "failure_reason": null,
  "remote_branch_deleted": false,
  "local_cleanup_executed": false,
  "production_write_execution": false,
  "database_mutation": false,
  "cms_mutation": false,
  "publish_allowed": false,
  "search_channel_action": false,
  "url_submission": false,
  "deploy_allowed": false,
  "history": [
    {
      "status": "planned",
      "note": "Authorized only after an exact execution prompt naming this item; scope, dependencies, allowed paths, validation and do_not are locked to this planning document."
    }
  ]
}
```

执行时把 exact `id`、`repo`、`branch`、`title` 和 `depends_on` 从上面的 manifest entry 复制到 state；不得在本 docs-only PR 中预建 planned state。

## 9. 执行提示词

下一项应使用：

```text
请执行 SEO-CORE-TEST-DETAIL-500-INCIDENT-01：在 fap-web 从最新 main 创建 codex/seo-core-test-detail-500-incident-01，先用 12 个 sitemap EN/ZH 核心测试详情 URL 的共享失败证据定位根因，再只在 authority owner 仓库做修复；保持 CMS/backend authority、LKG/minimal-shell、canonical/robots/hreflang 和 CTA 合约，不改 CMS 内容、publication、indexability、sitemap membership、llms 或 Search Channel。按 backend/docs/seo/seo-product-growth-status-and-pr-plan-2026-07-24.md 的 exact manifest/state 规划实施完整 PR 生命周期。
```

如果诊断明确根因属于 fap-api，必须停止 frontend 代码修复，输出 exact backend owner path、failure stage 和新的单一 scope PR 卡；不得在一个 PR 中跨仓库修复。

## 10. 放行顺序

```text
P0 core test 12/12 stable 200
  -> tiered SEO entry SLO
  -> bounded GSC backfill + ten-row live canary
  -> sitemap family observation + query owner
  -> search-to-result funnel
  -> five-article recovery
  -> Career 2092/2092 + activation + 1046/1046 readback
  -> career tier contract
  -> fifty-career review package + controlled apply
  -> ten-career readiness + channel canary
  -> D14/D28 winner-only cluster expansion
```

任何 5xx、sitemap/canonical/noindex drift、private URL exposure、review SHA 失效、Career coverage gap、queue anomaly 或 provider failure，都停止后续批次。

## 11. Repository rule impact

Documentation only.

- Backend/CMS remains the authority for public content, SEO, publication, indexability, sitemap enumeration, review evidence, Career tiers and Search Channel eligibility.
- fap-web remains a rendering, interaction and public contract consumer.
- No frontend editorial fallback is authorized.
- No production write, publish, deploy, URL submission or Search Channel action is authorized.
- Career 10K remains architecture/local-asset readiness only, not public publication.

## 12. Related documents

Backend:

- `backend/docs/seo/fermatmind-free-assessment-global-seo-geo-strategy-2026-07-04.md`
- `backend/docs/seo/gsc-live-readonly-adapter.md`
- `backend/docs/seo/gsc-readmodel-controlled-import-canary.md`
- `backend/docs/seo/seo-intel-url-truth-inventory.md`
- `backend/docs/seo/analytics-funnel-event-taxonomy-01.md`
- `backend/docs/seo/analytics-funnel-ga4-baidu-mapping-scan-01.md`
- `backend/docs/seo/career-10k-rollout-architecture-spec-01.md`
- `backend/docs/career/career-detail-stability-train-2026-07-18.md`
- `backend/docs/career/job-detail-cache-coverage.md`

Frontend:

- `docs/seo/fermatmind-content-cluster-map.md`
- `docs/seo/fermatmind-blog-research-strategy.md`
- `docs/seo/agent/OBSERVATION_WINDOWS.md`
- `docs/seo/career-quality-tiering-01.md`
