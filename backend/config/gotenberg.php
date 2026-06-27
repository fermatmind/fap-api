<?php

return [
    'enabled' => (bool) env('GOTENBERG_PDF_ENABLED', false),
    'base_url' => env('GOTENBERG_BASE_URL', ''),
    'timeout_seconds' => (int) env('GOTENBERG_TIMEOUT_SECONDS', 30),
    'connect_timeout_seconds' => (int) env('GOTENBERG_CONNECT_TIMEOUT_SECONDS', 5),
    'allow_single_label_hosts' => (bool) env('GOTENBERG_ALLOW_SINGLE_LABEL_HOSTS', true),
    'allowed_private_suffixes' => array_values(array_filter(array_map(
        static fn ($suffix) => strtolower(trim((string) $suffix)),
        explode(',', (string) env('GOTENBERG_ALLOWED_PRIVATE_SUFFIXES', '.internal,.local,.lan,.svc,.cluster.local'))
    ))),
    'default_pdf_options' => [
        'paperWidth' => env('GOTENBERG_PAPER_WIDTH', '8.27'),
        'paperHeight' => env('GOTENBERG_PAPER_HEIGHT', '11.69'),
        'marginTop' => env('GOTENBERG_MARGIN_TOP', '0.35'),
        'marginBottom' => env('GOTENBERG_MARGIN_BOTTOM', '0.35'),
        'marginLeft' => env('GOTENBERG_MARGIN_LEFT', '0.35'),
        'marginRight' => env('GOTENBERG_MARGIN_RIGHT', '0.35'),
        'printBackground' => env('GOTENBERG_PRINT_BACKGROUND', 'true'),
    ],
];
