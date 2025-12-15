# FAP 文档体系与版本规则（Stage 1 / v0.2-A）

本目录（docs/）是 FAP v0.2 的“规范与口径权威源”。  
任何接口字段、事件口径、内容资产结构、发布回滚规则的变更，必须先改 docs，再改代码。

---

## 1) 文档分层：哪些是“规范源”，哪些是“说明/记录”

### 1.1 规范源（Source of Truth）
以下文件属于“规范源”，出现冲突时以它们为准：

- `docs/fap-v0.2-glossary.md`  
  领域词典：字段名、概念、口径定义（scale/attempt/result/event 等）
- `docs/api-v0.2-spec.md`  
  API 契约：路由、请求/响应结构、错误码、字段类型
- `docs/mbti-content-schema.md`  
  内容结构规范：TypeProfile、profile_version 命名、资产目录规则
- `docs/content-release-checklist.md`  
  内容发布/回滚清单：发布步骤、回滚条件、验证点
- `docs/compliance-basics.md`  
  合规最小三件套：隐私说明、数据用途、用户权益通道
- `docs/copywriting-no-go-list.md`  
  文案禁区：绝对化用语、医疗诊断、歧视与高风险表达等

### 1.2 说明/记录（Optional / Notes）
可选文档，不作为强制口径源（但建议保留）：
- `docs/weekly-ritual.md`（周复盘执行流程）
- `docs/acceptance-playbook.md`（验收剧本 / 手工测试步骤）
- `docs/deploy-basics.md`（部署说明）
- 任何 `docs/notes-*`、`docs/rfcs/*`（讨论稿、会议纪要、决策记录）

---

## 2) 版本命名规则（v0.2 / v0.2-A / v0.2-B）

### 2.1 平台版本（Platform Version）
- `v0.2`：对外 API 版本（路由前缀 `/api/v0.2`）
- `v0.2-A`：Stage 1（系统能力建设）里程碑标签
- `v0.2-B`：Stage 2（业务闭环：测评→报告→分享→增长）里程碑标签

> 说明：A/B 是“阶段里程碑”，不一定体现在 API 路由里；API 路由仍保持 v0.2。

### 2.2 量表版本（scale_version）
- `scale_version`：量表题库与评分逻辑的版本号  
- 示例：`MBTI` 的 `scale_version = v0.2`（对应 144 题 + 当前评分逻辑）

规则：
- 改题目/改评分逻辑 => 必须升 `scale_version`（例如 v0.3）
- 旧版本结果可回溯、可重算，不被新版本破坏

### 2.3 内容版本（profile_version）
- `profile_version`：解释文案/报告内容资产版本号（与题库/评分版本解耦）
- 示例：`mbti32-v2.5`、`mbti32-cn-v1`

规则：
- 改文案/改结构/改分享字段 => 升 `profile_version`
- 不改变 `scale_version` 的情况下也可以升级 `profile_version`

### 2.4 内容包版本（content_package_version）
- `content_package_version`：内容资产包的版本号（用于发布/回滚与灰度）
- 示例：`MBTI-CN-v0.2-pack.1`

规则：
- 每次内容包发布，版本 +1（pack.1 / pack.2…）
- `profile_version` 属于“对外标识”，`content_package_version` 属于“发布工单/包版本”

---

## 3) 三个版本之间的关系（速查表）

| 概念 | 字段名 | 决定什么 | 什么时候升级 | 示例 |
|---|---|---|---|---|
| API版本 | 路由前缀 | 对外接口契约 | 接口结构/契约破坏性变更 | `/api/v0.2` |
| 量表版本 | scale_version | 题库与评分逻辑 | 改题/改算分/改维度 | `v0.2` |
| 内容版本 | profile_version | 报告文案与结构 | 改文案/模块/卡片 | `mbti32-v2.5` |
| 内容包版本 | content_package_version | 发布/回滚与灰度包 | 每次发布动作 | `MBTI-CN-v0.2-pack.1` |

---

## 4) 变更流程（Docs-first）

### 4.1 改动原则
- 任何“口径、字段、契约、事件、内容结构”变更：先改 docs，再改代码
- 禁止“只改代码不改 docs”，否则视为不合格变更

### 4.2 文档改动的自检清单（PR 合并前）
改动任一规范源文档时，必须自检：

1) 字段名是否与 Glossary 一致（snake_case / enum 值一致）
2) API 请求/响应结构是否与 `api-v0.2-spec.md` 一致（ok/error/message/data）
3) 事件口径是否与 Glossary 的 event_code 定义一致
4) 版本号是否符合规则：scale_version / profile_version / content_package_version
5) 是否影响已落库数据：attempts/results/events 的兼容性是否仍然成立
6) 是否需要更新发布/回滚清单（content-release-checklist）

### 4.3 发布回滚边界（避免线上不可控）
允许“只改内容不改代码”的范围（推荐）：
- `content_packages/**`（内容资产包）
- `docs/**`（规范与说明）

需要发版/迁移的范围（谨慎）：
- `backend/**`（接口、模型、逻辑）
- `database/migrations/**`（结构变更）

---

## 5) Stage 1 完成定义（v0.2-A）
当以下条件同时满足，可认为 Stage 1 完成：
- 规范源文档齐全且互相一致（glossary / api spec / content schema / release / compliance）
- POST /attempts 与 GET /attempts/{id}/result 可跑通
- events 可落库并能拉出 7 天统计（D1 周报口径可执行）