# MBTI 结果页文案正式上线路线

## 0. 路线选择

本方案选择一条明确路线：

- 公共结果页 authority 收口到后端 `PersonalityProfile` 体系的 V2 结构化读模型
- 保留现有 `v0.3 report` 内容包与 cards 选择器，继续承担深度报告与门控职责
- 新的结构化基线内容通过 import 批量进入后端
- `result`、`share`、`personality`、`seo/og`、`sitemap` 统一改为消费同一套公共 serializer

这样做的理由：

- 当前 SEO、OG、sitemap、Filament 编辑壳都已经建立在 `PersonalityProfile` 体系上
- 当前 report 又已经掌握 32 型和 A/T 权威内容
- 最稳妥的正式上线方式不是推翻其中一套，而是把它们接到同一套公共 schema 与 serializer 上

## A. 权威源收口

### 输入

- `content_packages/default/...` 的 32 型内容
- `results` 表中的五轴数值与 `type_code`
- 现有 `PersonalityProfile` 公共链路
- 本次审计文档

### 输出

- 一份明确的“公共结果页 authority DTO”定义
- 明确字段归属：
  - runtime numeric 归 `results`
  - 32 型人格文案归 structure baseline / public authority
  - 深度 cards 归 report content pack

### 改动面

- 新的 DTO/serializer 设计文档
- `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php`
- `backend/app/Services/V0_3/ShareService.php`
- `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php`

### 验收标准

- 所有 surface 都能回答“标题/副标题/summary/SEO/share 来自哪一份 authority”
- 不再允许出现“share 从 report 取一部分、SEO 从 CMS 取一部分、结果页前端自己再写一部分”

### 风险点

- 双轨期可能同时存在旧 personality API 和新 DTO
- 需要清晰的版本边界，避免老客户端直接 break

## B. schema / section 映射

### 输入

- 目标模块：`lettersIntro`、`traitOverview.dimensions`、`traits`、`career`、`growth`、`relationships`、`premiumTeaser`

### 输出

- 32 型可承载 schema
- 新 section keys / render variants / payload 结构
- `base_type_code` / `variant` 等派生字段约定

### 改动面

- `backend/database/migrations/*` 新迁移
- `backend/app/Models/PersonalityProfile.php`
- `backend/app/Models/PersonalityProfileSection.php`
- `backend/app/Filament/Ops/Resources/PersonalityProfileResource/Support/PersonalityWorkspace.php`

### 验收标准

- `PersonalityProfile` 能正式承接 `ENFJ-T` 等 32 型
- section schema 能承接：
  - `letters_intro`
  - `trait_overview`
  - `traits_blocks`
  - `career_blocks`
  - `growth_blocks`
  - `relationships_blocks`
  - `premium_teaser`

### 风险点

- 直接改现有 `SECTION_KEYS` 可能影响 V1 内容
- 更稳妥做法是：
  - V1 保留
  - 增量加入 V2 section keys
  - serializer 按 schema_version 分流

## C. authoring / import 方案

### 输入

- 附件原始文案
- 当前 32 型 report 内容包
- 现有 `content_baselines/personality/mbti.*.json`

### 输出

- 新的结构化 baseline 文件格式
- dry-run / create / upsert / revision 完整导入链路
- 双语批量导入能力

### 改动面

- `backend/app/Console/Commands/PersonalityImportLocalBaseline.php`
- `backend/app/PersonalityCms/Baseline/PersonalityBaselineReader.php`
- `backend/app/PersonalityCms/Baseline/PersonalityBaselineNormalizer.php`
- `backend/app/PersonalityCms/Baseline/PersonalityBaselineImporter.php`
- `content_baselines/personality/*`

### 验收标准

- 能对 `ENFJ-T`、`ENFJ-A`、`INFP-T`、`INTJ-T` 先做试导入
- upsert 幂等
- revision 正常生成
- dry-run 能给出 create/update/skip 统计与 diff 摘要

### 风险点

- 附件如果是半结构化文档，需要先转成稳定 authoring format
- 双语内容可能存在字段不对齐，需要 import 前 lint

## D. frontend 渲染升级

### 输入

- 新的公共结果页 DTO

### 输出

- 结果页、分享页、人格详情页统一使用后端结构化 payload
- 去除前端本地 fallback 文案

