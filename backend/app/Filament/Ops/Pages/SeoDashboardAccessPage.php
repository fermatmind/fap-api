<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Support\Rbac\PermissionNames;
use Filament\Pages\Page;

class SeoDashboardAccessPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Content Overview';

    protected static ?string $navigationLabel = 'SEO Intelligence';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'seo';

    protected static string $view = 'filament.ops.pages.seo-dashboard-access';

    /**
     * @return list<array{label:string,value:string,hint:string}>
     */
    public function statusCards(): array
    {
        return [
            [
                'label' => 'URL Truth rows',
                'value' => '7',
                'hint' => 'Verified `seo_urls` count from the SEO Dash MVP online closeout.',
            ],
            [
                'label' => 'Entity mappings',
                'value' => '7',
                'hint' => 'Verified `seo_url_entities` count from the SEO Dash MVP online closeout.',
            ],
            [
                'label' => 'Issue queue rows',
                'value' => '5',
                'hint' => 'Verified `seo_issue_queue` count from the SEO Dash MVP online closeout.',
            ],
            [
                'label' => 'Verified cards',
                'value' => '10',
                'hint' => 'Metabase dashboard card count verified before this route shell PR.',
            ],
        ];
    }

    /**
     * @return list<array{label:string,value:string,hint:string}>
     */
    public function boundaryCards(): array
    {
        return [
            [
                'label' => 'Metabase exposure',
                'value' => 'Private only',
                'hint' => 'Metabase remains localhost-bound on the approved private ECS host.',
            ],
            [
                'label' => 'Datasource',
                'value' => 'seo_intel',
                'hint' => 'The only approved Metabase datasource uses the readonly account.',
            ],
            [
                'label' => 'Sharing',
                'value' => 'Disabled',
                'hint' => 'Public sharing, anonymous links, and public embedding remain blocked.',
            ],
            [
                'label' => 'Operator SQL',
                'value' => 'Blocked',
                'hint' => 'Normal operators must not receive unrestricted native SQL access.',
            ],
        ];
    }

    /**
     * @return list<array{title:string,body:string}>
     */
    public function accessSteps(): array
    {
        return [
            [
                'title' => 'Confirm owner approval',
                'body' => 'Use this page as the Ops entry point and confirm the Metabase admin, dashboard, DB access, export policy, and emergency revoke owners before private access.',
            ],
            [
                'title' => 'Use a private channel',
                'body' => 'Access Metabase only through Workbench, bastion, VPN, or another approved owner-controlled private channel.',
            ],
            [
                'title' => 'Keep Metabase private',
                'body' => 'Do not iframe, reverse-proxy, publish, expose, or bind Metabase to a public interface from this page.',
            ],
            [
                'title' => 'Verify datasource boundary',
                'body' => 'The only approved datasource is `seo_intel` through `seo_intel_metabase_readonly`; business DB, Tencent RDS, Node2, and raw operational sources remain forbidden.',
            ],
        ];
    }

    public function getTitle(): string
    {
        return 'SEO Intelligence Access';
    }

    public static function canAccess(): bool
    {
        return self::hasAnyPermission([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_OPS_READ,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function hasAnyPermission(array $permissions): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (! is_object($user) || ! method_exists($user, 'hasPermission')) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
