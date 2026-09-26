# SEO Council 运营交接规则

本规则用于现有 Ops Agent Council、SEO 工作台、Mission、自然周卡和发布流水线。它不启用新的任务、模型、工具、通知、业务写入或自动发布权限。以当时生产 `REVISION`、正式回执和页面读回为准；历史演示、受控验收和当前排序预览不能代替自然运行。

## 责任与输入

| 责任方 | 当前职责 | 交接边界 |
| --- | --- | --- |
| M1：GSC 数据与运行 | 按已授权自然调度只读核 GSC 来源、完整窗口、数据质量和运行状态。 | 输出来源时间、数据最大日期、正式回执及 HOLD 原因；数据未到不填零、不补同步。 |
| M2：URL Truth 与 D1 | 对账公开 URL 身份、权威数量、聚类去重和 D1 观察。 | 输出实际分母、差异及对应权威版本；缺少来源时保持 HOLD，不用历史数量替代。 |
| M3：隐私与 Policy | 核证据过期、私有路径负向检查、权限和版本漂移。 | 安全或权限异常立即交唯一运营者处理；Council 本身不修复、不开放工具。 |
| 自然周卡 | 周四 13:45 UTC（北京时间 21:45）运行确定性发现、校验、去重和选择。 | 仅写 SEO 规划记录。每次最多创建或刷新 5 张卡，正式选择默认最多 3 张；不足不补。 |
| Codex | 复核来源、页面和产品事实，提出单页假设；获授权的仓库改动走现有精确 SHA 流水线。 | 分析不能冒充 Council 回执；不得代替内容权威、人工审校或特定发布确认。 |
| Pro | 提供专家意见和反例检查。 | 建议须由当前权威和数据重新核实，不是生产事实、权限或发布授权。 |
| 唯一运营者 | 选择、暂缓或否决候选；对需人工审校、受控内容操作和后续复盘负责。 | 决定绑定精确目标和版本；没有正式写入口时只记“暂存／待正式写入”，不得称已入库。 |

## 每日、每周、每月交接

1. **每日按既有调度看异常。** 先确认生产 `REVISION`、调度授权、`latest_natural` 与 `latest_controlled` 的时间和回执哈希，再看 M1/M2/M3 的 `execution_state`、`business_result` 与 Trace。执行失败、积压或证据不可读与已执行但业务 HOLD 分开记录；预期 GSC 延迟等待下一自然窗口。生产事故、安全泄露、权限越界和版本读回不一致立即升级给对应原任务及唯一运营者。
2. **每周只在真实 scheduled 周卡后选择。** 核两类周回执的 schema、hash、ISO 周、`scheduled_for`、实际 release SHA、`generation_summary` 和正式选中版本；逐项区分 `scan_rows`、信号候选、按 `canonical_url_hash|locale` 去重的页面、证据校验、合格页、创建/刷新卡、HOLD、上限未处理页。`current_ranking_preview` 只是预览，不能当成本周正式选择。零张且双回执合法时记“自然运行完成、出卡链未证明”，查过滤原因，等下一自然窗口。
3. **人工复核并交接。** 同一 URL 与语言的 MAP 和周卡候选合并；只接受公开合格目标和新鲜、完整、可比的正式来源。对每项记录来源版本、目标 URL/语言/页面族/正文权威版本、证据哈希、卡及 `selection_revision`、ledger/current pointer、建议动作、成本/风险、责任人、到期时间和“执行／暂缓／否决”理由。原始查询留在授权私有证据中。执行候选仍须单独通过内容权威、页面资格和发布门禁；规划卡的 `read_only_review` 与 `execution_allowed=false` 不授权发布。
4. **每月及每批成熟窗口复盘。** 保留实际发布 SHA 和 D0；仅在完整 D7、D14、D28 数据到达后按同口径比较页面与查询指标，记录同期发布和测量变更。选择保持、扩大、调整、延长观察或停止，并核事实是否过期。未发布的卡没有 D0，未成熟的数据没有效果结论。

交接状态最少包含：`未开始／进行中／待自然窗口／HOLD／已验收／条件未触发`、证据链接及时间、责任方、下一触发条件。`HOLD` 须写明缺少的来源或门禁；运行失败须保留失败 job/step 与正式回执。积压按可执行性、证据到期和风险排序；不因候选数量或预览分数绕过人工决定。四个自然周后逐窗复核运行、零卡、HOLD、失败和交接积压；四周无卡不能证明“周卡→发布→增长”。

## 当前权限和实现锚点

- `backend/bootstrap/app.php` 定义周卡自然窗口及 Council 调度；`backend/config/app.php` 的应用时区为 UTC。Council Mission 是否调度仍受 `seo_council.scheduler_enabled` 控制。
- `backend/config/seo_council.php` 默认关闭模型、Tool Broker 和通知；`SeoWeeklyPlanningReadService` 的权限投影将自然规划写与 GET/模型/工具/发布/实验/搜索提交分开。
- `SeoOpportunityCardGenerator` 定义来源门禁、去重键、最多 5 张卡和不可执行建议；`SeoWeeklyDecisionSelector` 定义默认 3 张及 `verified_zero`；`SeoWeeklyDecisionReceiptValidator` 与 `SeoWeeklyPlanningReadService` 验证正式双回执、卡版本和 ledger 后才展示“本周已选择”。
- `Platform12SystemHealthReadService` 将自然/受控证据、执行状态、业务结果和下一步分列。Ops 页面只读展示这些字段；本规则不能替代现场回执或生产权限核验。
