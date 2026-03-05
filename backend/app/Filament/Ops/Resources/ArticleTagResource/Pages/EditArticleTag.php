<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleTagResource\Pages;

use App\Filament\Ops\Resources\ArticleTagResource;
use Filament\Resources\Pages\EditRecord;

class EditArticleTag extends EditRecord
{
    protected static string $resource = ArticleTagResource::class;
}
