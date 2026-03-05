<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleCategoryResource\Pages;

use App\Filament\Ops\Resources\ArticleCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditArticleCategory extends EditRecord
{
    protected static string $resource = ArticleCategoryResource::class;
}
