# SEO-PLATFORM-03 单一 SEO 运营入口收敛 Closeout

## 结论

Canonical UI 为 `/ops/seo-operations`，Filament 导航仅注册 `SEO Operations`。
Legacy UI `/ops/seo` 保留在原登录、org selection、TOTP、CSRF 与 RBAC 中间件之后，
只跳转到 canonical UI；它不再读取 dashboard、不再执行查询、不再提供写操作。

本任务新增 `SeoOperationsReadService` 作为只读组合边界。它不拥有数据、不建表、
不复制 SQL，只委托既有 CMS 与 `seo_intel` read services，并为每个工作区返回
`state`、`source`、`observed_at`、`updated_at` 与 `unavailable_reason`。现有
`/api/v0.5/ops/seo-intel/*` 响应保持兼容。

## 迁移能力矩阵

| 能力 | 真实 read service / authority | 权限与新鲜度 | 读写边界 | 决定 |
| --- | --- | --- | --- | --- |
| CMS SEO inventory / issue queue | `SeoContentScopeViewModel`, `SeoOperationsService`; CMS primary DB | content read；按请求读取 | 现有受控 edit/preview/bulk service 可写，发布不在本任务 | 保留并进入 Execution |
| SEO Intelligence API | `SeoDashboardApiReadService`; `seo_intel` | owner / ops read；持久化 read model 时间戳 | API 只读兼容 | 保留 |
| URL Truth | `SeoUrlTruthReadService`; `seo_urls` / `seo_url_entities` | owner / ops read；当前持久化行 | 只读；无批量修复 | 迁入 Overview / Technical / inspector |
| Page Family | `PageFamilyCoverageReadService`; shared policy registry + backend/Career authority | 当前请求重算并携带 policy hash | classification only；无 action authorization | 迁入 Overview / AI risk cap / inspector |
| Issue Queue | `SeoIssueQueueReadService`, `SeoIssueClusterReadService`; detector issue authority | ops/content read；detected/updated timestamps | 仅既有 audited workflow service 可写 | 迁入 Overview / Technical / Execution |
| Opportunity Queue | `SeoOpportunityQueueReadService`; quality-gated masked GSC evidence | 只读；report window timestamp | `measurement_hold`，不得冒充 detector issue | 迁入 Opportunities |
| Search Channel Queue | `SeoSearchChannelQueueReadService`; existing queue/event authority | owner / ops read；queue timestamps | 全局 submission 关闭；本任务无 enqueue/approve/submit | 迁入 Overview / Execution |
| GSC | `SeoDashboardApiReadService::searchPerformance`; `seo_gsc_daily` | 7/28/90 天；collected/report timestamps | 只读；无 GSC write / external call | 迁入 Performance / inspector aggregate |
| Public product funnel | `SeoConversionFunnelReadService`; analytics aggregate authority | aggregate freshness；private paths excluded | 只读聚合 | 迁入 Performance |
| Technical audit | `SeoTechnicalAuditReadService`; URL Truth / Issue / crawler / GSC | source-specific state | 只读证据 | 迁入 Technical |
| crawler | `SeoCrawlerLogObservationReadService`; privacy-safe daily aggregates | last seen / updated timestamps | 只读；无 raw logs | 迁入 Overview / Technical |
| SEO scheduler | 无已实现生产 receipt read model | `not_implemented` | 不启用 scheduler | 后续任务 |
| SEO Agent | 仅共享 Page Family risk caps；无统一生产 Agent workspace | `not_implemented` | 无建议伪造、无执行 | 后续 #8/#11 |
| CWV / rank / backlinks | 外部 provider 未连接或未实现 | `not_connected` / `not_implemented` | 无 fallback 数字 | 后续任务 |

## 页面详情与安全边界

Execution 内的 inspector 仅以 issue UID 解析公开 canonical path，并显示 family、locale、
entity type、authority/source、publication/indexability、canonical/hreflang/schema、
sitemap/llms eligibility、CMS revision、GSC aggregate、issue、推荐动作、family risk cap、
edit/preview 与 rollback 状态。私人 result/attempt/report/history/share/order/payment/token
路径会 fail closed；query 被移除；URL/query/evidence/session hashes 不进入 Livewire payload。

Issue authority 与 Opportunity authority 仍分离。CMS checklist 不会被组合层转成 detector issue。
既有 RBAC、org/tenant、TOTP、CSRF、human approval、preview、audit、claim/locale gate、
Page Family risk cap、canary、最大影响范围和 rollback 边界均未放宽。Career rollout 仍为
`1–3 → 10 → 50 → cohort`。

## 当前生产聚合基线

以下为 2026-08-24 最近一次 authenticated production aggregate readback；旧的 17-row
URL Truth closeout 已被同日 post-activation readback 取代，未写死进 runtime：

- Page Family public authority 2,623；exactly-one-family 2,623；unclassified 0；ambiguous 0。
- private-excluded authority 2；36 项 private negative-set 的 public leak 为 0。
- family 分布：Tests 22、Articles/Topics 115、Career 2,120、Personality 323、Trust/Method/Help 39、Other Public 4。
- 当前 URL Truth authority-qualified gap 77；交接给 `SEO-PLATFORM-05`，本任务不修复。
- public sitemap 2,643；相对 public authority 多 20，仍是 consumer-consistency 差异而非 authority。
- GSC 90-day persisted read model：15,165 detail rows、83 dates、278 clicks、22,438 impressions、CTR 1.239%、average position 14.8737、latest lag 3 days。
- independent SEO Issue Queue 5 open rows / 3 P3 clusters / 5 unique affected URLs；GSC quality queue 2,073 historical snapshot rows，不与 detector issues 合并。
- Opportunity Queue production healthy；数量与筛选条件动态读取，不写静态生产数字。
- Search submission 保持关闭；scheduler receipt、CWV、rank、AI workspace、backlinks 均按真实状态显示，不显示假 0。

基线证据来自 `seo-platform-01-capability-truth.v1.json` 与
`seo-platform-02-page-family-policy-coverage.v1.json`；canonical UI 运行时始终读取当前服务值。

## 退役与未执行动作

已退役 `SeoDashboardAccessPage` 的 dashboard 查询、硬编码 7/7/5/10 卡片、旧 dashboard
Blade、重复导航和仅验证旧 UI 的测试。所有仍被 canonical UI 或 API 使用的 read service、
URL Truth / Issue / Search Channel authority、生产 API 与审计记录均保留。

本任务没有 migration、新表、新 external API、新 authority、新后台或新写入服务；没有执行
CMS publish、CMS 内容修改、URL Truth 写入/批量修复、GSC 写入、Search Channel enqueue、
IndexNow、搜索提交、crawler 原始日志读取或历史数据删除。

`SEO-PLATFORM-03` 只在 exact-SHA CI、staging、production、standard smoke 与登录后浏览器
smoke 全部通过且 active production revision 包含本提交后关闭。下一项为 `SEO-PLATFORM-04`；
本任务不提前实现 #4–#12。
