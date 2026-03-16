# MBTI 结果页文案未来文件改动清单

## 0. 说明

- 本清单是“未来真正实施时要改的文件”
- 当前这次任务没有修改这些业务文件
- 分类按：前端 / 后端 / CMS / import / tests / docs
- 若某一类关键文件在当前仓库中不存在，会明确标成“当前仓库未扫描到实际文件”

## 1. 前端

| 文件 | 为什么要改 |
| --- | --- |
| `fap-web/app/sitemap.ts` | 当前返回空数组，无法把 personality/result authority 正式发到前端 sitemap |
| `fap-web/app/robots.ts` | 若 sitemap 路径或 personality 收录策略变化，需要同步 robots 输出 |
| `当前仓库未扫描到的实际结果页 route/component 文件` | 真正结果页必须改成消费统一公共 DTO，移除本地 MBTI fallback 文案 |
| `当前仓库未扫描到的实际分享页/OG 组件文件` | share / OG 必须改成消费统一 authority，不能再自行拼文案 |

## 2. 后端

| 文件 | 为什么要改 |
| --- | --- |
| `backend/routes/api.php` | 若引入新的公共结果页 DTO endpoint 或 version，需要补路由 |
| `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php` | 当前 `result` 直接返回 `result_json`，需要接入统一结构化 serializer |
| `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php` | 当前只输出 profile + sections 行，需要升级成结构化结果页 payload |
| `backend/app/Services/V0_3/ShareService.php` | 当前 share 是混合拼装且会抹平 `-A/-T` fallback，需要改成统一 authority 输出 |
| `backend/app/Services/Cms/PersonalityProfileService.php` | 当前 public profile 查询按基础型设计，需要支持 32 型与明确回退规则 |
| `backend/app/Services/Cms/PersonalityProfileSeoService.php` | 当前 SEO/OG 不是从统一结果页 authority 生成，需要重构为同源输出 |
| `backend/app/Services/SEO/SitemapGenerator.php` | 当前 personality sitemap 只看基础型 CMS，需要对齐统一 authority |
| `backend/app/Models/PersonalityProfile.php` | 需要把 16 型扩展到 32 型，并约束新的 type code 规则 |
| `backend/app/Models/PersonalityProfileSection.php` | 需要扩展 section keys / render variants 以承接 `lettersIntro/traitOverview/premium_teaser` |
| `backend/app/Services/Report/Composer/ReportPayloadAssemblerComposeFinalizeTrait.php` | 若公共结果页 DTO 要复用 report 头部与 cards，需要补统一映射出口 |
| `backend/app/Services/Report/Composer/ReportPayloadAssemblerComposeBuildTrait.php` | 若把 report cards 映射成公共 `career/growth/relationships` blocks，需要在这里或旁路 serializer 接线 |
| `backend/app/Services/Report/ReportGatekeeperTeaserTrait.php` | 当前只有 blur，没有 authored premium teaser，需要接入 teaser payload |

## 3. CMS

| 文件 | 为什么要改 |
| --- | --- |
| `backend/app/Filament/Ops/Resources/PersonalityProfileResource.php` | 当前资源仍是 V1 人格页编辑壳，需要支持结构化结果页字段与 32 型 |
| `backend/app/Filament/Ops/Resources/PersonalityProfileResource/Support/PersonalityWorkspace.php` | 当前 `sectionDefinitions()` 只有 V1 section，需要扩展到目标模块 |

## 4. Import

| 文件 | 为什么要改 |
| --- | --- |
| `backend/app/Console/Commands/PersonalityImportLocalBaseline.php` | 当前命令只导入 V1 基线，需要支持新的结构化 baseline |
| `backend/app/PersonalityCms/Baseline/PersonalityBaselineReader.php` | 需要读取新的 baseline 文件与版本格式 |
| `backend/app/PersonalityCms/Baseline/PersonalityBaselineNormalizer.php` | 当前会拒绝 `-A/-T` 且不识别目标模块，需要升级校验 |
| `backend/app/PersonalityCms/Baseline/PersonalityBaselineImporter.php` | 需要把结构化模块写入新的 schema / section payload |
| `content_baselines/personality/mbti.en.json` | 当前内容源来自旧前端 deterministic transforms，需要升级或替换成结构化 32 型基线 |
| `content_baselines/personality/mbti.zh-CN.json` | 同上 |

## 5. 数据库 / Schema

| 文件 | 为什么要改 |
| --- | --- |
| `backend/database/migrations/*` 新迁移 | 需要扩 schema，支持 32 型、结构化模块、premium teaser、统一 payload 版本化 |

## 6. Tests

| 文件 | 为什么要改 |
| --- | --- |
| `backend/tests/Feature/V0_5/PersonalityPublicApiTest.php` | 当前只验证基础型 profile + sections，需要升级到结构化公共 DTO |
| `backend/tests/Feature/PersonalityCms/PersonalityBaselineImportTest.php` | 当前只验证 V1 16 型 baseline，需要增加 32 型结构化导入验证 |
| `backend/tests/Feature/V0_3/ShareSummaryContractTest.php` | 需要验证 share 与主结果页 authority 同源，并保留 `-A/-T` |
| `backend/tests/Feature/Report/MbtiReportContentEnhancementContractTest.php` | 需要验证 report 与公共结果页 DTO 的头部/identity/career 等映射一致性 |
| `新增：公共结果页 serializer 合同测试` | 锁定最终结构，避免回退到“前端本地拼文案” |
| `新增：四个样例类型 snapshot` | 先用 `ENFJ-T / ENFJ-A / INFP-T / INTJ-T` 验证结构与 A/T 差异 |

## 7. Docs

| 文件 | 为什么要改 |
| --- | --- |
| `docs/audits/mbti-result-copy-as-is.md` | 当前审计结论后续需要随着实施更新为对照文档 |
| `docs/audits/mbti-result-copy-backend-authority-map.md` | 实施后要更新 authority 收口状态 |
| `docs/audits/mbti-result-copy-target-schema-map.md` | 实施时可作为字段对照单 |
| `docs/audits/mbti-result-copy-gap-matrix.md` | 实施阶段可逐项关闭 gap |
| `docs/audits/mbti-result-copy-launch-plan.md` | 作为上线阶段 checklist |
| `docs/api/*` 或 `docs/content/*` 新 contract 文档 | 需要补正式公共结果页 DTO、import schema、A/T 回退规则文档 |

## 8. 建议先改的最小文件集合

如果按最小可行闭环推进，第一批真正要改的文件建议是：

1. `backend/app/Models/PersonalityProfile.php`
2. `backend/app/Models/PersonalityProfileSection.php`
3. `backend/database/migrations/*` 新迁移
4. `backend/app/PersonalityCms/Baseline/PersonalityBaselineNormalizer.php`
5. `backend/app/PersonalityCms/Baseline/PersonalityBaselineImporter.php`
6. `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php`
7. `backend/app/Services/V0_3/ShareService.php`
8. `backend/app/Services/Cms/PersonalityProfileSeoService.php`
9. `backend/tests/Feature/V0_5/PersonalityPublicApiTest.php`
10. `backend/tests/Feature/V0_3/ShareSummaryContractTest.php`

原因：

- 这批文件正好覆盖：
  - 32 型 schema
  - import
  - public serializer
  - share / SEO 对齐
  - 合同测试
