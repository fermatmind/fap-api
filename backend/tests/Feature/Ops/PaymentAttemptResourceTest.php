<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\PaymentAttemptResource\Pages\ListPaymentAttempts;
use App\Models\PaymentAttempt;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class PaymentAttemptResourceTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_payment_attempt_resource_is_readable_and_exposes_core_columns(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Payment Attempt Org');
        $chain = $this->seedCommerceOpsChain(orgId: (int) $selectedOrg->id, options: [
            'payment_attempt_state' => 'verified',
        ]);

        $paymentAttempt = PaymentAttempt::query()->withoutGlobalScopes()->findOrFail($chain['payment_attempt_id']);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/payment-attempts')
            ->assertOk()
            ->assertSee('支付尝试');

        session($this->opsSession($admin, $selectedOrg));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListPaymentAttempts::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$paymentAttempt])
            ->assertTableColumnExists('order_no')
            ->assertTableColumnExists('attempt_no')
            ->assertTableColumnExists('provider')
            ->assertTableColumnExists('state')
            ->assertTableColumnExists('provider_trade_no')
            ->assertTableFilterExists('state');

        $this->get('/ops/payment-attempts/'.$paymentAttempt->getKey())
            ->assertOk()
            ->assertSee((string) $chain['order_no'])
            ->assertSee((string) $chain['payment_attempt_id'])
            ->assertSee('Provider refs')
            ->assertSee('Timeline');
    }
}
