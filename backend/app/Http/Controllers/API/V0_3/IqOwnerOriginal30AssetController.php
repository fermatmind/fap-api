<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Attempts\AttemptSubmitService;
use App\Services\Iq\IqOwnerOriginal30BankService;
use App\Support\OrgContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class IqOwnerOriginal30AssetController extends Controller
{
    public function __construct(
        private readonly IqOwnerOriginal30BankService $ownerBank,
        private readonly AttemptSubmitService $attemptSubmitService,
    ) {}

    public function show(Request $request, string $path): BinaryFileResponse
    {
        $attemptId = trim((string) $request->query('attempt_id', ''));
        if ($attemptId === '') {
            throw new ApiProblemException(404, 'IQ_OWNER_ASSET_NOT_FOUND', 'owner IQ asset not found.');
        }

        $context = app(OrgContext::class);
        $attempt = $this->attemptSubmitService
            ->ownedAttemptQuery(
                $context,
                $attemptId,
                $this->resolveUserId($request, $context),
                $this->resolveAnonId($request, $context)
            )
            ->first();

        if (! $attempt instanceof Attempt || ! $this->ownerBank->isOwnerOriginalAttempt($attempt)) {
            throw new ApiProblemException(404, 'IQ_OWNER_ASSET_NOT_FOUND', 'owner IQ asset not found.');
        }

        return $this->ownerBank->publicAssetResponse($path);
    }

    private function resolveUserId(Request $request, OrgContext $context): ?string
    {
        $candidates = [
            $request->attributes->get('fm_user_id'),
            $request->attributes->get('user_id'),
            $context->userId(),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '' && preg_match('/^\d+$/', $value) === 1) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function resolveAnonId(Request $request, OrgContext $context): ?string
    {
        $candidates = [
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            $context->anonId(),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
