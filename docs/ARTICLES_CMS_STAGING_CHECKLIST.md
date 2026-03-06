# Articles 切 CMS 的 Staging 联调检查清单 + 联调执行报告模板

基线时间：2026-03-06  
后端仓库：`/Users/rainie/Desktop/GitHub/fap-api`  
前端仓库：`/Users/rainie/Desktop/GitHub/fap-web`

说明：

- 本文件只用于 staging 联调验收设计与执行记录。
- 本文件基于当前仓库真实实现编写，不假设不存在的路由、locale、sitemap 或 SEO 行为已经自动正确。
- 本次真实前端中文路由是 `/{locale=zh}`，不是 `/{locale=zh-CN}`；但前端请求后端 API 时会把 `zh` 映射为 `locale=zh-CN`。

---

## 1. 联调目标（Integration Scope）

### 本次 staging 联调覆盖范围

- [ ] `GET /api/v0.5/articles`
- [ ] `GET /api/v0.5/articles/{slug}`
- [ ] `GET /api/v0.5/articles/{slug}/seo`
- [ ] 前端 `/{locale}/articles`
- [ ] 前端 `/{locale}/articles/{slug}`
- [ ] article canonical
- [ ] article JSON-LD
- [ ] article sitemap inclusion

### 本次 staging 联调不在范围内

- [ ] `/career`
- [ ] `/personality`
- [ ] `/topics`
- [ ] CMS 写接口正确性回归
- [ ] Topic engine
- [ ] AI 内容生成
- [ ] 内容导入系统扩展

### 范围说明

- [ ] 本次只验收 articles 读链路是否打通。
- [ ] 如果 staging 中发现 `/career`、`/personality`、`/topics` 仍未接 CMS，不作为本次联调失败条件。
- [ ] 如果 CMS 写接口、导入器、Topic engine 有问题，不在本次联调结论中扩 scope。

---

## 2. Preconditions（联调前置条件）

### 后端前置条件

- [ ] staging 后端代码已部署。
- [ ] article 相关 migrations 已在 staging DB 执行。
- [ ] `GET /api/v0.5/articles`、`GET /api/v0.5/articles/{slug}`、`GET /api/v0.5/articles/{slug}/seo` 在 staging 可访问。
- [ ] 至少存在 1 到 3 篇已发布 article 用于联调。
- [ ] article 对应 `seo_meta` 已生成。
- [ ] CMS 写接口已受保护。
  - 代码依据：`routes/api.php` 中 `/api/v0.5/cms/articles*` 挂在 `AdminAuth + ResolveOrgContext + EnsureCmsAdminAuthorized` 组下。
- [ ] `/ops` 可登录，并且能进入 `Articles` 资源页。
- [ ] staging 后端 `APP_URL` 与 `services.seo.articles_url_prefix` 已确认。

### 前端前置条件

- [ ] staging 前端代码已部署。
- [ ] `/articles` 和 `/articles/[slug]` 已切到 backend CMS API。
  - 代码依据：`app/(localized)/[locale]/articles/page.tsx`
  - 代码依据：`app/(localized)/[locale]/articles/[slug]/page.tsx`
  - API 封装：`lib/cms/articles.ts`
- [ ] locale 路由可访问。
  - 真实英文路径：`/en/articles`
  - 真实中文路径：`/zh/articles`
- [ ] frontend build 已通过。
  - 代码依据：`package.json` 中 `build = velite build && next build`
- [ ] staging 前端 `/api/:path*` rewrite 已确认指向 staging backend，而不是 production backend。
  - 当前代码事实：`next.config.mjs` 写死到 `https://api.fermatmind.com/api/:path*`

### 数据前置条件

- [ ] 至少 1 篇 article 满足 `status=published`
- [ ] 至少 1 篇 article 满足 `is_public=true`
- [ ] 至少 1 篇 article 满足 `is_indexable=true`
- [ ] article 具备 `slug`、`title`、`content_md` 或 `content_html`
- [ ] article 对应 `seo_meta` 存在
- [ ] article 能进入最终对外 sitemap
- [ ] 联调使用的文章 slug 已记录：`<slug-1>`、`<slug-2>`、`<slug-3>`

