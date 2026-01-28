<?php

return [
    'enabled' => env('AGENT_ENABLED', false),
    'queue' => env('AGENT_QUEUE', 'agent'),

    'quiet_hours' => [
        'start' => env('AGENT_QUIET_HOURS_START', '22:00'),
        'end' => env('AGENT_QUIET_HOURS_END', '07:00'),
        'timezone' => env('AGENT_QUIET_HOURS_TZ', 'UTC'),
    ],

    'max_messages_per_day' => (int) env('AGENT_MAX_MESSAGES_PER_DAY', 2),
    'cooldown_minutes' => (int) env('AGENT_COOLDOWN_MINUTES', 240),

    'channels' => [
        'in_app' => true,
        'email' => env('AGENT_EMAIL_ENABLED', false),
        'webhook' => env('AGENT_WEBHOOK_ENABLED', false),
    ],

    'breaker' => [
        'fail_open' => env('AGENT_FAIL_OPEN', false),
        'suppress_on_budget_exceeded' => env('AGENT_SUPPRESS_ON_BUDGET', true),
    ],

    'risk_templates' => [
        'high' => [
            'title' => '我们注意到你可能需要支持',
            'body' => '如果你正在经历困难时刻，请考虑联系可信赖的人或专业支持。你并不孤单。',
        ],
    ],

    'triggers' => [
        'sleep_volatility' => [
            'days' => (int) env('AGENT_SLEEP_VOL_DAYS', 7),
            'stddev_threshold' => (float) env('AGENT_SLEEP_VOL_STDDEV', 1.5),
        ],
        'low_mood_streak' => [
            'days' => (int) env('AGENT_MOOD_STREAK_DAYS', 5),
            'min_score' => (float) env('AGENT_MOOD_MIN_SCORE', 2.0),
        ],
        'no_activity' => [
            'days' => (int) env('AGENT_NO_ACTIVITY_DAYS', 5),
        ],
    ],

    'budgets' => [
        'subject' => 'agent_message',
        'default_cost' => (float) env('AGENT_DEFAULT_COST_USD', 0.001),
    ],
];
