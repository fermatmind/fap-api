<?php

declare(strict_types=1);

namespace App\Livewire\Filament\Ops\Livewire;

use App\Support\OrgContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class CurrentOrgSwitcher extends Component
{
    public ?int $orgId = null;

    public string $orgName = '';

    public function mount(): void
    {
        $this->orgId = $this->resolveOrgId();

        if ($this->orgId === null) {
            $this->orgName = __('ops.topbar.no_org_selected');

            return;
        }

        $row = DB::table('organizations')
            ->select(['id', 'name'])
            ->where('id', $this->orgId)
            ->first();

        if ($row !== null) {
            $this->orgName = (string) $row->name;

            return;
        }

        // org_id points to a missing organization; clear to avoid a bad loop.
        $this->clearOrgSelection();
        $this->orgId = null;
        $this->orgName = __('ops.topbar.no_org_selected');
    }

    public function goSelectOrg(): mixed
    {
        $this->clearOrgSelection();

        return redirect()->to('/ops/select-org');
    }

    private function clearOrgSelection(): void
    {
        session()->forget('ops_org_id');

        Cookie::queue(Cookie::forget('ops_org_id', '/ops'));
        Cookie::queue(Cookie::forget('ops_org_id', '/'));
    }

    private function resolveOrgId(): ?int
    {
        $orgId = (int) app(OrgContext::class)->orgId();
        if ($orgId > 0) {
            return $orgId;
        }

        return null;
    }

    public function render(): View
    {
        return view('livewire.filament.ops.livewire.current-org-switcher');
    }
}
