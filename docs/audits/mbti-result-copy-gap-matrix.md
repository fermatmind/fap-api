# MBTI 结果页文案 Gap Matrix

## 0. 分级标准

- `L0`：只缺内容
- `L1`：只缺映射
- `L2`：缺 schema 或 serializer
- `L3`：缺完整生成链路

## 1. Gap Matrix

| Gap ID | 等级 | 缺口 | 具体文件 | 当前影响 | 需要补什么 |
| --- | --- | --- | --- | --- | --- |
| G01 | L3 | 没有单一“结果页公共 authority” | `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php`、`backend/app/Services/V0_3/ShareService.php`、`backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php`、`backend/app/Services/Report/Composer/ReportPayloadAssemblerComposeFinalizeTrait.php` | report、personality、share、SEO 分别读不同源 | 建立统一公共结果页 DTO 和 serializer |
| G02 | L3 | 当前 public personality 链路与 32 型 report 链路分叉 | `content_packages/default/...`、`content_baselines/personality/mbti.en.json`、`content_baselines/personality/mbti.zh-CN.json` | 同一人格在 report 与 public 页面文案不同步 | 收口到单一后端 authority，并建立投影/导入链路 |
| G03 | L3 | 附件式结构没有完整 import 链路 | `backend/app/Console/Commands/PersonalityImportLocalBaseline.php`、`backend/app/PersonalityCms/Baseline/PersonalityBaselineReader.php`、`backend/app/PersonalityCms/Baseline/PersonalityBaselineNormalizer.php`、`backend/app/PersonalityCms/Baseline/PersonalityBaselineImporter.php` | 无法批量导入 `lettersIntro/traitOverview/premiumTeaser` | 新 baseline schema + upsert/import + dry-run + diff 报表 |
| G04 | L2 | `PersonalityProfile` 只接受 16 基础型 | `backend/app/Models/PersonalityProfile.php` | public personality / SEO / sitemap 不可能正式承载 `ENFJ-T` 等变体 | 扩展 `TYPE_CODES` 与读取逻辑，显式支持 32 型 |
| G05 | L2 | baseline normalizer 拒绝 32 型 | `backend/app/PersonalityCms/Baseline/PersonalityBaselineNormalizer.php` | 导入链路无法写入 `-A/-T` 内容 | 修改 normalizer 规则与唯一性校验 |
| G06 | L2 | `sections` key 集合无法承载目标模块 | `backend/app/Models/PersonalityProfileSection.php`、`backend/app/Filament/Ops/Resources/PersonalityProfileResource/Support/PersonalityWorkspace.php` | 无法保存 `lettersIntro`、`traitOverview`、`premium_teaser` | 扩展 section keys/render variants 或引入显式 payload schema |
| G07 | L2 | `PersonalityController` 只会吐原始 profile + sections 行 | `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php` | 前端拿不到结构化结果页 DTO | 新增公共 serializer，输出模块化 payload |
| G08 | L2 | `AttemptReadController::result` 直接回传 `result_json`，不是正式文案 DTO | `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php` | 结果页如果直接用它，必然继续依赖前端 fallback | 让 result/report/share 共享统一公共模块 serializer |
| G09 | L2 | premium teaser 没有 authored schema | `backend/app/Services/Report/ReportGatekeeperTeaserTrait.php`、`backend/app/Models/PersonalityProfileSection.php` | 只能 blur，不能运营化写 teaser | 新增 teaser payload 字段族并与 CTA 行为接线 |
| G10 | L2 | SEO/OG/Twitter 未以 32 型 authority 为源 | `backend/app/Services/Cms/PersonalityProfileSeoService.php`、`backend/app/Models/PersonalityProfileSeoMeta.php` | SEO 与主结果页文案不一致 | 改为从统一 DTO 生成，再允许 overrides |
| G11 | L2 | sitemap 仍以基础型 CMS 为源 | `backend/app/Services/SEO/SitemapGenerator.php` | personality 页收录粒度与正式结果页不一致 | sitemap source 改读统一 32 型 authority 或其 public projection |
| G12 | L1 | share fallback 会去掉 `-A/-T` | `backend/app/Services/V0_3/ShareService.php` | 变体级标题/摘要可能回落到基础型 | variant-aware lookup + 明确降级规则 |
| G13 | L1 | share 没有统一消费 `identity_card.share_text` | `backend/app/Services/V0_3/ShareService.php` | 分享文案与 report identity card 不完全同源 | 固定 share 文案字段优先级 |
| G14 | L1 | report cards 已有，但未映射成公共 `career/growth/relationships` blocks | `backend/app/Services/Report/Composer/ReportPayloadAssemblerComposeBuildTrait.php`、`backend/app/Services/Report/Composer/ReportPayloadAssemblerComposeFinalizeTrait.php` | 结果页仍需要额外二次拼装 | 增加公共 section serializer |
| G15 | L1 | `fap-web` sitemap 为空，未接 personality sitemap source | `fap-web/app/sitemap.ts` | 前端 sitemap 不覆盖人格页 | 接入后端 authority 输出 |
| G16 | L1 | `next-sitemap.config.js` 缺失 | 仓库根目录缺失文件 | 现有 Next 侧 sitemap 配置不可审计 | 明确使用 App Router `sitemap.ts` 或补统一配置 |
| G17 | L0 | 32 型结构化正文内容尚未导入 | 未来结构化 baseline 文件 | schema 就绪后仍需内容投放 | 批量导入 32 型内容，至少覆盖双语目标范围 |
| G18 | L0 | `lettersIntro` 内容完全缺席 | 未来 baseline 内容文件 | 结果页无法达成目标结构 | 按 32 型批量编写/导入 |
| G19 | L0 | `traitOverview.dimensions` 叙事内容缺席 | 未来 baseline 内容文件 | 只能显示数值，没有结构化解释 | 导入维度 narrative/tips/blocks |
| G20 | L0 | premium teaser 正文缺席 | 未来 baseline 内容文件 | 无法做正式付费引导 | 编写 teaser copy 并按 32 型导入 |

## 2. 对 4 个样例类型的 gap 观察

| 样例 | 当前有的 | 当前缺的 | 结论 |
| --- | --- | --- | --- |
| `ENFJ-T` | report 32 型 profile / identity / cards / share_text | public personality 32 型记录、lettersIntro、traitOverview narrative、premium teaser、variant SEO | 适合作为第一批试导入样例 |
| `ENFJ-A` | 同上 | 同上 | 可直接验证 A/T 对照策略 |
| `INFP-T` | report 32 型内容完整 | public personality 只剩 `INFP` 基础型 | 最能暴露 base-type fallback 问题 |
| `INTJ-T` | report 32 型内容完整，share 当前也会输出 `type_code` | share/public fallback 仍查 `INTJ` | 最能验证 share/SEO 同源改造是否完成 |

## 3. 缺口优先级判断

### 必须先解的

- `G01` 单一 authority
- `G04/G05/G06` 32 型 + section schema
- `G07/G10/G11/G12` public serializer、SEO、sitemap、share 对齐

### 不能后拖的

- `G09` premium teaser schema
- `G14` report cards 到公共 blocks 的映射

### 可以放到内容导入阶段完成的

- `G17/G18/G19/G20`

## 4. Gap Matrix 结论

当前问题不在“文案写得不够多”，而在：

- authority 分叉
- schema 不足
- serializer 缺位
- import 目标格式缺失

所以正式上线工作不能从前端本地接附件开始，必须从后端 authority 收口开始。
