<?php

namespace App\Services\Commerce\Webhook;

use App\Internal\Commerce\PaymentWebhookHandlerCore;
use App\Jobs\GenerateReportPdfJob;
use App\Jobs\GenerateReportSnapshotJob;

class WebhookPostCommitService
{
    public function __construct(private PaymentWebhookHandlerCore $core) {}

    public function handle(array $ctx): array
    {
        $provider = (string) $ctx['provider'];
        $providerEventId = (string) $ctx['provider_event_id'];
        $result = (array) ($ctx['result'] ?? []);
        $postCommitCtx = is_array($ctx['post_commit_ctx'] ?? null)
            ? $ctx['post_commit_ctx']
            : null;
        $postCommitOutcome = null;

        if (($result['ok'] ?? false) && ! ($result['duplicate'] ?? false) && is_array($postCommitCtx)) {
            $postCommitOutcome = $this->core->runWebhookPostCommitSideEffects($postCommitCtx);

            if (($postCommitOutcome['ok'] ?? false) === true) {
                $this->core->markEventProcessed($provider, $providerEventId);
            } else {
                $errorCode = (string) ($postCommitOutcome['error_code'] ?? 'POST_COMMIT_FAILED');
                $errorMessage = (string) ($postCommitOutcome['error_message'] ?? 'post commit side effects failed.');
                $this->core->markEventError(
                    $provider,
                    $providerEventId,
                    'post_commit_failed',
                    $errorCode,
                    $errorMessage
                );

                $result = $this->core->serverError($errorCode, 'post commit side effects failed.');
            }
        } elseif (($result['ok'] ?? false) && ! ($result['duplicate'] ?? false) && ! ($result['ignored'] ?? false)) {
            $this->core->markEventProcessed($provider, $providerEventId);
        }

        $snapshotJobCtx = is_array($postCommitOutcome['snapshot_job_ctx'] ?? null)
            ? $postCommitOutcome['snapshot_job_ctx']
            : null;
        if (is_array($snapshotJobCtx) && ($result['ok'] ?? false)) {
            GenerateReportSnapshotJob::dispatch(
                (int) $snapshotJobCtx['org_id'],
                (string) $snapshotJobCtx['attempt_id'],
                (string) $snapshotJobCtx['trigger_source'],
                $snapshotJobCtx['order_no'] !== null ? (string) $snapshotJobCtx['order_no'] : null,
            )->afterCommit();
        }

        $pdfJobCtx = is_array($postCommitOutcome['pdf_job_ctx'] ?? null)
            ? $postCommitOutcome['pdf_job_ctx']
            : null;
        if (is_array($pdfJobCtx) && ($result['ok'] ?? false)) {
            GenerateReportPdfJob::dispatch(
                (int) $pdfJobCtx['org_id'],
                (string) $pdfJobCtx['attempt_id'],
                (string) $pdfJobCtx['trigger_source'],
                $pdfJobCtx['order_no'] !== null ? (string) $pdfJobCtx['order_no'] : null,
            )->afterCommit();
        }

        $ctx['post_commit_outcome'] = $postCommitOutcome;
        $ctx['result'] = $result;
        $ctx['normalized_result'] = $this->core->normalizeResultStatus($result);

        return $ctx;
    }
}
