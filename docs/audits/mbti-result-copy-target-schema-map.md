# MBTI 结果页目标结构到当前后端 Schema 的映射

## 0. 目标结构说明

本文件把“附件目标结构”按任务中点名模块进行推断映射。目标不是把附件硬编码进前端，而是定义：

- 哪些字段当前后端已能承接
- 哪些字段可以先塞进现有 `sections.payload_json`
- 哪些字段必须扩 schema
- 哪些字段必须新增 serializer / renderer

判定标签固定为四种：

- `已可直接承载`
- `可通过现有 sections 承载`
- `需要小幅扩 schema`
- `需要新增 renderer / serializer`

## 1. 目标字段逐项映射

| 目标字段 | 当前后端落点 | 判定 | 说明 |
| --- | --- | --- | --- |
| `typeCode` | `results.type_code`、`report.profile.type_code` | 已可直接承载 | runtime 已保留 `ENFJ-T` 这类完整码 |
| `baseTypeCode` | 可由 `typeCode` 派生 | 需要新增 renderer / serializer | 当前没有统一显式字段，只在 `ShareService` 内部临时派生 |
| `variant` | 可由 `typeCode` 派生 | 需要新增 renderer / serializer | 当前没有公开输出的 `A/T` 独立字段 |
| `typeName` | `type_profiles.json.type_name` | 已可直接承载 | 32 型 report 已有 |
| `headlineTitle` | `report_identity_cards.json.title` 或 `identity_layers.json.title` | 已可直接承载 | 已有更适合结果页首屏的标题 |
| `headlineSubtitle` | `report_identity_cards.json.subtitle` / `identity_layers.json.subtitle` | 已可直接承载 | 但未统一暴露给 public personality API |
| `tagline` | `type_profiles.json.tagline` / `report_identity_cards.json.tagline` | 已可直接承载 | 当前 share/report 都在用 |
| `summary` | `type_profiles.json.short_summary` + `identity_card.summary` | 已可直接承载 | 需统一优先级 |
| `rarity` | `type_profiles.json.rarity` | 已可直接承载 | 32 型 report 已有 |
| `keywords[]` | `type_profiles.json.keywords` / `identity_card.tags` | 已可直接承载 | 当前命名不统一 |
| `lettersIntro.title` | 无 | 需要小幅扩 schema | 需要新增 section key，例如 `letters_intro` |
| `lettersIntro.items[]` | 无 | 需要小幅扩 schema | 现有 `sections` 无法表达按字母拆解的人格介绍 |
| `traitOverview.title` | 无统一字段 | 可通过现有 sections 承载 | 可暂放新 section，但最好升级为显式结构 |
| `traitOverview.dimensions[].code` | `scores_pct` / `axis_states` | 已可直接承载 | 五轴数据已有 |
| `traitOverview.dimensions[].letter` | `typeCode` + `scores_pct` | 需要新增 renderer / serializer | 当前无标准输出对象 |
| `traitOverview.dimensions[].pct` | `results.scores_pct` | 已可直接承载 | 五轴包括 `AT` |
| `traitOverview.dimensions[].state` | `results.axis_states` | 已可直接承载 | 已有 `clear/strong/...` |
| `traitOverview.dimensions[].title` | 无 | 需要小幅扩 schema | 需要维度叙事文案 |
| `traitOverview.dimensions[].summary` | 无 | 需要小幅扩 schema | 不能继续靠前端词典拼装 |
| `traitOverview.dimensions[].strengthBlock` | 当前仅可从 `report_cards_*` 间接选取 | 需要新增 renderer / serializer | 需要统一 serializer 把维度摘要结构化 |
| `traitOverview.dimensions[].riskBlock` | 同上 | 需要新增 renderer / serializer | 同上 |
| `traitOverview.dimensions[].tips[]` | 当前 `cards[].tips` 有碎片 | 可通过现有 sections 承载 | 但建议做标准维度 payload |
| `career.intro` | 可取自 `career` cards 顶层描述组合 | 需要新增 renderer / serializer | 现有只有卡片池，没有 section intro |
| `career.blocks[]` | `report.sections.career.cards[]` | 已可直接承载 | 已有 cards、bullets、tips、module_code |
| `growth.intro` | 同理 | 需要新增 renderer / serializer | 需要将 card 池升为公共 section DTO |
| `growth.blocks[]` | `report.sections.growth.cards[]` | 已可直接承载 | 当前仅在 report 中可见 |
| `relationships.intro` | 同理 | 需要新增 renderer / serializer | 需要公共 DTO |
| `relationships.blocks[]` | `report.sections.relationships.cards[]` | 已可直接承载 | 但 public profile 当前只有单段文本 |
| `traits.blocks[]` | `report.sections.traits.cards[]` | 已可直接承载 | 适合作为“你的人格表现”区块 |
| `premiumTeaser.headline` | 无 | 需要小幅扩 schema | 当前只有 paywall blur，不是 authored teaser |
| `premiumTeaser.body` | 无 | 需要小幅扩 schema | 需要运营可维护内容位 |
| `premiumTeaser.bullets[]` | 无 | 需要小幅扩 schema | 同上 |
| `premiumTeaser.cta` | `ReportGatekeeper.cta` 可提供行为 | 可通过现有 sections 承载 | 行为已有，文案位缺失 |
| `share.title` | `ShareService` 运行时可拼出 | 需要新增 renderer / serializer | 应统一改由结果页 authority 输出 |
| `share.subtitle` | `ShareService` 运行时可拼出 | 需要新增 renderer / serializer | 当前 fallback 会丢 `-A/-T` |
| `share.summary` | `identity_card.summary` / `report.profile.short_summary` / `publicProfile.excerpt` | 需要新增 renderer / serializer | 应固定优先级并与主结果页同源 |
| `share.shareText` | `report_identity_cards.json.share_text` | 已可直接承载 | 当前 share API 没有充分消费这一字段 |
| `seo.title` | `seo_meta.seo_title` | 已可直接承载 | 但不是从 32 型公共 authority 生成 |
| `seo.description` | `seo_meta.seo_description` | 已可直接承载 | 同上 |
| `seo.og.*` | `seo_meta.og_*` | 已可直接承载 | 同上 |
| `seo.twitter.*` | `seo_meta.twitter_*` | 已可直接承载 | 同上 |
| `seo.jsonld` | `seo_meta.jsonld_overrides_json` + `PersonalityProfileSeoService` | 已可直接承载 | 需要改为消费 32 型 authority |
| `metadata.contentVersion` | `report.versions`、`profile.schema_version` | 已可直接承载 | 现有来源分裂 |
| `metadata.sourceVersion` | `content_package_version`、baseline `schema_version` | 已可直接承载 | 需要统一写法 |

