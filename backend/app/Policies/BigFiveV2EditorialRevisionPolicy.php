<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\BigFiveV2EditorialRevision;
use App\Support\Rbac\PermissionNames;

final class BigFiveV2EditorialRevisionPolicy
{
    public function viewAny(mixed $user = null): bool
    {
        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_CONTENT_READ);
    }

    public function view(mixed $user, BigFiveV2EditorialRevision $revision): bool
    {
        return $this->viewAny($user);
    }

    public function createDraft(mixed $user = null): bool
    {
        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_CONTENT_WRITE);
    }

    public function submitForReview(mixed $user, BigFiveV2EditorialRevision $revision): bool
    {
        return $this->createDraft($user)
            && $revision->workflow_state === BigFiveV2EditorialRevision::STATE_DRAFT;
    }

    public function approve(mixed $user, BigFiveV2EditorialRevision $revision): bool
    {
        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_APPROVAL_REVIEW)
            && $user->hasPermission(PermissionNames::ADMIN_CONTENT_RELEASE)
            && $revision->workflow_state === BigFiveV2EditorialRevision::STATE_REVIEW;
    }

    public function reject(mixed $user, BigFiveV2EditorialRevision $revision): bool
    {
        return $this->approve($user, $revision);
    }

    public function rollback(mixed $user, BigFiveV2EditorialRevision $revision): bool
    {
        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_APPROVAL_REVIEW)
            && $user->hasPermission(PermissionNames::ADMIN_CONTENT_RELEASE)
            && in_array($revision->workflow_state, [
                BigFiveV2EditorialRevision::STATE_APPROVED,
                BigFiveV2EditorialRevision::STATE_REJECTED,
            ], true);
    }

    public function exportReleaseCandidate(mixed $user, BigFiveV2EditorialRevision $revision): bool
    {
        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH)
            && $revision->workflow_state === BigFiveV2EditorialRevision::STATE_APPROVED;
    }

    public function publishToRuntime(mixed $user, BigFiveV2EditorialRevision $revision): bool
    {
        return false;
    }

    public function delete(mixed $user, BigFiveV2EditorialRevision $revision): bool
    {
        return false;
    }
}
