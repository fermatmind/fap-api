<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Filament\Ops\Resources\CareerRefundRequestResource;
use App\Models\AdminUser;
use App\Models\CareerRefundRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CareerRefundOpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_public_refund_workspace_without_selecting_an_organization(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'admin')->get('/ops/refund-requests')->assertOk()->assertSee('官网退款申请');
        $this->assertTrue(CareerRefundRequestResource::canViewAny());
        $other = AdminUser::create(['name' => 'Other', 'email' => 'other-refund@example.com', 'password' => 'fixture-password', 'is_active' => 1]);
        $other->roles()->attach($owner->roles()->first());
        $this->actingAs($other, 'admin')->get('/ops/refund-requests')->assertRedirect(route('filament.ops.auth.login'));
        $this->assertFalse(CareerRefundRequestResource::canViewAny());
    }

    public function test_global_refund_workspace_is_fixed_to_org_zero_even_when_a_tenant_is_selected(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'admin');
        foreach ([0, 12] as $org) {
            CareerRefundRequest::create(['org_id' => $org, 'order_no' => 'order-'.$org, 'owner_key' => 'fixture',
                'provider' => 'alipay', 'currency' => 'CNY', 'amount_cents' => 299, 'reason_code' => 'other', 'status' => 'pending']);
        }
        app(OrgContext::class)->set(12, null, 'owner', null, OrgContext::KIND_TENANT);
        $this->assertSame(['order-0'], CareerRefundRequestResource::getEloquentQuery()->pluck('order_no')->all());
        $this->assertFalse(CareerRefundRequestResource::canView(CareerRefundRequest::where('org_id', 12)->firstOrFail()));
    }

    private function owner(): AdminUser
    {
        $owner = AdminUser::create(['name' => 'Owner', 'email' => Str::uuid().'@example.com', 'password' => 'fixture-password', 'is_active' => 1]);
        $role = Role::create(['name' => 'refund-workspace-owner', 'guard_name' => 'admin']);
        foreach ([PermissionNames::ADMIN_OPS_READ, PermissionNames::ADMIN_OPS_WRITE, PermissionNames::ADMIN_FINANCE_WRITE, PermissionNames::ADMIN_APPROVAL_REVIEW] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['guard_name' => 'admin']);
            $role->permissions()->attach($permission);
        }
        $owner->roles()->attach($role);
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => $owner->id]);

        return $owner;
    }
}
