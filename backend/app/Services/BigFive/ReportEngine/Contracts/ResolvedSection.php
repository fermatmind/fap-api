<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Contracts;

final class ResolvedSection
{
    /**
     * @param  list<ResolvedBlock>  $blocks
     */
    public function __construct(
        public readonly string $sectionKey,
        public readonly string $status,
        public readonly array $blocks,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'section_key' => $this->sectionKey,
            'status' => $this->status,
            'blocks' => array_map(
                static fn (ResolvedBlock $block): array => $block->toArray(),
                $this->blocks
            ),
        ];
    }
}
