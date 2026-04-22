<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\SupportArticleResource\Pages;

use App\Filament\Ops\Resources\SupportArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupportArticle extends CreateRecord
{
    protected static string $resource = SupportArticleResource::class;
}
