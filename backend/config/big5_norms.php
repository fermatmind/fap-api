<?php

return [
    'resolver' => [
        'default_age_band' => '18-60',
        'chains' => [
            'zh-CN' => [
                '{locale}_prod_{gender}_{age_band}',
                '{locale}_prod_all_18-60',
                '{locale}_xu_{gender}_{age_band}',
                '{locale}_xu_all_18-60',
            ],
            'en' => [
                '{locale}_prod_{gender}_{age_band}',
                '{locale}_prod_all_18-60',
                '{locale}_johnson_{gender}_{age_band}',
                '{locale}_johnson_all_18-60',
            ],
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
];
