<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Services\Ops\EnneagramRegistryOpsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use RuntimeException;

class EnneagramRegistryReleasePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Enneagram Registry';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'enneagram-registry-release';

    protected static string $view = 'filament.ops.pages.enneagram-registry-release-page';

    /**
     * @var array<string,mixed>
     */
    public array $preview = [];

    /**
     * @var list<string>
     */
    public array $validationErrors = [];

    public function mount(EnneagramRegistryOpsService $service): void
    {
        $this->refreshPreview($service);
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRead();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_control_plane');
    }

    public static function getNavigationLabel(): string
    {
        return 'Enneagram Registry';
    }

    public function getTitle(): string
    {
        return 'Enneagram Registry Governance';
    }

    public function publishRegistryRelease(EnneagramRegistryOpsService $service): void
    {
        if (! ContentAccess::canRelease()) {
            Notification::make()->title('Publish permission required')->danger()->send();

            return;
        }

        try {
            $this->refreshPreview($service->publish($this->actorLabel()));
            Notification::make()->title('Enneagram registry release published')->success()->send();
        } catch (RuntimeException $e) {
            $this->refreshPreview($service);
            Notification::make()->title('Publish blocked')->body($e->getMessage())->danger()->send();
        }
    }

    public function refreshRegistryPreview(EnneagramRegistryOpsService $service): void
    {
        $this->refreshPreview($service);
    }

    public function activateRelease(string $releaseId, EnneagramRegistryOpsService $service): void
    {
        if (! ContentAccess::canRelease()) {
            Notification::make()->title('Activation permission required')->danger()->send();

            return;
        }

        try {
            $this->refreshPreview($service->activate($releaseId, $this->actorLabel()));
            Notification::make()->title('Release activated')->success()->send();
        } catch (RuntimeException $e) {
            $this->refreshPreview($service);
            Notification::make()->title('Activate failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function rollbackRelease(string $releaseId, EnneagramRegistryOpsService $service): void
    {
        if (! ContentAccess::canRelease()) {
            Notification::make()->title('Rollback permission required')->danger()->send();

            return;
        }

        try {
            $this->refreshPreview($service->rollback($releaseId, $this->actorLabel()));
            Notification::make()->title('Rollback completed')->success()->send();
        } catch (RuntimeException $e) {
            $this->refreshPreview($service);
            Notification::make()->title('Rollback failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * @param  array<string,mixed>|EnneagramRegistryOpsService  $payload
     */
    private function refreshPreview(array|EnneagramRegistryOpsService $payload): void
    {
        if ($payload instanceof EnneagramRegistryOpsService) {
            $payload = $payload->preview();
        }

        $this->preview = $payload;
        $this->validationErrors = array_values((array) data_get($payload, 'validation.errors', []));
    }

    private function actorLabel(): string
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        $email = is_object($user) ? trim((string) ($user->email ?? '')) : '';

        return $email !== '' ? $email : 'ops';
    }
}
