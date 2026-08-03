<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\PromotionContextFactory;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class ContentPromoteExactPackage extends Command
{
    protected $signature = 'content:promote-exact-package
        {--package= : Allowlisted backend-authority package directory}
        {--expected-package-sha256= : Exact package SHA-256}
        {--lane= : English parity lane W1-W8}
        {--subscope= : Optional registered lane subscope}
        {--phase= : preflight, draft-import, publish, or live-qa}
        {--receipt= : New immutable receipt destination}
        {--json : Emit one redacted JSON object to stdout}';

    protected $description = 'Promote one exact backend-authority content package through verified automated CMS lifecycle gates.';

    public function handle(PromotionContextFactory $contexts, ExactPackagePromotionService $promotion): int
    {
        try {
            if (! (bool) $this->option('json')) {
                throw new DomainException('json_mode_required');
            }
            $context = $contexts->make(
                package: (string) $this->option('package'),
                packageSha256: (string) $this->option('expected-package-sha256'),
                lane: (string) $this->option('lane'),
                subscope: is_string($this->option('subscope')) ? (string) $this->option('subscope') : null,
            );
            $result = $promotion->execute(
                context: $context,
                phase: strtolower(trim((string) $this->option('phase'))),
                receiptPath: (string) $this->option('receipt'),
            );
            $payload = [
                'ok' => true,
                'result' => 'SUCCEEDED',
                'receipt_kind' => $result['receipt']['receipt_kind'],
                'receipt_sha256' => $result['receipt_sha256'],
            ];
            $exit = self::SUCCESS;
        } catch (Throwable $throwable) {
            \Illuminate\Support\Facades\Log::error('W5 promotion phase failed', [
                'phase' => (string) $this->option('phase'),
                'exception' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);
            $payload = [
                'ok' => false,
                'result' => 'BLOCKED',
                'error_code' => $this->safeErrorCode($throwable),
            ];
            $exit = self::FAILURE;
        }

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $exit;
    }

    private function safeErrorCode(Throwable $throwable): string
    {
        $candidate = strtolower(trim((string) strtok($throwable->getMessage(), ':')));

        return preg_match('/\A[a-z0-9_]{1,96}\z/', $candidate) === 1 ? $candidate : 'unexpected_error';
    }
}
