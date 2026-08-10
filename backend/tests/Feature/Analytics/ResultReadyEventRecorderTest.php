<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\ResultReadyEventRecorder;
use App\Support\OrgContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class ResultReadyEventRecorderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_recorder_is_shared_across_scale_families_and_requires_a_valid_result(): void
    {
        $ctx = new OrgContext;
        $ctx->set(84, null, 'member', 'internal-anon', OrgContext::KIND_TENANT);
        $day = CarbonImmutable::parse('2026-08-10 08:00:00');

        foreach (['MBTI', 'BIG5_OCEAN', 'ENNEAGRAM', 'RIASEC'] as $index => $scaleCode) {
            $attemptId = (string) Str::uuid();
            $occurredAt = $day->addMinutes($index);
            $this->insertAttempt($attemptId, 84, 'en', $occurredAt, $occurredAt->addMinute());
            DB::table('attempts')->where('id', $attemptId)->update(['scale_code' => $scaleCode]);

            app(ResultReadyEventRecorder::class)->record($ctx, $attemptId);
            $this->assertSame(0, DB::table('events')->where('event_code', 'result_ready')->where('attempt_id', $attemptId)->count());

            $this->insertResult($attemptId, 84, $occurredAt->addMinutes(2));
            DB::table('results')->where('attempt_id', $attemptId)->update(['scale_code' => $scaleCode]);

            app(ResultReadyEventRecorder::class)->record($ctx, $attemptId);
            app(ResultReadyEventRecorder::class)->record($ctx, $attemptId);

            $event = DB::table('events')
                ->where('event_code', 'result_ready')
                ->where('attempt_id', $attemptId)
                ->first();
            $this->assertNotNull($event);
            $this->assertSame($scaleCode, (string) ($event->scale_code ?? ''));
            $this->assertNull($event->user_id);
            $this->assertNull($event->anon_id);
            $meta = json_decode((string) ($event->meta_json ?? '{}'), true);
            $this->assertSame([
                'scale_code',
                'form_code',
                'locale',
                'entry_surface',
                'source_page_type',
                'organic_channel',
                'device_class',
                'result_state',
            ], array_keys(is_array($meta) ? $meta : []));
            $this->assertSame(1, DB::table('events')->where('event_code', 'result_ready')->where('attempt_id', $attemptId)->count());
        }
    }

    public function test_invalid_result_does_not_emit_until_it_becomes_valid(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(85);
        $attemptId = $scenario['attempt_a'];
        DB::table('results')->where('attempt_id', $attemptId)->update(['is_valid' => false]);
        $ctx = new OrgContext;
        $ctx->set(85, null, 'member', 'internal-anon', OrgContext::KIND_TENANT);

        app(ResultReadyEventRecorder::class)->record($ctx, $attemptId);
        $this->assertSame(0, DB::table('events')->where('event_code', 'result_ready')->where('attempt_id', $attemptId)->count());

        DB::table('results')->where('attempt_id', $attemptId)->update(['is_valid' => true]);
        app(ResultReadyEventRecorder::class)->record($ctx, $attemptId);
        $this->assertSame(1, DB::table('events')->where('event_code', 'result_ready')->where('attempt_id', $attemptId)->count());
    }

    public function test_recorder_adds_only_an_exact_backend_resolved_public_article_id(): void
    {
        $attemptId = (string) Str::uuid();
        $occurredAt = CarbonImmutable::parse('2026-08-10 08:30:00');
        DB::table('articles')->insert([
            'id' => 53,
            'org_id' => 0,
            'slug' => 'personality-types',
            'locale' => 'en',
            'title' => 'Personality types',
            'content_md' => '# Personality types',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => $occurredAt->subDay(),
            'created_at' => $occurredAt->subDay(),
            'updated_at' => $occurredAt->subDay(),
        ]);
        $this->insertAttempt($attemptId, 84, 'en', $occurredAt, $occurredAt->addMinute());
        DB::table('attempts')->where('id', $attemptId)->update([
            'answers_summary_json' => json_encode([
                'meta' => [
                    'source_page_type' => 'article_detail',
                    'content_id' => '53',
                    'source_slug' => 'personality-types',
                    'landing_path' => '/en/articles/personality-types?utm_source=google',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->insertResult($attemptId, 84, $occurredAt->addMinutes(2));
        $ctx = new OrgContext;
        $ctx->set(84, null, 'member', 'internal-anon', OrgContext::KIND_TENANT);

        app(ResultReadyEventRecorder::class)->record($ctx, $attemptId);

        $event = DB::table('events')
            ->where('event_code', 'result_ready')
            ->where('attempt_id', $attemptId)
            ->first();
        $meta = json_decode((string) ($event->meta_json ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('53', $meta['source_article_id'] ?? null);
        $this->assertArrayNotHasKey('source_slug', $meta);
        $this->assertArrayNotHasKey('landing_path', $meta);

        $mismatchAttemptId = (string) Str::uuid();
        $this->insertAttempt($mismatchAttemptId, 84, 'en', $occurredAt->addHour(), $occurredAt->addHour()->addMinute());
        DB::table('attempts')->where('id', $mismatchAttemptId)->update([
            'answers_summary_json' => json_encode([
                'meta' => [
                    'source_page_type' => 'article_detail',
                    'content_id' => '53',
                    'source_slug' => 'wrong-slug',
                    'landing_path' => '/en/articles/personality-types',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->insertResult($mismatchAttemptId, 84, $occurredAt->addHour()->addMinutes(2));
        app(ResultReadyEventRecorder::class)->record($ctx, $mismatchAttemptId);

        $mismatchMeta = json_decode((string) DB::table('events')
            ->where('event_code', 'result_ready')
            ->where('attempt_id', $mismatchAttemptId)
            ->value('meta_json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('source_article_id', $mismatchMeta);
    }

    public function test_rolled_back_result_cannot_leave_a_result_ready_event(): void
    {
        $attemptId = (string) Str::uuid();
        $occurredAt = CarbonImmutable::parse('2026-08-10 09:00:00');
        $this->insertAttempt($attemptId, 86, 'en', $occurredAt, $occurredAt->addMinute());
        $ctx = new OrgContext;
        $ctx->set(86, null, 'member', 'internal-anon', OrgContext::KIND_TENANT);

        DB::beginTransaction();
        try {
            $this->insertResult($attemptId, 86, $occurredAt->addMinutes(2));
            app(ResultReadyEventRecorder::class)->record($ctx, $attemptId);
            $this->assertSame(1, DB::table('events')->where('event_code', 'result_ready')->where('attempt_id', $attemptId)->count());
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, DB::table('results')->where('attempt_id', $attemptId)->count());
        $this->assertSame(0, DB::table('events')->where('event_code', 'result_ready')->where('attempt_id', $attemptId)->count());
    }
}
