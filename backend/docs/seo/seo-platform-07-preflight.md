# SEO-PLATFORM-07-PREFLIGHT：Runtime SLO 与统一读模型实施合同

状态：`complete_preflight_docs_only`

证据截止：`2026-08-25T22:50:59Z`。本轮只读取当前 `fap-api` / `fap-web`
`origin/main`、Git/GitHub 历史、公开生产页面及现有脱敏只读 observer；没有启用 scheduler，
没有 DB、CMS、cache、URL Truth、sitemap、search 或生产写入。

结构化合同：`backend/docs/seo/generated/seo-platform-07-preflight.v1.json`。

## 唯一当前结论

`SEO-PLATFORM-01` 曾把 task #7 标为 `completed_by_prior_work`，理由只是当时 Issue
Queue 没有 runtime/SLO cluster handoff；这证明“当时没有被移交的 cluster”，不证明统一
Runtime SLO scheduler、持久化读模型或 UI 已发布。当前
`SeoTechnicalHealthUiContract::unavailableSnapshot()` 直接声明 `SEO-PLATFORM-07` 尚未发布统一
生产读模型，并把 runtime、public URL 与 cache revision 值保持为空。

因此唯一当前结论是：现有多个组件可复用，但 `SEO-PLATFORM-07` 整体为
`production_unproven`，Technical Health 必须维持 `MEASUREMENT_HOLD`。不得继续沿用历史
`completed_by_prior_work` 作为生产完成事实。

## 07-PF-A：能力真值与复用矩阵

| 能力 | 实现与真实调用方 | 数据 / schedule | 当前状态 | 可复用与缺口 |
| --- | --- | --- | --- | --- |
| Public Content Runtime aggregate | `PublicContentRuntimeDaily`、`RecordPublicContentRuntime`、`PublicContentRuntimeMetricsService`；v0.5 公开 API middleware 记录，`PublicContentHealthPage` 和受保护 ops runtime endpoint 读取 | Redis minute buckets + `public_content_runtime_daily`；`bootstrap/app.php` 每分钟 rollup，probe 配置为每 5 分钟 | `production_unproven` | 复用 route family、locale、status、histogram、freshness；缺统一 revision、false-404/noindex 与跨 HTML/API 关联 |
| Core Entry SLO | `CoreEntrySloManifest`、`CoreEntrySloObserver`、`CoreEntrySloInspector`、`seo-intel:core-entry-slo-observe` | 当前 16 个静态 L1/L2/L3 HTML targets；无 DB/CMS/search 写；配置 `enabled=false`，无 scheduler | `code_only` | 复用 bounded concurrency、timeout、sanitized artifact、canonical/robots/hreflang/visible-content 检查；必须改为 authority-driven cohort 并发布统一读模型 |
| Career Runtime SLO | `RecordCareerRuntimeSlo`、`CareerRuntimeSloService`、`career:runtime-slo-check` | rolling cache samples；每 5 分钟 lightweight command；可检查 public API/page/cache | `production_unproven` | 复用 Career-specific false-empty、locale count、cache age、p95；command 还能发 alert，不作为本轮只读探测工具；阈值不能外推全站 |
| crawler observation | `seo-intel:crawler-log-observe`、`CrawlerLogAggregateDryRun`、`SeoCrawlerLogObservationReadService` | fixture 或人工批准的单一 source，最大 1000 行，dry-run/no-write；无已证明 scheduler | `code_only` | 复用 privacy transform、bot/family 聚合、freshness envelope；缺自动生产 source receipt 与定时运行证据 |
| URL Truth | URL Truth models/services、`QueryOwnerUrlTruthReadModel` 及 SEO Operations consumers | backend/CMS authority projection；`SEO-PLATFORM-01` 的 2026-08-24 authenticated readback 记录当前缺口 | `production_degraded` | 作为采样资格与 false-404/noindex 对照；动态缺口必须运行时读取，禁止复制历史 17/77 为当前数 |
| Page Family Policy | `PageFamilyPolicyRegistry`、`PageFamilyClassifier`、`PageFamilyPolicyGuard` | 六个 public family + `unclassified` / `private_excluded`；policy hash `f020887403238b3f6ac8f521d46c9a2c6a080216d65dfe31eb835d41a489708e` | `production_healthy` | 直接复用 family/locale/authority/risk cap；分类不等于执行授权；动态 authority 缺失时 fail closed |
| frontend public loader resilience | fap-web `testLandingData.ts`、`testLandingCmsEnrichment.ts`、`personalityReadStability.ts`、React `cache()` loaders、CMS LKG helpers | request memoization、bounded 1.5–2s CMS enrichment、8s personality public read、6h bounded LKG；公开 HTML readback | `production_unproven` | 复用 timeout、dedupe、LKG/contract error 分类；不同 family 策略不一致，且没有统一 render/cache revision |
| revision evidence | authority 的 `source_hash` / published revision、Career authority revision、deploy exact SHA、部分 cache state | 分散在 family projection、release receipt 与 cache payload | `production_unproven` | 建统一字段而非发明一个 revision；公开 HTML 当前不能可靠回读 deploy/render/cache revision |
| Technical Health UI | `SeoTechnicalHealthUiContract` 与 Technical Health workspace | 当前 unavailable snapshot | `production_unproven` | UI 已具 fail-closed empty state；缺统一生产 read model 是唯一阻塞，而不是 UI 文案缺口 |

