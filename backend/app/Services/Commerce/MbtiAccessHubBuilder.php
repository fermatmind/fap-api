<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Attempt;
use App\Models\UnifiedAccessProjection;
use App\Services\Access\AttemptUnlockProjectionRepairService;
use App\Services\Mbti\MbtiPublicFormSummaryBuilder;
use App\Services\Report\InviteUnlockSummaryBuilder;
use App\Services\Report\ReportAccess;
use Illuminate\Support\Facades\DB;

final class MbtiAccessHubBuilder
{
    public function __construct(
        private OrderManager $orders,
        private AttemptUnlockProjectionRepairService $projectionRepair,
        private MbtiPublicFormSummaryBuilder $mbtiPublicFormSummaryBuilder,
        private InviteUnlockSummaryBuilder $inviteUnlockSummaryBuilder,
    ) {}

    /**
     * @param  array<string,mixed>  $gate
     * @return array<string,mixed>|null
     */
    public function buildForReportContext(Attempt $attempt, array $gate, ?string $userId, ?string $anonId): ?array
    {
        if (! $this->isMbtiScale($attempt->scale_code ?? null)) {
            return null;
        }

        $attemptId = $this->stringOrNull($attempt->id ?? null);
        $orgId = (int) ($attempt->org_id ?? 0);
        $order = $attemptId !== null
            ? $this->orders->findLatestAccessibleOrderForAttempt($orgId, $userId, $anonId, $attemptId)
            : null;
        $attribution = $order !== null ? $this->orders->extractAttributionFromOrder($order) : [];
        $orderNo = $order !== null ? $this->stringOrNull($order->order_no ?? null) : null;
        $delivery = $order !== null ? $this->presentDelivery($order) : $this->emptyDelivery();

        $reportUrl = $this->attemptReportUrl($attemptId);
        $reportPdfUrl = $this->attemptReportPdfUrl($attemptId);
        $locked = (bool) ($gate['locked'] ?? false);
        $unlockStage = ReportAccess::normalizeUnlockStage((string) data_get(
            $gate,
            'unlock_stage',
            $locked ? ReportAccess::UNLOCK_STAGE_LOCKED : ReportAccess::UNLOCK_STAGE_FULL
        ));
        $unlockSource = ReportAccess::normalizeUnlockSource((string) data_get(
            $gate,
            'unlock_source',
            ReportAccess::UNLOCK_SOURCE_NONE
        ));
        $inviteSnapshot = $attemptId !== null ? $this->resolveInviteProgressSnapshot($orgId, $attemptId) : null;
        $inviteUnlockSummary = $this->inviteUnlockSummaryBuilder->build(
            (string) ($attempt->scale_code ?? ''),
            $unlockStage,
            $unlockSource,
            (int) ($inviteSnapshot['completed_invitees'] ?? 0),
            (int) ($inviteSnapshot['required_invitees'] ?? 2)
        );

        return [
            'access_state' => $locked
                ? ReportAccess::ACCESS_HUB_STATE_LOCKED
                : ReportAccess::ACCESS_HUB_STATE_READY,
            'unlock_stage' => $unlockStage,
            'unlock_source' => $unlockSource,
            'invite_unlock_v1' => $inviteUnlockSummary,
            'mbti_form_v1' => $this->mbtiPublicFormSummaryBuilder->summarizeForAttempt($attempt),
            'report_access' => [
                'can_view_report' => $reportUrl !== null,
                'attempt_id' => $attemptId,
                'order_no' => $orderNo,
                'report_url' => $reportUrl,
                'source' => $reportUrl !== null
                    ? ReportAccess::ACCESS_HUB_SOURCE_REPORT_GATE
                    : ReportAccess::ACCESS_HUB_SOURCE_NONE,
            ],
            'pdf_access' => [
                'can_download_pdf' => ! $locked && $reportPdfUrl !== null,
                'report_pdf_url' => $reportPdfUrl,
                'source' => $reportPdfUrl !== null
                    ? ReportAccess::ACCESS_HUB_SOURCE_ATTEMPT_PDF
                    : ReportAccess::ACCESS_HUB_SOURCE_NONE,
            ],
            'recovery' => $this->buildRecoveryBlock(
                $attemptId,
                true,
                (bool) ($delivery['can_request_claim_email'] ?? false),
                (bool) ($delivery['can_resend'] ?? false),
                $attribution
            ),
            'workspace_lite' => $this->buildWorkspaceLiteBlock($attemptId),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function buildForOrderContext(object $order): ?array
    {
        return $this->buildForDeliveryContext($order);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function buildForLookupHit(object $order): ?array
    {
        return $this->buildForDeliveryContext($order);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildForDeliveryContext(object $order): ?array
    {
        $attemptId = $this->resolveMbtiAttemptIdForOrder($order);
        if ($attemptId === null) {
            return null;
        }

        $delivery = $this->presentDelivery($order);
        $reportUrl = $this->stringOrNull($delivery['report_url'] ?? null);
        $reportPdfUrl = $this->stringOrNull($delivery['report_pdf_url'] ?? null);
        $exactResultEntry = $this->buildExactResultEntry(
            $attemptId,
            (int) ($order->org_id ?? 0),
            $this->stringOrNull($order->user_id ?? null),
            $this->stringOrNull($order->anon_id ?? null),
            $this->stringOrNull($order->order_no ?? null)
        );
        $canViewReport = (bool) ($exactResultEntry['ready_to_enter'] ?? false);
        $canDownloadPdf = $this->normalizeProjectionState((string) ($exactResultEntry['pdf_state'] ?? 'missing'), 'pdf') === 'ready';
        $canRequestClaimEmail = (bool) ($delivery['can_request_claim_email'] ?? false);
        $canResend = (bool) ($delivery['can_resend'] ?? false);
        $attribution = $this->orders->extractAttributionFromOrder($order);

        return [
            'access_state' => $this->stringOrNull($exactResultEntry['access_state'] ?? null)
                ?? $this->resolveDeliveryAccessState($order, $canViewReport, $canRequestClaimEmail, $canResend),
            'unlock_stage' => $this->stringOrNull($exactResultEntry['unlock_stage'] ?? null)
                ?? ReportAccess::UNLOCK_STAGE_LOCKED,
            'unlock_source' => $this->stringOrNull($exactResultEntry['unlock_source'] ?? null)
                ?? ReportAccess::UNLOCK_SOURCE_NONE,
            'invite_unlock_v1' => is_array($exactResultEntry['invite_unlock_v1'] ?? null)
                ? $exactResultEntry['invite_unlock_v1']
                : null,
            'mbti_form_v1' => $this->mbtiPublicFormSummaryBuilder->summarizeForAttemptId(
                $attemptId,
                (int) ($order->org_id ?? 0)
            ),
            'report_access' => [
                'can_view_report' => $canViewReport,
                'attempt_id' => $attemptId,
                'order_no' => $this->stringOrNull($order->order_no ?? null),
                'report_url' => $reportUrl,
                'source' => $reportUrl !== null && $exactResultEntry !== null
                    ? ReportAccess::ACCESS_HUB_SOURCE_REPORT_GATE
                    : ReportAccess::ACCESS_HUB_SOURCE_NONE,
            ],
            'pdf_access' => [
                'can_download_pdf' => $canDownloadPdf,
                'report_pdf_url' => $reportPdfUrl,
                'source' => $reportPdfUrl !== null && $exactResultEntry !== null
                    ? ReportAccess::ACCESS_HUB_SOURCE_REPORT_GATE
                    : ReportAccess::ACCESS_HUB_SOURCE_NONE,
            ],
            'recovery' => $this->buildRecoveryBlock(
                $attemptId,
                true,
                $canRequestClaimEmail,
                $canResend,
                $attribution
            ),
            'workspace_lite' => $this->buildWorkspaceLiteBlock($attemptId),
            'exact_result_entry' => $exactResultEntry,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function buildExactResultEntryForOrder(object $order): ?array
    {
        $attemptId = $this->resolveMbtiAttemptIdForOrder($order);
        if ($attemptId === null) {
            return null;
        }

        return $this->buildExactResultEntry(
            $attemptId,
            (int) ($order->org_id ?? 0),
            $this->stringOrNull($order->user_id ?? null),
            $this->stringOrNull($order->anon_id ?? null),
            $this->stringOrNull($order->order_no ?? null)
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildRecoveryBlock(
        ?string $attemptId,
        bool $canLookupOrder,
        bool $canRequestClaimEmail,
        bool $canResend,
        array $attribution
    ): array {
        return [
            'can_lookup_order' => $canLookupOrder,
            'can_request_claim_email' => $canRequestClaimEmail,
            'can_resend' => $canResend,
            'attempt_id' => $attemptId,
            'share_id' => $this->stringOrNull($attribution['share_id'] ?? null),
            'compare_invite_id' => $this->stringOrNull($attribution['compare_invite_id'] ?? null),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildWorkspaceLiteBlock(?string $attemptId): array
    {
        return [
            'has_entry' => $attemptId !== null,
            'entry_kind' => ReportAccess::ACCESS_HUB_ENTRY_KIND_MBTI_HISTORY,
            'attempt_id' => $attemptId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function presentDelivery(object $order): array
    {
        $delivery = $this->orders->presentOrderDelivery($order);

        return is_array($delivery['delivery'] ?? null) ? $delivery['delivery'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyDelivery(): array
    {
        return [
            'can_view_report' => false,
            'report_url' => null,
            'can_download_pdf' => false,
            'report_pdf_url' => null,
            'can_resend' => false,
            'can_request_claim_email' => false,
        ];
    }

    private function resolveDeliveryAccessState(
        object $order,
        bool $canViewReport,
        bool $canRequestClaimEmail,
        bool $canResend
    ): string {
        if ($canViewReport) {
            return ReportAccess::ACCESS_HUB_STATE_READY;
        }

        if ($this->isPendingStatus($order)) {
            return ReportAccess::ACCESS_HUB_STATE_PENDING;
        }

        if ($canRequestClaimEmail || $canResend) {
            return ReportAccess::ACCESS_HUB_STATE_RECOVERY_AVAILABLE;
        }

        return ReportAccess::ACCESS_HUB_STATE_RECOVERY_AVAILABLE;
    }

    private function resolveMbtiAttemptIdForOrder(object $order): ?string
    {
        $attemptId = $this->stringOrNull($order->target_attempt_id ?? null);
        if ($attemptId === null) {
            return null;
        }

        $attempt = DB::table('attempts')
            ->where('org_id', (int) ($order->org_id ?? 0))
            ->where('id', $attemptId)
            ->first(['id', 'scale_code']);

        if (! $attempt || ! $this->isMbtiScale($attempt->scale_code ?? null)) {
            return null;
        }

        return $this->stringOrNull($attempt->id ?? null);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildExactResultEntry(
        string $attemptId,
        int $orgId,
        ?string $userId = null,
        ?string $anonId = null,
        ?string $orderNo = null
    ): ?array {
        $attempt = DB::table('attempts')
            ->where('org_id', $orgId)
            ->where('id', $attemptId)
            ->first(['id', 'scale_code']);

        if (! $attempt || ! $this->isMbtiScale($attempt->scale_code ?? null)) {
            return null;
        }

        $resultExists = DB::table('results')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->exists();
        if ($resultExists) {
            $this->projectionRepair->repairResultReadyProjectionIfNeeded(
                $orgId,
                $attemptId,
                $userId,
                $anonId,
                $orderNo
            );
        }

        $projection = UnifiedAccessProjection::query()->where('attempt_id', $attemptId)->first();
        $payload = is_array($projection?->payload_json) ? $projection->payload_json : [];
        $accessState = $this->normalizeProjectionState(
            (string) ($projection?->access_state ?? ($resultExists ? 'locked' : 'pending')),
            'access'
        );
        $reportState = $this->normalizeProjectionState(
            (string) ($projection?->report_state ?? ($resultExists ? 'ready' : 'pending')),
            'report'
        );
        $pdfState = $this->normalizeProjectionState(
            (string) ($projection?->pdf_state ?? 'missing'),
            'pdf'
        );
        $pageHref = $this->supportsPageEntry($accessState, $reportState)
            ? $this->resultPagePathForAttemptId($attemptId)
            : null;
        $readyToEnter = $accessState === 'ready' && $reportState === 'ready' && $pageHref !== null;
        $unlockStage = ReportAccess::normalizeUnlockStage((string) ($payload['unlock_stage'] ?? (
            $accessState === 'ready'
                ? ReportAccess::UNLOCK_STAGE_FULL
                : ReportAccess::UNLOCK_STAGE_LOCKED
        )));
        $unlockSource = ReportAccess::normalizeUnlockSource((string) ($payload['unlock_source'] ?? ReportAccess::UNLOCK_SOURCE_NONE));
        $inviteSnapshot = $this->resolveInviteProgressSnapshot($orgId, $attemptId);
        $inviteUnlockSummary = $this->inviteUnlockSummaryBuilder->build(
            (string) ($attempt->scale_code ?? ''),
            $unlockStage,
            $unlockSource,
            (int) ($inviteSnapshot['completed_invitees'] ?? 0),
            (int) ($inviteSnapshot['required_invitees'] ?? 2)
        );

        return [
            'attempt_id' => $attemptId,
            'mbti_form_v1' => $this->mbtiPublicFormSummaryBuilder->summarizeForAttemptId($attemptId, $orgId),
            'access_state' => $accessState,
            'report_state' => $reportState,
            'pdf_state' => $pdfState,
            'unlock_stage' => $unlockStage,
            'unlock_source' => $unlockSource,
            'reason_code' => $this->stringOrNull(
                $projection?->reason_code ?? ($resultExists ? 'projection_missing_result_ready' : 'projection_missing_result_pending')
            ),
            'access_level' => $this->stringOrNull($payload['access_level'] ?? null),
            'variant' => $this->stringOrNull($payload['variant'] ?? null),
            'projection_version' => (int) ($projection?->projection_version ?? 1),
            'modules_allowed' => $this->normalizeStringArray($payload['modules_allowed'] ?? null),
            'modules_preview' => $this->normalizeStringArray($payload['modules_preview'] ?? null),
            'invite_unlock_v1' => $inviteUnlockSummary,
            'ready_to_enter' => $readyToEnter,
            'source' => ReportAccess::ACCESS_HUB_SOURCE_REPORT_GATE,
            'actions' => [
                'page_href' => $pageHref,
                'pdf_href' => $this->supportsPdfDownload($accessState, $pdfState)
                    ? $this->attemptReportPdfUrl($attemptId)
                    : null,
                'wait_href' => $this->isWaitingState($reportState) ? $this->resultPagePathForAttemptId($attemptId) : null,
                'history_href' => '/history/mbti',
                'lookup_href' => '/orders/lookup',
            ],
            'meta' => [
                'produced_at' => optional($projection?->produced_at)->toIso8601String(),
                'refreshed_at' => optional($projection?->refreshed_at)->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{required_invitees:int,completed_invitees:int}|null
     */
    private function resolveInviteProgressSnapshot(int $orgId, string $attemptId): ?array
    {
        if (! DB::getSchemaBuilder()->hasTable('attempt_invite_unlocks')) {
            return null;
        }

        $row = DB::table('attempt_invite_unlocks')
            ->where('target_org_id', $orgId)
            ->where('target_attempt_id', $attemptId)
            ->first(['required_invitees', 'completed_invitees']);

        if (! $row) {
            return null;
        }

        return [
            'required_invitees' => max(1, (int) ($row->required_invitees ?? 2)),
            'completed_invitees' => max(0, (int) ($row->completed_invitees ?? 0)),
        ];
    }

    private function attemptReportUrl(?string $attemptId): ?string
    {
        return $attemptId !== null
            ? "/api/v0.3/attempts/{$attemptId}/report"
            : null;
    }

    private function attemptReportPdfUrl(?string $attemptId): ?string
    {
        return $attemptId !== null
            ? "/api/v0.3/attempts/{$attemptId}/report.pdf"
            : null;
    }

    private function resultPagePathForAttemptId(string $attemptId): string
    {
        return "/result/{$attemptId}";
    }

    private function isPendingStatus(object $order): bool
    {
        $paymentState = $this->orders->resolvedPaymentState($order);

        return ! in_array($paymentState, [
            \App\Models\Order::PAYMENT_STATE_PAID,
            \App\Models\Order::PAYMENT_STATE_FAILED,
            \App\Models\Order::PAYMENT_STATE_CANCELED,
            \App\Models\Order::PAYMENT_STATE_EXPIRED,
            \App\Models\Order::PAYMENT_STATE_REFUNDED,
        ], true);
    }

    private function isMbtiScale(mixed $scaleCode): bool
    {
        return strtoupper(trim((string) $scaleCode)) === ReportAccess::SCALE_MBTI;
    }

    private function supportsPageEntry(string $accessState, string $reportState): bool
    {
        return ! in_array($this->normalizeProjectionState($accessState, 'access'), ['deleted', 'expired'], true)
            && ! in_array($this->normalizeProjectionState($reportState, 'report'), ['deleted', 'expired', 'unavailable'], true);
    }

    private function supportsPdfDownload(string $accessState, string $pdfState): bool
    {
        return $this->normalizeProjectionState($accessState, 'access') === 'ready'
            && $this->normalizeProjectionState($pdfState, 'pdf') === 'ready';
    }

    private function isWaitingState(string $state): bool
    {
        return in_array($this->normalizeProjectionState($state, 'report'), ['pending', 'restoring'], true);
    }

    private function normalizeProjectionState(string $state, string $kind): string
    {
        $normalized = strtolower(trim($state));

        return match (true) {
            $normalized === 'ready' => 'ready',
            in_array($normalized, ['pending', 'generating', 'queued', 'running', 'submitted'], true) => 'pending',
            in_array($normalized, ['restoring', 'rehydrating'], true) => 'restoring',
            in_array($normalized, ['deleted', 'purged', 'anonymized'], true) => 'deleted',
            $normalized === 'expired' => 'expired',
            $kind === 'access' && in_array($normalized, ['locked', 'recovery_available'], true) => 'locked',
            in_array($normalized, ['missing', 'unavailable', 'archived', 'shrunk', 'failed', 'blocked'], true) => 'unavailable',
            default => $kind === 'access' ? 'locked' : 'unavailable',
        };
    }

    /**
     * @return list<string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = $this->stringOrNull($item);
            if ($normalized === null) {
                continue;
            }

            $items[$normalized] = $normalized;
        }

        return array_values($items);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
