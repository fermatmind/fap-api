# PROJECT_OVERVIEW — 费马测试（FAP）总纲导航
> 目标：把 **阶段0 → 阶段1 → 阶段2 → 阶段2.5 → 工业级方案 → PR0–PR15 / 任务包1–11** 串成一条可持续运营的“测评产品工厂”全景图  
> 仓库：`fap-api`（后端/中台/内容与数据） + `fap-web`（前端/SEO/交互/增长入口）

---

## 0. 这套系统最终是什么
一个可持续运营的测评产品工厂：
- **fap-web（前端）**：获客（SEO/GEO）、落地页、答题体验、分享入口
- **fap-api（后端）**：内容资产、题库与评分、动态报告、事件数据、运营复盘、AI洞察、外部数据接入、长期记忆与主动 Agent
- **运维与门禁**：可上线、可回滚、可验收、可追责（健康检查/自检/审计/仪表盘）

---

## 1. 目录树（按“控制/运行/内容/数据/工具”五大块组织）

### 1.1 fap-api（后端仓库）
```text
fap-api/
├─ .github/
│  └─ workflows/
│     ├─ ci_verify_mbti.yml
│     ├─ selfcheck.yml
│     ├─ deploy-production.yml
│     ├─ deploy-staging.yml
│     └─ publish-content.yml
├─ backend/
│  ├─ app/
│  │  ├─ Http/
│  │  │  ├─ Controllers/
│  │  │  │  ├─ API/
│  │  │  │  │  └─ V0_2/
│  │  │  │  │     ├─ AgentController.php
│  │  │  │  │     ├─ MemoryController.php
│  │  │  │  │     └─ Admin/
│  │  │  │  │        └─ AdminAgentController.php
│  │  │  │  └─ HealthzController.php
│  │  │  └─ Middleware/
│  │  │     └─ FmTokenAuth.php
│  │  ├─ Jobs/
│  │  │  ├─ AgentTickJob.php
│  │  │  └─ SendAgentMessageJob.php
│  │  ├─ Services/
│  │  │  ├─ Content/              # 内容包读取/解析/缓存/索引（PR0–PR5 主战场）
│  │  │  ├─ Report/               # 动态报告引擎 v1.2（Stage 2 主战场）
│  │  │  ├─ Analytics/
│  │  │  │  └─ EventRecorder.php  # 事件落库（漏斗/周报/仪表盘）
│  │  │  ├─ AI/
│  │  │  │  └─ Embeddings/
│  │  │  │     └─ EmbeddingClient.php
│  │  │  ├─ VectorStore/
│  │  │  │  ├─ VectorStoreInterface.php
│  │  │  │  ├─ VectorStoreManager.php
│  │  │  │  └─ Drivers/
│  │  │  │     ├─ QdrantDriver.php
│  │  │  │     └─ MySqlFallbackDriver.php
│  │  │  ├─ Memory/
│  │  │  │  ├─ MemoryService.php
│  │  │  │  ├─ MemoryProposer.php
│  │  │  │  ├─ MemoryRetriever.php
│  │  │  │  ├─ MemoryCompressor.php
│  │  │  │  └─ MemoryRedactor.php
│  │  │  └─ Agent/
│  │  │     ├─ AgentOrchestrator.php
│  │  │     ├─ Explainers/WhyThisMessageBuilder.php
│  │  │     ├─ Notifiers/InAppNotifier.php
│  │  │     ├─ Policies/
│  │  │     │  ├─ ConsentPolicy.php
│  │  │     │  ├─ RiskPolicy.php
│  │  │     │  └─ ThrottlePolicy.php
│  │  │     └─ Triggers/
│  │  │        ├─ LowMoodStreakTrigger.php
│  │  │        ├─ NoActivityTrigger.php
│  │  │        └─ SleepVolatilityTrigger.php
│  │  └─ Support/
│  │     └─ ... (CacheKeys 等)
│  ├─ config/
│  │  ├─ agent.php
│  │  ├─ memory.php
│  │  ├─ vectorstore.php
│  │  └─ ... (content_packs/cache/queue 等)
│  ├─ database/
│  │  └─ migrations/
│  │     ├─ 2026_01_28_140000_create_user_agent_settings.php
│  │     ├─ 2026_01_28_140100_create_memories_table.php
│  │     ├─ 2026_01_28_140200_create_embeddings_index.php
│  │     ├─ 2026_01_28_140300_create_embeddings_table.php
│  │     ├─ 2026_01_28_140400_create_agent_triggers.php
│  │     ├─ 2026_01_28_140500_create_agent_decisions.php
│  │     ├─ 2026_01_28_140600_create_agent_messages.php
│  │     └─ 2026_01_28_140700_create_agent_feedback.php
│  ├─ routes/
│  │  ├─ api.php
│  │  └─ web.php
│  └─ scripts/
│     ├─ ci_verify_mbti.sh
│     └─ pr14_verify_agent_memory.sh
├─ content_packages/
│  └─ default/
│     └─ CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/
│        ├─ manifest.json
│        ├─ version.json
│        ├─ questions.json
│        ├─ type_profiles/
│        ├─ axis_dynamics/
│        ├─ layer_profiles/
│        ├─ report_rules/
│        ├─ report_overrides.json
│        ├─ share_templates/
│        ├─ seo_meta.json
│        └─ content_graph/
├─ docs/
│  ├─ 04-ops/
│  │  ├─ agent-ops.md
│  │  ├─ agent-policy.md
│  │  └─ observability.md
│  ├─ 04-analytics/
│  │  └─ agent-metrics.md
│  ├─ ai/
│  │  ├─ agent-policy.md
│  │  └─ memory-rag.md
│  ├─ product/
│  │  └─ user-settings-agent.md
│  └─ verify/
│     ├─ pr14_verify.md
│     └─ pr14_recon.md
├─ tools/
│  └─ metabase/
│     └─ views/
│        ├─ v_agent_cost_daily.sql
│        ├─ v_agent_message_feedback.sql
│        ├─ v_agent_message_funnel.sql
│        ├─ v_agent_safety_flags.sql
│        ├─ v_agent_trigger_rate.sql
│        └─ v_memory_growth.sql
└─ deploy.php

1.2 fap-web（前端仓库）

fap-web/
├─ app/
│  ├─ tests/
│  │  ├─ page.tsx                 # /tests 列表
│  │  ├─ [slug]/page.tsx          # /tests/[slug] 落地页 + CTA
│  │  └─ [slug]/take/page.tsx     # 答题页（QuizEngine）
│  ├─ sitemap.ts                  # SEO sitemap
│  ├─ robots.ts                   # SEO robots
│  └─ layout.tsx
├─ lib/
│  ├─ content/                    # Velite/ContentLayer 抽象
│  ├─ seo/                        # generateMetadata + JSON-LD
│  └─ analytics/                  # 事件上报（PR6/漏斗）
├─ content/
│  ├─ tests/                      # MDX 落地页内容
│  ├─ types/                      # 16/32 类型内容（占位/扩量）
│  └─ blog/
└─ vercel.json / next.config.js


⸻

2. 全局链路图（文本画法）

2.1 用户增长主链路（SEO → 答题 → 报告 → 分享 → 回流）

[Search / Share Link]
        |
        v
(fap-web) /tests/[slug]  落地页（永久URL + meta + JSON-LD）
        |
        |  CTA: Start
        v
(fap-web) /tests/[slug]/take  QuizEngine（可恢复 + 埋点）
        |
        |  GET questions
        v
(fap-api) /api/v0.2/scales/MBTI/questions
        |
        |  POST attempts/submit
        v
(fap-api) attempts/results/events 写入（事务一致性）
        |
        |  GET result/report
        v
(fap-api) 动态报告引擎 v1.2 组装 Report JSON
        |
        |  share_generate -> share_id
        v
(fap-web) 分享页/卡片（OG/模板） -> share_click 回传
        |
        v
(fap-web) 回流到 /result or /report（带 share_id）
        |
        v
(fap-api) report_view/events 漏斗复盘 + 周报

2.2 内容资产链路（内容包 → 自检门禁 → 发布/回滚 → 缓存失效 → 前端消费）

content_packages（manifest/version/meta/cards/rules/graph）
        |
        |  self-check（严格门禁）
        v
CI / workflows/selfcheck.yml  + backend/scripts/ci_verify_mbti.sh
        |
        |  publish（可选：upload->audit->switch default）
        v
Admin 发布API / 发布审计表 / 回滚记录
        |
        |  cache invalidate（Redis keys / 本机缓存）
        v
线上读取顺序固定：Redis 热点 -> 本机缓存 -> 内容源(local/s3)
        |
        v
前端落地页 meta / 分享模板 / 报告 JSON 全部由内容包驱动

2.3 智能闭环（数据接入 → 洞察 → 记忆 → Agent 主动）

(integrations/webhooks/seeds) 外部数据(睡眠/情绪/步数/屏幕)
        |
        v
ingest_batches + domain tables（幂等/可回放）
        |
        v
insights（证据化 + 预算熔断）
        |
        v
memory propose -> user confirm -> confirmed memory（可删除/可导出）
        |
        v
agent tick -> triggers -> policies -> decisions -> messages -> feedback
        |
        v
metabase views / dashboards（触发率/发送率/负反馈/风险升级/成本）


⸻

3. 阶段地图（阶段0/1/2/2.5 各自解决什么）

3.1 阶段0：生产底座“能上线”
	•	Nginx + PHP-FPM + Laravel + MySQL + Redis 全链路打通
	•	.env 解析稳定，DB 权限从 root 转为专用用户
	•	migrations 跑通，生产缓存链路贯通（config/routes/views cached）
	•	public/storage 可 HTTP 访问
	•	/api/health 真域名硬验收通过

产出：你拥有可长期迭代的生产运行底座。

3.2 阶段1：中台最小骨架“可控、可记账、可复盘”
	•	领域模型口径（Scale/Attempt/Result/Report/ShareAsset）
	•	事件词典口径（漏斗/分享/质量/稳定性）
	•	周复盘模板（8 指标）与固定拉数逻辑

产出：运营与工程第一次用同一套语言协作。

3.3 阶段2：业务闭环“测评→报告→分享→增长”
	•	attempts/results/events 主链路真实落库
	•	报告引擎 v1.2 结构明确：TypeProfile + TraitScaleConfig + AxisDynamics + LayerProfiles + AssemblyPolicy + ContentGraph
	•	前端“UI不变，只渲染稳定 Report JSON”

产出：MBTI 一条最小业务闭环可以持续迭代。

3.4 阶段2.5：外功（SEO/GEO/内容/运营/权威/商业化）
	•	SEO 落地页体系（永久URL + meta + JSON-LD）
	•	分享模板资产（share_templates）
	•	找回体系（lookup + SSOT）
	•	权威体系（常模/白皮书）
	•	内容生产工作流（prompts + 质量门禁）
	•	支付闭环（状态机/回调幂等/对账/风控）

产出：让闭环“能增长、能沉淀、能变现”。

⸻

4. 四平面架构（你工业级方案的核心收口）

4.1 控制平面（repo 根目录）
	•	定义口径/验收/发布/回滚/运行手册
	•	位置：docs/、.github/workflows/、deploy.php、tools/

4.2 运行平面（backend）
	•	Laravel API（路由/控制器/服务/任务/脚本）
	•	位置：backend/app、backend/routes、backend/scripts

4.3 数据平面（MySQL + Redis）
	•	MySQL：attempt/result/events + 发布审计 + insights + integrations + memory + agent
	•	Redis：热点缓存 + 幂等键 + 预算 ledger + queue/session/cache

4.4 内容平面（content_packages）
	•	题库/报告骨架/卡片库/策略/推荐/分享模板/SEO meta/常模
	•	自检门禁保证“可发布可回滚不污染”

⸻

5. 接口与数据：最小“稳定契约”（前后端只认它）

5.1 核心 API（对外）
	•	GET  /api/health（阶段0）
	•	GET  /api/v0.2/healthz（PR8）
	•	GET  /api/v0.2/scales/MBTI
	•	GET  /api/v0.2/scales/MBTI/questions
	•	POST /api/v0.2/attempts（或 start/submit 组合）
	•	GET  /api/v0.2/attempts/{id}/result
	•	GET  /api/v0.2/attempts/{id}/report

5.2 事件（漏斗最小集）
	•	scale_view
	•	test_start
	•	question_answer
	•	test_submit
	•	result_view
	•	report_view
	•	share_generate
	•	share_click

5.3 报告 JSON（前端只渲染）
	•	profile（TypeProfile）
	•	scores（axis percent + side + state）
	•	highlights[]（Top-2 强度轴卡）
	•	borderline_note（最弱轴 <55）
	•	sections.{traits|career|growth|relationships}.cards[]
	•	layers.{role|strategy|identity}
	•	recommended_reads[]

⸻

6. PR0–PR15 位置索引（你目前进化链的“地图”）

说明：PR0–PR8 主要是“内容资产化 + 发布门禁 + 高并发稳定性”；PR9–PR15 主要是“可观测 + 可运营 + 可智能 + 可审计”的工业级能力。

PR0–PR5：内容包单一真相源 + 索引 API + Redis 热点缓存
	•	PR0 内容包定位规则、清理硬编码、脚本默认路径对齐
	•	触点：backend/config/content_packs.php、backend/app/Services/Content*、backend/scripts/*
	•	PR1 version.json + 污染文件清理 + 自检变发布门禁
	•	触点：content_packages/**/version.json、backend/app/Console/Commands/FapSelfCheck.php
	•	PR2 内容源驱动（local/s3）抽象
	•	触点：backend/app/Contracts/ContentSourceDriver.php、backend/app/Services/Content/Drivers/*
	•	PR3 本机缓存（S3 只做源）
	•	触点：backend/app/Services/Content/PackCache.php、backend/config/content_packs.php
	•	PR4 content-packs 索引 API（热更新/灰度必备）
	•	触点：backend/app/Http/Controllers/API/V0_2/*ContentPacks*、backend/routes/api.php
	•	PR5 Redis 热点缓存层（questions/manifest/assets）
	•	触点：backend/app/Support/CacheKeys.php、backend/app/Services/Content/*

PR6–PR8：异步队列 + healthz 收口 + 可运维
	•	PR6 报告生成异步队列化
	•	触点：backend/app/Jobs/*Report*、backend/config/queue.php
	•	PR7 内容发布闭环（发布API + 审计 + 回滚）
	•	触点：backend/app/Http/Controllers/API/V0_2/Admin/*Release*、发布审计表 migrations、deploy.php
	•	PR8 healthz 标准化 + 监控告警入口
	•	触点：backend/app/Http/Controllers/HealthzController.php、docs/04-ops/observability.md

PR9：可视化仪表盘（Grafana + Metabase）
	•	触点：tools/metabase/views/*（漏斗/转化/健康依赖/部署时间线）

PR10：Admin Console / RBAC / 审计
	•	触点：backend/app/Filament/*（若采用）或 backend/app/Http/Middleware/AdminAuth.php + audit_logs 表

PR11：常模/统计快照不漂移（可复现）
	•	触点：attempts 增快照字段、scale_norms_versions、attempt_quality、docs/content/psychometrics.md

PR12：AI 洞察（证据可追溯 + 预算熔断）
	•	触点：CheckAiBudget、BudgetLedger、ai_insights、tools/metabase/views/v_ai_*

PR13：Zero-Input 数据管线（Mock + Webhook + 幂等回放）
	•	触点：Integrations/*、Support/Idempotency/*、ingest_batches、domain tables、seeder

PR14：长期记忆 + Agent（可解释/可控/可审计/可关闭）
	•	触点（已落地的关键文件与表）：
	•	服务：backend/app/Services/Memory/*、backend/app/Services/Agent/*、backend/app/Services/VectorStore/*
	•	作业：backend/app/Jobs/AgentTickJob.php、SendAgentMessageJob.php
	•	控制器：backend/app/Http/Controllers/API/V0_2/MemoryController.php、AgentController.php、AdminAgentController.php
	•	配置：backend/config/agent.php、memory.php、vectorstore.php
	•	迁移：create_user_agent_settings / memories / embeddings_index / embeddings / agent_*
	•	文档：docs/ai/memory-rag.md、docs/ai/agent-policy.md、docs/product/user-settings-agent.md、docs/04-ops/agent-ops.md、docs/04-analytics/agent-metrics.md
	•	Metabase：tools/metabase/views/v_agent_*、v_memory_growth.sql

PR15：Scale Registry + Slug Lookup（v0.3 多量表/SEO 入口）
	•	触点：backend/database/migrations/*scales_registry*、*scale_slugs*、backend/app/Services/Scale/*、backend/app/Http/Controllers/API/V0_3/*、backend/routes/api.php
	•	文档：docs/03-stage3/pr15-scale-registry.md、docs/verify/pr15_verify.md、docs/verify/pr15_recon.md

⸻

7. 任务包1–11 在系统中的位置（阶段2.5 外功映射）

任务包 1–3：SEO/GEO（落地页 + meta + JSON-LD）
	•	fap-web：/tests/[slug] + generateMetadata + JSON-LD
	•	fap-api/content_packages：seo_meta.json（或 pack meta 字段）

任务包 4：share_templates（传播资产）
	•	content_packages：share_templates/*.json
	•	fap-web：分享页/OG/卡片渲染消费这些字段
	•	fap-api：记录 share_generate/share_click 事件

任务包 5：Report Lookup + SSOT（找回/跨端）
	•	数据平面：lookup_tokens / user_identity（手机号主账号）/ 绑定关系
	•	前端：找回入口 + 登录/绑定流程
	•	后端：找回 API + 风控限频 + 审计

任务包 6：动态常模 v1（percentile）
	•	数据平面：norms_table + version + window
	•	报告渲染：展示“超过 X% 的人（N=…，区间=…）”
	•	降级：缺失/过旧不影响主链路

任务包 7：效度反馈 + 周报指标
	•	后端：feedback 表 + 限频去刷 + 版本绑定
	•	周报：按 pack/report_version 聚合输出

任务包 8：ContentGraph（recommended_reads）
	•	content_packages：content_graph/* + rules/mapping
	•	报告 JSON：recommended_reads[]

任务包 9：白皮书/Authority
	•	research：HTML + PDF + Dataset JSON-LD
	•	SEO：权威页可被引用/收录

任务包 10：AI 内容工作流（prompts + 质量门禁）
	•	docs：prompts/quality-gates/baseline-set
	•	发布门禁：自检阻断“虚构统计/禁词/结构不合规”

任务包 11：支付闭环（状态机/回调幂等/对账/风控）
	•	order_state_machine + benefits + payment_events（审计/对账）
	•	webhook 幂等键与可重放

⸻

8. 验收入口（你用来“证明系统还活着/没漂移”的命令）

8.1 本地/CI（内容与MBTI链路）
	•	make selfcheck
	•	cd backend && php artisan fap:self-check --strict-assets --pkg=default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST
	•	cd backend && bash scripts/ci_verify_mbti.sh

8.2 线上（健康收口）
	•	/api/health（阶段0）
	•	/api/v0.2/healthz（PR8）
	•	/api/v0.2/scales/MBTI/questions
	•	/api/v0.2/content-packs

⸻

9. 这份总纲如何使用（仓库导航规则）
	•	你要找“上线/回滚/告警/排障”：看 docs/04-ops/* + deploy.php + workflows
	•	你要找“内容包规范/发布门禁/目录结构”：看 content_packages/* + backend/app/Services/Content/* + FapSelfCheck
	•	你要找“报告与个性化体感”：看 backend/app/Services/Report/* + 内容包 type_profiles/axis_dynamics/layer_profiles/report_rules
	•	你要找“增长复盘与漏斗”：看 EventRecorder + tools/metabase/views/*
	•	你要找“智能闭环（insights/memory/agent）”：看 docs/ai/* + backend/app/Services/{AI,Memory,Agent,VectorStore}/*

⸻
