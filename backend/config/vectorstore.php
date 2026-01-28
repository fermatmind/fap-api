<?php

return [
    'enabled' => env('VECTORSTORE_ENABLED', true),
    'driver' => env('VECTORSTORE_DRIVER', 'mysql_fallback'),
    'fail_open' => env('VECTORSTORE_FAIL_OPEN', true),

    'timeouts' => [
        'connect_seconds' => (int) env('VECTORSTORE_TIMEOUT_CONNECT', 3),
        'request_seconds' => (int) env('VECTORSTORE_TIMEOUT_REQUEST', 8),
    ],

    'qdrant' => [
        'enabled' => env('QDRANT_ENABLED', false),
        'endpoint' => env('QDRANT_ENDPOINT', 'http://127.0.0.1:6333'),
        'api_key' => env('QDRANT_API_KEY', ''),
        'collection' => env('QDRANT_COLLECTION', 'fap_memories'),
        'namespace' => env('QDRANT_NAMESPACE', 'default'),
    ],

    'mysql_fallback' => [
        'enabled' => env('VECTORSTORE_MYSQL_ENABLED', true),
        'namespace' => env('VECTORSTORE_MYSQL_NAMESPACE', 'default'),
        'top_k' => (int) env('VECTORSTORE_MYSQL_TOP_K', 10),
    ],
];
