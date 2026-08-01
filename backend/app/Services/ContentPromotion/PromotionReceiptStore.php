<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Support\ControlledReceiptWriter;
use DomainException;

final class PromotionReceiptStore
{
    public function __construct(private readonly ControlledReceiptWriter $writer) {}

    /** @param array<string, mixed> $receipt
     * @return array{receipt:array<string,mixed>,receipt_sha256:string,path:string}
     */
    public function write(string $path, array $receipt): array
    {
        $path = trim($path);
        $directory = dirname($path);
        $filename = basename($path);
        if ($path === '' || $directory === '.' || ! is_dir($directory) || is_link($directory) || is_file($path)) {
            throw new DomainException('receipt_destination_invalid_or_not_immutable');
        }
        $receipt['receipt_content_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($receipt));
        $bytes = (string) json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $sha256 = $this->writer->write($directory, $filename, $bytes);

        return ['receipt' => $receipt, 'receipt_sha256' => $sha256, 'path' => $path];
    }

    /** @return array{receipt:array<string,mixed>,sha256:string,path:string} */
    public function readPrevious(string $expectedKind, PromotionContext $context): array
    {
        $path = trim((string) env('CONTENT_PROMOTION_PREVIOUS_RECEIPT', ''));
        if ($path === '' || ! is_file($path) || is_link($path)) {
            throw new DomainException('previous_receipt_required');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new DomainException('previous_receipt_unreadable');
        }
        try {
            $receipt = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('previous_receipt_json_invalid');
        }
        if (! is_array($receipt)
            || ($receipt['receipt_kind'] ?? null) !== $expectedKind
            || ($receipt['result'] ?? null) !== 'SUCCEEDED'
            || ($receipt['lane'] ?? null) !== $context->lane
            || ($receipt['subscope'] ?? null) !== $context->subscope
            || ($receipt['package_sha256'] ?? null) !== $context->packageSha256
            || ($receipt['source_commit'] ?? null) !== $context->sourceCommit
            || ($receipt['release_policy_sha256'] ?? null) !== $context->releasePolicySha256
            || ($receipt['expected_count'] ?? null) !== $context->expectedRowCount) {
            throw new DomainException('previous_receipt_contract_mismatch');
        }

        return ['receipt' => $receipt, 'sha256' => hash('sha256', $bytes), 'path' => $path];
    }
}
