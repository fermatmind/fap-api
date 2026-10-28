<?php

declare(strict_types=1);

return [
    'title' => 'Fun quiz statistics',
    'note' => 'Anonymous activity counts for consenting visitors, grouped by public test, language and play role. These are actions, not unique people or clinical assessment completions. Private answers, scores, names, dates of birth and invitation links are never recorded. Asia/Shanghai calendar dates; up to 90 days. Counts begin with this integration; missing history is not reconstructed.',
    'from' => 'From',
    'to' => 'To',
    'test' => 'Test',
    'language' => 'Language',
    'role' => 'Play role',
    'all' => 'All',
    'apply' => 'Apply',
    'previous' => 'Previous',
    'next' => 'Next',
    'range_limit' => 'Choose a range of no more than 90 days.',
    'unavailable' => 'Activity storage is unavailable; counts cannot be confirmed.',
    'coverage' => ':tests registered tests · :rows language/role rows. Zero means no eligible activity observed, not no visitors.',
    'no_rows' => 'No registered test matches these filters.',
    'metrics' => [
        'fun_start' => 'Starts',
        'fun_complete' => 'Completions',
        'fun_result_view' => 'Result views',
        'fun_share' => 'Share actions',
    ],
    'columns' => [
        'test' => 'Test',
        'language' => 'Language',
        'role' => 'Play role',
        'start' => 'Starts',
        'complete' => 'Completions',
        'view' => 'Result views',
        'share' => 'Share actions',
        'methods' => 'Share action breakdown',
        'version' => 'Observed / current version',
        'privacy' => 'Collection policy',
    ],
    'roles' => [
        'single' => 'Individual test',
        'creator' => 'Friendship creator',
        'friend' => 'Invited friend',
        'solo' => 'Friendship solo',
    ],
    'methods' => [
        'copy' => 'Copied successfully',
        'system' => 'System share completed',
        'image' => 'Image download started',
        'link_open' => 'Share link opened (intent)',
    ],
    'privacy' => [
        'analytics_consent' => 'Analytics consent required',
        'explicit_opt_in' => 'Off by default; separate activity opt-in required',
    ],
];
