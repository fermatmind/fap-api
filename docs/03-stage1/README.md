> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Stage 1 (V0.2-A)
> Related Docs:
> - docs/04-stage2/README.md
> - docs/03-stage1/api-v0.2-spec.md
> - docs/03-stage1/fap-v0.2-glossary.md
> - docs/03-stage1/content-release-checklist.md
> - docs/03-stage1/compliance-basics.md
> - docs/03-stage1/copywriting-no-go-list.md

# Stage 1 (V0.2-A) — 中台最小骨架（规范 + 口径 + 最小数据闭环）

目标：让系统“可控、可记账、可发布、可复盘”。
一句话：用最小规范 + 最小数据闭环，让工程 / 内容 / 合规 / 数据运营能第一次一起跑。

---

## 1. Stage 1 定义（范围与产出）

Stage 1 只做“中台可长期复用的底座”，不追求完整业务功能。
主要产出：
- 统一术语与口径（对象、字段、版本、区域/语言预留）
- API 规范（v0.2 接口风格与 envelope）
- 内容发布与回滚机制（内容资产版本化）
- 合规基础与文案禁区（最小可上线口径）

---

## 2. 文档地图（Doc Map）

### 2.1 规范与口径
- API 规范：docs/03-stage1/api-v0.2-spec.md
- 术语词典：docs/03-stage1/fap-v0.2-glossary.md

### 2.2 内容发布与质量门禁
- 内容发布清单：docs/03-stage1/content-release-checklist.md
- 文案禁区清单：docs/03-stage1/copywriting-no-go-list.md

### 2.3 合规基础
- 合规基础（CN 最小口径）：docs/03-stage1/compliance-basics.md

---

## 3. 关键口径（必须统一的 4 件事）

1) 版本与路径口径  
- API 基础路径：/api/v0.2/...
- 内容包版本：例如 MBTI-CN-v0.2
- profile_version / content_version：必须可回溯、可灰度、可回滚

2) region / locale 预留位  
- region：CN_MAINLAND（未来扩 HK/TW/SG/GLOBAL）
- locale：zh-CN（未来扩 en-US/ja-JP）
> 即使当前只做 CN，也必须把字段口径写进规范。

3) 统一响应结构（Envelope）  
- ok / error / message / data
> 所有接口都必须对齐 api-v0.2-spec 的定义。

4) 事件与统计口径（为 Stage 2 漏斗/周报铺路）  
- 事件名、字段名、source/channel 的口径必须在 Stage 1 先固定。

---

## 4. 验收清单（Stage 1 Done Definition）

满足以下条件即可认为 Stage 1 完成：
- [ ] docs/03-stage1/ 下的规范文档齐全且可读
- [ ] API v0.2 的 envelope/错误码/路径风格在文档中明确
- [ ] 术语口径统一（Attempt/Result/Report/ShareAsset 等）
- [ ] 内容发布具备：版本号、灰度、回滚、检查清单
- [ ] 合规基础具备：隐私/免责声明/未成年人提示的最小口径
- [ ] Stage 2 的文档均引用 Stage 1 的规范路径（无旧路径残留）

---

## 5. 与 Stage 2 的衔接（Stage 2 依赖 Stage 1 什么）

Stage 2（V0.2-B）依赖 Stage 1：
- API 规范：docs/03-stage1/api-v0.2-spec.md
- 术语口径：docs/03-stage1/fap-v0.2-glossary.md
- 内容发布/回滚：docs/03-stage1/content-release-checklist.md
- 合规基础与禁区：docs/03-stage1/compliance-basics.md / copywriting-no-go-list.md

Stage 2 的索引见：
- docs/04-stage2/README.md