### 前置 blocker 检查

- [ ] blocker：若 staging 前端仍通过 `next.config.mjs` 指向生产 API，则本轮联调不应开始。
- [ ] blocker：若 staging 中没有任何 published/public/indexable article，则本轮联调不应开始。
- [ ] blocker：若尚未确定唯一 sitemap authority，则本轮联调结论不能标记为通过。

---

## 3. Backend API Checks

### 3.1 `GET /api/v0.5/articles`

#### 检查动作

- [ ] 调 `locale=en` + `org_id=0`
- [ ] 调 `locale=zh-CN` + `org_id=0`
- [ ] 检查分页字段是否返回
- [ ] 检查是否只返回 `published + is_public=true`
- [ ] 检查是否只返回期望 org 范围
- [ ] 检查 `is_indexable=false` 是否仍会出现在 list 中
- [ ] 检查 locale 行为
  - 当前代码事实：backend controller `index()` 没有按 `locale` 过滤
  - 当前前端事实：前端在 `lib/cms/articles.ts` 中做了二次 locale 过滤

#### 期望结果

- [ ] 状态码为 `200`
- [ ] 返回 JSON 顶层包含：
  - [ ] `ok`
  - [ ] `items`
  - [ ] `pagination.current_page`
  - [ ] `pagination.per_page`
  - [ ] `pagination.total`
  - [ ] `pagination.last_page`
- [ ] `items[*]` 至少包含：
  - [ ] `id`
  - [ ] `slug`
  - [ ] `locale`
  - [ ] `title`
  - [ ] `excerpt`
  - [ ] `content_md`
  - [ ] `content_html`
  - [ ] `published_at`
  - [ ] `updated_at`
  - [ ] `status`
  - [ ] `is_public`
  - [ ] `is_indexable`
  - [ ] `seo_meta`

#### 通过判定

- [ ] 返回至少 1 条 staging 已发布文章
- [ ] `status != published` 或 `is_public=false` 的文章不应出现在 list
- [ ] 若期望按 locale 隔离列表，则必须确认 backend 或 frontend 最终行为可接受

#### 风险备注

- [ ] blocker：backend list API 目前忽略 `locale` 参数；若 staging 同时存在多语言内容，前端分页可能出现数量不准或分页页码与当前 locale 实际数据不一致。

#### curl 模板

```bash
curl -sS "https://<backend-staging-host>/api/v0.5/articles?org_id=0&locale=en&page=1"
curl -sS "https://<backend-staging-host>/api/v0.5/articles?org_id=0&locale=zh-CN&page=1"
```

### 3.2 `GET /api/v0.5/articles/{slug}`

#### 检查动作

- [ ] 用 staging 已发布 slug 调英文
- [ ] 用 staging 已发布 slug 调中文
- [ ] 检查 404 行为
- [ ] 检查 `org_id` 行为
- [ ] 检查 `status / is_public` 过滤

#### 期望结果

- [ ] 状态码为 `200`
- [ ] 返回 JSON 顶层包含：
  - [ ] `ok`
  - [ ] `article`
- [ ] `article` 至少包含：
  - [ ] `id`
  - [ ] `slug`
  - [ ] `locale`
  - [ ] `title`
  - [ ] `excerpt`
  - [ ] `content_md`
  - [ ] `content_html`
  - [ ] `cover_image_url`
  - [ ] `published_at`
  - [ ] `updated_at`
  - [ ] `status`
  - [ ] `is_public`
  - [ ] `is_indexable`
  - [ ] `category`
  - [ ] `tags`
  - [ ] `seo_meta`

#### 通过判定

- [ ] staging 已发布 slug 返回 `200`
- [ ] staging 未发布或不可见 slug 返回 `404`
- [ ] `locale=en` 与 `locale=zh-CN` 行为符合预期

#### curl 模板

```bash
curl -sS "https://<backend-staging-host>/api/v0.5/articles/<slug>?org_id=0&locale=en"
curl -sS "https://<backend-staging-host>/api/v0.5/articles/<slug>?org_id=0&locale=zh-CN"
```

### 3.3 `GET /api/v0.5/articles/{slug}/seo`

#### 检查动作

