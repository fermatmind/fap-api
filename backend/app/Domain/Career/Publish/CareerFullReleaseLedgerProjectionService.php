<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class CareerFullReleaseLedgerProjectionService
{
    public const LEDGER_FILENAME = 'career-full-release-ledger.json';

    public function __construct(
        private readonly CareerFullReleaseLedgerService $ledgerService,
        private readonly CareerVerifiedRolloutBatchSlugAuthority $verifiedRolloutBatchSlugAuthority,
    ) {}

    /**
     * @return array{career-full-release-ledger.json: array<string, mixed>}
     */
    public function build(): array
    {
        return [
            self::LEDGER_FILENAME => $this->ledgerService
                ->build($this->verifiedRolloutBatchSlugAuthority->slugs(), trustedRolloutAuthority: true)
                ->toArray(),
        ];
    }
}
