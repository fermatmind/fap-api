# Self-check Gate: landing meta (meta/landing.json)

目的：把 SEO/GEO Meta 变成“内容包能力”，随 pack version 版本化、可回滚、可灰度；并用 self-check 在发布前强制拦截缺字段/错格式。

---

## 1) Meta 归属与固定落点（Contract）

### 1.1 文件位置（固定）
每个 content pack（pack root）必须使用固定路径：

- `meta/landing.json`

示例（MBTI pack）：
- `content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2/meta/landing.json`

说明：
- meta 文件必须随 `content_package_version` 一起变更与发布
- URL 不携带版本号；版本选择由后端/配置/灰度策略决定

---

## 2) Gate 行为（FAIL / WARN）

### 2.1 什么时候 FAIL（阻断发布）
满足任意条件即 FAIL（exit code != 0）：

1. **文件缺失**：pack root 下找不到 `meta/landing.json`
2. **JSON 非法**：无法解析为 JSON object
3. **schema/version 不合法**：
   - `schema` 必须为：`fap.landing.meta.v1`
   - `schema_version` 必须为：`1`
4. **必填字段缺失**（见第 3 节 Required Fields）
5. **关键字段格式非法**（见第 4 节 Format Rules）

### 2.2 什么时候 WARN（不阻断）
- FAQ“问题句式”质量提示：如果 `faq_list` 的问题都不包含常见疑问词（例如：什么/为什么/准吗/如何/多久/免费/隐私/公开），输出 WARNING 但不 fail。

---

## 3) Required Fields（必填字段）

> 说明：字段名以 `meta/landing.json` 为准；字段缺失直接 FAIL。

### 3.1 顶层必填
- `schema`
- `schema_version`
- `scale_code`
- `pack_id`
- `locale`
- `region`
- `slug`
- `last_updated`
- `index_policy`
- `canonical`
- `seo_title`
- `seo_description`
- `seo_keywords`
- `executive_summary`
- `data_snippet`
- `faq_list`
- `open_graph`
- `share_abstract`
- `schema_outputs`

### 3.2 index_policy 必填
- `index_policy.landing`（含 `index/follow`）
- `index_policy.take`（含 `index/follow/nocache`）
- `index_policy.result`（含 `index/follow/nocache`）
- `index_policy.share`（含 `index/follow/nocache`）

### 3.3 canonical 必填
- `canonical.canonical_path`
- `canonical.canonical_slug`

### 3.4 data_snippet 必填
- `data_snippet.h1_title`
- `data_snippet.intro`
- `data_snippet.table`
  - `caption`
  - `columns`（2 列）
  - `rows`（>= 1）
- `data_snippet.cta.primary.text`
- `data_snippet.cta.primary.href`

### 3.5 faq_list 必填
- `faq_list` 数组长度 **>= 3**
- 每条 FAQ 必须包含：
  - `question`（非空）
  - `answer`（非空）

### 3.6 open_graph 必填
- `open_graph.og_title`
- `open_graph.og_description`
- `open_graph.og_image`
- `open_graph.og_type`

---

## 4) Format Rules（关键格式规则）

> 说明：这些是“容易让 SEO 权重发散/失效”的高危点，全部按 FAIL 处理。

### 4.1 canonical_path 必须是相对路径
- ✅ 允许：`/test/personality-mbti-test`
- ❌ 禁止：`https://...` / `http://...` / `//...`
- ❌ 禁止：包含 query/hash（`?utm=` `#xxx`）

### 4.2 variants（题量版本）要求
- `variants` 数组 **必须存在且长度 >= 1**
- 每个 variant 必须包含：
  - `variant_code`（非空）
  - `label_zh`（非空）
  - `question_count`（>0）
  - `test_time_minutes`（非空）

> 备注：如果你的落地页要对齐“三档题量”，建议要求 `variants` 至少包含 24/93/144 三档；但 Stage 2 gate 先做最小：>=1 条即可（防止阻断）。

### 4.3 表格必须包含“三档并列”事实行（MBTI gate）
为了确保 Web 落地页与 meta 一致，MBTI pack 的 table.rows 必须包含两行关键事实（用“包含关系”判断即可）：

- 行 1：包含关键词 `题量（3档）`
- 行 2：包含关键词 `预计用时（3档）`

示例（期望出现在 rows 的第 1 列）：
- `["题量（3档）", "..."]`
- `["预计用时（3档）", "..."]`

### 4.4 FAQ 数量与基础格式
- `faq_list.length >= 3`（否则 FAIL）
- 每条 question/answer 非空（否则 FAIL）

### 4.5 SEO 字段基础约束（最小）
- `seo_title` 非空
- `seo_description` 非空
- `seo_keywords` 为数组且长度 >= 1

---

## 5) 命令与输出（示例）

### 5.1 运行方式
在 repo 的 `backend/` 下执行：

```bash
php artisan fap:self-check --pkg=default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2
echo $?
```

### 5.2 PASS 样例（节选）
```text
✅ meta/landing.json (landing meta gate)
  - OK (landing meta gate passed)
...
✅ SELF-CHECK PASSED
0
```

### 5.3 FAIL 样例（缺字段 / 格式非法）
```text
❌ meta/landing.json (landing meta gate)
  - ERR pack=MBTI... file=.../meta/landing.json path=$.seo_title :: missing required field
  - ERR pack=MBTI... file=.../meta/landing.json path=$.canonical.canonical_path :: must be relative path like /test/<slug>, got=https://...
  - ERR pack=MBTI... file=.../meta/landing.json path=$.faq_list :: must have >= 3 items, got=2
  - ERR pack=MBTI... file=.../meta/landing.json path=$.data_snippet.table.rows :: must contain row label '题量（3档）'
  - ERR pack=MBTI... file=.../meta/landing.json path=$.data_snippet.table.rows :: must contain row label '预计用时（3档）'
------------------------------------------------------------------------
❌ SELF-CHECK FAILED (see errors above)
1
```

### 5.4 WARN 样例（FAQ 句式提示，不阻断）
```text
✅ meta/landing.json (landing meta gate)
  - OK (required fields + formats)
  - WARN faq_list questions do not contain interrogatives (e.g. 什么/为什么/准吗/如何/多久/免费/隐私/公开) -> consider rewriting for SEO PAA
```

---

## 6) 验收清单（PR Review Checklist）

- [ ] pack 下存在 `meta/landing.json`
- [ ] `fap:self-check` 能定位该文件并校验通过
- [ ] 缺字段/格式非法时 `fap:self-check` **FAIL** 且 `exit code != 0`
- [ ] FAQ “疑问句式”缺失时输出 **WARN**（不阻断）
- [ ] PR 描述包含：规则摘要 + PASS/FAIL 日志样例