<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\Composer\ReportComposeContext;
use App\Services\Report\Composer\ReportPayloadAssembler;
use App\Services\Report\Composer\ReportPersistence;
use App\Services\Template\TemplateContext;
use App\Services\Template\TemplateEngine;
use Illuminate\Support\Facades\Log;

class ReportComposer
{
    public function __construct(
        private readonly ReportPayloadAssembler $assembler,
        private readonly ReportPersistence $persistence,
        private readonly TemplateEngine $templateEngine,
    ) {
    }

    public function compose(Attempt $attempt, array $ctx = [], ?Result $result = null): array
    {
        return $this->composeVariant($attempt, ReportAccess::VARIANT_FULL, $ctx, $result);
    }

    public function composeVariant(
        Attempt $attempt,
        string $variant,
        array $ctx = [],
        ?Result $result = null
    ): array
    {
        $ctx['variant'] = ReportAccess::normalizeVariant($variant);
        $ctx['report_access_level'] = $ctx['variant'] === ReportAccess::VARIANT_FREE
            ? ReportAccess::REPORT_ACCESS_FREE
            : ReportAccess::REPORT_ACCESS_FULL;

        $composeContext = ReportComposeContext::fromAttempt($attempt, $result, $ctx);
        $payload = $this->assembler->assemble($composeContext);

        if (($payload['ok'] ?? false) === true && is_array($payload['report'] ?? null)) {
            try {
                $templateContext = TemplateContext::fromReportCompose($attempt, $result, [
                    'variant' => $composeContext->variant,
                    'report_access_level' => $composeContext->reportAccessLevel,
                    'modules_allowed' => $composeContext->modulesAllowed,
                ]);
                $payload['report'] = $this->templateEngine->renderReportPayload(
                    $payload['report'],
                    $templateContext,
                    'text'
                );
            } catch (\Throwable $e) {
                Log::error('[REPORT] template_render_failed', [
                    'attempt_id' => (string) ($attempt->id ?? ''),
                    'error' => $e->getMessage(),
                ]);

                return [
                    'ok' => false,
                    'error' => 'REPORT_TEMPLATE_RENDER_FAILED',
                    'message' => 'report template render failed.',
                    'status' => 500,
                ];
            }
        }

        if (
            ($payload['ok'] ?? false) === true
            && $composeContext->persist
            && is_array($payload['report'] ?? null)
        ) {
            $attemptId = (string) ($payload['attempt_id'] ?? $composeContext->attemptId);
            $this->persistence->persist($attemptId, $payload['report']);
        }

        return $payload;
    }
}
