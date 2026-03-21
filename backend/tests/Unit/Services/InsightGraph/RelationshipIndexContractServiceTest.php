<?php

declare(strict_types=1);

namespace Tests\Unit\Services\InsightGraph;

use App\Services\InsightGraph\RelationshipIndexContractService;
use Tests\TestCase;

final class RelationshipIndexContractServiceTest extends TestCase
{
    public function test_it_builds_relationship_index_items_and_orders_them_by_resume_priority(): void
    {
        $service = app(RelationshipIndexContractService::class);

        $ready = $service->buildItem(
            'invite-ready',
            [
                'relationship_scope' => 'private_relationship_protected',
                'access_state' => 'private_access_ready',
                'participant_role' => 'inviter',
                'overview' => [
                    'title' => 'Ready relationship',
                    'summary' => 'Continue the next shared step.',
                ],
            ],
            [
                'consent_state' => 'purchased',
                'access_state' => 'private_access_ready',
            ],
            [
                'journey_state' => 'practice_started',
                'progress_state' => 'warming_up',
                'revisit_reorder_reason' => 'activate_first_dyadic_step',
                'last_dyadic_pulse_signal' => 'continue_dyadic_action',
            ],
            '/en/relationships/mbti/invite-ready',
            '2026-03-21T09:00:00+08:00',
            'en'
        );

        $refresh = $service->buildItem(
            'invite-refresh',
            [
                'relationship_scope' => 'private_relationship_protected',
                'access_state' => 'private_access_expired',
                'participant_role' => 'inviter',
                'overview' => [
                    'title' => 'Refresh relationship',
                    'summary' => 'Refresh private access before continuing.',
                ],
            ],
            [
                'consent_state' => 'purchased',
                'access_state' => 'private_access_expired',
            ],
            [
                'journey_state' => 'revisit_after_consent_refresh',
                'progress_state' => 'restricted',
                'revisit_reorder_reason' => 'refresh_private_access',
                'last_dyadic_pulse_signal' => 'refresh_private_access',
            ],
            '/en/relationships/mbti/invite-refresh',
            '2026-03-21T08:00:00+08:00',
            'en'
        );

        $awaiting = $service->buildItem(
            'invite-awaiting',
            [
                'relationship_scope' => 'private_relationship_protected',
                'access_state' => 'awaiting_second_subject',
                'participant_role' => 'inviter',
                'overview' => [
                    'title' => 'Awaiting relationship',
                    'summary' => 'Wait for the second participant.',
                ],
            ],
            [
                'consent_state' => 'pending',
                'access_state' => 'awaiting_second_subject',
            ],
            [
                'journey_state' => 'awaiting_partner',
                'progress_state' => 'not_started',
                'last_dyadic_pulse_signal' => '',
            ],
            '/en/relationships/mbti/invite-awaiting',
            '2026-03-21T07:00:00+08:00',
            'en'
        );

        $index = $service->buildIndex([$awaiting, $refresh, $ready]);

        $this->assertSame('relationship.index.v1', $index['relationship_index_version']);
        $this->assertSame('private_relationship_index', $index['index_scope']);
        $this->assertNotSame('', (string) ($index['relationship_index_fingerprint'] ?? ''));
        $this->assertSame('invite-ready', data_get($index, 'items.0.invite_id'));
        $this->assertSame('invite-refresh', data_get($index, 'items.1.invite_id'));
        $this->assertSame('invite-awaiting', data_get($index, 'items.2.invite_id'));
        $this->assertSame('ready_to_continue', data_get($index, 'items.0.revisit_priority_keys.0'));
        $this->assertSame('needs_consent_refresh', data_get($index, 'items.1.revisit_priority_keys.0'));
        $this->assertSame('awaiting_partner', data_get($index, 'items.2.relationship_resume_v1.relationship_entry_keys.0'));
        $this->assertSame('Continue relationship', data_get($index, 'items.0.relationship_resume_v1.continue_label'));
    }
}
