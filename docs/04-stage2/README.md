# Stage 2 Overview (FAP v0.2-B) — CN Mainland Business Loop

> Status: Active  
> Owner: liufuwei  
> Last Updated: 2025-12-16  
> Version: FAP v0.2-B  
> Related Docs:
> - docs/04-stage2/README.md
> - docs/04-stage2/mbti-report-engine-v1.2.md
> - docs/04-stage2/analytics-stage2-funnel-spec.md
> - docs/04-stage2/compliance-stage2-user-rights.md
> - docs/04-stage2/stage2-acceptance-playbook.md

## 1. Goal

跑通“测评 → 报告 → 分享 → 增长”的稳定回路。  
关键词：可用 + 可增长 + 可迭代。

Stage 2 的报告引擎统一升级为：**FAP 动态报告引擎 v1.2**  
（连续光谱 + 分层叙事 + 卡片矩阵 + 弱特质处理 + 内容图谱承接）。

## 2. Scope (4 Lines)

1) 工程线：测评 → 结果 → 分享的技术闭环  
2) 内容线：朋友圈 / 微信群可传播的分享资产（内容包可版本化）  
3) 合规线：最小“用户权益通道”（先人工流程）  
4) 数据运营线：周复盘机制 + 漏斗可视化（events → 指标）

## 3. Milestones (M1/M2/M3)

### M1 — 测评引擎 & 报告链路稳定

- 拉题 → 提交 → 结果可复访（attempt_id）稳定
- 后端返回：type_code + 五轴 percent + side/state + versions
- 只要求：TypeProfile + TraitScaleConfig（能算 state）先跑通

### M2 — 分享模板上线 + 埋点闭环

- 分享卡字段协议上线（/share/{attempt_id} or 等价）
- 上线 v1.2 的“千人千面体感最小集”：
  - highlights[]（Top-2 强度轴卡）
  - identity_card（A/T）
- 事件闭环：share_generate / share_click 可统计

### M3 — 合规与周复盘机制到位 + 完成一次真实运营实验

- role_card + strategy_card + borderline_note 上线
- recommended_reads[]（内容图谱承接）上线（可简版）
- 做一次运营实验并复盘（周报模板 + 结论）

## 4. Document Map (What to read)

### Stage 2 总纲

- docs/04-stage2/README.md
- docs/04-stage2/stage2-v0.2-b-overview.md

### 报告引擎（v1.2）

- docs/04-stage2/mbti-report-engine-v1.2.md
- docs/04-stage2/mbti-content-package-spec.md
- docs/04-stage2/mbti-content-schema.md

### API / 事件 / 数据

- docs/03-stage1/api-v0.2-spec.md  
  - Note: Stage 2 的接口、字段、envelope 口径以该 Spec 为准
- docs/04-stage2/event-responsibility-matrix.md
- docs/04-stage2/analytics-stage2-funnel-spec.md

### 合规

- docs/04-stage2/compliance-stage2-user-rights.md
- docs/03-stage1/compliance-basics.md  
  - Note: 全局合规基础（数据最小化、用途限定、可删除/可导出等原则）

### 发布与验收

- docs/03-stage1/content-release-checklist.md  
  - Note: 内容资产发布/回滚/灰度的全局检查清单
- docs/04-stage2/stage2-acceptance-playbook.md

## 5. Definitions (Quick)

- **Report JSON**：前端只渲染，不做阈值/选择逻辑  
- **Content Package**：TypeProfile / AxisDynamics / LayerProfiles / Policy 等可版本化资产集合  
- **State**：五轴 percent 映射后的状态（very_weak...very_strong），用于选卡与语气控制