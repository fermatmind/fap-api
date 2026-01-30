<?php

return [
    'answer_rows_write_mode' => env('ANSWER_ROWS_WRITE_MODE', 'off'),
    'draft_ttl_days' => env('DRAFT_TTL_DAYS', 14),
    'draft_cache_store' => env('DRAFT_CACHE_STORE', ''),

    'archive_driver' => env('ARCHIVE_DRIVER', 'file'),
    'archive_bucket' => env('ARCHIVE_BUCKET', ''),
    'archive_prefix' => env('ARCHIVE_PREFIX', 'archives'),
    'archive_path' => env('ARCHIVE_PATH', storage_path('app/archives')),
];
