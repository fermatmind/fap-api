<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\SEO;

use App\Http\Controllers\Controller;
use App\Services\SeoIntel\UrlTruth\PublicCanonicalConsumerSnapshot;
use Illuminate\Http\Response;

class LlmsController extends Controller
{
    public function llmsTxt(PublicCanonicalConsumerSnapshot $snapshot): Response
    {
        return $this->textResponse($snapshot->renderLlmsText(false));
    }

    public function llmsFullTxt(PublicCanonicalConsumerSnapshot $snapshot): Response
    {
        return $this->textResponse($snapshot->renderLlmsText(true));
    }

    private function textResponse(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=300, s-maxage=600',
        ]);
    }
}
