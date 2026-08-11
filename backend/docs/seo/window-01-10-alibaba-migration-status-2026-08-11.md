# FermatMind 窗口 1–10、IQ 与阿里云迁移完成度复核

Status: evidence-backed checkpoint; execution remains open
Owner: SEO / Growth / Backend / SRE
Evidence cutoff: 2026-08-11 12:20 CST
Initial evidence base: `ecc62a386076e29969d6975a2767f11963bb1690`
Repository `main` at cutoff: `25cbc70f60d2a32901339e7e2469d4ddf196e173`

## 1. 执行结论

这不是一个“十个窗口全部完成”的结项报告。当前事实是：

1. **服务器迁移基本完成**：公开 Web、API/Ops、staging 的运行时已经切到阿里云三节点；腾讯云运行资源已经退役。域名注册、DNSPod 和 ICP 继续由腾讯侧管理属于控制面保留，不是运行时依赖。
2. **Career 恢复未完成**：当前公开面仍是 30 个职业 slug / 60 个双语详情 URL，不是 1046 / 2092。当前能证明的可恢复 authority 是 342 个 slug / 684 个 locale item，不能用历史 1046 观测替代现存 authority。
3. **多个窗口是 code-ready，不是 production-complete**：Measurement、Topic Graph、Articles、Assessment、English SEO 的多项 PR 已合并，但后端候选版本尚未完成生产激活、回填与同窗口读回，因此不能宣称漏斗、CMS、图谱或业务结果已经线上生效。
4. **窗口 8 未形成交付，窗口 10 未开始**：Authority/GEO/Brand 只有前置证据发现；Off-site/Linkable Assets 没有执行输出。
5. **IQ 板块确实没有完成**：现有工作是规划和资产盘点，不是英文内容包、CMS 发布或 SEO 上线。

全站战略仍然成立，但后续执行顺序必须先解决“生产后端代码/数据库版本一致性”和 Career authority，再做依赖 Career 的图谱、English Career、linkable assets 与外链分发。

## 2. 证据口径

本报告使用以下状态，禁止混写：

| 状态 | 含义 |
|---|---|
| `VERIFIED_LIVE` | 本次或同一受控窗口有公开生产读回 |
| `MERGED_NOT_ACTIVE` | 代码已进 `main`，但没有证明生产 active revision 已包含它 |
| `EVIDENCE_COMPLETE` | 审计、台账或内容包完成，不代表 CMS/生产发布 |
| `PARTIAL` | 有可复用交付，但目标仍有明确缺口 |
| `BLOCKED_BY_GATE` | 必须等待 authority、生产激活、数据窗口或明确控制动作 |
| `NOT_STARTED` | 没有可验证执行输出 |
| `UNKNOWN` | 当前证据不足，禁止推断 |

证据优先级：生产公开读回和受保护 workflow receipt > GitHub merged/check 状态 > 仓库文档和生成物 > 窗口消息。历史数字只作为历史观测，不覆盖当前生产事实。

### 2.1 本次扫描边界

- 已复核窗口 1–10、独立 IQ 窗口、“迁移服务器”窗口、相关前后端合并 PR、迁移 receipts、后端控制面文档与有限公开 GET 读回。
- 未在本任务中 SSH 登录三个节点，因此 OS 服务、当前磁盘占用和 Task 10 服务端 apply 结果没有被本次独立重读。
- 未执行生产写、CMS 写、数据库写、cache warm、Search Channel 提交或新部署。
- GSC/GA4 账号内数据仅按各窗口已有导出和报告复核；本任务没有重新导出账号数据。

## 3. 当前生产真相

### 3.1 公开面读回

2026-08-11 的有界公开 GET 读回：

| Surface | 当前结果 | 状态 |
|---|---:|---|
| API `/up` | HTTP 200 | `VERIFIED_LIVE` |
| public flags | HTTP 200 | `VERIFIED_LIVE` |
| Career Jobs EN / ZH | 30 / 30 | `VERIFIED_LIVE` |
| Career Directory EN / ZH | 30 / 30 | `VERIFIED_LIVE` |
| backend sitemap source | 613 URLs | `VERIFIED_LIVE` |
| public sitemap | 613 `<loc>` | `VERIFIED_LIVE` |
| Career detail in sitemap | 60 URLs / 30 slugs | `VERIFIED_LIVE` |
| `llms.txt` Career refs | 60 | `VERIFIED_LIVE` |
| `llms-full.txt` | HTTP 200；120 Career refs，包含 enriched 重复引用 | `VERIFIED_LIVE` |

