# 2026-07 性能 PR Train 覆盖范围与月度扫描指南

## 1. 文档目的

本文记录 2026-07 已合并的 12 个性能优化 PR 分别解决了哪些系统板块、原始风险是什么、后续月度扫描应验证什么，以及出现回归时应归入哪个后续 scope。

本文是扫描重点清单，不代表线上 SLO、真实用户 p75 或 Core Web Vitals 结论。没有 RUM/CrUX 证据时，实验室数据、低频生产 HTTP 观察和代码证据必须分开陈述。

## 2. 已合并 PR 总览

| PR ID | 仓库 / PR | 优化板块 | 解决的问题 | 月度重点复核 |
| --- | --- | --- | --- | --- |
| `PERF-BASELINE-01` | fap-web #1703 | 性能治理基线 | 固化审计方法、证据边界、代表性指标、性能预算和 PR 验收口径，避免每次扫描使用不同样本和统计方式。 | 基线文档是否仍可执行；目标、采样次数、超时、冷热状态和预算是否被无审查修改；是否错误声称 field p75。 |
| `CAREER-NEXTSTEP-01` | fap-api #3014 | Career next-step API | `next-step-links` 慢查询/组装导致超时；加入 slug/locale 级缓存、物化读取和安全超时兜底，非关键推荐失败不应变成 5xx。 | 代表性 slug 三次串行请求的状态、median/worst；缓存复用；空结果兜底；慢查询、N+1、序列化大小和缓存命中。 |
| `CAREER-NEXTSTEP-02` | fap-web #1707 | Career detail 前端降级 | 非关键 next-step 请求会拖住职业详情主内容；改为短超时和 graceful empty state。 | 主内容是否独立渲染；next-step 慢/失败时页面是否仍可用；是否重新出现长 await、阻塞 Suspense 或错误覆盖主页面。 |
| `PUBLIC-API-CACHE-01` | fap-api #3015 | 公共 API HTTP 缓存 | 匿名、已发布、`org_id=0` 公共 GET 缺少可共享缓存语义；同时保护私人结果、订单、支付、attempt/report/auth 保持 `private/no-store`。 | 公共目标的 `Cache-Control`、ETag、Age；私人路由负向清单；Vary/鉴权边界；错误响应是否被公共缓存；CDN 与源站头是否一致。 |
| `CAREER-CACHE-01` | fap-web #1709 | Career directory/detail 页面缓存 | 未过滤、可索引公共职业页错误使用 no-store；搜索、筛选、分页和私人态需要继续隔离。 | 无过滤公共页的 revalidate/tag；搜索/筛选/noindex 状态仍 no-store；canonical/robots 不因缓存串态；发布后精确失效。 |
| `GLOBAL-JS-01` | fap-web #1710 | 全站公共 shell / RSC | SiteChrome/Header/Footer/CookieBanner 形成全量 client shell，所有公共页承担过多 hydration JS。 | 公共 layout 的 client boundary；共享 JS 体积；RSC/SSR 与 hydration 错误；静态 footer/navigation 是否被重新 client 化。 |
| `GLOBAL-JS-02` | fap-web #1711 | Header/Footer 交互懒加载 | 隐藏 QR、菜单、locale/social 交互在首屏提前下载；QR 曾使用 priority。 | 冷加载 waterfall；隐藏 QR 不得 priority；菜单/QR/locale chunk 是否只在交互后加载；交互可访问性和 mobile/desktop 回归。 |
| `PERSONALITY-CACHE-01` | fap-api #3017 | Personality detail/SEO 读模型 | 32 A/T 公共人格页在 ISR MISS 时重复进行昂贵后端组装；增加稳定公共 read model 缓存和精确失效。 | EN/ZH、A/T detail+SEO 的 cold/warm 时延；read-model 命中；发布/更新失效；内容、locale、variant 不串缓存。 |
| `PERSONALITY-WARMUP-01` | fap-api #3018 | Personality 发布后预热与 payload | 冷启动成本高且人格 API/HTML payload 超过 500 KB；提供发布后 EN/ZH 关键路由预热命令并移除重复 projection alias。 | payload bytes；重复字段是否回归；warmup 命令 dry-run/测试；关键 EN/ZH 路由覆盖。生产 warmup 必须单独授权，月度审计不得自动执行。 |
| `ARTICLE-CACHE-01` | fap-web #1713 | Article detail/SEO Data Cache | 已发布文章 detail/SEO 强制 `no-store`，每次请求重复后端组装；改为 5 分钟 tagged revalidate，并由签名发布 webhook 按 locale+slug 精确清除 detail/SEO 标签。 | detail/SEO fetch 的 revalidate/tag；发布 webhook 两个标签均失效；未发布/404/私人内容不进入公共缓存；列表失效未被破坏。 |
| `ARTICLE-FANOUT-01` | fap-web #1714 | Article 内链标签渲染 | 单篇文章为获取内链标题最多额外拉取 24 个完整 article detail；移除运行时详情 fanout，使用 CMS authored anchor text，裸路径使用安全 deterministic fallback。 | 页面请求数不随文章内链数增长；不得重新出现 `Promise.all(...slice(0, 24))` 或逐链接 detail；CMS anchor text、裸路径 fallback 和 sanitizer 保持正确。 |
| `PERF-OPS-01` | fap-web #1715 | 长期性能运营 | 缺少稳定的定期只读扫描、slow page/API top-N、缓存头审计和发布 SOP；新增 Node 24 扫描器、每周 workflow、90 天报告留存和可选 enforce。 | workflow 是否按周运行；报告是否生成并保留；目标清单是否仍覆盖 L1/L2/L3；失败是否先 inspect；不得扫描私人流程或触发 warmup/deploy。 |

