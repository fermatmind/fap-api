<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleTagResource\Pages;

use App\Filament\Ops\Resources\ArticleTagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateArticleTag extends CreateRecord
{
    protected static string $resource = ArticleTagResource::class;
}