613 是当前可见总量，不是永久工程常量。它由当前非 Career cohort 与 60 个 Career detail 共同形成；Career 恢复后必须从 authority 重新枚举，禁止把 2645 或其他历史总数硬编码。

### 3.2 后端版本分裂

| 层 | 版本 / 事实 | 解释 |
|---|---|---|
| production active backend | `40020ab7ef269ee56ce597e9f2fd2fbb99e83549` | 当前受控事故证据中的 active revision |
| repository `main` at cutoff | `25cbc70f60d2a32901339e7e2469d4ddf196e173` | 含发布 authority 与 public-DNS guard 修复，但未证明已 active |
| failed candidate deploy | `ecc62a386076e29969d6975a2767f11963bb1690` | migration 已执行，activation 被 public-DNS guard 阻止 |
| guard repair | PR #3645 已 squash merge 为 `25cbc70f60d2a32901339e7e2469d4ddf196e173`；该 control-plane SHA 的 CI/staging 正在运行 | 没有新的 production dispatch；仍需 candidate-bound 部署，不自动授权生产写 |

这意味着数据库 schema 可能已经领先于 active application code。部署恢复前必须重新做 migration status、candidate/active identity、public guard 和 post-activation smoke，不可只看 GitHub `main` 判断生产能力。

## 4. 窗口总表

| 窗口 | 目标 | 当前状态 | 最重要缺口 |
|---|---|---|---|
| 1 | Career 1046 authority / 恢复 / 防复发 | `BLOCKED_BY_GATE` | 仅证实 342 authority、当前公开 30；1046 不存在于已验证 source |
| 2 | 全站 Measurement | `PARTIAL` | 生产激活、28 天 GA4↔backend 对账、Career after-diff、qualified completes 未完成 |
| 3 | Assessment SEO | `PARTIAL` | CMS/生产读回和四个主测试 SERP 实验未启动 |
| 4 | Personality SEO | `PARTIAL` | INTP-A 首个实验未生产发布/观察；新 pSEO 继续冻结 |
| 5 | SEO Articles | `PARTIAL` | Top 10 未导入 CMS，技术手册未发布，90 天处理未到期 |
| 6 | Topic Graph / Internal Links | `PARTIAL` | authority/renderer 已合并，但未导入/激活，2092 post graph 不存在 |
| IQ 补充 | IQ 英文内容与 SEO | `EVIDENCE_COMPLETE` 仅限规划 | 0 个 complete-authoritative；没有 producer/CMS/runtime 交付 |
| 7 | Technical SEO / Performance | `PARTIAL` | 7-PR train 已完成；field CWV、完整窗口和 shrink guard 未完成 |
| 8 | Authority / GEO / Brand | `NOT_STARTED`（仅 preflight） | 无交付文件、提交或 PR；技术手册 v1 未发布 |
| 9 | English SEO | `PARTIAL` | closeout train 完成；RIASEC/Career/28 天实验仍未执行 |
| 10 | Off-site / Linkable Assets | `NOT_STARTED` | 无 baseline、目标表、资产或分发结果 |
| 迁移服务器 | 腾讯运行时→阿里三节点 | `PARTIAL_CLOSEOUT` | 运行时迁移完成；Task 10 apply 未独立复核，另有少量运维尾项 |

## 5. 窗口 1 — Career 1046

### 已完成

- `CAREER-COLD-CACHE-DISCOVERABILITY-GATE-02` 已合并，建立 detail → directory → sitemap 的冷启动顺序和 fail-closed 防线。
- Career Truth 重扫已形成正式 `NO_GO`：当前可证明 authority 为 342 slug / 684 locale item，公开 cohort 为 30 slug / 60 URLs。
- C03 对当前 30 个公开 cohort 完成 cache/discoverability 收敛；这是 current-set 修复，不是 1046 恢复。
- detail indexability enum、部署存储边界和 durable promotion authority 修复已进入后端 `main`。
- 前端 sitemap/llms 稳定性修复已部署，当前公开 Career 60 URL 能被枚举。

