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
        '_explain', // 是否实际对外暴露由 shouldExposeExplain 决定
    ],

    /*
    |--------------------------------------------------------------------------
    | Expose _explain in production?
    |--------------------------------------------------------------------------
    | 线上建议默认 false；需要时再开。
    */
    'expose_explain' => env('RE_EXPLAIN_PAYLOAD', false),

];