<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Support\Rbac\PermissionNames;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class SelectOrgPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Select Org';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'select-org';

    protected static string $view = 'filament.ops.pages.select-org-page';

    public string $search = '';

    public string $returnTo = '';

    /**
     * @var list<array{id:int,name:string,status:string,domain:?string,updated_at:string}>
     */
    public array $organizations = [];

    public function mount(): void
    {
        $this->returnTo = trim((string) request()->query('return_to', ''));
        $this->refreshOrganizations();
    }

    public function updatedSearch(): void
    {
        $this->refreshOrganizations();
    }

    public function selectOrg(int $orgId): void
    {
        if (! \App\Support\SchemaBaseline::hasTable('organizations')) {
            Notification::make()
                ->title('Organizations table missing')
                ->danger()
                ->send();

            return;
        }

        $exists = DB::table('organizations')
            ->where('id', $orgId)
            ->exists();

        if (!$exists) {
            Notification::make()
                ->title('Organization not found')
                ->danger()
                ->send();

            return;
        }

        session(['ops_org_id' => $orgId]);

        Notification::make()
            ->title('Organization selected')
            ->success()
            ->send();

        $target = '/ops';
        if ($this->returnTo !== '' && str_starts_with($this->returnTo, '/ops')) {
            $target = $this->returnTo;
        }

        $this->redirect($target, navigate: true);
    }

    public function createOrganization(): void
    {
        if (! \App\Support\SchemaBaseline::hasTable('organizations')) {
            Notification::make()
                ->title('Organizations table missing')
                ->danger()
                ->send();

            return;
        }

        if (! $this->canCreateOrganization()) {
            Notification::make()
                ->title('Permission denied')
                ->danger()
                ->send();

            return;
        }

        $nextNumber = (int) DB::table('organizations')->count() + 1;
        $name = 'Organization '.$nextNumber;

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => $name,
            'owner_user_id' => 0,
            'status' => 'active',
            'domain' => null,
            'timezone' => 'UTC',
            'locale' => 'en-US',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->refreshOrganizations();

        Notification::make()
            ->title('Organization created')
            ->body('org_id='.$orgId)
            ->success()
            ->send();
    }

    public function goToImport(): RedirectResponse
    {
        return redirect()->route('filament.ops.pages.organizations-import');
    }

    public function whyVisibleHint(): string
    {
        return 'No organization is selected yet. Create a new organization or import/sync existing organizations.';
    }

    public function canCreateOrganization(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (! is_object($user) || ! method_exists($user, 'hasPermission')) {
            return false;
        }

        return $user->hasPermission(PermissionNames::ADMIN_OWNER)
            || $user->hasPermission(PermissionNames::ADMIN_ORG_MANAGE);
    }

    private function refreshOrganizations(): void
    {
        if (! \App\Support\SchemaBaseline::hasTable('organizations')) {
            $this->organizations = [];

            return;
        }

        $search = trim($this->search);

        $query = DB::table('organizations')
            ->select(['id', 'name', 'status', 'domain', 'updated_at'])
            ->orderBy('id', 'desc');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%' . $search . '%');

                if (preg_match('/^\d+$/', $search) === 1) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }

        $this->organizations = $query
            ->limit(20)
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'name' => trim((string) $row->name),
                'status' => trim((string) ($row->status ?? 'active')),
                'domain' => $row->domain !== null ? trim((string) $row->domain) : null,
                'updated_at' => trim((string) ($row->updated_at ?? '')),
            ])
            ->values()
            ->all();
    }
}