### 未完成

- 没有找到 SHA、slug 集合和 locale 数都满足 1046 / 2092 的唯一可恢复 authority artifact。
- 不能执行原 Task 2 的 1046 恢复；腾讯运行资源已退役，历史观测不能充当 authority。
- 24 个 detail index-state mismatch 的代码修复尚未证明生产 active/cache refreshed。
- 全 1046 可见治理文案、locale 污染、claim boundary 和 B18 reconciliation 未完成。
- 恢复前后 GSC/GA4 差分、三窗口稳定性、空 Redis/缺 artifact red-team 尚未封口。

### 决策

- Career C06 link gate 保持关闭。
- 接受当前 30 作为可验证 public cohort，但不得将其表述为 1046 恢复成功。
- 下一步先完成后端安全部署和 current truth readback，再决定是继续寻找历史 authority，还是发起一个新的、受控的 1046 publication program。后者是 publication 变更，不是灾难恢复。

## 6. 窗口 2 — 全站 Measurement

### 已完成

- GSC safe export：12,336 条 query × page × country × device 行，覆盖 current 28d、previous 28d 和 90d；Search Console 不提供可用 language dimension，因此语言为 unsupported/unknown，不得推断。
- 2026-08-07 保存 Page Indexing 快照：969 indexed / 2133 not indexed。
- attempt/order commerce truth、failure cohort、result-ready、continue-exploration、edge-click 与四引擎 read-model 相关前后端 PR 已合并。
- load failure 与 submit failure 已在工程口径中拆分。

### 未完成

- 后端相关代码尚未证明生产 active，相关 migration/backfill/readback 没有同窗口闭环。
- GA4 与 backend attempt/order 的 28 天对账未完成；consent 与生产 network send 未完整验证。
- Career 恢复后差分无从执行。
- `organic qualified assessment completes` 还没有形成生产可用、可对账的正式 KPI。
- 四引擎看板属于工程基础基本就绪，业务数据完整性仍是 `UNKNOWN/PARTIAL`。

### 下一步

后端部署成功后，固定同一 UTC/业务日窗口依次做 migration status、backfill、GA4↔backend reconciliation、四引擎看板读回，再冻结 qualified complete 定义。禁止先看结果再选择窗口。

## 7. 窗口 3 — Assessment SEO

### 已完成

- 六个公开测试的 catalog/query ownership 和核心 SERP/landing 审计已完成。
- Catalog convergence、Measurement M04 remediation、RIASEC CMS bridge 的后端代码已合并。
- IQ/EQ canonical 的 desktop/mobile 8/8 技术审计通过；四个旧非 canonical 路径保持 200 + noindex Not Found，这只是现状记录，不代表最佳 redirect 策略已落地。
- MBTI、RIASEC、Big Five、Enneagram 的 landing 与业务机会已形成 evidence-backed backlog。

### 未完成

- Test hub/category IA、landing 文案与 RIASEC 入口方案尚未通过 CMS/生产读回封口。
- Big Five CTR/result value、Enneagram landing→type/wing、IQ/EQ next-step 尚未形成生产实验。
- 四个主测试受控 SERP 实验启动数为 0。

### 下一步

先激活已合并的 catalog/read-model 能力并读回，再按 MBTI → Big Five → RIASEC → Enneagram 的业务优先级启动单变量、固定 28 天的受控实验。IQ/EQ 维持维护级，不与 L1/L2 抢资源。

## 8. 窗口 4 — Personality SEO

### 已完成

- 357 个公开人格页面与 GSC 合并盘点完成。
- Top 20 高展示低 CTR、MBTI base/variant/comparison、Big Five dimension/high-low/facet、Enneagram type/wing/subtype、legacy alias 与 cannibalization 审计完成。
- 人格→测试/文章/职业的 edge 设计已进入 Topic Graph authority/renderer 工程链。
- INTP-A 首个 SEO title experiment 的 authority 与 promotion package 代码已合并。