- [ ] 用 staging 已发布 slug 调英文
- [ ] 用 staging 已发布 slug 调中文
- [ ] 检查 `meta` 是否齐全
- [ ] 检查 `jsonld` 是否齐全
- [ ] 检查 `canonical` 是否为 backend 默认规则

#### 期望结果

- [ ] 状态码为 `200`
- [ ] 返回 JSON 顶层包含：
  - [ ] `meta.title`
  - [ ] `meta.description`
  - [ ] `meta.canonical`
  - [ ] `meta.og`
  - [ ] `meta.twitter`
  - [ ] `meta.robots`
  - [ ] `jsonld`

#### 通过判定

- [ ] SEO API 能返回 `meta + jsonld`
- [ ] `jsonld` 至少包含：
  - [ ] `@context`
  - [ ] `@type`
  - [ ] `headline`
  - [ ] `datePublished`
  - [ ] `dateModified`
  - [ ] `mainEntityOfPage`

#### 风险备注

- [ ] 当前代码事实：backend `ArticleSeoService` 默认 canonical 为 `/articles/{slug}`，不是前端 locale 路径。
- [ ] 当前代码事实：backend `jsonld.mainEntityOfPage` 默认也是 `/articles/{slug}`。

#### curl 模板

```bash
curl -sS "https://<backend-staging-host>/api/v0.5/articles/<slug>/seo?org_id=0&locale=en"
curl -sS "https://<backend-staging-host>/api/v0.5/articles/<slug>/seo?org_id=0&locale=zh-CN"
```

---

## 4. Frontend Page Checks

### 4.1 `/{locale}/articles`

#### 检查动作

- [ ] 打开英文列表页：`https://<frontend-staging-host>/en/articles`
- [ ] 打开中文列表页：`https://<frontend-staging-host>/zh/articles`
- [ ] 检查列表是否非空
- [ ] 检查 `title / excerpt / published_at` 是否渲染
- [ ] 检查 article link 是否跳到 locale detail
- [ ] 检查 API 为空时空态是否优雅
- [ ] 检查 canonical 是否指向 locale 版本

#### 期望结果

- [ ] 页面可打开
- [ ] 不报 500
- [ ] 有 article 卡片
- [ ] 卡片至少显示：
  - [ ] `title`
  - [ ] `excerpt`
  - [ ] `published_at` 或格式化后的日期
  - [ ] 详情跳转链接
- [ ] 若后端返回空，页面出现空态卡片而不是报错
- [ ] 页面源码包含 locale canonical

### 4.2 `/{locale}/articles/{slug}`

#### 检查动作

- [ ] 打开英文详情页：`https://<frontend-staging-host>/en/articles/<slug>`
- [ ] 打开中文详情页：`https://<frontend-staging-host>/zh/articles/<slug>`
- [ ] 检查详情不 404
- [ ] 检查正文是否优先渲染 `content_html`
- [ ] 若 `content_html` 为空，检查 `content_md` fallback 是否正常
- [ ] 检查 breadcrumb 是否正常
- [ ] 检查 `RelatedContent` 区块即使为空也不报错
- [ ] 检查 canonical、title、description、JSON-LD

#### 期望结果

- [ ] 页面可打开
- [ ] 已发布 slug 不 404
- [ ] 正文可读，无明显原始 HTML/Markdown 泄漏
- [ ] breadcrumb 为 `Home / Articles / <title>` 或中文对应
- [ ] 页面源码有 `<script type="application/ld+json">`
- [ ] canonical 与最终 locale URL 一致

### 4.3 locale 检查

#### 仓库真实 locale 规则

- [ ] 实际前端路径支持：`/en/*` 与 `/zh/*`
- [ ] 实际前端不支持：`/zh-CN/*`
- [ ] API locale 仍应传：`en` / `zh-CN`

#### 必查地址

- [ ] `https://<frontend-staging-host>/en/articles`
- [ ] `https://<frontend-staging-host>/zh/articles`
- [ ] `https://<frontend-staging-host>/en/articles/<slug>`
- [ ] `https://<frontend-staging-host>/zh/articles/<slug>`

#### 失败判定

