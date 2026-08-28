<?php

declare(strict_types=1);

return [
    'contract_version' => 'career.current_authority_parity.config.v1',
    // Read-only production CONFIG GET maxmemory captured 2026-08-28.
    'redis_maxmemory_baseline_bytes' => 2_147_483_648,
    'career_budget_percent' => 80,
    'career_budget_bytes' => 1_717_986_918,
    'redis_major' => 6,
    'redis_image' => 'redis@sha256:d0c875bdacfb5c4d2c2d9124de3f53cee1dc9ceff8936bd459fabc135cb33015',
    'redis_version' => '6.2.24',
    'redis_policy' => 'noeviction',
    'cache_key_prefix' => 'career:public-authority:job-detail:v3',
];
