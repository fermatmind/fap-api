<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use Filament\Pages\Page;

/** Compatibility-only route for the retired /ops/seo dashboard. */
final class SeoDashboardAccessPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'seo';

    protected static string $view = 'filament.ops.pages.seo-operations';

    public function mount(): void
    {
        $this->redirect(SeoOperationsPage::getUrl());
    }

    public static function canAccess(): bool
    {
        return SeoOperationsPage::canAccess();
    }
}
