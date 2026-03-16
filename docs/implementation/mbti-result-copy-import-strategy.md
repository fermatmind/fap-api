# MBTI 结果页文案导入策略

## 0. 目标

本策略回答两个问题：

1. 附件 `/Users/rainie/Desktop/微信小程序结果页文案.txt` 如何批量转成后端可导入内容
2. 如何按 32 型、保留 `-A/-T`、可回滚地迁入后端

## 1. 源文件现实情况

附件不是可直接导入的 baseline JSON，而是一个 JS-like 源文件：

- 32 个类型对象
- 1 个聚合对象 `MBTI_PROFILES`
- 每个类型对象包含固定 6 个一级模块

已确认的导入风险：

- 维度 ID 为 `NS/FT`，与后端 runtime `SN/TF` 不一致
- 一些块标题带有装饰编号或语义不完整
- 文件没有单独的 SEO/share authoring
- 文件没有 full premium body，只有 teaser

因此不能把附件原文直接当最终 baseline 写库，必须经过“抽取 -> 规范化 -> 映射 -> 校验 -> 导入”五步。

## 2. 批量导入总体流程

```mermaid
flowchart LR
    A["微信小程序结果页文案.txt"] --> B["AST/对象抽取"]
    B --> C["标准化转换"]
    C --> D["Canonical Baseline JSON"]
    D --> E["Dry-run 校验"]
    E --> F["Pilot 导入 4 个样例"]
    F --> G["按波次导入剩余 28 个"]
    G --> H["发布 / 验收 / 回滚窗口"]
```

## 3. 附件到导入基线的转换规则

## 3.1 对象抽取

抽取规则：

- 识别所有 `const XXX = { ... }`
- 忽略 `const MBTI_PROFILES = { ... }`
- 将常量名转回标准 `type_code`
  - `ENFJ_T` -> `ENFJ-T`
  - `INFP_T` -> `INFP-T`

校验：

- 必须正好抽到 32 条类型记录
- 每条必须包含：
  - `code`
  - `type`
  - `name`
  - `nickname`
  - `rarity`
  - `keywords`
  - `heroSummary`
  - `lettersIntro`
  - `overview`
  - `traitOverview`
  - `career`
  - `growth`
  - `relationships`

## 3.2 类型对齐规则

| 附件字段 | 规范化规则 |
| --- | --- |
| `code` | 必须转成 `^[EI][SN][TF][JP]-(A|T)$` |
| `type` | 必须是基础型，例如 `ENFJ` |
| `variant` | 从 `code` 后缀派生 |
| 常量名与 `code` | 必须完全一致，否则阻断导入 |

示例：

- `const ENFJ_T` + `code: 'ENFJ-T'` + `type: 'ENFJ'` => 合法
- 若出现 `const ENFJ_T` 但 `code: 'ENFJ-A'` => 直接报错

## 3.3 轴向标准化规则

这是导入中必须写死的规则：

| 附件轴 ID | 后端 canonical 轴 ID | 原因 |
| --- | --- | --- |
| `EI` | `EI` | 一致 |
| `NS` | `SN` | 后端评分/runtime 使用 `SN` |
| `FT` | `TF` | 后端评分/runtime 使用 `TF` |
| `JP` | `JP` | 一致 |
| `AT` | `AT` | 一致 |

同时要保留附件的展示极性：

- `NS` 中左侧其实是 `N`，右侧是 `S`
- `FT` 中左侧其实是 `F`，右侧是 `T`

导入后 payload 需保留：

- `axis_code`
- `source_axis_code`
- `left_pole`
- `right_pole`

这样前端不需要再根据轴名猜极性。

## 3.4 标题规范化规则

### 不能直接保留原样的情况

- `1. 人格概要｜你是怎样的 ENFJ-T？`
- ` 的短板`
- ` 系中的强项`

导入规则：

- 顺序号剥离，进入 `sort_order`
- `display_title` 与 `full_title` 分离
- 异常块标题统一用 canonical label dictionary 修复

导入字典示例：

```yaml
career.advantages: 你的职场优势
career.weaknesses: 你的职场短板
growth.strengths: 你的强项
growth.weaknesses: 你的短板
relationships.strengths: 关系中的强项
relationships.weaknesses: 关系中的短板
```

## 3.5 premium teaser 处理规则

附件中以下模块只导 teaser：

- `growth.motivators`
- `growth.drainers`
- `relationships.relAdvantages`
- `relationships.relRisks`

导入规则：

- 落入所属 section 的 `premium_teasers[]`
- `access_level = premium_teaser`
- 不补 full premium body

## 3.6 SEO / Share 处理规则

附件没有显式提供：

- `seo_title`
- `seo_description`
- `og_title`
- `share_text`

因此导入规则不是“从附件硬提”，而是：

- `seo_meta` 先生成默认值
- `share_text` 先沿用现有 `report_identity_cards.share_text`
- 如需运营覆盖，再追加 override 文件