## 3. 月度重点扫描矩阵

### 3.1 全局公共页面

- 首页、测试中心、人格页、职业目录、职业详情、文章详情各取 EN/ZH 代表样本。
- 每个目标至少三次串行样本，记录 status、redirect、TTFB/总时长、bytes、`Cache-Control`、ETag、Age、`x-nextjs-cache`/`x-vercel-cache`。
- 浏览器实验室扫描记录 LCP、INP 可替代实验室信号、CLS、long tasks、hydration 错误、请求数、JS transfer 和主线程成本。
- 冷/暖、desktop/mobile 必须分组，不得混算。
- 共享首屏 JS 相对 2026-07 基线不得增长；任一路由同配置 median 回归超过 5% 需复核。

### 3.2 Career

- `next-step-links` 必须是非关键依赖：超时/空结果不能阻塞 detail 主体。
- 核对 directory/detail 公共缓存与 search/filter/noindex/no-store 边界。
- 检查 API 查询数、慢 SQL、N+1、序列化字节和相同 slug/locale 重复请求。
- 输出 Career slow page/API top-N，并将 next-step 单列。

### 3.3 Personality

- 覆盖 MBTI L1、Big Five L2；A/T 至少各取 EN/ZH 样本。
- 对 detail 与 SEO 分别比较 cold/warm，并记录 read-model/cache evidence。
- 监测 500 KB 级 payload 回归、重复 projection 字段和 HTML 体积。
- 仅验证 warmup 命令、覆盖清单和发布 SOP；没有 exact SHA/生产授权不得执行 warmup。

### 3.4 Article

- detail/SEO 必须使用独立 locale+slug tag，发布 revalidation 必须同时清理两者。
- 文章正文含 0、5、24+ 条内部链接时，请求数量不应随链接数线性增长。
- 检查 authored anchor text、裸路径 fallback、sanitizer、CTA attribution 是否保持一致。
- 输出 article detail page、detail API、SEO API 的 median/worst/bytes/cache headers。

### 3.5 缓存与隐私负向边界

- 公共 cache audit 只允许匿名、已发布、`org_id=0` 的 GET。
- 订单、支付、attempt、result、report、auth、找回链接和私人分享不得进入公共扫描目标、sitemap、llms 或公开缓存。
- 任何带身份、租户、草稿、预览或私人 token 的响应发现 `public`/`s-maxage`，按高优先级停止并单独开安全 scope。
- 404/5xx、鉴权失败和限流响应不得因本轮优化被长期公共缓存。

## 4. 固定输出格式

每次月度扫描必须交付：

1. 执行摘要：结论为 `PASS`、`PARTIAL` 或 `FAIL`，列出前三项风险。
2. 证据边界：生产只读观察、浏览器实验室、代码证据、field data 分开。
3. 目标与环境：exact SHA、时间、地区、设备、浏览器、网络配置、冷热状态、样本数、超时和并发。
4. slow pages top-N 与 slow APIs top-N。
5. cache header audit 和私人路由负向审计。
6. 12 个历史优化板块逐项回归状态：`pass/regressed/unknown/not-tested`。
7. 与上月和 2026-07 基线的同口径差异。
8. 建议 PR 队列：一个 PR 一个 scope，给出 ID、标题、仓库、依赖、允许路径、验收命令和停止条件。
9. 外部问题 sidecar；不得为了让报告好看而混入当前修复。

## 5. 回归阈值与处置

- 新增 timeout、5xx、hydration error 或私人缓存泄露：`FAIL`，立即停止相关 train。
- 同配置 median 或 payload 回归超过 5%：`PARTIAL`，复测并检查变更 SHA、缓存状态和样本一致性。
- 公共页共享 JS 增长或 client shell 扩张：单独 `GLOBAL-JS` follow-up。
- Career next-step 再次阻塞主体：单独 backend 或 frontend follow-up，不与缓存 PR 合并。
- Personality payload/read-model 回归：区分后端序列化、缓存失效和前端 HTML/JS，禁止跨仓库混成一个 PR。
- Article tag 或 fanout 回归：缓存与 fanout 分开处理。
- 监控工具失效：只修 `PERF-OPS`，不得顺便修线上慢页面。

## 6. 禁止事项

- 月度扫描默认只读；不得 CMS 写入、生产导入、DB migration、cache purge、manual deploy 或 production deploy。
- 不等待 staging deploy，不把 staging pending/failed 当作性能代码 PR 的阻塞，除非 deploy workflow 本身是当前 scope/required check。
- 不探测私人结果、订单、支付、attempt/report 链接。
- 不把三次样本称为真实用户 p75，不把 Lighthouse 单次结果称为线上 Core Web Vitals。
- 不在前端制造 CMS-backed 内容 fallback，不改变 backend/CMS authority。

## 7. 相关入口

- fap-web：`docs/performance/production-performance-baseline-2026-07-12.md`
- fap-web：`docs/performance/performance-operations-sop.md`
- fap-web：`scripts/performance/audit-public-performance.mjs`
- fap-web：`scripts/performance/public-performance-targets.json`
- fap-web：`.github/workflows/public-performance-audit.yml`
- 本仓库月度审计模板：`docs/performance/monthly-full-stack-performance-audit-prompt-template.md`


