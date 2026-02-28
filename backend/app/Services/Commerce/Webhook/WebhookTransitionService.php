<?php

namespace App\Services\Commerce\Webhook;

use App\Internal\Commerce\PaymentWebhookHandlerCore;

class WebhookTransitionService
{
    public function __construct(private PaymentWebhookHandlerCore $core)
    {
    }

    public function handle(array $ctx): array
    {
        $ctx['lock_key'] = $this->core->buildWebhookLockKey(
            (string) $ctx['provider'],
            (int) $ctx['normalized_org_id'],
            (string) $ctx['provider_event_id']
        );
        $ctx['lock_ttl'] = max(1, (int) config(
            'services.payment_webhook.lock_ttl_seconds',
            10
        ));
        $ctx['lock_block'] = max(0, (int) config(
            'services.payment_webhook.lock_block_seconds',
            5
        ));
        $ctx['contention_budget_ms'] = max(1, (int) config(
            'services.payment_webhook.lock_contention_budget_ms',
            3000
        ));
        $ctx['lock_wait_started_at'] = microtime(true);
        $ctx['post_commit_outcome'] = null;
        $ctx['post_commit_ctx'] = null;

        return $ctx;
    }
}
