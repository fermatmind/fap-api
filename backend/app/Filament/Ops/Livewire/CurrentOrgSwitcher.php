<?php

declare(strict_types=1);

namespace App\Filament\Ops\Livewire;

use App\Services\Ops\OrgVisibilityResolver;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class CurrentOrgSwitcher extends Component
{
    public int $currentOrgId = 0;

    public string $currentOrgName = 'No Org Selected';

    /** @var list<array{id:int,name:string}> */
    public array $organizations = [];

    public function mount(): void
    {
        $this->refreshState();
    }

    public function switchOrg(int $orgId): void
    {
        if (! \App\Support\SchemaBaseline::hasTable('organizations')) {
            return;
        }

        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        $exists = app(OrgVisibilityResolver::class)->isVisibleOrganization($user, $orgId);
        if (! $exists) {
            return;
        }

        session(['ops_org_id' => $orgId]);

        $this->redirect(request()->getRequestUri(), navigate: true);
    }

    public function goSelectOrg(): void
    {
        $this->redirect('/ops/select-org', navigate: true);
    }

    public function render()
    {
        return view('filament.ops.livewire.current-org-switcher');
    }

    private function refreshState(): void
    {
        if (! \App\Support\SchemaBaseline::hasTable('organizations')) {
            $this->currentOrgId = 0;
            $this->currentOrgName = 'No Org Selected';
            $this->organizations = [];

            return;
        }

        $rawOrgId = (string) session('ops_org_id', '');
        if ($rawOrgId !== '' && preg_match('/^\d+$/', $rawOrgId) === 1) {
            $this->currentOrgId = (int) $rawOrgId;
        }

        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        $this->organizations = app(OrgVisibilityResolver::class)
            ->visibleOrganizationsQuery($user)
            ->select(['id', 'name'])
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'name' => trim((string) $row->name),
            ])
            ->all();

        if ($this->currentOrgId > 0 && app(OrgVisibilityResolver::class)->isVisibleOrganization($user, $this->currentOrgId)) {
            $row = DB::table('organizations')
                ->where('id', $this->currentOrgId)
                ->first(['name']);

            if ($row) {
                $this->currentOrgName = trim((string) $row->name);

                return;
            }
        }

        $this->currentOrgId = 0;
        $this->currentOrgName = 'No Org Selected';
    }
}
