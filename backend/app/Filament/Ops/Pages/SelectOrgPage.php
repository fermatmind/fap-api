<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class SelectOrgPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Select Org';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'select-org';

    protected static string $view = 'filament.ops.pages.select-org-page';

    public string $search = '';

    /**
     * @var list<array{id:int,name:string}>
     */
    public array $organizations = [];

    public function mount(): void
    {
        $this->refreshOrganizations();
    }

    public function updatedSearch(): void
    {
        $this->refreshOrganizations();
    }

    public function selectOrg(int $orgId): void
    {
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

        $this->redirect('/ops');
    }

    private function refreshOrganizations(): void
    {
        $search = trim($this->search);

        $query = DB::table('organizations')
            ->select(['id', 'name'])
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
            ])
            ->values()
            ->all();
    }
}
