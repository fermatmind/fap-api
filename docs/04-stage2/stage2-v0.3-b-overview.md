> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: FAP v0.3-B
> Related Docs:
> - docs/04-stage2/README.md
> - docs/04-stage2/mbti-report-engine-v1.2.md
> - docs/04-stage2/analytics-stage2-funnel-spec.md
> - docs/04-stage2/compliance-stage2-user-rights.md

# FAP：Stage 2（V0.2-B）总纲
中国大陆业务闭环（测评 → 报告 → 分享 → 增长）

## 1. 目标
让“测评-报告-分享-留存/转化”跑出稳定流量回路。
关键词：可用 + 可增长 + 可迭代。

## 2. Stage 2 的不变约束
- 前端 UI 尽量不动：前端只渲染稳定的 Report JSON，不做阈值判断/选卡逻辑。
- 内容与策略全部中台化：可配置、可版本化、可灰度、可回滚。
- 口径统一：沿用 Stage 1 的 glossary / api spec / events spec 口径（region/locale/currency/price_tier 等）。

## 3. Stage 2 的升级结论（统一升级为：FAP 动态报告引擎 v1.2）
Stage 2 的目标不变，但报告引擎必须升级为 v1.2：
- 连续光谱（五轴百分比=位置/支持强度）
- 分层叙事（Role/Strategy/Identity）
- 卡片矩阵（Explain/Behavior/Pitfall/Action）
- 弱特质处理（<55% 触发“灵活/双栖/情境化”）
- 内容图谱承接（recommended_reads）

详见：
- docs/mbti-report-engine-v1.2.md
- docs/mbti-content-package-spec.md

## 4. Stage 2 围绕四条线
1) 工程线：测评 → 结果 → 报告(v1.2 组装) → 分享 的技术闭环  
2) 内容线：朋友圈/微信群可传播的分享资产 + 深读承接  
3) 合规线：最小“用户权益通道”  
4) 数据运营线：周复盘机制 + 漏斗可视化 + 一次真实运营实验  

## 5. 里程碑（M1/M2/M3）
### M1：测评引擎 & 报告链路稳定（先跑通“骨架 + state”）
交付：
- attempts/results/events 写入稳定（事务一致性）
- result/report 返回：type_code + 五轴 percent + side/state + versions
- TypeProfile 可按 profile_version 返回
- TraitScaleConfig 生效（能稳定计算 state）

验收：见 docs/stage2-acceptance-checklist.md（M1）

### M2：分享模板上线 + 埋点闭环（千人千面体感上线）
交付：
- highlights[]（Top-2 强度轴卡片）
- identity_card（A/T 完整叙事与建议）
- 分享卡生成链路 + share_generate/share_click 事件闭环
- 用数据验证：停留、分享生成率、分享点击率的变化

验收：见 docs/stage2-acceptance-checklist.md（M2）

### M3：合规与周复盘机制到位 + 完成一次真实运营实验
交付：
- role_card + strategy_card 上线（分层叙事完成）
- borderline_note（弱特质 <55）上线
- recommended_reads[] 上线（内容图谱承接）
- 完成一次真实运营实验复盘（含对照/AB 或小圈层投放）

验收：见 docs/stage2-acceptance-checklist.md（M3）

## 6. Stage 2 的“通关剧本”
1) 准备一条内容投放（朋友圈/微信群）
2) 引导进入测评（scale_view → test_start → test_submit）
3) 结果页展示 v1.2 报告（result_view/report_view）
4) 触发分享（share_generate → share_click）
5) 周报能完整对上漏斗数据，并记录本周动作与结论

## 7. 依赖与文档索引
- 术语口径：docs/03-stage1/fap-v0.3-glossary.md（Stage 1）
- API 口径：docs/03-stage1/api-v0.3-spec.md（Stage 1）
- 内容架构：docs/mbti-report-engine-v1.2.md（Stage 2）
- 内容包规范：docs/mbti-content-package-spec.md（Stage 2）
- 事件与漏斗：docs/analytics-stage2-funnel-spec.md（Stage 2）
- 合规权益通道：docs/compliance-stage2-user-rights.md（Stage 2）
- 验收清单：docs/stage2-acceptance-checklist.md（Stage 2）