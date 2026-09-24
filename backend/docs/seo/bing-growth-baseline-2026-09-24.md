# Bing 增长基线与阻断记录（2026-09-24）

## 口径与状态

- 当前完整 28 天：2026-08-27 至 2026-09-23；前 28 天：2026-07-30 至 2026-08-26。Web 搜索与 AI 引用必须分开统计。
- Bing Webmaster Tools 当前 Google 登录账号下的 `https://fermatmind.com/` 停在 **Add & verify site**。该站点的 Bing Search Performance、URL Inspection、Crawl、IndexNow 提交历史不可读取。GSC Wizard 的 Bing 连接器返回订阅已结束，不能替代站长工具数据。
- 此文档是读数与缺口记录。未向搜索引擎提交 URL，未改 CMS、站点地图或线上配置。

## 1. 真实流量与测评漏斗

| 来源与窗口 | 可核实读数 | 解释边界 |
| --- | --- | --- |
| 百度统计，当前完整 28 天，来源网站 `cn.bing.com` | 97 PV、48 UV、49 IP、跳出率 65.52%、平均访问时长 00:07:37 | 按引荐来源网站统计，不能充当 Bing Webmaster Tools 的搜索点击数，也未证明全部为自然搜索。 |
| 百度统计，前 28 天 | 全站仅 2 PV、2 UV，均为直接访问；未列出 Bing 行 | 早期埋点覆盖明显不足，不计算环比增长率。 |
| GA4，当前完整 28 天首页卡片 | 首次用户来源 `cn.bing.com / referral` 为 127 活跃用户；`bing / organic` 为 14 活跃用户；会话来源 `cn.bing.com / referral` 为 47 会话 | 不同归因口径不能相加；`cn.bing.com` 被分类为 referral，单筛 Organic Search 会漏算。会话来源卡只显示前七项，`bing / organic` 会话数未知。 |
| 百度统计转化、GA4 关键事件 | 百度统计显示 `--`；GA4 首页关键事件卡显示无可用数据 | 都不能解释成零测评开始或零测评完成。 |

需补的同口径漏斗：`Bing 自然搜索 → canonical 入口页 → 开始测评 → 完成测评`。先核实 GA4 的会话来源分类、测评开始/完成事件与会话关联，再以 Bing Webmaster Tools 的 Web 搜索页面/查询点击校验入口。百度统计概况页的 MBTI、RIASEC 全站入口排名不能当成 Bing 赢家。

Bing 查询、页面、曝光、点击、CTR、平均排名、国家/地区、设备和前后 28 天变化均为 **未取得**；不存在可审计的 Bing Top 20 排名名单。

## 2. 发现、抓取与收录

- 线上 `sitemap.xml` 返回 200，包含 1,151 个 URL，其中中文测试路径 8、中文文章路径 89、中文人格路径 181、中文职业详情路径 579。`robots.txt` 返回 200，`User-Agent: *` 为 `Allow: /`，并指向该 sitemap。
- 仓库 Career Current manifest 有 1,046 个中文身份，其中 579 个标记有公开正文、467 个无公开正文；线上 sitemap 中的 579 个中文职业详情 URL 与有正文数一致。不能把 1,046 个身份或旧的“约 400 页”当成已上线正文数。
- 只读抽查 MBTI、RIASEC、Big Five 人格、文章，以及 `accountants-and-auditors`、`actors`、`zoologists-and-wildlife-biologists` 三个职业页：HTTP 200、自指 canonical、`index, follow`。这不是 579 页逐页验收。
- 抽查带 `use_xbridge3` 的 MBTI 参数页与带 `utm_source` 的职业页：canonical 均指无参数 URL。MBTI `/take?form=...` 返回 `noindex, nofollow, noarchive, nocache` 且 canonical 指公开测试页。未见该样本的参数重复 canonical 缺陷。
- 分层：**可发现/候选可索引**可由 sitemap 与样本证明；**Bing 已发现、已抓取、已索引、有 Web 曝光、有 Web 点击**均未取得站长工具证据。Bingbot 的实际 4xx/5xx、robots 命中、抓取量和 URL Inspection 结论均未知。

## 3. IndexNow 链路

- `https://fermatmind.com/BingSiteAuth.xml` 返回 404，首页未发现 Bing 验证 meta；这是 Bing Webmaster Tools 所示 XML 验证方案的缺口。它与 IndexNow key 文件是不同凭据，不能互相代替。
- 后端有 Search Channel Queue、IndexNow bounded executor、发布后 `seo-agent:post-publish-indexnow-auto` 命令及历史接受回执。该命令及调用它的 priority scheduler 继承 `RetiredSeoAgentCommand`，在非单元测试环境 `isEnabled()` 为 false；不能把旧文档中的命令存在视为当前生产自动触发。当前文章 release closeout 明确要求 `indexnow_submission_count == 0`。
- 当前生产 IndexNow key/keyLocation、具体新内容发布触发、最近提交及失败日志未取得。历史 `accepted` 只表示 provider 收到更新信号，不是已抓取、已索引或排名改善。现阶段没有依据重提交全站 URL。

## 4. 已有赢家 Top 20

**HOLD：没有 Bing Webmaster Tools Web 搜索页面×查询证据，不能选出或编造 20 页。**解锁后按页面×查询×国家/地区×设备列出曝光、点击、CTR、排名及最小动作；先选有点击且排名 4–10 并有测评开始的页面，再选排名 11–20 且意图匹配的页面，最后检查内容完整但发现/索引异常的页面。低基数点击不以夸张增长率判成功。

## 5. 中文职业页试点

当前可核实的公开发现集合是 579 个有正文的中文职业详情 URL，Bing 已发现/索引/曝光的全量状态仍未知。没有 Bing 查询需求和中文地域 SERP 样本前，不指定“10–20 个有需求证据”的候选，也不扩量。试点逐页核对职责、入行、技能、薪资证据与适用地区、职业区别、内容来源和相关内链；不为 Bing 另建职业页。

## 6. 每周复核与文章选题

每周在同一完整 7 天窗口复核 Bing Web 点击、曝光、查询、已索引/抓取问题和 Bing 来源测评开始/完成，保留相同前一窗口作为比较；AI 引用单列。每日现有文章选题在同一候选池同时核对 Google 与 Bing 机会，不增加日更数量。Bing 站点验证与漏斗事件口径完成前，周报只能标注缺失，不能以百度统计引荐数代替 Bing Web 点击。

## 本次证据边界与下一验收

证据：2026-09-24 已登录 Bing/百度统计/GA4 页面只读观察；线上公开 `robots.txt`、`sitemap.xml`、页面与验证文件 HTTP 只读请求；仓库 manifest、IndexNow 实现和当前 release 脚本。Bing 站点验证成功并产生可读取数据后，补齐两个完整 28 天的查询/页面导出、核心 URL Inspection 和抓取问题，再形成 Top 20 与职业 10–20 页试点。按 [Bing 官方验证说明](https://www2.bing.com/webmasters/help/add-and-verify-site-12184f8b)，新添加站点的数据生成通常需要约 48 小时。[IndexNow 官方说明](https://www.indexnow.org/faq)也明确提交不保证收录。

已在 Codex 现有任务建立每周一 09:00（Asia/Shanghai）的只读 Bing 复核 heartbeat；站点状态无实质变化时静默。
