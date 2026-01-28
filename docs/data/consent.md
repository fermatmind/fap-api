# 数据授权与同意（Consent v0.1）

## consent_version
- 当前版本：`v0.1`
- 记录位置：`integrations.consent_version`
- 变更策略：如需升级，新增版本号并在 providers 文档中说明。

## 用途声明（最小可执行）
- 用途：为用户生成个人数据洞察与趋势统计。
- 处理范围：sleep / health / screen_time 样本。
- 非用途：不用于广告画像；不外部分享原始数据。

## 留存策略（最小可执行）
- 原始样本保留 365 天（后续可配置）。
- 聚合结果保留 2 年。
- 用户撤回后：停止写入 + 标记 revoked_at。

## 撤回流程
1) `POST /api/v0.2/integrations/{provider}/revoke`
2) integrations.status = revoked，写 revoked_at
3) 后续 PR 可增加数据删除与导出任务
