<?php

declare(strict_types=1);

namespace App\Support\Rbac;

final class PermissionNames
{
    public const ADMIN_OWNER = 'admin.owner';

    public const ADMIN_OPS_READ = 'admin.ops.read';

    public const ADMIN_OPS_WRITE = 'admin.ops.write';

    public const ADMIN_FINANCE_WRITE = 'admin.finance.write';

    public const ADMIN_APPROVAL_REVIEW = 'admin.approval.review';

    public const ADMIN_CONTENT_READ = 'admin.content.read';

    public const ADMIN_CONTENT_PUBLISH = 'admin.content.publish';

    public const ADMIN_CONTENT_PROBE = 'admin.content.probe';

    public const ADMIN_AUDIT_READ = 'admin.audit.read';

    public const ADMIN_AUDIT_EXPORT = 'admin.audit.export';

    public const ADMIN_EVENTS_READ = 'admin.events.read';

    public const ADMIN_FUNNEL_READ = 'admin.funnel.read';

    public const ADMIN_CACHE_INVALIDATE = 'admin.cache.invalidate';

    public const ADMIN_ORG_MANAGE = 'admin.org.manage';

    public const ADMIN_GLOBAL_SEARCH = 'admin.search.global';

    public const ADMIN_GO_LIVE_GATE = 'admin.go_live_gate';

    public const ADMIN_MENU_COMMERCE = 'admin.menu.commerce';

    public const ADMIN_MENU_SUPPORT = 'admin.menu.support';

    public const ADMIN_MENU_SRE = 'admin.menu.sre';

    public const ADMIN_MENU_AUDIT = 'admin.menu.audit';

    public const ROLE_OWNER = 'Owner';

    public const ROLE_OPS = 'Ops';

    public const ROLE_CONTENT = 'Content';

    public const ROLE_ANALYST = 'Analyst';

    public const ROLE_OPS_FINANCE = 'OpsFinance';

    public const ROLE_OPS_SUPPORT = 'OpsSupport';

    public const ROLE_SRE = 'SRE';

    public const ROLE_SECURITY_AUDITOR = 'SecurityAuditor';

    public const ROLE_OPS_ADMIN = 'OpsAdmin';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN_OWNER,
            self::ADMIN_OPS_READ,
            self::ADMIN_OPS_WRITE,
            self::ADMIN_FINANCE_WRITE,
            self::ADMIN_APPROVAL_REVIEW,
            self::ADMIN_CONTENT_READ,
            self::ADMIN_CONTENT_PUBLISH,
            self::ADMIN_CONTENT_PROBE,
            self::ADMIN_AUDIT_READ,
            self::ADMIN_AUDIT_EXPORT,
            self::ADMIN_EVENTS_READ,
            self::ADMIN_FUNNEL_READ,
            self::ADMIN_CACHE_INVALIDATE,
            self::ADMIN_ORG_MANAGE,
            self::ADMIN_GLOBAL_SEARCH,
            self::ADMIN_GO_LIVE_GATE,
            self::ADMIN_MENU_COMMERCE,
            self::ADMIN_MENU_SUPPORT,
            self::ADMIN_MENU_SRE,
            self::ADMIN_MENU_AUDIT,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function defaultRolePermissions(): array
    {
        return [
            self::ROLE_OPS_ADMIN => self::all(),
            self::ROLE_OPS_FINANCE => [
                self::ADMIN_OPS_READ,
                self::ADMIN_FINANCE_WRITE,
                self::ADMIN_APPROVAL_REVIEW,
                self::ADMIN_MENU_COMMERCE,
                self::ADMIN_GLOBAL_SEARCH,
            ],
            self::ROLE_OPS_SUPPORT => [
                self::ADMIN_OPS_READ,
                self::ADMIN_APPROVAL_REVIEW,
                self::ADMIN_MENU_SUPPORT,
                self::ADMIN_GLOBAL_SEARCH,
            ],
            self::ROLE_SRE => [
                self::ADMIN_OPS_READ,
                self::ADMIN_OPS_WRITE,
                self::ADMIN_EVENTS_READ,
                self::ADMIN_CACHE_INVALIDATE,
                self::ADMIN_MENU_SRE,
            ],
            self::ROLE_SECURITY_AUDITOR => [
                self::ADMIN_AUDIT_READ,
                self::ADMIN_AUDIT_EXPORT,
                self::ADMIN_MENU_AUDIT,
            ],

            // Legacy compatibility roles
            self::ROLE_OWNER => self::all(),
            self::ROLE_OPS => [
                self::ADMIN_OPS_READ,
                self::ADMIN_OPS_WRITE,
                self::ADMIN_APPROVAL_REVIEW,
                self::ADMIN_CACHE_INVALIDATE,
                self::ADMIN_FINANCE_WRITE,
                self::ADMIN_AUDIT_READ,
                self::ADMIN_EVENTS_READ,
                self::ADMIN_MENU_COMMERCE,
                self::ADMIN_MENU_SUPPORT,
                self::ADMIN_MENU_SRE,
                self::ADMIN_GLOBAL_SEARCH,
            ],
            self::ROLE_CONTENT => [
                self::ADMIN_CONTENT_READ,
                self::ADMIN_CONTENT_PUBLISH,
                self::ADMIN_CONTENT_PROBE,
                self::ADMIN_AUDIT_READ,
            ],
            self::ROLE_ANALYST => [
                self::ADMIN_EVENTS_READ,
                self::ADMIN_AUDIT_READ,
                self::ADMIN_FUNNEL_READ,
            ],
        ];
    }
}
