<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

interface CareerRuntimePublishProjectionCoverageSnapshot
{
    /**
     * Return one in-process snapshot keyed by normalized slug and public locale.
     *
     * @param  list<string>  $locales
     * @return array<string, array<string, mixed>>
     */
    public function jobDetailCoverageItems(array $locales): array;
}
