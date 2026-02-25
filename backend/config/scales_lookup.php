<?php

return [
    // compat: alias + canonical 都可查
    // canonical_only: 仅 canonical 可查，alias 返回 404
    'alias_mode' => env('FAP_SCALE_LOOKUP_ALIAS_MODE', 'compat'),
];
