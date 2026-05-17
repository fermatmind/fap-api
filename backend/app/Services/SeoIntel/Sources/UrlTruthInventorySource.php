<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Sources;

use App\Services\SeoIntel\UrlTruthInventoryRecord;

interface UrlTruthInventorySource
{
    /**
     * @return list<UrlTruthInventoryRecord>
     */
    public function candidates(): array;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array;
}
