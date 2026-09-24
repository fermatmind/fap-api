# Bing 增长基线与阻断记录（2026-09-24）

## 口径与状态

- 当前完整 28 天：2026-08-27 至 2026-09-23；前 28 天：2026-07-30 至 2026-08-26。Web 搜索与 AI 引用必须分开统计。
- 站点 `https://fermatmind.com/` 已于 2026-09-24 经 Google Search Console 导入 Bing Webmaster Tools，页面显示导入 1 个站点成功。Bing 首页提示报告最多需要 48 小时处理；Search Performance 页面明确要求 48 小时后再查看。此时没有可导出的 Web 查询/页面表现数据。GSC Wizard 的 Bing 连接器返回订阅已结束，不能替代站长工具数据。
- 此文档是读数与缺口记录。未向搜索引擎提交 URL，未改 CMS、站点地图或线上配置。

## 1. 真实流量与测评漏斗

| 来源与窗口 | 可核实读数 | 解释边界 |
| --- | --- | --- |
| 百度统计，当前完整 28 天，来源网站 `cn.bing.com` | 97 PV、48 UV、49 IP、跳出率 65.52%、平均访问时长 00:07:37 | 按引荐来源网站统计，不能充当 Bing Webmaster Tools 的搜索点击数，也未证明全部为自然搜索。 |
| 百度统计，前 28 天 | 全站仅 2 PV、2 UV，均为直接访问；未列出 Bing 行 | 早期埋点覆盖明显不足，不计算环比增长率。 |
| GA4，当前完整 28 天首页卡片 | 首次用户来源 `cn.bing.com / referral` 为 127 活跃用户；`bing / organic` 为 14 活跃用户；会话来源 `cn.bing.com / referral` 为 47 会话 | 不同归因口径不能相加；`cn.bing.com` 被分类为 referral，单筛 Organic Search 会漏算。会话来源卡只显示前七项；下方事件报告补出了 `bing / organic` 的会话读数。 |
| GA4 事件报告，当前完整 28 天，**会话来源/媒介精确匹配** `cn.bing.com / referral` | `session_start` 47 次/43 用户、`test_start` 33 次/13 用户、`test_complete` 15 次/13 用户 | 证明该归因来源有测评行为；事件次数不是同一会话或同一用户的有序漏斗，不能用 15/33 作为完成率。`referral` 分类也不能单独证明全部是 Bing Web 自然搜索。 |
| GA4 事件报告，同期，精确匹配 `bing / organic` | `session_start` 3 次/3 用户、`test_start` 0 次、`test_complete` 0 次 | 与上行分开统计；样本极小，不能推出必应自然流量无转化。 |
| GA4 事件报告，同期，来源/媒介**包含** `bing` | `session_start` 50 次/46 用户、`test_start` 33 次/13 用户、`test_complete` 15 次/13 用户 | 聚合口径仅用于检查归因漏记；不可与上面两个精确来源重复相加。 |
| 百度统计转化、GA4 关键事件 | 百度统计显示 `--`；GA4 首页关键事件卡显示无可用数据 | 都不能解释成零测评开始或零测评完成。 |

GA4 事件报告在同一 `cn.bing.com / referral` 会话来源/媒介筛选下，再按“着陆页 + 查询字符串”拆分（2026-08-27 至 2026-09-23）：

| 着陆页族（合并测评页与 `/take` 参数） | `test_start` 次数 | `test_complete` 次数 | 边界 |
| --- | ---: | ---: | --- |
| 中文 RIASEC 测评 | 15 | 6 | 公开页 14 次开始，`/take?form=riasec_60` 1 次；完成分别为 4、2 次。 |
| 中文 MBTI 测评 | 9 | 3 | 公开页 7 次开始，带人格页入口参数的 `/take` 2 次；完成分别为公开页 2 次、另一 `/take?form=mbti_93` 1 次。 |
| 中文九型测评 | 5 | 3 | 开始均落在 forced-choice `/take`；完成分别落在 forced-choice 2 次和 Likert `/take` 1 次。 |
| 中文 `infp-t` 人格页 | 4 | 1 | 着陆页维度是 GA4 会话维度，不能推断该页就是 Bing Web 点击页面。 |
| 其他/未设置 | 0 | 2 | 一条结果页路径、一条 `(not set)`；不公开结果页私有 ID。 |

