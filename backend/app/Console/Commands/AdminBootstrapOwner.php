<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminBootstrapOwner extends Command
{
    protected $signature = 'admin:bootstrap-owner
        {--email= : Owner email}
        {--password= : Owner password}
        {--name= : Owner name}';

    protected $description = 'Create or update admin owner and initialize roles/permissions';

    public function handle(): int
    {
        $email = trim((string) $this->option('email'));
        $password = trim((string) $this->option('password'));
        $name = trim((string) $this->option('name'));

        if ($email === '' || $password === '') {
            $this->error('Missing required --email or --password');
            return 1;
        }

        if ($name === '') {
            $name = 'Owner';
        }

        if (!Schema::hasTable('admin_users')) {
            $this->error('Missing admin_users table. Run migrations first.');
            return 1;
        }

        $user = AdminUser::updateOrCreate([
            'email' => $email,
        ], [
            'name' => $name,
            'password' => Hash::make($password),
            'is_active' => 1,
        ]);

        if (Schema::hasTable('roles') && Schema::hasTable('permissions')) {
            foreach (PermissionNames::all() as $perm) {
                Permission::firstOrCreate([
                    'name' => $perm,
                ], [
                    'description' => null,
                ]);
            }

            $rbac = app(RbacService::class);
            foreach (PermissionNames::defaultRolePermissions() as $roleName => $perms) {
                Role::firstOrCreate([
                    'name' => $roleName,
                ], [
                    'description' => null,
                ]);
                $rbac->syncRolePermissions($roleName, $perms);
            }

            $rbac->grantRole($user, PermissionNames::ROLE_OPS_ADMIN);
            $rbac->grantRole($user, PermissionNames::ROLE_OWNER);
        }

        $this->writeAudit($user->id, $email);

        $url = (string) config('admin.url', 'http://127.0.0.1:18010/admin');
        $this->info('Owner ready: ' . $email);
        $this->info('Login: ' . $url);

        return 0;
    }

    private function writeAudit(int $adminId, string $email): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        $request = Request::create('/cli/admin/bootstrap-owner', 'POST');
        $request->attributes->set('request_id', (string) Str::uuid());

        app(AuditLogger::class)->log(
            $request,
            'admin_bootstrap_owner',
            'AdminUser',
            (string) $adminId,
            [
                'email' => $email,
                'actor' => 'cli',
            ]
        );
    }
}
