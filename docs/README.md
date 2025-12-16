> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Docs Index v0.2.1
> Related Docs:
> - docs/03-stage1/README.md
> - docs/04-stage2/README.md

# FAP 文档总入口（Docs Index）

本目录是 Fermat Assessment Platform（FAP）的规范与阶段执行文档入口。
原则：**Docs-first、口径统一、可验收、可回滚**。

---

## 0) 快速导航（Start Here）

- Stage 1（V0.2-A）：中台最小骨架（规范/口径/发布/合规基础）
  - docs/03-stage1/README.md

- Stage 2（V0.2-B）：中国大陆业务闭环（测评→报告→分享→增长）
  - docs/04-stage2/README.md

---

## 1) 文档体系与目录约定

- `docs/03-stage1/`：Stage 1 产物（全局口径、API 规范、合规基础、发布清单）
- `docs/04-stage2/`：Stage 2 产物（闭环总纲、报告引擎 v1.2、漏斗、用户权益、验收剧本）
- `content_packages/`：内容资产包（可版本化、可灰度、可回滚）

---

## 2) 全局口径（所有阶段必须遵守）

### 2.1 版本号口径（最小集合）
- `scale_version`：量表/题库版本（例：`v0.2`）
- `profile_version`：32 型主档骨架版本（例：`mbti32-v2.5`）
- `content_package_version`：内容资产包版本（例：`MBTI-CN-v0.2`）
- `report_engine_version`：报告引擎版本（例：`v1.2`）
- `policy_version`：组装策略/阈值策略版本（例：`policy-v0.2-r1`）

> 约束：线上数据可回溯；升级只能“加版本”，不能覆盖旧版本的语义。

### 2.2 API 返回 Envelope 口径
所有接口统一返回：
- `ok`
- `error`
- `message`
- `data`

（详细定义见 Stage 1 API 文档）

### 2.3 命名与字段风格
- 字段命名：`snake_case`
- 时间：ISO8601（UTC 或明确时区）
- 枚举值：在 Glossary 内定义为准

---

## 3) 变更与发布规则（Docs-first）

- 任何“口径/字段/事件/版本号”变化：先改文档，再改代码。
- 内容资产变更：按发布清单走（灰度→发布→回滚）。
- 事件词典与漏斗口径：以 Stage 1 Glossary + Stage 2 Funnel Spec 为准。

---

## 4) 推荐阅读顺序（新加入项目时）

1) docs/03-stage1/README.md（先理解口径与规范）
2) docs/03-stage1/api-v0.2-spec.md（接口口径）
3) docs/04-stage2/README.md（Stage2 总纲与里程碑）
4) docs/04-stage2/mbti-report-engine-v1.2.md（报告引擎结构）
5) docs/04-stage2/analytics-stage2-funnel-spec.md（漏斗指标与事件映射）

---

## 5) 验收入口（Definition of Done）

- Stage 1 验收：见 `docs/03-stage1/README.md`
- Stage 2 验收：见 `docs/04-stage2/acceptance-playbook.md`