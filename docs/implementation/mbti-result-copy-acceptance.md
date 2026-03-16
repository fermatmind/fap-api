# MBTI 结果页文案正式上线验收清单

## 0. 验收目标

上线验收必须证明三件事：

1. 后端 canonical schema 已经成为唯一公共 authority
2. 前端已完全消费 canonical payload，而不是本地常量
3. 32 型、`-A/-T`、premium teaser、share/SEO/OG 都已经闭环

## 0.1 PR-1 实际验收范围

PR-1 还不是“正式上线验收”，本轮只验收 scaffold 是否正确落地。

PR-1 必须通过的实际检查项：

1. `MbtiPublicTypeIdentity` 对 `5` 位 `type_code` 的校验是强约束
2. `MbtiCanonicalSectionRegistry` 已定义 PR-1 要求的 canonical section keys
3. premium teaser key 的 render type 固定为 `premium_teaser`
4. `MbtiCanonicalPublicResultPayloadBuilder` 不会 silent fallback 到 base type
5. `MbtiCanonicalPublicResultPayloadBuilder` 不会默认兜底 `ENFJ-T`
6. pilot adapter 可以从当前 report-like authority 读出 internal canonical payload
7. live public route 未切换

PR-1 当前不要求通过的事项：

- `32` 型全文导入
- frontend render 接线
- share / seo / og / sitemap 切流
- personality public contract 切换

## 1. API Contract 验收

PR-1 当前不切 live API contract，因此这一章在本轮只保留“不得提前变更”的检查：

- `GET /api/v0.3/attempts/{id}/result` 不改
- `GET /api/v0.3/attempts/{id}/report` 不改
- `GET|POST /api/v0.3/attempts/{id}/share` 不改
- `GET /api/v0.3/shares/{id}` 不改
- `GET /api/v0.5/personality/{type}` 不改
- `GET /api/v0.5/personality/{type}/seo` 不改

## 1.1 必须存在的 contract

对每个目标类型，API 必须稳定输出：

- `profile`
- `sections`
- `seo_meta` 或 metadata projection
- `share` projection

### 必验字段

| 层 | 必验字段 |
| --- | --- |
| profile | `type_code`, `base_type_code`, `variant`, `title`, `hero_kicker`, `excerpt`, `tagline`, `rarity_text`, `keywords` |
| sections | `letters_intro`, `overview`, `trait_overview`, `career`, `growth`, `relationships` |
| trait_overview | `dimensions` 长度必须为 5，轴只能是 `EI/SN/TF/JP/AT` |
| premium teaser | `growth` 中 2 条，`relationships` 中 2 条 |
| share | `title`, `subtitle`, `summary`, `type_code` |
| metadata | `title`, `description`, `og`, `twitter`, `canonical` |

## 1.2 API 不允许出现的情况

- `type_code = ENFJ-T`，但 `base_type_code/variant` 缺失
- `trait_overview` 中出现 `NS` 或 `FT`
- `share` 只输出 `ENFJ`
- `sections` 缺少 `letters_intro`
- premium teaser 缺少 `title` 或 `teaser`

## 2. Frontend Render 验收

## 2.1 页面级验收

结果页必须完整显示：

- 顶部区
- 维度区
- 职业区
- 成长区
- 关系区
- premium teaser 区

## 2.2 渲染来源验收

前端必须证明：

- 页面 copy 全来自 backend payload
- 代码库内不再存在 32 型 MBTI 本地对象常量
- 页面没有本地 string fallback

验收方式：

- 代码审查
- 搜索本地常量
- network payload 对照

## 3. A/T 类型验收

最少必须覆盖以下样例：

- `ENFJ-T`
- `ENFJ-A`
- `INFP-T`
- `INTJ-T`

### 验收点

