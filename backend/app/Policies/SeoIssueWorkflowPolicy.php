<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminUser;
use App\Services\SeoIntel\OpsDashboard\SeoIssueWorkflowService;
use App\Support\Rbac\PermissionNames;

final class SeoIssueWorkflowPolicy
{
    public function transition(AdminUser $actor, string $action, string $status): bool
    {
        if ((int) $actor->is_active !== 1 || $actor->locked_until?->isFuture()) {
            return false;
        }

        if (! $actor->hasPermission(PermissionNames::ADMIN_CONTENT_WRITE)
            && ! $actor->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH)
            && ! $actor->hasPermission(PermissionNames::ADMIN_OWNER)) {
            return false;
        }

        return in_array($status, SeoIssueWorkflowService::allowedStatusesFor($action), true);
    }
}
