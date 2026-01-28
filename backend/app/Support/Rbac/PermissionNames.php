<?php

declare(strict_types=1);

namespace App\Support\Rbac;

final class PermissionNames
{
    public const ADMIN_OWNER = 'admin.owner';

    public const ADMIN_OPS_READ = 'admin.ops.read';
    public const ADMIN_OPS_WRITE = 'admin.ops.write';

    public const ADMIN_CONTENT_READ = 'admin.content.read';
    public const ADMIN_CONTENT_PUBLISH = 'admin.content.publish';
    public const ADMIN_CONTENT_PROBE = 'admin.content.probe';

    public const ADMIN_AUDIT_READ = 'admin.audit.read';
    public const ADMIN_AUDIT_EXPORT = 'admin.audit.export';

    public const ADMIN_EVENTS_READ = 'admin.events.read';
    public const ADMIN_FUNNEL_READ = 'admin.funnel.read';

    public const ADMIN_CACHE_INVALIDATE = 'admin.cache.invalidate';

    public const ROLE_OWNER = 'Owner';
    public const ROLE_OPS = 'Ops';
    public const ROLE_CONTENT = 'Content';
    public const ROLE_ANALYST = 'Analyst';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN_OWNER,
            self::ADMIN_OPS_READ,
            self::ADMIN_OPS_WRITE,
            self::ADMIN_CONTENT_READ,
            self::ADMIN_CONTENT_PUBLISH,
            self::ADMIN_CONTENT_PROBE,
            self::ADMIN_AUDIT_READ,
            self::ADMIN_AUDIT_EXPORT,
            self::ADMIN_EVENTS_READ,
            self::ADMIN_FUNNEL_READ,
            self::ADMIN_CACHE_INVALIDATE,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function defaultRolePermissions(): array
    {
        return [
            self::ROLE_OWNER => self::all(),
            self::ROLE_OPS => [
                self::ADMIN_OPS_READ,
                self::ADMIN_OPS_WRITE,
                self::ADMIN_CACHE_INVALIDATE,
                self::ADMIN_AUDIT_READ,
                self::ADMIN_EVENTS_READ,
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
