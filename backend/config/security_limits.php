<?php

return [
    // 公网轻量事件接口（share click）默认上限：16KB，便于快速止损 DoS
    'public_event_max_payload_bytes' => (int) env('PUBLIC_EVENT_MAX_PAYLOAD_BYTES', 16384),

    // meta_json 字段额外限制：避免小包体内塞超深/超大的数组导致验证与序列化开销
    'public_event_meta_max_bytes' => (int) env('PUBLIC_EVENT_META_MAX_BYTES', 4096),
    'public_event_meta_max_keys'  => (int) env('PUBLIC_EVENT_META_MAX_KEYS', 50),
];
