<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Ops\OrgVisibilityResolver;
use App\Support\Rbac\PermissionNames;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cookie;
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

    public int $currentOrgId = 0;

    public string $currentOrgName = 'No Org Selected';

    /**
     * @var list<array{id:int,name:string,status:string,domain:?string,updated_at:string}>
     */
    public array $organizations = [];

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.select_org');
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');

        return app(OrgVisibilityResolver::class)->canSelectOrganizations(auth($guard)->user());
    }

    public function mount(): void
    {
        $this->returnTo = trim((string) request()->query('return_to', ''));
        $this->refreshOrganizations();
        $this->refreshCurrentOrgSummary();
    }

    public function getTitle(): string
    {
        return 'Select organization';
    }

    public function getSubheading(): ?string
    {
        return 'Use the Fermat Ops workspace shell to choose the active organization before opening commerce, content, or runtime workflows.';
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

        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        $exists = app(OrgVisibilityResolver::class)->isVisibleOrganization($user, $orgId);

        if (! $exists) {
            Notification::make()
                ->title('Organization not found')
                ->danger()
                ->send();

            return;
        }

        session(['ops_org_id' => $orgId]);
        Cookie::queue(cookie(
            name: 'ops_org_id',
            value: (string) $orgId,
            minutes: 60 * 24 * 30,
            path: '/ops',
            domain: null,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));

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

        $payload = [
            'name' => $name,
            'owner_user_id' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (\App\Support\SchemaBaseline::hasColumn('organizations', 'status')) {
            $payload['status'] = 'active';
        }
        if (\App\Support\SchemaBaseline::hasColumn('organizations', 'domain')) {
            $payload['domain'] = null;
        }
        if (\App\Support\SchemaBaseline::hasColumn('organizations', 'timezone')) {
            $payload['timezone'] = 'UTC';
        }
        if (\App\Support\SchemaBaseline::hasColumn('organizations', 'locale')) {
            $payload['locale'] = 'en-US';
        }

        $orgId = (int) DB::table('organizations')->insertGetId($payload);

        $this->refreshOrganizations();

        Notification::make()
            ->title('Organization created')
            ->body('org_id='.$orgId)
            ->success()
            ->send();
    }

    public function goToImport(): void
    {
        $this->redirectRoute('filament.ops.pages.organizations-import', navigate: true);
    }

    public function whyVisibleHint(): string
    {
        return 'No organization is selected yet. Create a new organization or import/sync existing organizations.';
    }

    public function visibleOrganizationsCount(): int
    {
        return count($this->organizations);
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

        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        $query = app(OrgVisibilityResolver::class)
            ->visibleOrganizationsQuery($user)
            ->orderBy('id', 'desc');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%');

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

    private function refreshCurrentOrgSummary(): void
    {
        $orgId = $this->resolveSelectedOrgId();
        if ($orgId <= 0) {
            $this->currentOrgId = 0;
            $this->currentOrgName = 'No Org Selected';

            return;
        }

        $this->currentOrgId = $orgId;

        if (! \App\Support\SchemaBaseline::hasTable('organizations')) {
            $this->currentOrgName = 'No Org Selected';
            $this->currentOrgId = 0;

            return;
        }

        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        if (! app(OrgVisibilityResolver::class)->isVisibleOrganization($user, $this->currentOrgId)) {
            $this->currentOrgId = 0;
            $this->currentOrgName = 'No Org Selected';

            return;
        }

        $row = DB::table('organizations')
            ->select(['name'])
            ->where('id', $this->currentOrgId)
            ->first();

        if ($row === null) {
            $this->currentOrgId = 0;
            $this->currentOrgName = 'No Org Selected';

            return;
        }

        $this->currentOrgName = trim((string) $row->name);
    }

    private function resolveSelectedOrgId(): int
    {
        $rawSessionOrgId = (string) session('ops_org_id', '');
        if ($rawSessionOrgId !== '' && preg_match('/^\d+$/', $rawSessionOrgId) === 1) {
            return max(0, (int) $rawSessionOrgId);
        }

        $rawCookieOrgId = (string) request()->cookie('ops_org_id', '');
        if ($rawCookieOrgId !== '' && preg_match('/^\d+$/', $rawCookieOrgId) === 1) {
            $orgId = max(0, (int) $rawCookieOrgId);
            if ($orgId > 0) {
                session(['ops_org_id' => $orgId]);
            }

            return $orgId;
        }

        return 0;
    }
}
