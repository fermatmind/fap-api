<?php

return [
    'salt' => env('FAP_EXPERIMENTS_SALT', 'pr23_sticky_bucket_v1'),
    'experiments' => [
        'PR23_STICKY_BUCKET' => [
            'is_active' => true,
            'variants' => [
                'A' => 50,
                'B' => 50,
            ],
        ],
    ],
];
