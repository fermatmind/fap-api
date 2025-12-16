> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Repo README v0.2.1
> Related Docs:
> - docs/README.md
> - docs/03-stage1/README.md
> - docs/04-stage2/README.md

# FAP API v0.2 · Skeleton (MBTI 最小中台骨架)

> Fermat Assessment Platform · 中台 API 仓库（设计 & 骨架阶段）  
> 一句话：这里是「费马测评中台」的后端大脑，对外提供 `/api/v0.2/...`

---

## 0. Quick Links（先看这里）

- 文档总入口：`docs/README.md`
- Stage 1（V0.2-A）索引：`docs/03-stage1/README.md`
- Stage 2（V0.2-B）索引：`docs/04-stage2/README.md`

如果你只做一件事：  
**先按 Stage 1 的规范把接口/术语/事件口径对齐，再按 Stage 2 跑通“测评→报告→分享→增长”的闭环。**

---

## 1. 当前阶段（Stage 2 · Skeleton-level）

当前进度：**仅到“设计 + 文档骨架”，尚未进入完整后端实现。**

已完成 / 进行中：

- ✅ 独立 API 仓库：`fap-api`
- ✅ 服务定位明确：只做「数据 + 业务规则」，不渲染页面
- ✅ 参考 `fap-specs`（规范仓库），开始按模块沉淀设计：
  - API 端点设计（01-api-design）
  - DB 结构草图（02-db-design）
  - 环境与配置策略（03-env-config）
  - 埋点与数据运营方案（04-analytics）
- ⏳ 还未开始：
  - Laravel 项目正式初始化与跑通
  - 路由 / 控制器 / 数据库存储实现

> 说明：这个 README 目前是“设计阶段版”。当 Laravel 代码真正落地后，这里会补齐安装、运行、部署与验收步骤。

---

## 2. 仓库定位 / Repo Purpose

这个仓库专门用来承载：

- **FAP v0.2 的中台 API 服务设计 + 实现**
- **只做「数据 + 业务规则」，不渲染页面**
- 当前阶段只服务 **MBTI 测评**，后续会扩展到 IQ / 情感 / 职业等量表

---

## 3. 与 `fap-specs` 的关系（口径优先级）

- `fap-specs`：**中台宪法（规范）**
  - 领域模型、API 规范、事件词典、内容规范、合规模板、KPI 模板
- `fap-api`：**中台实现（工程落地）**
  - 根据 `fap-specs` 落地 API、DB、环境、埋点与拉数方案

约定（强制）：

> 任何接口 / DB 字段 / 事件命名，如果与 `fap-specs` 冲突：  
> **以 `fap-specs` 为准**，本仓库需要回头修正。

---

## 4. 预期技术栈 / Planned Tech Stack

后端框架（推荐）：

- Language: PHP 8.3+
- Framework: Laravel 10/11/12（与你现有生态一致）
- Web Server: Nginx
- DB: MySQL
- Cache & Queue: Redis

为什么现在就写在 README：

- 即使还没 `laravel new`，也先把默认技术栈写清楚  
- 未来外包 / 合作者一看 README 就知道用什么

---

## 5. 当前目录结构（文档骨架）

> 注：下面是“Stage 2 文档期”的目录结构（尚未包含完整 Laravel 业务代码目录）。

```txt
fap-api/
  README.md

  content_packages/
    ...                           # 内容资产包（后续会用于报告引擎与版本化）

  docs/
    03-stage1/
      README.md
      api-v0.2-spec.md
      fap-v0.2-glossary.md
      content-release-checklist.md
      compliance-basics.md
      copywriting-no-go-list.md

    04-stage2/
      README.md
      stage2-v0.2-b-overview.md
      mbti-report-engine-v1.2.md
      mbti-content-schema.md
      mbti-content-package-spec.md
      analytics-stage2-funnel-spec.md
      compliance-stage2-user-rights.md
      event-responsibility-matrix.md
      acceptance-playbook.md

  00-plan/
    Stage2-skeleton-checklist.md   # 阶段 2 骨架验收清单（打勾用）

  01-api-design/
    MBTI-v0.2-endpoints.md         # MBTI v0.2 具体接口设计草稿

  02-db-design/
    mbti-v0.2-schema.md            # MBTI 最小 DB 表结构草图（attempts/results/events）

  03-env-config/
    env-strategy.md                # local / staging / production 环境与配置边界策略

  04-analytics/
    mbti-v0.2-event-mapping.md     # A3 事件词典 → events 表/日志字段映射
    D1-mapping-from-events.md      # D1 KPI 从 events/表 的计算口径
    weekly-ritual.md               # 每周固定复盘流程模板