上表事件次数各合计开始 33、完成 15，与精确来源筛选总数一致。不同事件的着陆页统计不能拼成同一用户的有序漏斗，`/take` 会话也不能当作可索引搜索入口。需补的同口径漏斗仍是 `Bing Web 自然搜索 → canonical 入口页 → 开始测评 → 完成测评`：用 Bing Webmaster Tools 的 Web 页面/查询点击校验入口，再用 GA4 同会话标识核对事件顺序。百度统计概况页的 MBTI、RIASEC 全站入口排名不能当成 Bing 赢家。

Bing 查询、页面、曝光、点击、CTR、平均排名、国家/地区、设备和前后 28 天变化均为 **未取得**；不存在可审计的 Bing Top 20 排名名单。

## 2. 发现、抓取与收录

- 线上 `sitemap.xml` 返回 200，包含 1,151 个 URL，其中中文测试路径 8、中文文章路径 89、中文人格路径 181、中文职业详情路径 579。`robots.txt` 返回 200，`User-Agent: *` 为 `Allow: /`，并指向该 sitemap。
- 仓库 Career Current manifest 有 1,046 个中文身份，其中 579 个标记有公开正文、467 个无公开正文；线上 sitemap 中的 579 个中文职业详情 URL 与有正文数一致。不能把 1,046 个身份或旧的“约 400 页”当成已上线正文数。
- 2026-09-24 对公开 `/api/v0.5/career/directory?locale=zh-CN&per_page=100` 的 11 页做全量只读遍历：目录报告 1,043 个成员（另 3 个身份排除），逐行结果为 579 个 `indexable`、464 个 `noindex`。579 个 `indexable` 的 canonical path 与当时 sitemap 中的 579 个中文职业详情路径双向差集均为 0。这证明当前公开目录权威与 sitemap 的**候选可索引集合**一致，不证明这 579 页已由 Bing 发现、抓取或收录；目录的 `detail_ready` 也不能替代逐页正文质量检查。
- 只读抽查 MBTI、RIASEC、Big Five 人格、文章，以及 `accountants-and-auditors`、`actors`、`web-developers`、`zoologists-and-wildlife-biologists` 四个职业页：HTTP 200、自指 canonical、`index, follow`。这不是 579 页逐页验收。
- 抽查带 `use_xbridge3` 的 MBTI 参数页与带 `utm_source` 的职业页：canonical 均指无参数 URL。MBTI `/take?form=...` 返回 `noindex, nofollow, noarchive, nocache` 且 canonical 指公开测试页。未见该样本的参数重复 canonical 缺陷。
- Bing URL Inspection 对中文 MBTI、RIASEC 测评页和 `accountants-and-auditors` 中文职业页均显示 **Indexed successfully / URL can appear on Bing**，且未提示 SEO/GEO 问题。该职业页显示 2026-04-19 已发现、2026-09-22 最近抓取尝试、允许抓取、抓取成功、允许索引；检查详情中的 canonical URL 显示 `-`，不能据此判定全站 canonical 状态。
- 2026-09-24 新增 URL Inspection 抽查：中文 `infp-t` 人格页、`big-five-30-facets-explained-with-model-caveats` 文章页和 `actors` 职业页也显示 **Indexed successfully**。`infp-t` 的最近抓取为当日 02:15，允许抓取、抓取成功、允许索引。六个已索引样本仍不能外推为全站覆盖率。
- `web-developers` 与 `zoologists-and-wildlife-biologists` 两个中文职业页的 **Bing Index 快照**均为 **Not indexed due to NOINDEX directive**：分别在 2026-09-07 00:28 和 2026-09-02 05:44 最近抓取，允许抓取、抓取成功、当时不允许索引。与之相对，两页当日公开 HTTP 响应均为 200、无 `X-Robots-Tag`、meta robots `index, follow`、自指 canonical；Bing **Live URL** 当日测试均显示 **URL can be indexed by Bing**。因此已证实的是 Bing 旧快照与当前线上索引许可不一致；不能据此认定当前仍含 `noindex`，也不能把实时测试当成已收录。这两页列为优先观察下一次抓取及索引状态的样本。
- 分层：sitemap 证明 **可发现候选**；六个受检 URL 可升至 **Bing 已索引**；上述两个职业页为 **当前可索引、Bing 快照未索引**；**有 Web 曝光、有 Web 点击**仍待 Search Performance 报告。Site Explorer 当前显示 `No data available`，Bingbot 的全站 4xx/5xx、robots 命中及抓取量尚不能汇总，样本不能外推至 579 页。
- 已在 Bing Site Scan 建立 10 页 URL-list 扫描 `FermatMind Bing core and career audit 2026-09-24`，覆盖中文 MBTI、RIASEC、Big Five、九型测评、`infp-t`、Big Five 文章及四个上述职业页；当前状态为 `Queued`，错误和警告尚无结果。Site Scan 明示其 crawler 尚未使用 Bingbot IP；即使完成，也只能作为页面技术扫描，不能替代 Bingbot 抓取日志或 Web 收录证据。不要在队列中重复建立扫描。

