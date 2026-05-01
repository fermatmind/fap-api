<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleCategoryResource\Pages;

use App\Filament\Ops\Resources\ArticleCategoryResource;
use App\Models\ArticleCategory;
use Filament\Resources\Pages\EditRecord;

class EditArticleCategory extends EditRecord
{
    protected static string $resource = ArticleCategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var ArticleCategory $record */
        $record = $this->getRecord();
        $data['org_id'] = (int) $record->org_id;

        return $data;
    }
}
