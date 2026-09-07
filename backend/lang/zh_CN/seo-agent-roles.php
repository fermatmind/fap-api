<?php

return [
    'title' => '专家团角色',
    'disclaimer' => '角色定义不代表独立模型正在运行；此处不提供实时任务执行状态。',
    'registry_unavailable' => '角色注册表暂不可用，无法读取角色列表。',
    'snapshot_unavailable' => 'Council 治理快照暂不可用。',
    'empty' => '注册表当前没有角色。',
    'unknown_duty' => '暂无职责展示配置，请根据角色 ID 核对注册定义。',
    'global' => ['paused' => 'Council 已暂停', 'disabled' => 'Council 未启用', 'restricted' => 'Council 受限', 'unavailable' => '状态不可用', 'read_only' => 'Council 只读计算已启用'],
    'states' => ['dormant_not_authorized' => '未授权运行', 'unknown' => '角色状态未知'],
    'roles' => [
        'orchestrator' => ['name' => '总协调者', 'duty' => '协调任务、选择审查模式并汇总建议'],
        'technical' => ['name' => '技术 SEO 专家', 'duty' => '核对后端、前端与线上搜索规则的一致性'],
        'analytics' => ['name' => '搜索分析专家', 'duty' => '分析脱敏搜索数据与效果衡量证据'],
        'quality' => ['name' => '内容质量专家', 'duty' => '审查内容、实体、声明和重复问题'],
        'research' => ['name' => '竞品研究专家', 'duty' => '分析公开竞品结构，不复制竞品正文'],
        'stability' => ['name' => '内容稳定性专家', 'duty' => '检查公开内容的运行、缓存与投影稳定性'],
        'cro' => ['name' => '转化优化专家', 'duty' => '分析聚合漏斗并提出转化优化建议'],
        'reviewer' => ['name' => '独立审查者', 'duty' => '独立复核证据、策略与安全边界'],
        'career' => ['name' => '职业内容候选 Agent', 'duty' => '生成限定范围的职业内容候选，不负责发布'],
    ],
];