## 2. 推荐的目标后端承载形态

推荐把公共结果页 authority 升级为“32 型 + 结构化 payload”的后端读模型：

```yaml
profile:
  type_code: ENFJ-T
  base_type_code: ENFJ
  variant: T
  locale: zh-CN
  title: 主人公
  subtitle: 把人心与目标组织成统一节奏的引导者，校准感更强
  tagline: 先理解人，再推动群体走向同一方向
  summary: ...
  rarity: 约 2–3%
  keywords: [...]
modules:
  letters_intro:
    title: ...
    items: [...]
  trait_overview:
    title: ...
    dimensions: [...]
  traits:
    intro: ...
    blocks: [...]
  career:
    intro: ...
    blocks: [...]
  growth:
    intro: ...
    blocks: [...]
  relationships:
    intro: ...
    blocks: [...]
  premium_teaser:
    headline: ...
    body: ...
    bullets: [...]
share:
  title: ...
  subtitle: ...
  summary: ...
  share_text: ...
seo:
  title: ...
  description: ...
  og: ...
  twitter: ...
```

落地建议：

- `profile` 继续放在 `personality_profiles`
- 结构化模块优先走 `personality_profile_sections.payload_json`
- 但 section key 必须升级，不能继续只用 `core_snapshot/strengths/...`
- 输出必须新增一个“公共结果页 serializer”，不能继续靠 `PersonalityController` 直接吐原始 section 行

## 3. 明确禁止继续留在前端本地硬编码的字段

以下字段不允许继续作为前端本地大常量：

- 任何 32 型 `type_code` 对应的 `title/subtitle/tagline/summary`
- `lettersIntro` 全部内容
- `traitOverview.dimensions` 的叙事标题、解释、tips
- `career/growth/relationships` 的 block 文案
- `premium teaser` 全部文案
- `share.title/share.subtitle/share.summary/shareText`
- `seo.title/description/og/twitter`
- `-A/-T` 与基础型之间的回退映射词典

原因：

- 当前后端已有 32 型 runtime authority 基础
- 前端本地硬编码会制造第三套 source of truth
- 未来批量导入、CMS 修改、SEO 同步都无法闭环

## 4. 4 个样例类型试映射

以下只给“目标字段结构示意”，不写最终业务代码。

### 4.1 `ENFJ-T`