### 未完成

- INTP-A 没有生产 CMS promotion、public readback 或固定观察窗口结果。
- 首批 CMS 修订包没有完成线上发布。
- Personality→Career 链接必须继续受 Career C06 gate 约束。

### 决策

所有新增人格组合 pSEO 继续冻结。先完成一个受控 INTP-A 实验的发布、28 天观察和停止/扩展决策，再考虑下一批。

## 9. 窗口 5 — SEO Articles

### 已完成

- 已建立 129 个 locale page / 111 个 discoverable article 的完整表现台账和分类。
- Top 10 refresh package、topic ownership、article→test 审计、article→article/personality/career edge registry、技术方法资产草案与未来 30 天 `10 更新 / 0 批量新增 / 1 证据资产` 计划已完成。
- backend result-ready attribution 和 17 个显式 public article observation 的代码已合并。

### 未完成

- 只读 M04 快照中，112 条 exact mappings 没有 observed click/start/complete，另有 17 条 unknown；后续代码合并不等于生产数据已补齐。
- Top 10 CMS refresh 未导入/发布。
- 真实技术手册/方法资产未发布。
- Career edges 继续关闭；90 天 dead-content 复核日期为 2026-11-08。

### 决策

继续执行 `10 / 0 / 1`，不批量新写文章。先让既有 111 篇的 attribution 与 internal edge 可观测，再依据 28/90 天证据决定 refresh、merge、noindex 或 retire。

## 10. 窗口 6 — Topic Graph / Internal Links

### 已完成

- Career 恢复前的 553 URL captured graph 已完成：full graph 17,064 unique edges / 24,060 instances；contextual graph 4,467 / 8,011；49 orphan；最大深度 5。
- 4,805 个候选 edge 完成治理：3,268 approved backend modeling、1,255 non-Career hold、282 Career blocked。
- backend authority、G03 alignment、前端 renderer 和 edge-click tracking 代码均已合并。

### 未完成

- 虽然 migration 在失败部署过程中执行，仍没有 edge import、activation 和 public rendered readback 证据。
- 当前 live corpus 已是 613 URL，不能继续把 553 当成当前总量；553 仅是 Career 恢复前历史快照。
- Career 恢复后的 2092 URL 图谱、前后 orphan/depth/authority 差分不存在。

### 决策

先完成非 Career approved edges 的受控导入/读回。所有目标为“预期 1046 cohort”的新链接继续禁止发布，直到 Career Task 6/C06 证明实际公开 target set。

## 11. IQ 补充窗口

独立 IQ 窗口只完成 planning-only scan：18 个资产、16 个 required candidate；10 个 `partial_content`、3 个 claim-gated、5 个 frontend fixture-only、5 个 missing EN，`complete_authoritative = 0`。

缺口包括 PDF/certificate、share/history、media、英文内容 authority、producer package、CMS import、runtime QA 和 Search visibility。状态仍是 `registered/not_started`，不是 launch-ready。

下一步必须单独建立 IQ 后端 authority/content package，先解决 claim boundary 和真实结果价值；不得把窗口 3 的 IQ/EQ canonical 技术审计当作 IQ 板块已完成。

## 12. 窗口 7 — Technical SEO / Performance

### 已完成

- 7 个聚焦 PR train 全部合并。
- 525 个 non-Career locale page / 306 identities 已冻结并验证 525/525 HTTP 200。
- 20 个 alias 保持 301；canonical 样本与 attribution parameter URL 已审计。
- RUM instrumentation 已合并；llms fanout 事故另有稳定性修复并完成前端生产部署。

### 未完成

- L1 只有 1/3 interval windows；窗口内缺少完整、当前的 GSC/GA4 cohorts。
- RUM 默认 off/noop，没有 field CWV 月度数据。
- 按证据拆分的性能修复候选尚未实施。
- non-Career sitemap/llms shrink guard 仍等待 Career C06 后的正确 cohort contract。

### 下一步

开启受控 RUM 采样与月度 field CWV，优先保护 MBTI L1、再 Big Five L2；性能 PR 必须由 route-level lab/field 证据触发。discoverability freeze 保持到 shrink guard 有 backend authority。

