<?php

declare(strict_types=1);

return [
    'title' => '娱乐测试统计',
    'note' => '仅汇总全站公开娱乐测试中同意统计的访客动作，按测试、语言和玩法角色分组；不随顶部组织切换而改变。这里是动作次数，不是去重人数或正式量表成功次数。不记录私人答案、分数、昵称、出生资料或邀请链接。日期采用北京时间，单次最多90天。从本次接入起计数，不倒造缺失历史。',
    'from' => '开始日期',
    'to' => '结束日期',
    'test' => '测试',
    'language' => '语言',
    'role' => '玩法角色',
    'all' => '全部',
    'apply' => '应用',
    'previous' => '上一页',
    'next' => '下一页',
    'range_limit' => '请选择不超过90天的日期范围。',
    'unavailable' => '统计存储暂不可用，不能确认次数。',
    'coverage' => '已登记 :tests 项测试 · :rows 条语言/角色记录。0表示未观察到符合条件的动作，不代表没有访客。',
    'no_rows' => '没有符合筛选条件的已登记测试。',
    'metrics' => [
        'fun_start' => '开始次数',
        'fun_complete' => '完成次数',
        'fun_result_view' => '结果查看次数',
        'fun_share' => '分享动作次数',
    ],
    'columns' => [
        'test' => '测试',
        'language' => '语言',
        'role' => '玩法角色',
        'start' => '开始',
        'complete' => '完成',
        'view' => '结果查看',
        'share' => '分享动作',
        'methods' => '分享动作明细',
        'version' => '已观察到的内容版本',
        'privacy' => '采集约定',
    ],
    'roles' => [
        'single' => '普通单人测试',
        'creator' => '朋友默契·创建者',
        'friend' => '朋友默契·好友',
        'solo' => '朋友默契·单人',
    ],
    'methods' => [
        'copy' => '复制成功',
        'system' => '系统分享完成',
        'image' => '图片开始下载',
        'link_open' => '分享入口打开（意向）',
    ],
    'privacy' => [
        'analytics_consent' => '遵循全站分析同意',
        'explicit_opt_in' => '默认关闭，另需主动同意匿名次数统计',
    ],
];