## 3. IndexNow 链路

- 本次通过 GSC 导入完成 Bing 站点验证，不再需要 XML 或 meta 作为当前验证前提。`https://fermatmind.com/BingSiteAuth.xml` 仍返回 404；它与 IndexNow key 文件是不同凭据，不能互相代替。
- 前端仓库 `fap-web/public/` 中的现有 IndexNow key 静态文件已与同名线上根路径逐字节比对：线上返回 HTTP 200、`text/plain`，正文与文件名中的 key 一致。历史修复说明曾记录 keyLocation 指向该路径而线上文件 404 的问题；当前文件可访问证明这一验证文件缺口已修复。生产 `SEO_INTEL_INDEXNOW_KEY_LOCATION` 是否仍指向该文件、近期提交是否被接受，尚无生产配置或回执证据；不在报告中公开 key 值。
- 后端有 Search Channel Queue、IndexNow bounded executor、发布后 `seo-agent:post-publish-indexnow-auto` 命令及历史接受回执。该命令及调用它的 priority scheduler 继承 `RetiredSeoAgentCommand`，在非单元测试环境 `isEnabled()` 为 false；不能把旧文档中的命令存在视为当前生产自动触发。`seo-agent:post-publish-search-submit` 同样继承此基类，且其执行模式仅入队、不向外部提交。当前文章 release closeout 明确要求 `indexnow_submission_count == 0`。
- CMS 文章发布可产生 `PublicAuthorityChanged` 和 `ContentReleaseFollowUp`；前者可触发 URL Truth 增量同步，后者发送缓存失效/广播。两条路径均未直接触发 IndexNow。仓库 `SEO_INTEL_INDEXNOW_ENABLED`、`SEO_INTEL_INDEXNOW_LIVE_API_ENABLED`、Search Channel Queue 写入和 live submission 默认关闭，生产环境实际开关值未取得。因此现有生产链路的缺口是：页面验收通过后的合格 URL 集合如何进入可执行 IndexNow 提交，以及相应接受/失败回执如何与发布证据绑定；不能仅凭命令和执行器存在认定闭环已生效。
- Bing IndexNow 页面当前显示 `Get Started` 入门页，未给出可核验的提交列表。当前生产 IndexNow key/keyLocation、具体新内容发布触发、最近提交及失败日志未取得；不能把页面入门状态等同于从未提交。历史 `accepted` 只表示 provider 收到更新信号，不是已抓取、已索引或排名改善。现阶段没有依据重提交全站 URL。
- Bing Sitemaps 页面显示 `0 rows`；GSC 导入向导也显示 0 个可导入 sitemap。这只说明站长工具当前没有记录，线上公开 sitemap 仍正常返回。未在此扫描中提交 sitemap。

