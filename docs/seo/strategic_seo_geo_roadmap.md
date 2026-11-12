# FermatMind 全域 SEO & GEO 战略差距分析与实施路线图

审计日期：2026-11-12（北京时间）。代码基线：fap-api `ee4bf8915`、fap-web `74d59e652`，均为本轮 fetch 后的 `origin/main`。线上独立取样，不以 Git HEAD 代替线上版本。范围：前后端公开路由、内容权威、导航/发现入口、测评与漏斗契约、原始 HTML、公开搜索和竞品；未运行生产写入、业务发布或搜索提交。正文中「实测」指本轮公开读取或可复算的仓库证据；「判断」是待验证的解释；「未知」不计作零。

## 战略取舍

优先抢占有直接产品闭环的**免费霍兰德测评**，用**职业测试选型页**处理泛需求，用**职业库**承接查职责和比较岗位的人。MBTI 引擎提供工作偏好观察，RIASEC 提供实测兴趣，职业库提供可回查的职业任务。三个信号在用户决策流程中互补，不能互换，也不构成录用、胜任力或关系结果预测。首选品牌的理由应当是「能免费得到清楚的兴趣结果，并能据此检查真实工作任务」，而非「更准」或「匹配率最高」。

「第一」是分市场、引擎、设备和查询的目标：先记录中国大陆简中桌面/移动非个性化结果，并将 Google 的可观察简中市场单列。逐词追踪有效自然结果第一位占比、目标页归属、非品牌点击、测评完成和报告解锁；不把平均排名 1.0、一次截图或 AI 首个链接当作全网第一。「中文职业搜索」同时用原词和「职业查询／职业库／职业职责／岗位职责」做意图簇验证，不预设原词是大词。

## 1. 四大母词与现有权重流向