## 13. 窗口 8 — Authority / GEO / Brand

窗口只完成前置证据发现，没有生成目标交付文件、commit 或 PR。旧本地分支基于过期 base，不能作为有效成果继续叠加。

未完成项包括：首页 claim proof ledger、作者/审核者/组织实体清单、六测评技术手册证据清单、editorial/methodology/changelog 架构、固定 AI citation 查询集、技术手册 v1、Author/claim proof 后端 contract 和月度 citation 对比。

窗口 9 的 B03 技术手册盘点只能作为输入，不能替代窗口 8。应从最新 `main` 重新启动，先做 proof ledger 和 claim gate，再发布技术手册；没有公开证据的用户数、评分、团队、专家和媒体主张保持禁用。

## 14. 窗口 9 — English SEO

### 已完成

- closeout PR train 全部合并。
- M01 safe rows：4,192 EN 行 / 152 canonicals。
- EN RIASEC baseline 和 CMS experiment proposal 已完成但未 apply。
- 175 个 EN Personality 页面审计产生 5 个 metadata 和 1 个 link candidate。
- 40 篇 EN Articles 分为 1 refresh、27 hold、12 insufficient。
- W3 Article package 已通过 live QA，但 17 个目标仍 noindex 且不在 sitemap/llms。

### 未完成

- RIASEC CMS bridge 代码未证明生产 active，也没有 CMS 写或 T+0 读回。
- 5–10 个英文 Career 必须等待 Career Task 6/7；当前没有正式 Career Guide package。
- B03 技术手册仍 partial；28 天 expand/stop 复盘未发生。

### 决策

English SEO 的报告/closeout 已完成，增长实验未完成。先做 RIASEC 单变量 CMS 实验；Career 内容必须使用实际公开且通过内容安全的 slug，不得预选历史 1046 cohort。

## 15. 窗口 10 — Off-site / Linkable Assets

当前没有 referring-domain/brand-mention baseline、目标表、crosswalk、Atlas feasibility、定向分发或 citation outcome 交付，状态为 `NOT_STARTED`。

它不需要机械等待窗口 1–9 全部结束：referring domains、品牌 mentions 和高校/咨询师/媒体/数据社区目标表可以独立开始。但以下发布动作必须等待依赖：

- 双语 Career crosswalk：等待 Career authority/public cohort 稳定；
- Assessment × Career Atlas：等待隐私、方法与 claim review；
- 技术手册分发：等待窗口 8 产生公开 proof-gated v1；
- 独立 citation 复盘：等待资产真实发布并完成固定观察窗口。

## 16. 迁移服务器窗口

迁移线程中的 12 项任务必须按各自边界判断，不能只用“服务器已迁移”概括：

| Task | 范围 | 完成度 | 当前证据 / 尾项 |
|---:|---|---|---|
| 1 | 腾讯运行资源退役与阿里三机锁定 | `COMPLETE` | 腾讯 MySQL、Redis、旧 runtime nodes 已退出；receipt 为 `TENCENT_RUNTIME_RESOURCES_ZERO` |
| 2 | 三节点 TLS 自动续签 | `COMPLETE` | production Web/API、staging 的签发、timer、renewal dry-run 与 reload hook 已验收 |
| 3 | 三节点 reboot resilience | `COMPLETE` | staging、Web、API 按序重启恢复；API Nginx/Docker ordering 修复后复测通过 |
| 4 | 两仓 `main` 保护 | `COMPLETE` | PR + required checks + squash-only；禁删/禁强推；无日常 bypass |
| 5 | 本机十窗口 heavy guard | `COMPLETE` | 原子重型租约、task status 与跨仓规则已生效 |
| 6 | production API 并发/探测预算与旧服务状态 | `COMPLETE_WITH_OBSERVATION` | 前后端生产读取边界已合并；旧本机 MySQL/Redis 状态清理；CI 峰值下按 `PASS_WITH_EXPECTED_CI_LOAD_SPIKE` 接受 |
| 7 | 部署 Skills 与阿里拓扑权威更新 | `COMPLETE` | 全局/后端部署流程已按三节点、exact-SHA、受保护 Environment 更新 |
| 8 | GitHub Actions 生命周期治理 | `COMPLETE_FOR_CURRENT_PHASE` | 一次性 workflow 已远端禁用并登记；14 个 source workflows 仍 active，其中 4 个为 Career/MBTI 临时控制，最终目标 10 |
| 9 | Environment / secret authority 清理 | `COMPLETE` | 8 个旧 repository deploy secrets 和误建 Environment 已清理；四套 host key authority 更新；retired-host guard 保留 |
| 10 | release、artifact 与 journald 存储治理 | `PARTIAL_CLOSEOUT` | 前后端代码与 staging 验证完成；production server apply 只有平行窗口报告，本次未找到独立 apply/readback receipt |
| 11 | RDS 备份恢复演练与 PostgreSQL 用途审计 | `COMPLETE_WITH_RETIREMENT_TAIL` | MySQL 隔离恢复演练完成、临时实例释放；PostgreSQL 归类 `ARCHIVE_AND_RETIRE`，实际归档/释放未完成 |
| 12 | 三节点 SSH hardening 与本机 alias 清理 | `COMPLETE` | key-only 非 root sudo、root 拒绝、密码/交互关闭、登录参数收紧；仍需密码轮换 |

