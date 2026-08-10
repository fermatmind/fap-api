<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ResultReadyEventRecorder
{
    /** @var list<string> */
    private const META_PROPERTIES = [
        'scale_code',
        'form_code',
        'locale',
        'entry_surface',
        'source_page_type',
        'organic_channel',
        'device_class',
        'result_state',
    ];

    public function __construct(
        private readonly EventRecorder $events,
        private readonly MeasurementAttributionDimensions $dimensions,
    ) {}

    public function record(
        OrgContext $ctx,
        string $attemptId,
    ): void {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || ! SchemaBaseline::hasTable('attempts') || ! SchemaBaseline::hasTable('results') || ! SchemaBaseline::hasTable('events')) {
            return;
        }

        try {
            DB::transaction(function () use ($ctx, $attemptId): void {
                $orgId = $ctx->orgId();
                $result = DB::table('results')
                    ->where('org_id', $orgId)
                    ->where('attempt_id', $attemptId)
                    ->where('is_valid', true)
                    ->lockForUpdate()
                    ->first(['computed_at']);
                if ($result === null) {
                    return;
                }

                $alreadyRecorded = DB::table('events')
                    ->where('org_id', $orgId)
                    ->where('event_code', FunnelEventTaxonomy::RESULT_READY)
                    ->where('attempt_id', $attemptId)
                    ->exists();
                if ($alreadyRecorded) {
                    return;
                }

                $attempt = DB::table('attempts')
                    ->where('org_id', $orgId)
                    ->where('id', $attemptId)
                    ->first([
                        'scale_code',
                        'locale',
                        'client_platform',
                        'channel',
                        'answers_summary_json',
                    ]);
                if ($attempt === null) {
                    return;
                }

                $safeDimensions = array_intersect_key(
                    $this->dimensions->fromAttempt($attempt, 'ready'),
                    array_flip(self::META_PROPERTIES),
                );
                $this->events->record(
                    FunnelEventTaxonomy::RESULT_READY,
                    null,
                    $safeDimensions,
                    [
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'scale_code' => $safeDimensions['scale_code'],
                        'locale' => $safeDimensions['locale'],
                        'occurred_at' => $result->computed_at ?? now(),
                    ],
                );
            });
        } catch (Throwable $exception) {
            Log::warning('RESULT_READY_EVENT_RECORD_FAILED', [
                'org_id' => $ctx->orgId(),
                'exception_class' => $exception::class,
            ]);
        }
    }
}
