<?php

declare(strict_types=1);

use App\Services\ContentPromotion\PromotionExecutionContext;
use Illuminate\Foundation\Application;

return static function (Application $app): void {
    $values = [];
    foreach ([
        'source_commit',
        'workflow_run_id',
        'workflow_run_attempt',
        'expected_row_count',
        'executor_release_sha256',
        'release_policy_sha256',
        'workflow_signature',
        'previous_receipt',
    ] as $field) {
        $name = 'CONTENT_PROMOTION_'.strtoupper($field);
        // Preserve the existing SERVER -> ENV -> process -> config -> default precedence.
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        if (! is_string($value) || $value === '') {
            $value = getenv($name);
        }
        if (is_string($value) && $value !== '') {
            $values['content_promotion.execution.'.$field] = $value;
        }
    }
    $app->instance(PromotionExecutionContext::class, new PromotionExecutionContext($values));
};
