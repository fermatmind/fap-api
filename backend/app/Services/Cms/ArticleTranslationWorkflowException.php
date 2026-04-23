<?php

declare(strict_types=1);

namespace App\Services\Cms;

use RuntimeException;

final class ArticleTranslationWorkflowException extends RuntimeException
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(
        string $message,
        public readonly array $blockers = []
    ) {
        parent::__construct($message);
    }
}
