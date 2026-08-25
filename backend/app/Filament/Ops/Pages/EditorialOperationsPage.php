<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

/**
 * Backward-compatible URL alias for the canonical Content workspace.
 */
class EditorialOperationsPage extends ContentWorkspacePage
{
    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $slug = 'editorial-operations';
}
