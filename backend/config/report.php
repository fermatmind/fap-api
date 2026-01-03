<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Share Click / Share Resolve report whitelist
    |--------------------------------------------------------------------------
    | 只透传前端真正需要的 report 顶层 keys（123test 风格：最小闭包）
    */
    'share_report_allowed_keys' => [
        'scores',
        'highlights',
        '_meta',
        '_explain', // 是否实际对外暴露由 expose_explain / policy 决定
    ],

    /*
    |--------------------------------------------------------------------------
    | Expose _explain in production?
    |--------------------------------------------------------------------------
    | 线上建议默认 false；需要时再开。
    */
    'expose_explain' => (bool) env('RE_EXPLAIN_PAYLOAD', false),

    /*
    |--------------------------------------------------------------------------
    | Highlights (Strength / Blindspot / Action)
    |--------------------------------------------------------------------------
    | 说明：
    | - 这里给的是“默认值”（代码层兜底）
    | - 运行时允许被 content_packages 里的 report_highlights_policy.json 覆盖（你接入 loader 后生效）
    | - assets.* 用来告诉代码：policy/pools/rules 这三个文件名叫什么
    */
    'highlights' => [
        // 总开关：关掉就不产出 highlights（或返回空数组，由你的实现决定）
        'enabled' => (bool) env('REPORT_HIGHLIGHTS_ENABLED', true),

        // 总条数（默认 3~4）
        'min_total' => (int) env('REPORT_HIGHLIGHTS_MIN_TOTAL', 3),
        'max_total' => (int) env('REPORT_HIGHLIGHTS_MAX_TOTAL', 4),

        // 每个 pool 的 min/max（默认 strength 最多 2）
        'per_pool' => [
            'strength' => [
                'min' => (int) env('REPORT_HIGHLIGHTS_STRENGTH_MIN', 1),
                'max' => (int) env('REPORT_HIGHLIGHTS_STRENGTH_MAX', 2),
            ],
            'blindspot' => [
                'min' => (int) env('REPORT_HIGHLIGHTS_BLINDSPOT_MIN', 1),
                'max' => (int) env('REPORT_HIGHLIGHTS_BLINDSPOT_MAX', 1),
            ],
            'action' => [
                'min' => (int) env('REPORT_HIGHLIGHTS_ACTION_MIN', 1),
                'max' => (int) env('REPORT_HIGHLIGHTS_ACTION_MAX', 1),
            ],
        ],

        // 是否允许对外暴露 explain（最终是否输出还要看 report.expose_explain）
        'should_expose_explain' => (bool) env('REPORT_HIGHLIGHTS_EXPOSE_EXPLAIN', false),

        // content_packages 里的三个资产文件名（与你创建的文件一致）
        'assets' => [
            'policy' => env('REPORT_HIGHLIGHTS_POLICY_FILE', 'report_highlights_policy.json'),
            'pools'  => env('REPORT_HIGHLIGHTS_POOLS_FILE', 'report_highlights_pools.json'),
            'rules'  => env('REPORT_HIGHLIGHTS_RULES_FILE', 'report_highlights_rules.json'),
        ],

        // 产出层的一些策略默认值（同样可被 policy 覆盖）
        'dedupe' => [
            'by_template_id' => true,
            'by_group_id'    => true,
        ],

        // 当规则没选够时的补齐策略（实现时会用到）
        'fill_strategy' => [
            'primary' => 'rules',
            'secondary' => ['tag_match', 'fallback_ids'],
            'tag_match_min_overlap' => (int) env('REPORT_HIGHLIGHTS_TAG_MATCH_MIN_OVERLAP', 1),
        ],
    ],

];