- [ ] 若测试同学仍按 `/zh-CN/articles` 验证前端页面，应判定为路径用错，不作为代码缺陷结论。

---

## 5. SEO Validation

### 核心 SEO 检查项

- [ ] canonical 是否与真实前端 locale 路径一致
- [ ] backend SEO API 返回值是否被前端正确消费
- [ ] `title`
- [ ] `description`
- [ ] OpenGraph
- [ ] Twitter
- [ ] JSON-LD
- [ ] 页面源码中的 `<script type="application/ld+json">`
- [ ] hreflang / alternates
- [ ] article 是否进入最终 sitemap
- [ ] 只能存在一个最终对外 sitemap authority

### Canonical Conflict Check

#### 当前代码事实

- [ ] backend 默认 canonical：`/articles/{slug}`
- [ ] backend 默认 JSON-LD `mainEntityOfPage`：`/articles/{slug}`
- [ ] frontend 真实详情路径：`/en/articles/{slug}` / `/zh/articles/{slug}`
- [ ] frontend 已在 detail page 中做 canonical 收敛
- [ ] frontend 已在 detail page 中对 backend JSON-LD URL 做收敛

#### staging 必须确认

- [ ] 最终 HTML `<link rel="canonical">` 是 locale URL
- [ ] 最终 HTML JSON-LD 中 `mainEntityOfPage` 是 locale URL
- [ ] 不能出现 backend `/articles/{slug}` 与 frontend `/{locale}/articles/{slug}` 同时对外暴露为 canonical

### 页面源码检查动作

- [ ] 打开 `view-source:https://<frontend-staging-host>/en/articles/<slug>`
- [ ] 打开 `view-source:https://<frontend-staging-host>/zh/articles/<slug>`
- [ ] 搜索：
  - [ ] `<title>`
  - [ ] `<meta name="description">`
  - [ ] `<link rel="canonical">`
  - [ ] `og:title`
  - [ ] `og:description`
  - [ ] `twitter:title`
  - [ ] `twitter:description`
  - [ ] `application/ld+json`

### 失败判定

- [ ] canonical 仍为 `/articles/{slug}` 视为失败
- [ ] JSON-LD `mainEntityOfPage` 仍为 `/articles/{slug}` 视为失败
- [ ] staging 页面仍输出 production canonical / robots 视为失败

---

## 6. Sitemap Validation

### 当前仓库可见事实

- [ ] frontend 侧存在 sitemap authority 候选
  - `package.json` 的 `postbuild` 会运行 `next-sitemap`
  - `next-sitemap.config.js` 会生成 frontend sitemap
  - `public/robots.txt` 当前指向 `https://fermatmind.com/sitemap.xml`
- [ ] backend 侧也存在 sitemap authority 候选
  - `routes/web.php` 暴露 `/sitemap.xml`
  - `SitemapController` + `SitemapGenerator` 动态生成 XML

### staging 必须确认

- [ ] 最终对外 sitemap authority 是谁
  - [ ] frontend
  - [ ] backend
- [ ] article URL 是否出现在最终对外 sitemap 中
- [ ] sitemap 中 article URL 是否与前端最终 locale URL 一致
- [ ] 若 sitemap 中 article URL 与前端最终 locale URL 不一致，则判定联调不通过

### backend sitemap 专项检查

- [ ] 确认 `services.seo.articles_url_prefix` 的真实值
- [ ] 确认 backend sitemap 是否输出 `/articles/{slug}` 或某个非 locale 前缀
- [ ] 若 backend sitemap 非 locale，而前端 canonical 是 locale URL，则判定 authority 冲突

### frontend sitemap 专项检查

- [ ] 确认 frontend `next-sitemap.config.js` 是否已切到 CMS 内容源
- [ ] 当前代码事实：frontend sitemap 仍基于 `.velite/blog.json` 构建
- [ ] 若 articles 页面已切 CMS，但 frontend sitemap 仍基于本地 Velite，则必须明确是否接受

### 浏览器 / curl 检查步骤

```bash
curl -i "https://<frontend-staging-host>/sitemap.xml"
curl -i "https://<backend-staging-host>/sitemap.xml"
curl -sS "https://<frontend-staging-host>/sitemap.xml" | rg "/articles/"
curl -sS "https://<backend-staging-host>/sitemap.xml" | rg "/articles/"
```