`COMPLETE_WITH_OBSERVATION` 表示受控目标已完成但留下容量观察，不代表发生生产故障；`COMPLETE_FOR_CURRENT_PHASE` 表示当前治理阶段关闭，但项目临时 workflows 会随对应项目再次收敛。

### 16.1 已验证完成

- 当前是阿里云三节点运行拓扑：Production Web、Production API/Ops、Staging combined。
- 生产 MySQL 已迁移至阿里云 RDS；备份/恢复演练完成，临时恢复实例已释放。
- 腾讯运行时资源 receipts 标记为 `TENCENT_RUNTIME_RESOURCES_ZERO`；腾讯旧节点不再是生产 runtime dependency。
- DNS 已把 production Web/API/staging 指向阿里云；域名、DNSPod、ICP 留在腾讯控制面属于有意保留。
- 三节点 TLS 自动续期和 reboot self-recovery receipts 通过。
- 三节点 SSH 已收敛到 key-only、非 root sudo 与更严格登录策略；旧腾讯/HK/重复本地 aliases 已移除。
- GitHub `main` ruleset、PR/squash 和无日常 bypass 的 solo-owner 护栏已启用。
- 本机 multi-window heavy guard 已启用。

### 16.2 Task 10：代码已验证，服务端 apply 未独立重读

- 后端 `deploy.php` 已将 production releases 设为 5、staging 设为 3，并在 activation 前清除 release 中固定的非 runtime 目录。
- 迁移目录存在 `task10-release-storage-preflight.json`，计划把 Web releases/artifacts 收敛为 3、API releases 为 5、staging 为 3，并设置 journald 14 天及 128M/256M 上限。
- 平行窗口曾报告 apply 已完成，但本任务没有找到独立的 Task 10 apply/readback receipt，也没有 SSH 重读。因此服务端实际 release count、journal limit 和磁盘回收量标记为 `OBSERVED_NOT_REVERIFIED`。

### 16.3 仍需收尾

- PR #3645 已合并；先等待 exact control-plane SHA `25cbc70f60d2a32901339e7e2469d4ddf196e173` 的 CI/staging 证据，再对仍符合资格的 approved immutable candidate 发起单独受控 production deploy，确认 migration/code/active revision 对齐。
- 生产部署成功后重新读回 Career、Measurement、Topic Graph 和 RIASEC bridge，不得沿用部署前快照。
- PostgreSQL RDS 仅保存 Metabase metadata，运行时已禁用，仍需按 `ARCHIVE_AND_RETIRE` 完成归档/退役。
- 服务器账号密码轮换仍是显式风险尾项。
- GitHub Actions 目前保留 Career/MBTI 临时 workflow；相关项目关闭后应从 14 个 source workflows 收敛到 10 个长期 workflow。
- 用受控只读 SSH 补一次 Task 10 的 release/journald/disk readback，产出最终 receipt。

