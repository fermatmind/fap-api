<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\SupportArticleResource\Pages;

use App\Filament\Ops\Resources\SupportArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupportArticle extends EditRecord
{
    protected static string $resource = SupportArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(false),
        ];
    }
}
