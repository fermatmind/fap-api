<?php

declare(strict_types=1);

namespace App\Services\BigFive\Cms;

use App\Models\AdminUser;
use App\Models\BigFiveV2EditorialRevision;
use App\Policies\BigFiveV2EditorialRevisionPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use LogicException;

final class BigFiveV2EditorialApprovalFlow
{
    public function __construct(
        private readonly BigFiveV2EditorialWorkflow $workflow = new BigFiveV2EditorialWorkflow,
        private readonly BigFiveV2EditorialRevisionPolicy $policy = new BigFiveV2EditorialRevisionPolicy,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function submitForReview(
        AdminUser $actor,
        BigFiveV2EditorialRevision $revision,
        ?string $note = null,
    ): BigFiveV2EditorialRevision {
        $this->authorize($this->policy->submitForReview($actor, $revision), 'submit Big Five V2 editorial revision for review');

        $from = (string) $revision->workflow_state;
        $updated = $this->workflow->submitForReview($revision, (int) $actor->id, $note);

        return $this->recordAudit($updated, 'submitted_for_review', $actor, $from, (string) $updated->workflow_state, $note);
    }

    /**
     * @throws AuthorizationException
     */
    public function approve(
        AdminUser $actor,
        BigFiveV2EditorialRevision $revision,
        ?string $note = null,
    ): BigFiveV2EditorialRevision {
        $this->authorize($this->policy->approve($actor, $revision), 'approve Big Five V2 editorial revision');
        $this->assertRoleSeparation($actor, $revision, 'approve');

        $from = (string) $revision->workflow_state;
        $updated = $this->workflow->approve($revision, (int) $actor->id, $note);

        return $this->recordAudit($updated, 'approved', $actor, $from, (string) $updated->workflow_state, $note);
    }

    /**
     * @throws AuthorizationException
     */
    public function reject(
        AdminUser $actor,
        BigFiveV2EditorialRevision $revision,
        ?string $note = null,
    ): BigFiveV2EditorialRevision {
        $this->authorize($this->policy->reject($actor, $revision), 'reject Big Five V2 editorial revision');
        $this->assertRoleSeparation($actor, $revision, 'reject');

        $from = (string) $revision->workflow_state;
        $updated = $this->workflow->reject($revision, (int) $actor->id, $note);

        return $this->recordAudit($updated, 'rejected', $actor, $from, (string) $updated->workflow_state, $note);
    }

    /**
     * @throws AuthorizationException
     */
    public function archiveForRollback(
        AdminUser $actor,
        BigFiveV2EditorialRevision $revision,
        ?string $note = null,
    ): BigFiveV2EditorialRevision {
        $this->authorize($this->policy->rollback($actor, $revision), 'archive Big Five V2 editorial revision for rollback');
        $this->assertRollbackRoleSeparation($actor, $revision);

        $from = (string) $revision->workflow_state;
        $updated = $this->workflow->archive($revision, (int) $actor->id, $note);

        return $this->recordAudit($updated, 'rollback_archived', $actor, $from, (string) $updated->workflow_state, $note);
    }

    /**
     * @return array<string,bool>
     */
    public function capabilityMap(AdminUser $actor, BigFiveV2EditorialRevision $revision): array
    {
        return [
            'view' => $this->policy->view($actor, $revision),
            'create_draft' => $this->policy->createDraft($actor),
            'submit_for_review' => $this->policy->submitForReview($actor, $revision),
            'approve' => $this->policy->approve($actor, $revision),
            'reject' => $this->policy->reject($actor, $revision),
            'rollback' => $this->policy->rollback($actor, $revision),
            'export_release_candidate' => $this->policy->exportReleaseCandidate($actor, $revision),
            'publish_to_runtime' => $this->policy->publishToRuntime($actor, $revision),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function auditTrail(BigFiveV2EditorialRevision $revision): array
    {
        $metadata = $revision->metadata_json;
        $trail = is_array($metadata) ? ($metadata['editorial_audit_trail'] ?? []) : [];

        return is_array($trail) ? array_values(array_filter($trail, 'is_array')) : [];
    }

    private function authorize(bool $allowed, string $action): void
    {
        if (! $allowed) {
            throw new AuthorizationException('Not authorized to '.$action.'.');
        }
    }

    private function assertRoleSeparation(AdminUser $actor, BigFiveV2EditorialRevision $revision, string $action): void
    {
        $actorId = (int) $actor->id;
        if ($actorId > 0 && in_array($actorId, [
            (int) $revision->created_by_admin_user_id,
            (int) $revision->submitted_by_admin_user_id,
        ], true)) {
            throw new LogicException('Big Five V2 editorial role separation prevents '.$action.' by the author or submitter.');
        }
    }

    private function assertRollbackRoleSeparation(AdminUser $actor, BigFiveV2EditorialRevision $revision): void
    {
        $actorId = (int) $actor->id;
        if ($actorId <= 0) {
            throw new LogicException('Big Five V2 editorial rollback requires an identified admin actor.');
        }

        $priorActorIds = array_values(array_filter(array_unique([
            (int) $revision->created_by_admin_user_id,
            (int) $revision->submitted_by_admin_user_id,
            (int) $revision->reviewed_by_admin_user_id,
        ])));

        if (in_array($actorId, $priorActorIds, true)) {
            throw new LogicException('Big Five V2 editorial role separation prevents rollback by the author, submitter, or reviewer.');
        }
    }

    private function recordAudit(
        BigFiveV2EditorialRevision $revision,
        string $action,
        AdminUser $actor,
        string $from,
        string $to,
        ?string $note,
    ): BigFiveV2EditorialRevision {
        $metadata = is_array($revision->metadata_json) ? $revision->metadata_json : [];
        $trail = $this->auditTrail($revision);
        $trail[] = [
            'action' => $action,
            'actor_admin_user_id' => (int) $actor->id,
            'from_state' => $from,
            'to_state' => $to,
            'note' => $note,
            'occurred_at' => Carbon::now()->toISOString(),
        ];

        $metadata['editorial_audit_trail'] = $trail;
        $revision->metadata_json = $metadata;
        $revision->save();

        return $revision->refresh();
    }
}