## 4. 已有赢家 Top 20

**HOLD：没有 Bing Webmaster Tools Web 搜索页面×查询证据，不能选出或编造 20 页。**解锁后按页面×查询×国家/地区×设备列出曝光、点击、CTR、排名及最小动作；先选有点击且排名 4–10 并有测评开始的页面，再选排名 11–20 且意图匹配的页面，最后检查内容完整但发现/索引异常的页面。低基数点击不以夸张增长率判成功。

## 5. 中文职业页试点

当前可核实的公开可索引集合是 579 个有正文的中文职业详情 URL，已与公开目录全部 1,043 个成员和 sitemap 逐行交叉核对；`accountants-and-auditors` 和 `actors` 两页由 Bing URL Inspection 证实已索引；`web-developers` 和 `zoologists-and-wildlife-biologists` 两页的旧索引快照仍为 `noindex`，实时测试已恢复可索引，需观察后续抓取。其余页面的 Bing 全量发现/索引/曝光状态仍未知。目前只有少量全网关键词需求和中文 SERP 样本，尚不足以指定 10–20 个站点试点页或扩量。试点逐页核对职责、入行、技能、薪资证据与适用地区、职业区别、内容来源和相关内链；不为 Bing 另建职业页。

Bing Keyword Research 提供了**全网查询需求**线索（2026-06-24 至 2026-09-21，China 筛选）：`会计师`约 1.7K 关键词曝光，全球约 1.8K；`前端工程师`312，全球 341。中文 SERP 样本中，前者由百科、会计机构/考试站点及问答站占据前列，后者由百科、问答、招聘与岗位说明页竞争。`网页开发人员`精确词没有可用趋势量；更宽的 `网页开发`全球 437。上述数据不是 fermatmind.com 的站点曝光或点击，不足以直接把 `accountants-and-auditors`、`web-developers` 判为赢家；仅将其列入后续逐页试点候选，待 Bing Web 页面×查询数据、地域适用性和正文来源复核。

## 6. 每周复核与文章选题

每周在同一完整 7 天窗口复核 Bing Web 点击、曝光、查询、已索引/抓取问题和 Bing 来源测评开始/完成，保留相同前一窗口作为比较；AI 引用单列。每日现有文章选题在同一候选池同时核对 Google 与 Bing 机会，不增加日更数量。Bing 搜索表现处理完成、漏斗入口页与会话口径补齐前，周报须标注缺失，不能以百度统计引荐数代替 Bing Web 点击。

## 本次证据边界与下一验收

证据：2026-09-24 已登录 Bing/百度统计/GA4 页面观察；线上公开 `robots.txt`、`sitemap.xml`、页面与验证文件 HTTP 只读请求；仓库 manifest、IndexNow 实现和当前 release 脚本。Bing 站点验证已成功；待报告生成后补齐两个完整 28 天的查询/页面导出、核心 URL Inspection 和抓取问题，再形成 Top 20 与职业 10–20 页试点。Bing 站长工具页面明确提示报告最多 48 小时处理。[Bing 官方验证说明](https://www2.bing.com/webmasters/help/add-and-verify-site-12184f8b)解释站点导入/验证；[IndexNow 官方说明](https://www.indexnow.org/faq)明确提交不保证收录。

已在 Codex 现有任务建立每周一 09:00（Asia/Shanghai）的只读 Bing 复核 heartbeat；站点状态无实质变化时静默。
