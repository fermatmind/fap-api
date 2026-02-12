<?php

return [
    'allowed_ips' => array_filter(array_map('trim', explode(',', (string) env('HEALTHZ_ALLOWED_IPS', '127.0.0.1/32')))),
    'verbose' => (bool) env('HEALTHZ_VERBOSE', false),
];
