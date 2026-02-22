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

    'bootstrap' => [
        'source_root' => storage_path('app/norm_sources/big5'),
        'fallback_raw_csv' => 'content_packs/BIG5_OCEAN/v1/raw/norm_stats.csv',
        'quality_filters' => ['A', 'B'],
        'artifact_output_dir' => 'resources/norms/big5/build_artifacts',
        'hash_algo' => 'sha256',
        'sources' => [
            'johnson_osf' => [
                'locale' => 'en',
                'region' => 'GLOBAL',
                'group_id' => 'en_johnson_all_18-60',
                'gender' => 'ALL',
                'age_min' => 18,
                'age_max' => 60,
                'norms_version' => '2026Q1_bootstrap_v1',
                'source_id' => 'GLOBAL_IPIPNEO_JOHNSON_ARCHIVE',
                'source_type' => 'open_dataset',
                'status' => 'CALIBRATED',
                'published_at' => '2026-02-21T00:00:00Z',
                'input_csv' => 'johnson_osf/en_attempts.csv',
                'fallback_group_id' => 'en_johnson_all_18-60',
            ],
            'zh_cn_validation' => [
                'locale' => 'zh-CN',
                'region' => 'CN_MAINLAND',
                'group_id' => 'zh-CN_xu_all_18-60',
                'gender' => 'ALL',
                'age_min' => 18,
                'age_max' => 60,
                'norms_version' => '2026Q1_xu_v1',
                'source_id' => 'ZH_CN_IPIPNEO120_XU',
                'source_type' => 'peer_reviewed',
                'status' => 'PROVISIONAL',
                'published_at' => '2026-02-21T00:00:00Z',
                'input_csv' => 'zh_cn_validation/zh_cn_attempts.csv',
                'fallback_group_id' => 'zh-CN_xu_all_18-60',
            ],
        ],
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
