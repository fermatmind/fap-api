<?php

return [
    'resolver' => [
        'default_age_band' => '18-60',
        'age_bands' => [
            '18-29' => ['min' => 18, 'max' => 29],
            '30-44' => ['min' => 30, 'max' => 44],
            '45-60' => ['min' => 45, 'max' => 60],
        ],
        'chains' => [
            'zh-CN' => [
                '{locale}_{gender}_{age_band}',
                '{locale}_all_{age_band}',
                '{locale}_all_18-60',
                '{locale}_bootstrap_all_18-60',
            ],
            'en' => [
                '{locale}_{gender}_{age_band}',
                '{locale}_all_{age_band}',
                '{locale}_all_18-60',
                '{locale}_bootstrap_all_18-60',
            ],
        ],
    ],

    'rolling' => [
        'window_days_default' => 365,
        'min_samples_default' => 1000,
        'quality_levels_default' => ['A', 'B'],
        'source_id' => 'FERMATMIND_SDS20_PROD_ROLLING',
    ],

    'drift' => [
        'threshold_mean' => 3.0,
        'threshold_sd' => 3.0,
    ],

    'psychometrics' => [
        'window_days_default' => 90,
        'min_samples' => 100,
        'thresholds' => [
            'crisis_rate_warn' => 0.05,
            'quality_c_or_worse_warn' => 0.25,
        ],
    ],
];