真实 scheduler 结论以仓库 wiring 为 `verified`；当前生产是否按期执行在缺 exact scheduled receipt 时为
`production_unproven`，不由代码存在推断为健康。

## 07-PF-B：public cohort 与 private negative-set

采样单位是 `(policy_version, family, locale, authority_revision, cohort_role)`，不是 URL 总数。
候选必须同时满足：Page Family 恰好分类为一个 public family、当前 authority 为 published/active、
indexability 权限明确、locale 为 `zh-CN` 或 `en`、canonical 无 query/fragment。每个 family/locale
按稳定排序选择：

1. `core`：policy 注册的 hub/static entry；
2. `long_tail`：当前 authority 中稳定 identity hash 排序的 detail；
3. `recent`：有 material publish evidence 的最近一条；没有 material evidence 则 unavailable；
4. `historical`：最早仍 active 且当前 authority 可读的一条；
5. `redirect_boundary`：有显式 canonical/alias authority 的边界；不得自行拼 alias；
6. `changed_revision`：自上次 baseline 后 authority revision 变化的确定性增量。

六个 public family（Tests、Articles/Topics、Career、Personality、Trust/Method/Help、
Other Public）与两个 locale 都使用同一选择框架；某个格子没有 authority 时记录
`unobserved`，不能用其他 locale 或前端 fallback 补位。动态集合改变只改变本次候选，不改变
合同。

永久 negative-set 由 authority facts 与 route template 共同决定：private entity type/flow、attempt、
result、report、order/payment/checkout、token/recovery、私人 share/history、用户绑定页面、noindex
private、未发布 authority，以及 `unclassified` / 权限不明。禁止把目标、原始日志、raw URL、query
或正文写入 artifact。

路径段只能作为一个信号，不能单独决定 private。当前 sitemap 中有 2 个 Personality 路径包含
`order` 段，而 policy 的朴素 segment rule 会把它们送入 `private_excluded`；这可能是 public entity
slug 与 commerce segment 的分类碰撞。证据状态为 `unknown`，必须由 entity type + route authority
复核，不能报告为“2 条私人 URL 泄漏”，也不能纳入 probe。

## 07-PF-C：只读多样本 baseline

### Existing Core Entry observer

- observed at `2026-08-25T22:49:20Z`；manifest hash
  `fdeedeaed052f646034e7a3c2fc5c6471ec9c5ecac31f16c80500cac875f37e8`；
- 16 targets，concurrency 4，timeout 10s，redirect 不跟随；13 healthy、3 incident、0 unobserved；
- 3 个 baseline incident 为 1 次 `ttfb_breach` 与 2 次 `http_redirect`；
- artifact hash `65b628902187c7bfad26c1f47fe7214945ce40aacaa3371b7960f6e2bf267c23`；
- 该 command 明示 DB/CMS/search/scheduler/production mutation 均为 false。

### Dynamic sitemap cohort observation

- observed at `2026-08-25T22:50:59Z`；当次 sitemap 2643 entries，sitemap hash
  `5316a0f7859c33d3556eca03f4a87867a4ab714b7b9ef8ec71298b13eb3a1306`；
- 每个现存 family/locale 取 shallow core、最新 detail、最早 detail，去重后 31 targets；
- concurrency 4、timeout 8s、retry limit 0、redirect 不跟随；31 个 200、0 failure、0 unobserved；
- authority-qualified 样本内 404=0、410=0、5xx=0、false noindex=0、canonical mismatch=0、
  visible-content missing=0、metadata missing=0；
- HTML 结构观察到 hreflang missing 2、JSON-LD missing 6。页面类型并非都必须有 JSON-LD，
  因此只记 observation，不直接认定 defect；
- 单请求 elapsed 范围 347–2743ms。它不是 TTFB，也没有冷/热分组，不能与 SLO 阈值直接比较；
- public response 未提供可信统一 authority/render/cache/deploy revision，四项均为 unavailable。

两个 baseline 的目标与检测器不同，不能相加成一个成功率。它们只证明一次 bounded observation；
不能证明 scheduler、新鲜度或长期 SLO。当前状态保持 `MEASUREMENT_HOLD`。

## 07-PF-D：SLI、候选 SLO 与严重度

