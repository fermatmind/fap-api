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
                '{locale}_prod_{gender}_{age_band}',
                '{locale}_prod_all_{age_band}',
                '{locale}_prod_all_18-60',
                '{locale}_xu_{gender}_{age_band}',
                '{locale}_xu_all_{age_band}',
                '{locale}_xu_all_18-60',
            ],
            'en' => [
                '{locale}_prod_{gender}_{age_band}',
                '{locale}_prod_all_{age_band}',
                '{locale}_prod_all_18-60',
                '{locale}_johnson_{gender}_{age_band}',
                '{locale}_johnson_all_{age_band}',
                '{locale}_johnson_all_18-60',
            ],
        ],
        'required_groups' => [
            'en_johnson_all_18-60',
            'en_johnson_f_18-29',
            'en_johnson_m_18-29',
            'zh-CN_prod_all_18-60',
            'zh-CN_prod_f_18-29',
            'zh-CN_prod_m_18-29',
        ],
    ],

    'standardizer' => [
        'z_clamp_min' => -3.5,
        'z_clamp_max' => 3.5,
        'cdf_file' => 'resources/stats/normal_cdf_0p01.csv',
    ],

    'rolling' => [
        'publish_thresholds' => [
            'zh-CN_prod_all_18-60' => 5000,
            'en_prod_all_18-60' => 2000,
            'zh-CN_all_18-60' => 5000,
            'en_all_18-60' => 2000,
        ],
        'window_days_default' => 365,
        'publish_every_valid_samples' => 50000,
    ],

    'psychometrics' => [
        'window_days_default' => 90,
        'min_samples' => 100,
        'thresholds' => [
            'domain_alpha_warn' => 0.65,
            'facet_item_total_corr_warn' => 0.20,
        ],
    ],
];