### 改动面

- 当前仓库内能确认的前端文件只有：
  - `fap-web/app/sitemap.ts`
  - `fap-web/app/robots.ts`
- 实际结果页 route/component 文件未在当前仓库扫描到，需要在真实前端仓库落地

### 验收标准

- 页面渲染不再依赖本地 MBTI 文案常量
- 32 型 `-A/-T` 在 UI 中可见且一致
- `lettersIntro`、`traitOverview.dimensions`、分层 `career/growth/relationships` 全部由后端结构化输出驱动

### 风险点

- 当前前端真实文件缺席，必须先补齐 repo 边界
- 若前端已有隐藏 fallback，必须全部清理

## E. premium teaser 接线

### 输入

- 新 `premium_teaser` authored payload
- 现有 `ReportGatekeeper` / `cta` 门控行为

### 输出

- 有内容、可维护、可 A/B 的 premium teaser 模块

### 改动面

- `backend/app/Models/PersonalityProfileSection.php`
- `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php`
- `backend/app/Services/V0_3/ShareService.php`
- `backend/app/Services/Report/ReportGatekeeperTeaserTrait.php`

### 验收标准

- premium teaser 文案来自 authoring/import，不是写死在前端
- CTA 行为继续由 gatekeeper 负责
- teaser 可独立调整而不影响 report cards

### 风险点

- 容易把 teaser 再做成第二套半硬编码配置
- 必须规定 teaser 与 CTA 的边界：文案归 content，行为归 gatekeeper

## F. share / seo / og 对齐

### 输入

- 统一公共结果页 DTO
- SEO overrides 机制

### 输出

- share / SEO / OG / Twitter / JSON-LD 与主结果页同源

### 改动面

- `backend/app/Services/V0_3/ShareService.php`
- `backend/app/Services/Cms/PersonalityProfileSeoService.php`
- `backend/app/Services/SEO/SitemapGenerator.php`
- `fap-web/app/sitemap.ts`

### 验收标准

- `ENFJ-T` 与 `ENFJ-A` 的分享标题/副标题/summary 可区分
- `INTJ-T` 不再通过 `INTJ` base type 做 silent fallback
- sitemap 与 personality public pages 的收录来源与结果页 authority 一致

### 风险点

- 若 URL 仍使用基础型 slug，需要明确 canonical 策略
- 不能让 SEO schema 与前台页面各自生成不同文案

## G. QA / contract / snapshot / acceptance

### 输入

- 新 schema
- 新 serializer
- 四个样例类型

### 输出

- 合同测试
- snapshot
- acceptance checklist

### 改动面

- `backend/tests/Feature/V0_5/PersonalityPublicApiTest.php`
- `backend/tests/Feature/PersonalityCms/PersonalityBaselineImportTest.php`
- `backend/tests/Feature/V0_3/ShareSummaryContractTest.php`
- `backend/tests/Feature/Report/MbtiReportContentEnhancementContractTest.php`
- 新增公共结果页 serializer 合同测试

### 验收标准

- 四个样例类型全部通过：
  - `ENFJ-T`
  - `ENFJ-A`
  - `INFP-T`
  - `INTJ-T`
- 验证项至少包括：
  - `typeCode` 保留
  - `variant` 可读
  - `lettersIntro` 有值
  - `traitOverview.dimensions` 结构完整
  - `career/growth/relationships` blocks 完整
  - premium teaser 有值
  - share/SEO/OG 与主结果页主文案同源

### 风险点

- 只测 `report` 不够，必须补 `personality`、`share`、`seo`、`sitemap`
- snapshot 必须锁定字段结构，不只是锁定几个字符串

## 1. 正式上线最小可行切分

建议最小切分如下：

1. 先打通四个样例类型的 32 型 public authority
2. 再打通统一 serializer
3. 再接 share / SEO / sitemap
4. 最后批量导入所有 32 型与双语内容

原因：

- 先跑通四个样例，最容易暴露 `A/T`、base type fallback、public API schema 的结构问题
- 先跑通 authority 与 serializer，再铺量导入，返工成本最低

## 2. 路线总结

正式上线不是“把附件接到页面上”，而是把当前三条链路：

- result/report
- public personality
- share/SEO/sitemap

收口成一条长期可维护的后端内容链路。