| SLI | 公式 / 来源 | 窗口与最小样本 | 候选阈值与状态 |
| --- | --- | --- | --- |
| public availability | authority-qualified 2xx / observed eligible probes | proposed rolling 10m + 24h；每 family/locale 至少 3 次且跨 3 个 schedule slots | proposed：任何模板/API-wide 5xx 直接 incident；比例阈值待基线，不足则 hold |
| false 404 / 410 | eligible authority 返回 404/410 的 unique identities / eligible observed | 10m + 24h；至少 3 observations；410 还需 retire authority 对照 | verified mismatch 才计；无 authority 不得称 false |
| false noindex | authority indexable 且 rendered noindex / eligible rendered | 10m + 24h；至少 3 | 模板级直接证据可 P1；单 URL 首次 observation 不高于 P2 |
| shell / core missing | required-visible-module contract 失败 / 200 pages | 10m + 24h；每 family/locale 3 | family template 多 URL直接证据可 P1；单样本 hold/observe |
| canonical/hreflang/schema drift | authority/render/feed 不一致 unique identities / eligible observed | deploy-bound canary + 24h；3 observations | canonical/hreflang 必须有 authority；schema 仅对 eligible type 计算 |
| revision drift | authority revision != render/cache/readback revision | deploy/authority event + 30m recovery window；至少 2 independent readbacks | 无 revision source即 unavailable，不显示 0 |
| page latency | HTML TTFB p50/p95/p99 per family/locale | rolling 10m + 24h；至少 30 samples，且冷/热分层 | Core Entry 当前 1.0–2.5s budget 只适用于其 manifest；全站阈值 proposed |
| API timeout | timeout count / eligible public API calls；duration histogram | 10m + 24h；至少 30 | frontend 已存在 1.5–2s enrichment 与 8s personality budgets；不存在统一已批准 3s SLO |
| crawler/readback stale | now - last successful scheduled receipt | 2 expected intervals + grace | scheduler 未证明或 source 未连接即 `MEASUREMENT_HOLD` |
| private URL exposure | verified private authority identity appearing in target/log/feed/report | event based；最小样本 1 个直接证据 | P0/P1 需要 entity/route/authority 三方直接证据；字符串碰撞不升级 |

仓库中没有找到可作为“全站页面 5 秒、API 3 秒、3 个样本”统一生产 SLO 的现行 authority。
可定位的 5 秒是 Career cache rebuild alert，1.5–2 秒是 test landing enrichment budget，8 秒是
Personality public read timeout，Core Entry 是每 target 1.0–2.5 秒 TTFB budget。它们均保持原范围；
“3 个样本”只作为本合同的 proposed baseline minimum，不伪装为已生效全站策略。

严重度要求：P0/P1 必须包含当前 authority、多个受影响 identity 或共享模板/API 证据、时间戳、
sanitized readback 与可复现 detector。单 URL 与推断最高进入 P2/hold。issue 仅在同一 cluster 连续满足
窗口与样本门槛时开启；证据过期、scheduler 未证明、revision unavailable 时保持
`MEASUREMENT_HOLD`；恢复需连续两个完整窗口通过且 authority/readback revision 对齐；关闭还要
记录 recovery readback，不能因没有新样本自动关闭。

## 07-PF-E：cluster、统一读模型与实施交接

幂等 cluster key：

`sha256(detector_version | normalized_root_cause | page_family | locale | authority_revision | runtime_or_cache_revision)`。

URL 仅贡献不可逆 identity hash 与 unique count；同一 template/API/cache/revision 事故合并为一个
cluster。必需字段为 detector、root cause、family、locale、authority/runtime/cache/release revision、
affected unique count、first/last observed、evidence state、severity、recovery/close condition 与
measurement hold reason。

统一 production read model 最小字段：

- snapshot identity、window、sample counts、success/failure/unobserved、freshness；
- family/locale/cohort、current vs baseline 与各 SLI numerator/denominator；
- authority/render/cache/deploy revision，各自 `value/state/source/observed_at`；
- issue cluster、severity、evidence state、`MEASUREMENT_HOLD` reason；
- public readback、last recovery evidence、scheduler receipt 与 next expected run；
- unavailable 值必须为 null + state，不能显示 0。

后续最小 runtime scope：复用 Page Family registry/classifier、URL Truth、Public Content Runtime
aggregates、Core Entry inspector、Career SLO 与 crawler sanitized aggregate；新增一个 authority-driven
cohort resolver、revision adapter、detector/cluster projector、scheduled receipt 和 read-only snapshot API，
最后把 `SeoTechnicalHealthUiContract` 从 unavailable snapshot 绑定到该 API。不得复制已有 metrics，
不得让 UI 直接拼接 authority。

聚焦测试应覆盖：六 family × 两 locale 的动态 cohort；private/unclassified/authority missing fail
closed；404/410/noindex/shell/revision/timeout 分类；最小样本和 stale receipt hold；cluster 幂等与
模板故障去重；null/unavailable 不变成 0；revision 恢复；脱敏与 secret/private URL guard。

上线验收需要 exact-SHA CI、staging bounded readback、production scheduler receipt、两个连续完整
窗口、Technical Health API/UI parity 及 private negative-set。scheduler 激活和任何 issue mutation
仍属于后续 `SEO-PLATFORM-07`，本 preflight 未执行。

## Deferred / 外部非阻塞项

- fap-web public response 缺统一 render/cache/deploy revision；不影响本 docs-only CI。
- crawler production scheduled receipt 未证明；保持 `production_unproven`。
- 2 个 Personality `order` segment classification collision candidates 需要 Page Family/URL Truth owner
  在后续 runtime scope 中解析；本轮未把它们声明为 private leak。
- `read_only_gsc=true`；`search_submission_allowed=false`。
