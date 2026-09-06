<?php

return [
    'missions' => ['GSC 数据新鲜度与运行状态', 'URL Truth、聚类去重与 D1', '隐私、Policy 与证据漂移'],
    'overview' => '每日只读检查',
    'actionable' => '待处理异常',
    'next_run' => '下次运行（UTC）',
    'source_gap' => '来源缺失或过期。展开 Trace 核对来源状态与时间；缺少必要来源时不算完成接线验收。',
    'time_unknown' => '源时间不可用',
    'sources' => ['gsc_scheduled_receipt' => 'GSC 调度回执', 'scheduled_runtime_probe' => '运行探测回执',
        'public_api_health' => '公开 API 探测', 'url_truth_reconciliation' => 'URL Truth 对账', 'issue_cluster' => '问题聚类',
        'd1_observation' => 'D1 观测', 'sitemap_observation' => 'Sitemap 缓存观测', 'private_route_negative_set' => '私有路由负向检查',
        'evidence_expiry' => '证据过期检查', 'registry_version_vector' => '版本向量', 'stored_evidence_safety' => '脱敏证据校验', 'council_tool_audit' => '工具调用审计'],
    'inspect_trace' => '检查发现异常。请打开 Trace 查看原因与来源，再到对应运营工作区处理。',
    'trace' => '查看依据',
    'states' => ['READY' => '本次检查正常', 'HOLD' => '需要检查', 'NOT_STARTED' => '尚未开始',
        'RUNNING' => '运行中', 'UNAVAILABLE' => '证据不可用', 'STALE' => '检查结果已过期'],
];
