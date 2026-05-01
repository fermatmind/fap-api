<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleTagResource\Pages;

use App\Filament\Ops\Resources\ArticleTagResource;
use App\Support\OrgContext;
use Filament\Resources\Pages\CreateRecord;

class CreateArticleTag extends CreateRecord
{
    protected static string $resource = ArticleTagResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['org_id'] = max(0, (int) app(OrgContext::class)->orgId());

        return $data;
    }
}