| 母词 | 主承接（建议） | 线上原始 HTML 实测 | P0 最小改动与验收 |
| --- | --- | --- | --- |
| 霍兰德职业测试 | [中文 RIASEC 测评](https://fermatmind.com/zh/tests/holland-career-interest-test-riasec) | 200、自指、index/follow。Title「霍兰德职业兴趣测试免费版：60/140题与三字代码」；H1「霍兰德职业兴趣测试（免费版）」；description 含两版用时、结果免费与兴趣边界。HTML 已有两版入口和解释。 | 保留 URL/Title 主干；首屏按「免费测评及结果 → 题量/耗时 → 六维与三字代码 → 开始」组织，展示匿名结果样例；验证目标簇点击和开始率。 |
| 职业兴趣测试 | 同上；只有「怎么选」信息意图导向职业测试选型 | 两页均自指且 index。选型页 Title、H1 与测评页重叠；`/zh/tests` Title/H1 也含职业兴趣，但定位全量测评总览。 | 不新建同义着陆页。测评词内链统一指向正式测试页；选型页内链用「职业测试怎么选」；测试总览保留分类入口。先看查询×URL 再定改动幅度。 |
| 职业测试 | [职业测试选型](https://fermatmind.com/zh/career/tests) | 200、自指、index/follow。Title「职业测试与职业兴趣测试：霍兰德、MBTI 怎么选」；H1「职业测试：先做职业兴趣测试」。已有用途对照、入口、代码示例及解释。 | 建议 Title「职业测试怎么选：霍兰德、MBTI 与大五」；H1「职业测试怎么选」。首屏用「探索工作活动／理解做事偏好／观察行为特质」三个任务做选择，明显标出各自结果与限制。 |
| 中文职业搜索 | [中文职业库](https://fermatmind.com/zh/career) | 200、自指、index/follow；Title「全部职业库」，description 偏浏览/筛选；唯一 H1 是品牌口号「测量自己，看见职业，训练未来」。`/zh/career/jobs` 实测 308 到该页。已有目录状态和分页；职业详情有独立身份。 | 建议 Title「中文职业库：查职业职责、入行条件与职业方向」；H1「搜索职业，查看工作职责与入行条件」。搜索框、可点击的职业示例/行业入口和职责样例前置；不开放任意筛选、搜索参数给索引。 |

表中 Title 省略品牌后缀。样本没有依赖 meta keywords；不补关键词堆叠。传统排序与摘要使用页面可见主题、标题、有效内链等信号；不能以本表测量 PageRank。部署前后用同样的页面样本、设备与解析口径核对首屏和主内容，移动端可另做实机可视区域检查；本轮首屏结论基于 HTML 结构，不是像素级实测。

**内链证据。** 数的是响应中真实 `a[href]` 出现次数，含页头/页脚，非唯一站点入链或搜索权重。中文职业库→选型页 0、→测评页 1；全量测试总览→选型页 0、→测评页 2；职业选型→测评页 3；两个职业详情样本（会计与审计人员、金融与投资分析师）各→选型 1、→测评路径 4（其中 3 条带查询参数）。人格目录→选型 0、→测评页 1。带 `entry_surface` 的测评样本 200，canonical 指无参主 URL，但通用搜索样本也发现了带归因参数的入口。判断：改善主内容连接和可抓取 URL 一致性比增加页脚重复链接更有价值。试点保留 CTA 归因，用既有点击事件承载公开入口来源，并评估将索引页的可抓取锚点收敛至干净 URL；先验证旧归因、无 JS 路径和无重复计数。职业库→选型、选型→测评和职业库、测评→解读和真实职责、详情→相关职业/对比各保留明确的场景链接。不要用站点级硬编码精确匹配锚文本。

**Google／百度／Bing 抓取。** 以普通浏览器、Baiduspider、bingbot、Googlebot 的 UA 直接 GET；三个 bot 样本均能在未经 JS 执行的完整 HTML 中取得测评、选型和 INTJ 的 200、H1、canonical、robots、语言 alternate。旧职业库入口对三者均 308；目标职业库以普通 UA 200。Next 配置保留默认 HTML 限制 UA 并显式加入 Googlebot（fap-web `next.config.mjs:153`）；无需据此推断百度/Bing 空壳。脚本解析完整响应，区分实际 link 与序列化数据；没有模拟真实搜索引擎 IP、没有读最终浏览器 DOM，也未取得三端本期正式抓取/索引数据。此阶段 P0 是在百度抓取诊断、Bing URL Inspection、GSC 正式检查中证实问题；从现有聚合爬虫记录按页面族查真实爬虫来源、状态、延迟和缓存命中。若确有缺正文/挑战/超时，再做同内容 SSR、元数据前置、资源可达性或 CDN 修复；禁止按爬虫 UA 提供不同正文。中国大陆节点分别验证首字节、跳转、JS/CSS 和搜索回退，不依据境外 HTTP 样本宣称国内稳定。

公开 [sitemap](https://fermatmind.com/sitemap.xml) XML 实测 7,744 个去重 `loc`：中/英职业目录、选型、霍兰德和 INTJ/INTP 对比均在；职业详情中/英各 1,046 个；旧 MBTI 职业独立页和对比工具均不在。繁中职业资产存在于后端 manifest，抽样时公开 sitemap 没有繁中职业详情，不能推断三语职业页面已具备同等发现资格。保持当前繁中市场与回链策略，单独核定后再扩。职业目录搜索/行业筛选和分页在 `app/(localized)/[locale]/career/page.tsx:169–199` 有既有 noindex/canonical 规则；不牺牲职业详情的真实 `a` 导航和独立发现。参考 [Google JavaScript SEO](https://developers.google.com/search/docs/crawling-indexing/javascript/javascript-seo-basics)、[Bing Webmaster Guidelines](https://www.bing.com/webmasters/help/webmaster-guidelines-30fba23a)、[百度搜索资源平台](https://ziyuan.baidu.com/)；对 URL 筛选策略见 [Google 分面导航说明](https://developers.google.com/crawling/docs/faceted-navigation)。

## 2. 遗留页面和矩阵基线

### 旧 MBTI 职业推荐：先改消费者，再决定逐页 301

- `/zh|en/career/recommendations/mbti/{16 基础型}`：31 次初检加 1 次补查，32 页均 200 和 noindex；中英 INTJ 为 follow，其余此次为 nofollow；繁中 INTJ 另抽到 200/noindex,follow。按类型和后缀扩展的路由/调用点存在；32 仅是这次中英基础型核验范围，不能据此通配所有历史 URL。
- 线上真实残留：`/zh/career/recommendations` 19 个旧路由锚点（含重复）；INTJ-A 和 INTJ-T 各 1 个，INTJ 基础页样本为 0。源码残留在 fap-web 的 `app/(localized)/[locale]/career/recommendations/page.tsx:100`、`personality/[type]/page.tsx:1794–1796`、`topics/[slug]/page.tsx:356`，以及 `lib/mbti/personalityHub.adapter.ts:225,255,446`、`personalityQuickLocate.ts:32`、`personalityWorkbench.ts:99`、`intpContentPack.ts:132+`。分别核条件分支、公共内容/JSON-LD、预览专用路径和 CMS 历史内容；`lib/career/api/` 同名 API 调用不是网页链接，禁止一并重定向。
- 目的地须具有同语言、同类型且等价的公开任务示例。中文 INTJ 已见 `#intj-career-exploration`（也有 `#work_style`）；代码 `personality/[type]/page.tsx:1696–1712` 依赖基础型、投影权限和工作段落，`74d59e652` 增补双语场景字典，不足以证明所有线上页面都出现同等锚点。逐 URL 验证 SSR id、实际任务内容、canonical、状态和已迁移的问答/重要外链上下文。A/T 旧链接以等价内容决定替换到本型基础工作段，先修入口语义；不将繁中悄悄跳到简中，也不把全部旧路径送往目录。
- 同语言等价确认后，用现有可发真实 HTTP 301 的入口（若框架默认给 308，要明确验收与兼容决策）一次跳到对应 `/personality/{type}#实际锚点`；规范页的 canonical 不带 fragment。保留不等价但仍有用户用途的旧页为 noindex，缺失身份按 404，避免软 404、循环和跨语言。改完重点对照 32 条映射、反向入口、sitemap/llms、旧链接流量与目标页转化。永久重定向的确定收益是减少中转和错误目的地；历史 noindex 页可转移的信号未知，须用可用站长数据和外链验证。参考 [Google 永久重定向说明](https://developers.google.com/search/docs/crawling-indexing/301-redirects)。

### 职业对比：工具已存在，公开搜索产品尚未存在

线上 `/zh/career/compare` 200/noindex,follow，自指无参；会计与金融分析参数样本首响应 200、同 canonical、未呈现该对的 SSR 内容，路径版本 404。代码 `career/compare/page.tsx:9–38` 将无效/非试点参数送回比较入口（流式响应不等于收到真正 3xx），只有工业设计师与平面设计师的 `c1,c2` 试点能预装。 `lib/career/compare.ts:8–61` 有 3 个 UI 候选，读取原生职业详情并校验合同、公开正文和职责，组件已有任务、环境、技能/作品和入行资格。缺口是**成对意图的独立正文、公开 URL 身份、可发现入口**；参数并非天然不能排名，此处现有 noindex 是有意的正确边界。

**发布规格。** 保留任意组合工具为 noindex；另建编辑审核白名单的 ISR/SSR 对比页，从同一职业详情读者取事实。稳定 pair ID 由两个 canonical slug 无序组合产生；URL 由白名单反查，禁止靠任意字符串拆 `-vs-`。规范方向在清单冻结（可选择既有流量方向），反序/已知精确别名 301；未知、同 slug、不可发布成员不生成公开页；旧参数仅对已上线的精确对可考虑一次 301 并保留原始工具行为，其余保持工具语义。资料暂时读取失败时不输出空壳 200；使用同版本可读缓存或临时失败策略。locale 只在可比内容独立合格且互链后才加 hreflang；不跨语 canonical。投影到同一 URL Truth/sitemap/llms，去重已知反向、参数、别名、预览和 noindex。测试正反序、未知/失效、分页和缓存、SSR 正文、来源失效和语言互链。

**配对资格。** 每对至少有一个有证据的真实选择问题、双方各两条可追溯任务、至少两项可解释的决策差异和一个同样本试做；区分美国 O*NET 职业背景和中国本地资格/招聘，允许未知，不补造。共享 O*NET 代码不合并独立职业；若同代码又拿不出清晰范围和真实任务差异，不发布该对。相同模板只做容器，发布前检查证据/正文指纹近似重复与人工答案差异；组合关系、同义词与职业身份不可混用。优先设计试点，其次会计/财务、分析/研究等候选；需从查询、站内搜索和对比使用数据验证需求，不假设会计样本已进入试点。按题域分层选 8–12 对，首发 +8–12 简中 URL，双语均达标 +16–24，后续通过实验再到累计 30–50 对。743 若最终核实，数学上有 275,653 个无序不同职业对；只是上限，不能作为页面计划。

### 人格：扩充已有跨型，隔离配对意图

后端 `backend/content_assets/personality_public/current/manifest.json:7–31`：每 locale 16 基础页、32 A/T、16 A/T 对比、7 跨型、1 MBTI hub，合计 72 个 MBTI 身份 × 3。7 个跨型为 ENFP/ENTP、ENTJ/INTJ、ESTJ/ENTJ、INFJ/INFP、INTJ/INTP、ISFP/INFP、ISTJ/ISFJ。前端复用 `lib/mbti/personalityComparison.ts:56–80` 与 `personality/[type]/page.tsx:594,671`；线上抽样 INTJ/INTP 已是 200、可索引并在 sitemap。优先改已有页，不能重复生产它。

线上 INTJ、INTJ-A、INTJ-T、INTJ A/T 对比均为自指 index/follow，Title/H1 已分别讲四组偏好、自信后缀、复核行动、同型后缀差异。共同出现 INTJ、计划/反馈是内容交叉，**未证明关键词蚕食**。用近 28/90 天查询×落地页×设备/市场看主 URL 份额、周间互换、CTR 和点击；检查「INTJ 人格」「INTJ-A 意思」「INTJ-T 意思」「INTJ-A 和 INTJ-T 区别」「INTJ 和 INTP 区别」分别是否由预期页面承接。报告 CTR 时分位置带，低样本标未知。先调整重复标题、首段、FAQ 和链接锚文；只有目的相同且缺独立价值的页面才评估合并，不把全部 A/T canonical 到基础型。A/T 是后缀解释，不混作四字母 MBTI 的第五个官方轴。

跨型总上限 120 对/locale，现有 7，理论新增 113；首轮新增 4–6 个高需求简中 URL，围绕一项区别及可复盘的共同场景。关系配对 16×16 的 256 个方向格只有 136 个无序组合（含 16 个同型）；首轮新增 4–6 简中 URL，独立 `/personality/relationships/{pair}` 候选，关系页讲如何沟通、分歧修复与反例；比较页讲概念区分和行为识别。不做方向镜像、默认同型全铺、不提供最佳配对或成功率。若同一对的意图/正文无法区分，先在现有对比页加关系小节，独立页增量可为零。人格双试点总 +8–12 简中 URL，英/繁中仅在同等证据与本地需求验证后增发。

## 3. 竞品：国际产品机制与国内真实页面

样本以 2026-11-12 可公开读取的页面为准。Schema 是原始 HTML 中成功解析的 JSON-LD 顶层类型；没有发现不代表没有微数据、其他路由或渲染后标记。未完成竞品整份问卷、登录、购买或私有报告验收；以下转化路径描述的是公开入口及明示条件。

| 国际竞品与原页 | 矩阵、内容、转化和 Schema 实测 | 费马可执行差距 |
| --- | --- | --- |
| Truity [TypeFinder for Career Planning](https://www.truity.com/test/type-finder-careers)、[人格找职业](https://www.truity.com/search-careers/personality-type)、[关系选择器](https://www.truity.com/blog/page/personality-type-interactions-compatibility)、[INTJ/INTP 配对](https://www.truity.com/blog/type-relationship-advisor/INTJ/INTP) | 人格与兴趣共同进入测评；另设按类型直接浏览；配对入口支持选型，单对页讲沟通及日常摩擦并引到测评。未枚举全部配对或核实权重。TypeFinder 样本未解析到 JSON-LD。 | 保留「先测兴趣」与「直接查职业」双入口。配对靠对话场景和可试行为形成价值；不用对方样本的满意度百分比推断个人或跨市场结局。 |
| 16Personalities [理论](https://www.16personalities.com/articles/our-theory)、[INTJ 关系](https://www.16personalities.com/intj-relationships-dating)、[INTJ Career Suite](https://www.16personalities.com/premium/career-suite/intj) | 基础类型延展到关系、职业等场景；按类型售卖 Career Suite，把免费类型阅读导向具体工具和内容。关系样本 JSON-LD 为 WebSite、Organization、BreadcrumbList、Article。未证实任意两型的配对矩阵。 | 学习「一个类型身份 → 多个明确阅读任务 → 与任务相关的付费承诺」；费马用任务验证和比较，不将 A/T 体系当官方 MBTI 证据。 |
| CareerExplorer [软件工程师](https://www.careerexplorer.com/careers/software-engineer/)、[入行训练](https://www.careerexplorer.com/careers/software-engineer/education-and-training-requirements/)、[测评](https://www.careerexplorer.com/career-test/) | 职业 hub 挂职责、教育训练、工资、市场、环境、满意度及相近职业；软件工程师 vs 软件开发者作为正文小节。入行页按教育、实习、就业、证书分步骤。详情首屏直接引导免费测评；测评说明兴趣、环境、经历和人格。职业样本 JSON-LD 有 Article。未确认所有职业存在独立 A-vs-B 路由。 | 先把 Top 职业的「任务 → 入行 → 可验证下一步」做深，只有明确比较需求才独立成页。不要复制演示匹配率或把美国劳动力数据本地化成中国数字。 |
| Zippia [软件工程师](https://www.zippia.com/software-engineer-jobs/)、[入行路径](https://www.zippia.com/software-engineer-jobs/how-to-become-a-software-engineer/)、[INTJ 职业指南](https://www.zippia.com/advice/best-careers-for-intj-personality/) | 本轮职业原页及子页遭人机校验，Schema、最新技能/薪资模块及真实转化路径未知。URL 证实有职业主题与入行子路由的样本；仓库文献审计 [§1](../research/mbti_riasec_academic_crosswalk.md#1-文献和竞品审计) 曾读到类型职业清单、理由、薪资及岗位入口，为历史佐证。无全站规模或配对声明。 | 将学历、技能、职位去向拆为有本地来源的决策问题；费马先服务职业探索，真实招聘合作如将来有授权再另做。 |

### 百度和 Bing 榜单的证据限制

| 精确母词 | 百度大陆入口 | Bing zh-CN 入口（未登录桌面） | 同意图替代样本（无排名含义） |
| --- | --- | --- | --- |
| 霍兰德职业测试 | 302 到验证码；前五未知 | `cn.bing.com` 301 至全局首页；`www.bing.com/search` 200，但有机结果返回不相干地名，拒作榜单 | 壹心理、分秒医生、明镜心理 |
| 职业兴趣测试 | 同上 | 200 但结果主题是 DeepSeek，拒作榜单 | 壹心理、如镜、易读心理 |
| 职业测试 | 同上 | 200 但结果与职业无关，拒作榜单 | 新精英、分秒医生、学职平台 |
| 中文职业搜索 | 同上 | 200 但结果主题是 Windows 网络共享，拒作榜单 | 学职平台、职友集、智联 |

用这两个引擎的查询结果页逐词读取，Bing 按 `li.b_algo > … h2/a` 提取并解码跳转，再做相关性检查；正确的 SERP title 未保证结果对应查询。不能以通用网页搜索的顺序冒充百度或 Bing 实时前五。上述异常也不能推断这些词没有竞争。后续基线以正常大陆访问环境手工记录 4 词 × 2 引擎 × 桌面/移动 × 至少 3 个日期；逐条记录自然位次、广告/AI 模块、最终 URL、首屏答案、Schema 和 CTA，SERP 校验失败时保留 Unknown。公开市场排名样本与站长工具本站表现分别记账。GSC Wizard 读取资源返回 `INVALID_ARGUMENT`；没有本期查询×URL 或关键词量/KD。历史仓库 [Bing 增长基线](../../backend/docs/seo/bing-growth-baseline-2026-09-24.md) 的 10-01/02 仅两天：霍兰德职业测试 28 曝光/0 点击/平均排名 4.64；中文 RIASEC 页面 451 曝光/29 点击；不能据此声明当前排名。

| 国内同意图原页 | 标题布局、正文厚度与转化 | 费马破局点 |
| --- | --- | --- |
| [壹心理活动页](https://www.xinli001.com/activity/397) | 页题覆盖霍兰德免费，正文是职业兴趣测试集合；192 题 SDS 描述和免费 CTA 旁接多款动机/倾向测试，首屏可快速择测；未核登录后报告。 | 保持单一测评主意图，用题量、耗时、结果样例和真实职责链接提高承诺清晰度。 |
| [分秒医生标准入口](https://fenmiao.cn/test/hld) / [试用页](https://fenmiao.cn/test/hld-trial) | H1 集中 HLD/在线测评；标准入口并列试用、标准、专业和问答；试用页原文显示答题与付费解锁，且两个页面显示的试用题数不一致。 | 测前把「结果免费」讲清并与实际履约一致；深度付费明确是可选产品，不借「免费」设中途阻塞。 |
| [新精英职业测评](https://m.xjy.cn/evaluation.html) | 测评目录按生涯/职业素养/性格等分类，首批卡片突出霍兰德与 MBTI、限时免费及自报人数；咨询和课程进入全站导航。 | 用工具适用边界和结果样例帮助选择；人数只能引用本站可验证口径，不作虚假社证。 |
| [学信网学职平台](https://xz.chsi.com.cn/occupation/index.action) | 职业探索入口含行业、搜索、工作任务摘要；收藏和按专业的推荐有登录入口。学生场景与机构信任较强。 | 做更容易引用的逐职业任务与入行条件，明确职业事实来源；支持从兴趣结果回到匿名职业浏览。 |
| [职友集软件工程师](https://www.jobui.com/salary/quanguo-ruanjiangongchengshi/) / [智联具体职位](https://www.zhaopin.com/jobdetail/CCL1382511140J40944495415.htm) | 职友集覆盖职位名、工资、样本时间/说明和岗位入口；智联用职位名、地点、学历、岗位要求导向收藏、相似职位、沟通及投递。统计与单条招聘是两种证据。 | 做中文职责和决策对比，标明本地数据地域、期间和样本；不将某个招聘职位的要求写成整个职业统一门槛。未核实猎聘、测测占据本轮榜单位次。 |

## 4. GEO：已有入口，缺的是可独立引用的职业证据

**线上机器人规则。** [robots.txt](https://fermatmind.com/robots.txt) 200，只有 `User-Agent: *` / `Allow: /` 和 sitemap 声明，符合 fap-web `app/robots.ts:4–22`；因此以下 crawler 在 robots 层没有限制，未证明它们实际通过边缘防护、抓取或被引用。训练选择与搜索出现分别决定。

| 代理 | 用途与建议 |
| --- | --- |
| `OAI-SearchBot` / `GPTBot` / `ChatGPT-User` | [OpenAI 爬虫说明](https://developers.openai.com/api/docs/bots)：分别是搜索、潜在训练和用户触发读取。保留公开内容的搜索可达性；如需退出训练，单独定义 GPTBot，避免误伤搜索；用户触发不等同自主爬取。 |
| Googlebot / `Google-Extended` | [Google 爬虫说明](https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers#google-extended)：Extended 是产品控制 token，不是独立 HTTP UA，也不控制 Google 搜索收录或排序；其适用的 Gemini 训练/grounding 用途另评估。Google AI 搜索用普通搜索资格、可展示摘要及内容。 |
| `PerplexityBot` / `Perplexity-User` | [Perplexity 爬虫说明](https://docs.perplexity.ai/docs/resources/perplexity-crawlers)：前者用于搜索，后者是用户触发读取；核实际访问和来源，勿用 UA 自报当身份。 |
| `ClaudeBot` / `Claude-SearchBot` / `Claude-User` | [Anthropic 说明](https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler)：训练、搜索、用户读取分开。训练政策如需调整，独立处理，不连带挡搜索。 |

保护结果、报告、订单和账号要靠鉴权与明确的公开 URL 资格；robots 不是访问控制。保持已允许的正式爬虫访问，先查已验证爬虫的聚合日志，证实有阻断再作公开路径级修正，拒绝仅凭伪造 UA 放宽访问。对影响搜索摘要与引用的 robots meta/HTTP 头做一致性验收。Google 明确说明 [AI 功能没有专用 Schema 或机器可读文本要求](https://developers.google.com/search/docs/appearance/ai-features)。

**`llms.txt` 已上线。** [简版](https://fermatmind.com/llms.txt) 200，实测约 49.7 万字符、约 6,491 个站内去重链接；[完整版](https://fermatmind.com/llms-full.txt) 首次读取超时，另一次完成约 1.97 MB，约 6,527 个站内去重链接，不能据两次判断可用性趋势。简版有霍兰德、选型、职业目录、中英职业详情与人格条目，前部却先出现娱乐测验；完整版的抽样文本没有选型 URL。规则位于 fap-web `app/llms.txt/route.ts:213+`、`app/llms-full.txt/route.ts`。P1 先在现有简版前部增加简明的职业方法、测试、示范职业和精选对比栏目，让方法页成为直接入口；保留既有目录消费者和完整发现契约，实际影响评估后再精简长清单。自动比对摘要与 HTML、索引资格与语言、参数和别名排除、响应体积/时延及同一版本 LKG。遵循 [llms.txt 提案](https://llmstxt.org/) 的可读组织思路；不当作索引协议或引用保证。[Google 生成式搜索指南](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide) 明确其搜索不使用 llms.txt 作为专门信号，优先投资可索引的独特 HTML 内容。

**公开方法页建议。** 在既有研究与方法内容体系新增一个稳定的中英页面，例如 `/zh/research/career-evidence-methodology`（实际 URL 由 CMS/URL Truth 核定）；链接到 RIASEC 测评及信效度、职业库、具体职业的证据区块与数据版本。首段用普通话说明三种层级：

1. **可核查职业事实。** 按 slug 说明 O*NET-SOC 映射、版本、Core Task ID、原表/来源链接、编辑翻译和适用范围。标示 USDOL/ETA、CC BY 4.0、修改说明、O*NET 是商标、无官方背书；确认是适用 [O*NET 31.0 数据库许可](https://www.onetcenter.org/database.html) 的文件。区分美国职业描述与中国资格或招聘事实。公开覆盖统计必须能复算并说明排除项、重复代码、暂缓状态与日期。
2. **费马职业兴趣结果。** 按题本分别解释 60/140、六维和前三码，标示近似并列、题本与语言的证据边界，说明与 [O*NET Interest Profiler](https://www.onetcenter.org/dl_files/IP_Manual.pdf) 共享框架但不是同一量表。「准确」需要该题本、该语言和样本证据，不能从任务来源推导。
3. **研究中的 MBTI → 兴趣。** 指定 [文献稿](../research/mbti_riasec_academic_crosswalk.md) 实际是 16×6 离线研究草案（第 3、7–9、64、87–92 行）；不是 743 职业任务公开白皮书。五组 Strong 与连续 MBTI 相关的描述性投影尚未检验职业 Top 10/20，ISTJ/ISTP 轮廓相关 0.948；不开放生产排名，不输出匹配概率。要发布为方法附录，先区分来源、范围、排除、复算、偏差/限制与实测兴趣基线，保留独立样本和外部效度检验。

**覆盖核算。** Career Current manifest（`backend/content_assets/career/current/manifest.json:7–14`）在基线含 1,046 个 slug、3,138 个合格 locale 文件；它只证明文件覆盖和身份。遍历 `backend/database/data/career_onet_crosswalk_review*.json` 的 9 个审查文件，按 slug 去重：667 条 `review_pass` 且有 `official_core_task_ids`；其中 420 条在显式 `onet_version: 31.0` 报告中还含 `task_bindings`。早期审查文件的源 task hash 相同，结构不同，不能把这两个子集和生产公开覆盖互换。迁移 `2026_11_12_041000_confirm_batch_52_career_onet_crosswalks.php:14–26,29–56` 只说明审核/应用机制，不能替代逐条可引用数据。**743 是待复核的业务口径，不是本报告可证明的生产事实。** 对外前用现有只读导出做 `manifest slug × 已确认映射 × 31.0 core task id × locale 可见/绑定` 对账，给出未覆盖和源代码复用数量；匹配真实现网样本。1,046 个职业不等于 1,046 个 O*NET 代码；严禁按代码合并。

**Fact Block 交付形态。** SSR 正文在前，标题可稳定链接；字段「简明主张／职业 slug 与地理范围／事实来源与定位（任务 ID 或表）／数据版次与查阅日期／编辑改写说明／不确定处／怎样引用」。例如设计试点由页面列「原型与材料/制造约束」和「视觉版式与交付文件」，各附 [工业设计师 O*NET 27-1021.00](https://www.onetonline.org/link/summary/27-1021.00) 和 [平面设计师 O*NET 27-1024.00](https://www.onetonline.org/link/summary/27-1024.00)；上线前实际绑定 Core Task ID，再明确这只是工作比较，不代表人职适配。方法页提供版本、覆盖与更新记录；让正文、JSON-LD 和机器入口共用同一真实事实，禁止隐藏只给模型看的宣传。

| Schema | 采用条件 |
| --- | --- |
| [Dataset](https://developers.google.com/search/docs/appearance/structured-data/dataset) | 发布真实可复用的公开数据后才标。单独标数据范围、版本、来源、许可、修改、变量、覆盖和稳定可读 CSV/JSON 分发 URL；论文式说明本身用 Article/WebPage，不能假造下载或把私有数据写入。 |
| [SoftwareApplication](https://developers.google.com/search/docs/appearance/structured-data/software-app) | 霍兰德线上样本已有 SoftwareApplication 和 FAQPage。校验名称、免费功能边界、链接、应用类型及真实报价；不捏造评分/评价以追求富结果。 |
| [FAQPage](https://schema.org/FAQPage) | 只标可见真实问答，优先改善直接答案和引用。Google 已于 2026-05-07 停止展示 FAQ 富结果，随后移除文档；不以其作增长 KPI。[官方更新](https://developers.google.com/search/updates) |
| [ItemList](https://schema.org/ItemList) | 仅表达页面可见顺序与真实规范链接（如精选对比、示例职业）。不把通用列表当 Google 轮播保证，不在对比指南冒充 JobPosting。 |

**无品牌验证方案。** 上线前固定 20 条中文、10 条英文；分免费测评推荐、真实工作职责、兴趣与职业映射/证据、成对选择四组。例「免费做完也能看结果的霍兰德测试有哪些」「哪里能查工业设计和平面设计的真实任务」「RIASEC 结果如何用于查职业，哪些不适用」。每组保留无品牌、无指定来源原文；同模型/搜索开关/市场设定，ChatGPT、Gemini、Perplexity 各每提示词 3 次独立新会话，记录产品/模型/日期、是否启用网页搜索、首推荐/首个可见引用、URL、事实正确性和竞品。分母为可成功取样的合格响应，另记无引用与失败比例；每周定期同协议重测，保留未改页或提示词组作对照。观察「提及率、可见引用率、首推荐率、正确引用率、有效 AI 引荐会话、完成与解锁」，不把提及当引流或把首位来源当推荐。用 [Bing AI Performance](https://www.bing.com/webmasters/help/ai-performance-9f8e7d6c) 可用时补充趋势；其引用数据不是点击或排名。本轮未运行多引擎提示词基线，当前 AI 份额未知。

## 5. 执行顺序、验收与停止条件

以下时间从后续授权实施开始计算。单人按序交付；每项使用现有页面、权威与自动发布链。先测基线，后续 28 天窗口与前一同长度窗口、未改同类页对照；样本稀疏就延长，记录季节性、模型变化和数据延迟。上线验收和自然结果是两个里程碑。

| 优先级与建议顺序 | 可交付任务 | 通过标准与决策 |
| --- | --- | --- |
| **P0 · 第 1 周** | 固定四词/意图簇和主 URL。读近 28/90 天 Google、百度、Bing 查询×页，补齐大陆 SERP 与真实爬虫诊断，建落地页→测评完成基线。 | 证据能区分索引、排名、CTR 和转化；没有数据时明确空缺。只有实际访问、渲染或边缘故障才改抓取配置。 |
| **P0 · 第 1–2 周** | 三个主承接页改可见标题/首屏及主内容内链。同步清理可替换的归因参数链接；验证现有 RIASEC 60/140 流程。 | 每个目标页 200、唯一自指且可索引；在现有有效 locale 保持互链；普通和 bot 样本 H1、任务内容、入口相同；后续看查询簇归属与测评完成，不能以站内链接次数作为业务结果。 |
| **P0 · 第 2 周** | 先换职业推荐目录/人格/专题消费者；确认工作段后发布首批 1–3 类型的中英精确 301，再完成合格的 32 页映射。 | 一跳、同 locale、有效锚点、目标自指 200、旧路由不进发现入口，无真实用户用途丢失。无法等价的页继续维持既有资格。 |
| **P1 · 第 3–4 周** | 公开方法页中英 2 页、证据 Fact Block、已有 llms 首部改版。版本覆盖可复算后再决定是否公开下载；先跑无品牌基线。 | 方法事实和来源可追溯，HTML/JSON-LD/llms 一致；站内至少由三类相关页面连入；无品牌答案引用正确性提高或可定位未引用原因，不以发布等同增长。 |
| **P1 · 第 4–8 周** | 职业 8–12 对简中先发；现有 INTJ/INTP 可修；跨型与关系各新增 4–6 页。 | 首批每对通过证据、去重和 SSR 验收。至少一个完整数据窗口观察首次索引及正确 canonical、目标词归属、有机点击和从对比到下一步。若大量重复归并、异常无曝光、读者无进一步操作或破坏现有页，停扩并查需求/发现/正文。 |
| **P2 · 第 7–12 周** | 做深 12–20 个已存在的高需求职业页（优先更新原 URL），用 6–10 篇有独立信息意图的博客补齐问题，优化一个匿名到付费漏斗。 | 职业页有人能试的下一步，跨页浏览和测评完成可计量；深度报告的购买、授权和阅读逐项验证，不用支付按钮点击冒充收入。 |

**职业深度。** 先以查询曝光、站内搜索未满足率、职业详情 CTA、用户决策难度和证据就绪度排序。优先候选：工业/平面设计、财务/审计/分析、软件/数据、教育等可复核职业，实际名单以基线为准。每页写清工作产物、每日任务、入行最低条件与常见路径、可展示技能、初中高级职责变化、一项低成本试做、相邻职业；将行业代理、本地岗位样本与官方资质、薪资样本的地域和期间分别标出。先补决策内容，搜索意图有独立需求才拆子页。避免大批新标题套同一正文；遵照 [Google 规模内容政策](https://developers.google.com/search/docs/essentials/spam-policies#scaled-content)。

**博客内链。** 先复用已有「职业迷茫做哪种测试」「MBTI 和霍兰德结果不一致怎么办」等页面。以解读前三码、兴趣并列、专业/转行验证、行业任务和相近职业选择为信息题；正文按意图放「查任务／做测试／对比」一至两个主路径，并回链方法页。已排名页与候选题重合时更新现页；避免同一答案换标题、标签和大面积模板页脚交叉链接。

**商业漏斗。** 面向搜索/AI 入站，同一匿名会话或获准的测评标识串联：落地 → 详情/对比/CTA → 创建真实 attempt → 提交成功 → 可读免费结果 → 合适时展示可购买的深度报告预览 → 已确认支付 → 授权 → 报告可读。读 [既有漏斗词表](../../backend/docs/seo/analytics-funnel-event-taxonomy-01.md) 和支付/解锁读模型，以 `test_start`、`test_submit`、`payment_success`、`report_unlock`、`report_ready` 的事实源去重；按渠道、locale、落地页族、设备和题本分层，并分别保留 Bing organic/referral 与 AI 引荐。只在读回确切的 SKU、报告范围、购买/退款、授权和语言可用性后做报告 CTA；本轮未验证 RIASEC 的付费 SKU，不能把它写作现有权益。免费结果保持可见，付费价值可用明确示例说明多职业比较、具体任务验证或可交付行动计划，避免重复卖免费分数。先做职业详情/对比 CTA 和测试首屏的一项对照实验，再验证免费结果上的报告预览。主要指标：每百合格入站完成数、结果→确认付费率、每百入站授权可读数；护栏：完成率、报告交付失败、退款/投诉、页面响应及归因重复。对无同意数据仅用合规聚合；不把答案、私有结果或 entitlement 放进 URL、日志或发现入口。

**验收口径。** 拟定业务目标与可观测分母：① 四词自然位次按固定地区/设备/引擎分别报 Top 1 频次和非品牌点击；② URL 归属按每个簇目标页获得的有效曝光/点击份额报，参数规范化后对照；③ AI 分开首推荐、引用、事实正确和有效引荐；④ 同源转化按聚合 cohort 计量，注明样本量和区间。发布只通过仓库现有受影响检查和同 SHA 验收；仅在搜索面真实变化且符合仓库约束时提交允许的 URL。任何阶段都不把 sitemap 成员或 `site:` 查询当作正式收录。当前前五榜单、实时归属/蚕食、真实爬虫和生产证据覆盖、AI 首选率均待补基线；路线图没有把未知当成已实现指标。
