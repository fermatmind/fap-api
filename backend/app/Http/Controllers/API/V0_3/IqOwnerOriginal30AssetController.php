<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Iq\IqOwnerOriginal30BankService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class IqOwnerOriginal30AssetController extends Controller
{
    public function __construct(
        private readonly IqOwnerOriginal30BankService $ownerBank,
    ) {}

    public function show(string $path): BinaryFileResponse
    {
        return $this->ownerBank->publicAssetResponse($path);
    }
}