### blocker 判定

- [ ] blocker：若 staging 团队无法明确“唯一对外 sitemap authority”，本轮联调不得签通过。
- [ ] blocker：若唯一对外 sitemap 中 article URL 与最终前端 locale URL 不一致，本轮联调不得签通过。

---

## 7. Ops Validation

### staging 中要做的 ops 验证

- [ ] 登录 `/ops`
- [ ] 进入 `Articles`
- [ ] 找到已发布 article
- [ ] 确认以下字段：
  - [ ] `status`
  - [ ] `is_public`
  - [ ] `is_indexable`
  - [ ] `slug`
  - [ ] `locale`
  - [ ] `title`
  - [ ] `excerpt`
  - [ ] SEO fields
    - [ ] `seo_title`
    - [ ] `seo_description`
    - [ ] `canonical_url`
    - [ ] `og_title`
    - [ ] `og_description`
    - [ ] `og_image_url`
- [ ] 必要时重新生成或编辑 SEO
- [ ] 验证后台状态与前台展示一致

### 期望结果

- [ ] Ops 中 article 状态与前台 list/detail 完全一致
- [ ] Ops 中 SEO 字段与 `/api/v0.5/articles/{slug}/seo` 返回一致
- [ ] 已发布 article 能在前端显示
- [ ] 不公开或未发布 article 不应出现在前端

### 失败判定

- [ ] ops 已显示 published，但前端 list 为空
- [ ] ops 中 slug 与前台 detail slug 不一致
- [ ] ops 中 SEO 已填，但 SEO API / 前端页面未反映

---

## 8. Pass / Fail Criteria

### PASS 条件

- [ ] article list API 返回 published items
- [ ] article detail API 返回指定 slug
- [ ] SEO API 返回 `meta + jsonld`
- [ ] 前端 article list 可访问
- [ ] 前端 article detail 可访问
- [ ] canonical 正确
- [ ] JSON-LD 正确
- [ ] sitemap 中出现 article
- [ ] sitemap 中 article URL 与前端最终 locale URL 一致
- [ ] ops 与前台数据一致
- [ ] staging 团队已明确唯一 sitemap authority

### FAIL 条件

- [ ] list 为空但后台已发布内容
- [ ] detail 404
- [ ] canonical 非 locale URL
- [ ] JSON-LD 缺失
- [ ] JSON-LD `mainEntityOfPage` 非 locale URL
- [ ] sitemap authority 不明确
- [ ] staging 仍输出 production robots/canonical
- [ ] 前后台文章不一致
- [ ] 前端 staging 实际请求了 production API
- [ ] list 分页明显异常且可追溯到 backend locale 未过滤

---

## 9. Smoke Run Sheet

### 1. backend api smoke

- [ ] 动作：执行三个 read API 的英文/中文探针
- [ ] 命令：见 Appendix backend curl
- [ ] 通过标准：`list=200`、`detail=200`、`seo=200`
- [ ] 失败时记录：
  - [ ] 请求 URL
  - [ ] status code
  - [ ] response body
  - [ ] slug
  - [ ] locale

### 2. ops content check

- [ ] 动作：登录 `/ops` 检查发布文章
- [ ] 访问地址：`https://<backend-staging-host>/ops`
- [ ] 通过标准：至少 1 篇 article 满足 `published + public + indexable`
- [ ] 失败时记录：
  - [ ] article id
  - [ ] slug
  - [ ] status
  - [ ] is_public
  - [ ] is_indexable
  - [ ] seo fields 截图

### 3. frontend list page

- [ ] 动作：打开 `/en/articles` 与 `/zh/articles`
- [ ] 访问地址：
  - [ ] `https://<frontend-staging-host>/en/articles`
  - [ ] `https://<frontend-staging-host>/zh/articles`
- [ ] 通过标准：页面打开、列表有内容、链接可点、canonical 正确
- [ ] 失败时记录：
  - [ ] 页面 URL
  - [ ] 页面截图
  - [ ] HTML 源码中的 canonical
  - [ ] 接口响应

### 4. frontend detail page