当前运行拓扑、发布路径和安全边界见 [BACKEND-DEPLOY-TARGET-ALIYUN-01](../../../docs/04-ops/backend-deploy-target-aliyun-01.md)。

## 17. 后续执行队列

### P0：恢复生产一致性

1. 等待 control-plane SHA `25cbc70f60d2a32901339e7e2469d4ddf196e173` 的 CI、Code Scanning 与 staging 证据完成；取证截止时没有 production dispatch。
2. 发起新的后端 production deploy，绑定 exact approved immutable candidate SHA/release 与同 SHA staging receipt；candidate 必须仍可从当前 `main` 到达，并明确排除所有更新的 `main` commits。
3. 验证 active revision、migration status、queue/scheduler、Redis/RDS、`/up`、flags、Career directory/detail、sitemap source。
4. 重新冻结 post-deploy 613/30-or-changed truth；任何 cohort 变化必须有 authority receipt。

### P1：关闭依赖链

1. Window 1：重新执行 current Career Truth；没有 1046 authority 时正式选择“继续寻找”或“新 publication program”。
2. Window 2：backfill + GA4/backend 28 天对账 + qualified complete KPI。
3. Window 6：导入 approved non-Career edges并做 rendered/readback；Career edges 继续关闭。
4. Window 3/4/9：依次上线一个 RIASEC CMS 实验和一个 INTP-A 实验，不并发扩大变量。
5. Window 5：发布 Top 10 refresh 和一份真实技术手册，仍不批量新增。

### P2：补齐未启动板块

1. 重启 Window 8，从 proof ledger、author/reviewer/org entities 和六测评证据清单开始。
2. 单独启动 IQ authority/content package，不复用前端 fixture 作为 CMS authority。
3. 启动 Window 10 的只读 baseline 和目标表；发布/分发依赖 proof 与 Career gate。
4. 建立 Window 7 field CWV/RUM 月度观察。

## 18. 不得外推的结论

- 1046 曾公开过，不等于当前仍存在可恢复的 1046 authority。
- PR merged，不等于 production active、migration backfilled 或 CMS published。
- sitemap/llms 出现 URL，不等于 indexed、ranking、AI citation 或业务转化。
- GSC impressions 或 GA4 events，不等于 backend-qualified assessment complete。
- 553、613、2092、2645 都是特定 cohort/time-window 事实，不是硬编码规则。
- TLS、reboot、runtime retirement receipts 通过，不等于所有服务器运维尾项都已关闭。

## 19. 主要证据索引

- Career current truth：[career-1046-truth-rescan-06.md](career-1046-truth-rescan-06.md)
- Career protected publication：[career-1046-publication-control.md](../../../docs/operations/career-1046-publication-control.md)
- Career C03 runbook：[career-c03-cache-only-discoverability-recovery.md](../../../docs/operations/career-c03-cache-only-discoverability-recovery.md)
- Measurement production boundary：[measurement-m04-backend-read-model-remediation-01.md](measurement-m04-backend-read-model-remediation-01.md)
- Topic Graph authority：[public-topic-edge-authority-contract.md](public-topic-edge-authority-contract.md)
- Greenfield baseline：[greenfield-current-published-baseline.md](../operations/greenfield-current-published-baseline.md)
- Actions lifecycle：[github-actions-lifecycle.md](../../../docs/operations/github-actions-lifecycle.md)
- 阿里云部署目标：[backend-deploy-target-aliyun-01.md](../../../docs/04-ops/backend-deploy-target-aliyun-01.md)
- 迁移 receipts（仓库外本机证据）：`FermatMind迁移/*-20260810.json`、`post-migration-ops-20260811/task10-release-storage-preflight.json`

## 20. 本文档的更新触发条件

以下任一事实变化时必须更新本文档或生成后继状态文档：

- backend active revision 改变；
- Career authority/public/sitemap cohort 改变；
- Window 2 production reconciliation 完成；
- Topic Graph edge import/activation 完成；
- Window 8 或 10 产生首个正式交付；
- Task 10 服务端 readback、PostgreSQL retirement 或密码轮换完成。
