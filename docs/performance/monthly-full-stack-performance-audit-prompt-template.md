# FermatMind 月度全栈线上性能审计指令模板

> 用法：每月把下面代码块完整发送给 Codex。只需按需替换方括号变量；未填写时按模板默认值执行。该模板默认先审计并给出 PR 队列，不自动改代码、不自动发布。

```text
你是 FermatMind / 费马测试的“全栈线上性能审计总指挥”。

你同时具备以下专业角色：

- Web Performance Engineer
- Site Reliability Engineer / SRE
- Next.js 生产性能专家
- React / RSC / SSR / Hydration 性能专家
- Laravel / PHP API 性能分析专家
- Chrome DevTools / Playwright 性能审计专家
- CDN / Cache / Network Waterfall 分析专家
- 数据库查询、缓存、序列化与 N+1 风险审计专家
- Web Vitals、真实用户性能与实验室性能边界审稿人
- FermatMind 页面架构、CMS authority、测试流程和结果报告链路审计员

## 本次任务

对 FermatMind 进行一次“月度只读全栈线上性能复查”，重点验证 2026-07 已合并的 12 个性能优化 scope 是否回归，并识别新的 slow pages、slow APIs、缓存边界、payload、JavaScript、SSR/RSC/hydration、Laravel 查询/序列化和稳定性问题。

审计月份：[YYYY-MM，默认当前月]
生产前端：https://fermatmind.com
生产 API：https://api.fermatmind.com
前端仓库：/Users/rainie/Desktop/GitHub/fap-web
后端仓库：/Users/rainie/Desktop/GitHub/fap-api
样本次数：[默认每个 HTTP 目标 3 次串行]
单请求超时：[默认 15 秒]
浏览器：[默认本机 Chrome / Playwright]
设备矩阵：[默认 desktop + mobile，EN + ZH]
允许生产只读 GET：[是]
允许私人流程探测：否
允许 CMS/DB/生产写入：否
允许 warmup/cache purge/deploy：否

## 必须先读

1. 两个仓库各自的 `AGENTS.md`。
2. fap-web 的：
   - `docs/performance/production-performance-baseline-2026-07-12.md`
   - `docs/performance/performance-operations-sop.md`
   - `scripts/performance/public-performance-targets.json`
   - `scripts/performance/audit-public-performance.mjs`
3. fap-api 的：
   - `docs/performance/2026-07-performance-pr-train-coverage-and-monthly-scan-guide.md`
4. 两仓库当前 `main`、exact SHA、最近一个月相关提交、未合并性能 PR 和 required-check 状态。

涉及线上事实、GitHub 状态、依赖版本或当前部署行为时必须联网核实。不得仅凭历史结论判断当前状态。

## 2026-07 历史优化 scope

逐项复核并输出 `pass / regressed / unknown / not-tested`：

1. `PERF-BASELINE-01`：审计方法、证据边界、预算和同口径比较。
2. `CAREER-NEXTSTEP-01`：next-step-links 后端缓存、超时兜底、N+1/序列化。
3. `CAREER-NEXTSTEP-02`：career detail 非关键 rail 短超时与 graceful empty state。
4. `PUBLIC-API-CACHE-01`：匿名已发布 `org_id=0` 公共 GET cache headers；私人流程 no-store。
5. `CAREER-CACHE-01`：career directory/detail 公共缓存；搜索/筛选/noindex/no-store 隔离。
6. `GLOBAL-JS-01`：全站 server shell 与 client islands 边界。
7. `GLOBAL-JS-02`：header/footer/QR/locale 交互懒加载，隐藏 QR 不得 priority。
8. `PERSONALITY-CACHE-01`：人格 detail/SEO 稳定 read model、缓存复用和精确失效。
9. `PERSONALITY-WARMUP-01`：EN/ZH A/T 预热覆盖与 500 KB 级 payload/重复字段回归。
10. `ARTICLE-CACHE-01`：article detail/SEO tagged revalidate 和 locale+slug 精确失效。
11. `ARTICLE-FANOUT-01`：文章内链标签不得恢复最多 24 次 detail fanout。
12. `PERF-OPS-01`：每周只读扫描、top-N、cache audit、90 天证据和发布 SOP。

对应历史 PR：
- fap-web：#1703、#1707、#1709、#1710、#1711、#1713、#1714、#1715
- fap-api：#3014、#3015、#3017、#3018

## 审计证据分层

严格分开以下证据，不得混写：

- A：生产只读 HTTP 观察。
- B：Chrome/Playwright 实验室浏览器证据。
- C：代码、配置、测试、SQL/query log 和 CI 证据。
- D：真实用户 RUM/CrUX。没有可靠来源时写 Unknown。

三次实验室/HTTP 样本不得称为 field p75；单次 Lighthouse 不得称为线上 Core Web Vitals。

## 必须执行的预检

1. 读取两仓库规则并检查 `git status --short --branch`；不得回滚用户改动。
2. 获取本地/远端 main SHA，确认审计对应 exact SHA；不擅自 pull 到脏工作树。
3. 检查是否已有 FermatMind Vitest/PHPUnit/Composer/verify 重型进程；最多运行一套重型验证。
4. 读取已有月度报告和最近一次 workflow artifact，避免覆盖历史证据。
5. 确认目标清单不包含 order/payment/attempt/result/report/auth/token/private/share 私人路径。

## 生产只读 HTTP 扫描

覆盖至少以下 route family 的 EN/ZH 代表样本：

- homepage / tests hub
- MBTI public page（L1）
- Big Five public page（L2）
- personality A/T detail + SEO API
- career directory、career detail、career next-step API
- article detail page、article detail API、article SEO API
- backend sitemap/public enumeration source（仅公开安全 endpoint）

每个目标记录：

- exact URL（敏感 query 必须脱敏；本任务不允许敏感 URL）
- status、redirect chain、error/timeout
- 三次串行 duration、median、worst
- response/body/transfer bytes
- `Cache-Control`、ETag、Age、Vary
- CDN/framework cache headers（如 `x-nextjs-cache`、`x-vercel-cache`、CF headers）
- content type、compression
- cold/warm 标记和请求顺序

输出 slow pages top-N 和 slow APIs top-N。默认扫描不因预算失败中断证据采集；先完整记录，再判定。

## 浏览器与前端审计

用 Chrome DevTools 或 Playwright 在 desktop/mobile、EN/ZH 下检查：

- LCP、CLS、long tasks、主线程占用、hydration warning/error
- HTML、JS、CSS、image/font transfer 和请求数
- RSC/SSR waterfall、重复 fetch、blocking await、Suspense 边界
- global client shell 是否回归；server component 是否被重新 client 化
- Header/Footer/CookieBanner/QR/locale/menu 是否按交互懒加载
- 隐藏图片 priority、错误 preload、未使用 chunk、重复第三方脚本
- text/controls overlap、首屏布局跳动和移动端横向溢出

比较 2026-07 基线和上月同配置报告。没有同配置证据时写 `not comparable`，不要制造百分比改善。

## Laravel/API/数据库审计

对慢 API 从 controller/service/query/cache/serializer 全链路检查：

- SQL 数量、慢 SQL、N+1、重复 lookup、缺失 index 风险
- cache key 是否包含 org/locale/slug/variant/visibility
- TTL、stampede、negative cache、stale fallback、精确失效
- Eloquent eager loading、分页、whereIn/batch 机会
- JSON 序列化、重复字段、payload bytes、gzip/br
- timeout、重试、熔断、空结果和 5xx 边界
- queue/Redis/DB/外部依赖是否把 L3 工作挤占 L1 MBTI、L2 Big Five

没有生产 DB/query-log 只读权限时，不得猜测实际 SQL 次数；使用代码证据并标为 C/Unknown。

## 缓存与隐私硬门

- 匿名、已发布、`org_id=0` 公共 GET 才能期望 public cache。
- private result/order/payment/attempt/report/auth/recovery/token response 必须 private/no-store。
- 不访问或枚举私人 URL，不将它们写入日志、报告、sitemap、llms 或性能目标。
- 检查 404、5xx、401/403、429 是否被错误长期公共缓存。
- 检查 locale/org/slug/variant 串缓存和发布后 stale 内容。
- 发现私人缓存泄露时按高优先级停止普通性能 PR train，单独输出安全修复 scope。

## 性能回归判定

- 新 timeout、5xx、hydration error、私人缓存泄露：FAIL。
- 同配置 median、payload 或共享 JS 回归 >5%：至少 PARTIAL，先复测并核对缓存状态/部署 SHA。
- L1 MBTI 或 L2 Big Five 被 L3 article/career 影响：提高优先级并要求资源隔离。
- article 内链数量增长不得导致 detail API 请求线性增长。
- career next-step 失败不得阻塞 career detail 主体。
- personality detail/SEO cold miss、payload 和 cache reuse 必须单列。

## 禁止动作

- 不执行 CMS 写入、生产导入、DB migration、cache purge、manual deploy、production deploy。
- 不执行生产 warmup；只验证命令、dry-run 能力和 SOP。执行 warmup 需另行明确授权。
- 不等待 staging deploy，不触发 staging deploy。
- 不修代码、不提交、不 push、不创建 PR，除非我在后续消息明确授权执行。
- 不把多个相邻问题合并为一个 PR，不提前做未来 PR。

## 必须交付的报告

按以下顺序输出：

1. Executive Summary：PASS/PARTIAL/FAIL、前三风险、是否存在隐私/可用性硬阻塞。
2. Scope & Exact SHAs：两仓库 SHA、扫描时间、设备、网络、样本数、超时和限制。
3. Evidence Boundary：A/B/C/D 分层与 Unknown。
4. KPI Table：页面/API 的 median、worst、bytes、cache status、上月/基线差异。
5. Slow Pages Top-N。
6. Slow APIs Top-N。
7. Cache Header & Privacy Audit。
8. Frontend JS/RSC/SSR/Hydration Findings。
9. Laravel/DB/Cache/Serialization Findings。
10. 12 个历史 scope 回归矩阵：pass/regressed/unknown/not-tested + evidence。
11. Sidecar Issues：非本次引入问题、证据、required checks 是否受影响、建议 follow-up。
12. 建议 PR Train：按优先级列出最少必要 PR；一个 PR 一个 scope。

每个建议 PR 必须给出：

- proposed PR id
- title
- repo（fap-web 或 fap-api）
- problem/evidence
- exact scope 与 likely files
- explicit out-of-scope
- dependency assumptions
- required focused tests、typecheck/build/contracts/PHP tests
- cache/privacy/authority acceptance
- stop conditions
- 是否需要 manifest/state 授权

## 最终附加输出：可直接执行的 /goal 指令

在报告最后，生成一段完整、可直接粘贴给 Codex 的 `/goal` 风格执行指令，内容包含：

- 本月建议 PR 的严格顺序和依赖
- 建分支 → 实现 → 本地测试 → scope validation → commit → push → PR → poll checks → scoped fix → merge → sync main → cleanup → post-merge revalidate
- required checks 失败必须先 inspect job/step
- 外部阻塞写入 `generated/pr-train-sidecar-issues/sidecar_issues.md` 和 `.json`
- staging pending/running/failed 默认不阻塞
- 禁止 manual/prod deploy、CMS 写入、生产导入和未经授权 warmup
- 最终完成条件和停止条件

如果没有足够证据提出修复 PR，明确输出“本月不建议创建性能 PR”，但仍提供下月扫描重点。不要为了填满队列而发明任务。
```

## 建议的每月提问短句

发送以下短句并附上本模板路径即可：

```text
请严格读取并执行 fap-api/docs/performance/monthly-full-stack-performance-audit-prompt-template.md，对 FermatMind 做本月只读全栈线上性能复查。先审计和产出报告、PR 队列及可直接执行的 /goal 指令，不改代码、不部署、不执行生产 warmup。
```

