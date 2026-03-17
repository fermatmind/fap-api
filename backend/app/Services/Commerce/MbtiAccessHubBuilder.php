<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Attempt;
use App\Services\Report\ReportAccess;
use Illuminate\Support\Facades\DB;

final class MbtiAccessHubBuilder
{
    public function __construct(
        private OrderManager $orders,
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

        return [
            'access_state' => $locked
                ? ReportAccess::ACCESS_HUB_STATE_LOCKED
                : ReportAccess::ACCESS_HUB_STATE_READY,
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
        $canViewReport = (bool) ($delivery['can_view_report'] ?? false);
        $canDownloadPdf = (bool) ($delivery['can_download_pdf'] ?? false);
        $canRequestClaimEmail = (bool) ($delivery['can_request_claim_email'] ?? false);
        $canResend = (bool) ($delivery['can_resend'] ?? false);
        $attribution = $this->orders->extractAttributionFromOrder($order);

        return [
            'access_state' => $this->resolveDeliveryAccessState(
                (string) ($order->status ?? ''),
                $canViewReport,
                $canRequestClaimEmail,
                $canResend
            ),
            'report_access' => [
                'can_view_report' => $canViewReport,
                'attempt_id' => $attemptId,
                'order_no' => $this->stringOrNull($order->order_no ?? null),
                'report_url' => $reportUrl,
                'source' => $reportUrl !== null
                    ? ReportAccess::ACCESS_HUB_SOURCE_ORDER_DELIVERY
                    : ReportAccess::ACCESS_HUB_SOURCE_NONE,
            ],
            'pdf_access' => [
                'can_download_pdf' => $canDownloadPdf,
                'report_pdf_url' => $reportPdfUrl,
                'source' => $reportPdfUrl !== null
                    ? ReportAccess::ACCESS_HUB_SOURCE_ORDER_DELIVERY
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
        ];
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
        string $status,
        bool $canViewReport,
        bool $canRequestClaimEmail,
        bool $canResend
    ): string {
        if ($canViewReport) {
            return ReportAccess::ACCESS_HUB_STATE_READY;
        }

        if ($this->isPendingStatus($status)) {
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

    private function isPendingStatus(string $status): bool
    {
        return ! in_array(
            strtolower(trim($status)),
            ['paid', 'fulfilled', 'failed', 'canceled', 'cancelled', 'refunded'],
            true
        );
    }

    private function isMbtiScale(mixed $scaleCode): bool
    {
        return strtoupper(trim((string) $scaleCode)) === ReportAccess::SCALE_MBTI;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