```yaml
typeCode: ENFJ-T
baseTypeCode: ENFJ
variant: T
profile:
  typeName:
    source: content_packages/.../type_profiles.json.items.ENFJ-T.type_name
  title:
    source: content_packages/.../report_identity_cards.json.items.ENFJ-T.title
  subtitle:
    source: content_packages/.../report_identity_cards.json.items.ENFJ-T.subtitle
  tagline:
    source: content_packages/.../type_profiles.json.items.ENFJ-T.tagline
  summary:
    source:
      - content_packages/.../type_profiles.json.items.ENFJ-T.short_summary
      - content_packages/.../report_identity_cards.json.items.ENFJ-T.summary
      - content_packages/.../identity_layers.json.items.ENFJ-T.one_liner
lettersIntro:
  source: none
  targetLanding: personality_profile_sections.section_key=letters_intro
traitOverview:
  dimensions:
    numeric:
      source: results.scores_pct + results.axis_states
    narrative:
      source: none
      targetLanding: personality_profile_sections.section_key=trait_overview
career:
  blocks:
    source: report.sections.career.cards[]
growth:
  blocks:
    source: report.sections.growth.cards[]
relationships:
  blocks:
    source: report.sections.relationships.cards[]
premiumTeaser:
  source: none
share:
  shareText:
    source: content_packages/.../report_identity_cards.json.items.ENFJ-T.share_text
seo:
  source: current seo_meta only, not variant-aware
```

缺口：

- `lettersIntro` 缺字段
- `traitOverview` 缺维度叙事与公共 serializer
- `premiumTeaser` 缺字段
- `seo/share` 仍未以 `ENFJ-T` 为公共 authority

### 4.2 `ENFJ-A`

```yaml
typeCode: ENFJ-A
baseTypeCode: ENFJ
variant: A
profile:
  typeName:
    source: type_profiles.json.items.ENFJ-A.type_name
  title:
    source: report_identity_cards.json.items.ENFJ-A.title
  subtitle:
    source: report_identity_cards.json.items.ENFJ-A.subtitle
  summary:
    source:
      - type_profiles.json.items.ENFJ-A.short_summary
      - report_identity_cards.json.items.ENFJ-A.summary
      - identity_layers.json.items.ENFJ-A.one_liner
lettersIntro:
  targetLanding: new section payload
traitOverview:
  dimensions:
    numeric: results 5-axis
    narrative: new section payload
career/growth/relationships:
  blocks: report cards serializer
premiumTeaser:
  targetLanding: new section payload
seo/share:
  currentFallback: personality_profiles.type_code = ENFJ
```

缺口：

- 与 `ENFJ-T` 同类
- A/T 差异虽在 report pack 中存在，但未沉淀到公共 personality/SEO/share

### 4.3 `INFP-T`

```yaml
typeCode: INFP-T
baseTypeCode: INFP
variant: T
profile:
  authored32:
    source:
      - type_profiles.json.items.INFP-T
      - report_identity_cards.json.items.INFP-T
      - identity_layers.json.items.INFP-T
  publicCms:
    currentFallback:
      - personality_profiles.type_code = INFP
      - personality_profile_sections (base type only)
traitOverview:
  numeric: results.scores_pct + axis_states
  narrative: missing
sections:
  traits/career/growth/relationships:
    source: report cards
premiumTeaser:
  missing: true
seo:
  currentFallback: seo_meta attached to INFP base type only
```

缺口：

- `INFP-T` 虽有 32 型权威内容，但公共链路只能退到 `INFP`
- 结果页结构升级字段全部未进入 CMS/public API

### 4.4 `INTJ-T`

```yaml
typeCode: INTJ-T
baseTypeCode: INTJ
variant: T
profile:
  authored32:
    source:
      - type_profiles.json.items.INTJ-T
      - report_identity_cards.json.items.INTJ-T
      - identity_layers.json.items.INTJ-T
  publicCms:
    currentFallback:
      - personality_profiles.type_code = INTJ
share:
  currentBehavior:
    type_code: INTJ-T
    publicProfileLookup: strips_to_INTJ
traitOverview:
  numeric: available
  narrative: missing
lettersIntro:
  missing: true
premiumTeaser:
  missing: true
```

缺口：

- `share` 最容易出现“标题/摘要与 `INTJ-T` 本体不完全对齐”的问题
- `INTJ-T` 的 SEO/OG 目前仍要借 `INTJ` base type 页面兜底

## 5. 目标 Schema Map 结论

可以直接复用的部分其实不少：

- 32 型 identity/profile 内容已经存在于 report 内容包
- 五轴 numeric 数据已经存在于 results
- cards 化的 `career/growth/relationships/traits` 已经存在于 report

真正缺的是四件事：

- 公共 authority 必须升为 32 型
- section key/payload 必须升级到能承接 `lettersIntro/traitOverview/premiumTeaser`
- public API 必须提供统一结构化 serializer
- share/seo/og 必须改为消费同一套结构化 authority
