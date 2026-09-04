<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationOutbox;
use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationPolicyContract;
use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationTransport;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class SeoPlatform12F02NotificationOutboxTest extends TestCase
{
    private RecordingPlatform12NotificationTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.seo_intel', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('seo_council.connection', 'seo_intel');
        config()->set('seo_council.notification_dispatch_enabled', true);
        config()->set('seo_council.notification_max_attempts', 3);
        DB::purge('seo_intel');
        DB::connection('seo_intel')->getPdo();
        $this->migration()->up();

        $this->transport = new RecordingPlatform12NotificationTransport;
        $this->app->instance(Platform12NotificationTransport::class, $this->transport);
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');

        parent::tearDown();
    }

    public function test_expand_only_schema_has_fixed_delivery_state_fields_and_preserves_evidence_on_down(): void
    {
        $columns = DB::connection('seo_intel')->getSchemaBuilder()->getColumnListing('seo_council_notification_outbox');
        $this->assertSame([], array_values(array_diff([
            'notification_id', 'fingerprint', 'event_type', 'subject_hash', 'policy_revision',
            'incident_state', 'status', 'payload_json', 'attempt', 'max_attempts',
            'available_at', 'lease_token_hash', 'lease_expires_at', 'sent_at', 'last_error_code',
        ], $columns)));

        $outbox = app(Platform12NotificationOutbox::class);
        $outbox->enqueue($this->classification('schema'), 'failed', 'HOLD');
        $row = DB::connection('seo_intel')->table('seo_council_notification_outbox')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(3, (int) $row->max_attempts);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row->fingerprint);
        $this->assertSame(hash('sha256', implode('|', [
            $row->event_type, $row->subject_hash, $row->policy_revision, $row->incident_state,
        ])), $row->fingerprint);

        $this->migration()->down();
        $this->assertTrue(DB::connection('seo_intel')->getSchemaBuilder()->hasTable('seo_council_notification_outbox'));
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_notification_outbox')->count());
    }

    public function test_duplicate_enqueue_and_duplicate_dispatch_deliver_only_once(): void
    {
        $outbox = app(Platform12NotificationOutbox::class);
        $first = $outbox->enqueue($this->classification('dedupe'), 'failed', 'HOLD');
        $duplicate = $outbox->enqueue($this->classification('dedupe'), 'failed', 'HOLD');

        $this->assertSame('pending', $first['status']);
        $this->assertSame('suppressed', $duplicate['status']);
        $this->assertSame($first['notification_id'], $duplicate['notification_id']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_notification_outbox')->count());

        $claim = $outbox->claim('worker:dedupe:one');
        $this->assertSame('CLAIMED', $claim['status']);
        $sent = $outbox->dispatch($claim['claim'], 'HOLD');
        $replay = $outbox->dispatch($claim['claim'], 'HOLD');

        $this->assertSame('sent', $sent['status']);
        $this->assertSame('suppressed', $replay['status']);
        $this->assertCount(1, $this->transport->deliveries);
        $this->assertSame('sent', DB::connection('seo_intel')->table('seo_council_notification_outbox')->value('status'));
    }

    public function test_expired_worker_claim_is_recovered_and_does_not_duplicate_send(): void
    {
        $outbox = app(Platform12NotificationOutbox::class);
        $outbox->enqueue($this->classification('worker-crash'), 'failed', 'FAILED');
        $abandoned = $outbox->claim('worker:crashed:one', 1);
        $this->assertSame('CLAIMED', $abandoned['status']);

        DB::connection('seo_intel')->table('seo_council_notification_outbox')->update([
            'lease_expires_at' => '2000-01-01 00:00:00',
        ]);
        $recovered = $outbox->claim('worker:recovered:two', 60);
        $this->assertSame('CLAIMED', $recovered['status']);
        $this->assertSame(2, $recovered['claim']['attempt']);
        $this->assertSame('sent', $outbox->dispatch($recovered['claim'], 'FAILED')['status']);

        $this->assertCount(1, $this->transport->deliveries);
        $this->assertSame('EMPTY', $outbox->claim('worker:after:done')['status']);
    }

    public function test_transport_failure_is_bounded_never_changes_verdict_and_is_health_readable(): void
    {
        $this->transport->failuresRemaining = 3;
        $outbox = app(Platform12NotificationOutbox::class);
        $outbox->enqueue($this->classification('transport-failure'), 'failed', 'POLICY_HOLD');

        foreach (range(1, 3) as $attempt) {
            DB::connection('seo_intel')->table('seo_council_notification_outbox')->update([
                'available_at' => '2000-01-01 00:00:00',
            ]);
            $claim = $outbox->claim('worker:failure:'.$attempt);
            $result = $outbox->dispatch($claim['claim'], 'POLICY_HOLD');

            $this->assertSame('POLICY_HOLD', $result['mission_verdict']);
            $this->assertFalse($result['verdict_mutated']);
            $this->assertSame($attempt === 3 ? 'failed' : 'pending', $result['status']);
        }

        $health = $outbox->health();
        $this->assertSame('TERMINAL_FAILURE', $health['state']);
        $this->assertSame(1, $health['terminal_failure_count']);
        $this->assertSame(1, $health['status_counts']['failed']);
        $this->assertTrue($health['read_only']);
        $this->assertSame('TRANSPORT_RETRY_EXHAUSTED', DB::connection('seo_intel')
            ->table('seo_council_notification_outbox')->value('last_error_code'));
    }

    public function test_failed_to_healthy_transition_creates_exactly_one_recovery_notification(): void
    {
        $outbox = app(Platform12NotificationOutbox::class);
        $classification = $this->classification('recovery');
        $outbox->enqueue($classification, 'failed', 'FAILED');
        $event = $classification['sanitized_event'];

        $first = $outbox->enqueueRecovery(
            $event['event_type'],
            $event['subject_hash'],
            $event['policy_revision'],
            $event['evidence_refs'],
            $event['expires_at'],
            'CLOSED',
        );
        $duplicate = $outbox->enqueueRecovery(
            $event['event_type'],
            $event['subject_hash'],
            $event['policy_revision'],
            $event['evidence_refs'],
            $event['expires_at'],
            'CLOSED',
        );

        $this->assertSame('pending', $first['status']);
        $this->assertSame('suppressed', $duplicate['status']);
        $this->assertSame($first['notification_id'], $duplicate['notification_id']);
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_council_notification_outbox')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_notification_outbox')
            ->where('incident_state', 'healthy')->count());
    }

    public function test_no_council_event_produces_no_notification_and_non_immediate_event_is_not_persisted(): void
    {
        $outbox = app(Platform12NotificationOutbox::class);
        $this->assertSame('VALID_ZERO', $outbox->health()['state']);
        $this->assertSame('EMPTY', $outbox->claim('worker:no:event')['status']);
        $this->assertCount(0, $this->transport->deliveries);

        $ordinary = $this->event('GENERAL_OBSERVATION', 'P2', 'ordinary');
        $classification = app(Platform12NotificationPolicyContract::class)->evaluate($ordinary);
        $result = $outbox->enqueue($classification, 'active', 'READY');

        $this->assertSame('suppressed', $result['status']);
        $this->assertSame('NON_IMMEDIATE_EVENT', $result['reason_code']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_notification_outbox')->count());
        $this->assertCount(0, $this->transport->deliveries);
    }

    /** @return array<string, mixed> */
    private function classification(string $suffix): array
    {
        return app(Platform12NotificationPolicyContract::class)->evaluate(
            $this->event('DATA_FAILURE', 'P1', $suffix),
        );
    }

    /** @return array<string, mixed> */
    private function event(string $eventType, string $severity, string $suffix): array
    {
        return [
            'event_type' => $eventType,
            'severity' => $severity,
            'subject_hash' => hash('sha256', 'outbox-subject-'.$suffix),
            'evidence_refs' => [[
                'id' => 'public-evidence-'.$suffix,
                'hash' => hash('sha256', 'outbox-evidence-'.$suffix),
            ]],
            'policy_revision' => app(Platform12NotificationPolicyContract::class)->reference()['hash'],
            'state' => 'ACTIVE',
            'expires_at' => '2026-09-11T12:00:00Z',
            'decision_metrics' => null,
        ];
    }

    private function migration(): object
    {
        return require database_path('migrations/seo_intel/2026_09_04_040000_create_seo_council_notification_outbox.php');
    }
}

final class RecordingPlatform12NotificationTransport implements Platform12NotificationTransport
{
    /** @var list<array{notification_id:string,payload:array<string,mixed>}> */
    public array $deliveries = [];

    public int $failuresRemaining = 0;

    public function send(string $notificationId, array $sanitizedPayload): void
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new RuntimeException('transport details must not escape');
        }
        $this->deliveries[] = ['notification_id' => $notificationId, 'payload' => $sanitizedPayload];
    }
}