| 验收项 | 要求 |
| --- | --- |
| `ENFJ-T` vs `ENFJ-A` 顶部 headline | 必须不同 |
| `ENFJ-T` vs `ENFJ-A` `AT` 维度 summary | 必须不同 |
| `INFP-T` `type_code` | 不得被抹平成 `INFP` |
| `INTJ-T` share/SEO | 不得 silently fallback 到 `INTJ` |

## 4. Premium Teaser 验收

每个类型至少必须具备：

- `growth.motivators.teaser`
- `growth.drainers.teaser`
- `relationships.relAdvantages.teaser`
- `relationships.relRisks.teaser`

### 验收点

- teaser 文案存在
- teaser 只展示 teaser，不展示伪造 full premium 内容
- CTA 状态正确
- 未购买时可见 teaser
- 已购买时 teaser 行为与 full report 行为不冲突

## 5. Share / SEO / OG 验收

## 5.1 Share

必须验证：

- `share.title/subtitle/summary` 与主结果页同源
- `share.type_code` 保留完整变体
- `ENFJ-T` 与 `ENFJ-A` 的 share 不得完全相同

## 5.2 SEO

必须验证：

- 页面 `title` 与当前 canonical payload 对齐
- `description` 来自统一规则，而不是前端模板
- `canonical` 可追溯到 variant-aware route 规则

## 5.3 OG / Twitter

必须验证：

- `og_title` 与 `title` 同步
- `og_description` 与 `description` 同步
- 若无 override，使用 derived 默认值

## 6. 32 型覆盖率验收

## 6.1 最低覆盖率门槛

- 每个 locale 32 条 `type_code`
- 每条都有 6 个主 section
- 每条都有 4 个 premium teaser

## 6.2 覆盖率报表要求

验收时必须生成覆盖率清单：

| 指标 | 目标 |
| --- | --- |
| 32 型记录数 | 32 |
| 每型 section 数 | 6 |
| 每型维度数 | 5 |
| 每型 premium teaser 数 | 4 |
| 轴名异常数 | 0 |
| A/T 降级 fallback 次数 | 0 |

## 7. 样例截图 / Snapshot / E2E 要求

## 7.1 截图要求

至少对以下类型输出整页截图：

- `ENFJ-T`
- `ENFJ-A`
- `INFP-T`
- `INTJ-T`

每个类型至少包含 5 张关键截图：

1. 顶部区
2. 维度区
3. 职业区
4. 成长区 + premium teaser
5. 关系区 + premium teaser

## 7.2 Snapshot 要求

必须锁定以下 snapshot：

- canonical payload snapshot
- share payload snapshot
- SEO metadata snapshot

推荐至少对以下类型锁定：

- `ENFJ-T`
- `ENFJ-A`
- `INFP-T`
- `INTJ-T`

## 7.3 E2E 要求

E2E 至少覆盖：

- result page open
- 5 维度正确渲染
- premium teaser 正确显示
- share card 内容正确
- metadata 正确注入

## 8. 上线阻断条件

出现以下任一情况，禁止上线：

- 任一 type_code 被抹平成基础型
- 任一类型缺少 `letters_intro`
- 任一类型维度区出现 `NS/FT`
- 任一 premium teaser 缺失
- share / SEO / OG 与主结果页文案不同源
- 前端代码库内仍存在 32 型本地 copy 常量

## 9. 上线通过条件

只有满足以下全部条件，才算“文案可正式上线”：

- 32 型完整导入
- public serializer 稳定
- 前端只消费后端 canonical payload
- premium teaser 接线完成
- share / SEO / OG 对齐完成
- Pilot 4 型截图、snapshot、e2e 全绿

## 10. 验收结论格式

建议上线验收结论固定输出为：

```markdown
- Schema: pass / fail
- Import: pass / fail
- API contract: pass / fail
- Frontend render: pass / fail
- A/T preservation: pass / fail
- Premium teaser: pass / fail
- Share / SEO / OG: pass / fail
- 32-type coverage: pass / fail
- Screenshots: pass / fail
- Snapshot / E2E: pass / fail
```

只要有任一 `fail`，就不能宣称“正式上线可用”。
