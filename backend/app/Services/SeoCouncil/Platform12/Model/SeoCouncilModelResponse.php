<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Model;

final readonly class SeoCouncilModelResponse
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public array $output,
        public array $usage,
        public int $transportAttempts,
    ) {}
}