## 4. 批量导入步骤

## Step 1. 生成中间标准化文件

输出建议：

- `content_baselines/personality/mbti-result-v2.zh-CN.json`
- `content_baselines/personality/mbti-result-v2.en.json`

每条记录包含：

- `profile`
- `sections`
- `seo_meta`

## Step 2. Dry-run 校验

dry-run 必须输出：

- `profiles_found`
- `will_create`
- `will_update`
- `will_skip`
- `section_count`
- `premium_teaser_count`
- `axis_code_mismatch_count`

## Step 3. Pilot 导入

先只导 4 个样例：

- `ENFJ-T`
- `ENFJ-A`
- `INFP-T`
- `INTJ-T`

目标：

- 验证 A/T 保留
- 验证 `NS/FT -> SN/TF` 转换
- 验证 `growth/relationships` teaser 落点
- 验证 share / SEO / public serializer

## Step 4. 扩到配对基础型

Pilot 稳定后，补齐对应 pair：

- `INFP-A`
- `INTJ-A`

原因：

- 这样能完整验证两组 base type 的双变体对照

## Step 5. 分波次迁移全部 32 型

建议波次：

### Wave 0

- `ENFJ-T`
- `ENFJ-A`
- `INFP-T`
- `INTJ-T`

### Wave 1

- `INFP-A`
- `INTJ-A`
- `INFJ-A`
- `INFJ-T`

### Wave 2

- 全部 NF：
  - `ENFJ-*`
  - `ENFP-*`
  - `INFJ-*`
  - `INFP-*`

### Wave 3

- 全部 NT：
  - `ENTJ-*`
  - `ENTP-*`
  - `INTJ-*`
  - `INTP-*`

### Wave 4

- 全部 SJ：
  - `ESFJ-*`
  - `ESTJ-*`
  - `ISFJ-*`
  - `ISTJ-*`

### Wave 5

- 全部 SP：
  - `ESFP-*`
  - `ESTP-*`
  - `ISFP-*`
  - `ISTP-*`

这么排的理由：

- 先覆盖用户关注的样例
- 再按气质组迁移，方便内容 QA 和运营复审

## 5. A/T 双变体策略

正式迁移时，A/T 必须遵循以下策略：

### 5.1 存储策略

- 每个 `type_code` 独立一条 record
- 不允许把 `ENFJ-A` / `ENFJ-T` 合并成一条基础型内容

### 5.2 共享规则

允许共享的是：

- `base_type_code`
- route alias / compare 逻辑
- 统计归类

不允许共享的是：

- 头部 headline
- AT 维度 summary/description
- premium teaser
- share 标题与摘要

### 5.3 回退规则

仅允许服务端显式降级：

- `ENFJ-T` -> `ENFJ`

但必须满足两个条件：

- 当前变体记录不存在
- 降级行为被日志记录 / 指标监控

禁止：

- 前端自行把 `ENFJ-T` 截成 `ENFJ`
- SEO/share 默认 silent fallback 到基础型

## 6. 回滚方式

回滚必须支持两层：

## 6.1 内容回滚

依赖现有 `personality_profile_revisions`：

- 每次 `upsert` 生成 revision snapshot
- 若某一波内容错误，可按 `type_code + locale` 恢复上一 revision

## 6.2 发布回滚

建议保留双版本开关：

- `schema_version = v1`
- `schema_version = mbti_result_v2`

回滚方式：

- serializer 回退只读 `v1`
- 或将新导入记录降为 draft / not public

## 6.3 全量回滚顺序

1. 先关闭新 serializer
2. 再回退 public profile 可见版本
3. 最后按 wave 逐步恢复 revision

## 7. 导入验收前置校验

每次导入前必须通过：

- 32 条记录数正确
- 每条 6 个 section 均存在
- 每条 `trait_overview.dimensions` 数量为 5
- `axis_code` 只允许 `EI/SN/TF/JP/AT`
- 每条 `growth` 有 2 个 premium teaser
- 每条 `relationships` 有 2 个 premium teaser
- `type_code` 唯一
- `slug` 唯一

## 8. 导入后的验收信号

导入完成后必须能回答：

- 32 型是否都存在？
- `ENFJ-T` 与 `ENFJ-A` 是否头部文案不同？
- `INFP-T` 是否仍保持 `INFP-T` 而不是退成 `INFP`？
- `trait_overview` 是否已全部使用 `SN/TF` canonical 轴？
- teaser 是否只显示 teaser，没有伪造 full premium？

## 9. Import Strategy 结论

附件可直接作为“文案素材源”，但不能直接作为“数据库导入格式”。

正式导入必须经过：

- 对象抽取
- 类型对齐
- 轴向标准化
- 标题规范化
- premium teaser 分流
- dry-run + pilot + 分波次迁移 + revision 回滚

否则会把一份前端对象稿再次复制成一套难维护的后端脏数据。
