<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

interface Platform12EvidenceReader
{
    /** Returns only sanitized read-model fields, source references and explicit gaps. */
    public function capture(string $missionId): array;
}
