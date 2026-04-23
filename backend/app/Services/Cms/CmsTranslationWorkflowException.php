<?php

declare(strict_types=1);

namespace App\Services\Cms;

use RuntimeException;

final class CmsTranslationWorkflowException extends RuntimeException
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(string $message, private readonly array $blockers = [])
    {
        parent::__construct($message);
    }

    /**
     * @return list<string>
     */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