- [ ] 动作：打开 `/en/articles/<slug>` 与 `/zh/articles/<slug>`
- [ ] 访问地址：
  - [ ] `https://<frontend-staging-host>/en/articles/<slug>`
  - [ ] `https://<frontend-staging-host>/zh/articles/<slug>`
- [ ] 通过标准：不 404、正文正常、breadcrumb 正常、JSON-LD 正常
- [ ] 失败时记录：
  - [ ] 页面 URL
  - [ ] slug
  - [ ] 页面截图
  - [ ] HTML 源码中的 canonical/JSON-LD

### 5. seo source check

- [ ] 动作：对比 SEO API 与页面最终 head
- [ ] 命令：
  - [ ] `curl /api/v0.5/articles/<slug>/seo`
  - [ ] `view-source:https://<frontend-staging-host>/<locale>/articles/<slug>`
- [ ] 通过标准：前端最终 canonical/title/description/OG/Twitter 与联调规则一致
- [ ] 失败时记录：
  - [ ] backend SEO payload
  - [ ] frontend head 片段
  - [ ] 差异说明

### 6. sitemap check

- [ ] 动作：检查唯一 authority 的 sitemap
- [ ] 命令：见 Appendix
- [ ] 通过标准：article URL 在最终 sitemap 中，且与前端 locale URL 一致
- [ ] 失败时记录：
  - [ ] sitemap authority 判定
  - [ ] sitemap XML 片段
  - [ ] canonical URL
  - [ ] 差异说明

### 7. final pass/fail signoff

- [ ] 动作：汇总 API / Ops / Frontend / SEO / Sitemap 五项结果
- [ ] 通过标准：Section 8 PASS 条件全部满足
- [ ] 失败时记录：
  - [ ] blocker 列表
  - [ ] owner
  - [ ] next action
  - [ ] 预计复验时间

---

## 10. Appendix

### backend

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend

php artisan route:list --path=api/v0.5 --except-vendor
curl -sS "http://127.0.0.1:8000/api/v0.5/articles?org_id=0"
curl -sS "http://127.0.0.1:8000/api/v0.5/articles/<slug>?org_id=0&locale=en"
curl -sS "http://127.0.0.1:8000/api/v0.5/articles/<slug>/seo?org_id=0&locale=en"
curl -i http://127.0.0.1:8000/sitemap.xml
```

### frontend

```bash
cd /Users/rainie/Desktop/GitHub/fap-web

pnpm build
pnpm dev
```

### 页面访问模板

```text
https://<frontend-staging-host>/en/articles
https://<frontend-staging-host>/en/articles/<slug>
https://<frontend-staging-host>/zh/articles
https://<frontend-staging-host>/zh/articles/<slug>
```

说明：

- 前端真实中文路由是 `/zh/*`，不是 `/zh-CN/*`
- 后端 API locale 参数仍建议使用 `locale=zh-CN`

### 推荐补充命令

```bash
curl -sS "https://<backend-staging-host>/api/v0.5/articles?org_id=0&locale=en&page=1"
curl -sS "https://<backend-staging-host>/api/v0.5/articles?org_id=0&locale=zh-CN&page=1"
curl -sS "https://<backend-staging-host>/api/v0.5/articles/<slug>?org_id=0&locale=zh-CN"
curl -sS "https://<backend-staging-host>/api/v0.5/articles/<slug>/seo?org_id=0&locale=zh-CN"
curl -i "https://<frontend-staging-host>/sitemap.xml"
curl -i "https://<backend-staging-host>/sitemap.xml"
```

---

## 执行报告模板

### 基本信息

- 执行时间：
- 执行环境：
- backend staging host：
- frontend staging host：
- article slug：
- 执行人：

### 结果摘要

- backend api smoke：PASS / FAIL
- ops content check：PASS / FAIL
- frontend list page：PASS / FAIL
- frontend detail page：PASS / FAIL
- seo source check：PASS / FAIL
- sitemap check：PASS / FAIL

### blocker 列表

- blocker 1：
- blocker 2：
- blocker 3：

### 结论

- 最终结论：PASS / FAIL
- 是否允许进入下一阶段：
- 后续动作：

