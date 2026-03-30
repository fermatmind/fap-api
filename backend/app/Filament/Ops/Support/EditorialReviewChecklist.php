<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Services\Cms\ContentPublishGateService;

final class EditorialReviewChecklist
{
    /**
     * @return list<string>
     */
    public static function missing(string $type, object $record): array
    {
        return ContentPublishGateService::missing($type, $record);
    }
}
