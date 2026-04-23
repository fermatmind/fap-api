<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Bridge;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use Illuminate\Support\Facades\Log;

final class BigFiveLiveRuntimeBridge
{
    public const RESPONSE_KEY = 'big5_report_engine_v2';

    public function __construct(
        private readonly BigFiveReportEngine $engine,
        private readonly LiveReportContextAdapter $contextAdapter,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function build(Attempt $attempt, Result $result, string $scaleCode): ?array
    {
        if (! (bool) config('big5_report_engine.v2_bridge_enabled', false)) {
            return null;
        }

        if (strtoupper(trim($scaleCode)) !== 'BIG5_OCEAN') {
            return null;
        }

        $context = $this->contextAdapter->adapt($attempt, $result);
        if ($context === null) {
            return null;
        }

        try {
            return $this->engine->generate($context);
        } catch (\Throwable $exception) {
            Log::warning('BIG5_REPORT_ENGINE_V2_BRIDGE_FAILED', [
                'attempt_id' => (string) ($attempt->id ?? ''),
                'result_id' => (string) ($result->id ?? ''),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
