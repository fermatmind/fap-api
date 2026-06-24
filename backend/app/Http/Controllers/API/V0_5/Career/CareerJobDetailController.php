<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Services\Career\AiImpactAssets\CareerAiImpactPreviewDetailShellBuilder;
use App\Services\Career\Bundles\CareerCnProxyPublicOwnerSurfaceBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerJobDetailController extends Controller
{
    use RespondsWithNotFound;

    private const INTERNAL_READER_PAYLOAD_KEYS = [
        'source_id',
        'source_ids',
        'source_trace_id',
        'evidence_id',
        'row_hash',
        'search_projection',
        'audit_fields',
        'compile_refs',
        'crosswalk_ids',
        'import_run_id',
        'compile_run_id',
        'index_state_id',
    ];

    private const RAW_READER_PAYLOAD_VALUE_REPLACEMENTS = [
        'industry_proxy_or_recruitment_sample_only' => 'recruitment-market reference only',
        'industry_proxy / recruitment_sample' => 'recruitment-market reference',
        'candidate_only_not_runtime_seo' => 'candidate reference only',
        'source_bounded_reference_only' => 'source-bounded reference only',
        'backend projection review' => 'reader projection review',
        'search candidate only' => 'search reference candidate only',
        'salary_and_outlook' => 'salary and outlook context',
        'staging_preview_only' => 'preview only',
        'industry_proxy' => 'recruitment-market reference',
        'raw enum' => 'reader label',
    ];

    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
        private readonly CareerCnProxyPublicOwnerSurfaceBuilder $cnProxySurfaceBuilder,
        private readonly CareerAiImpactPreviewDetailShellBuilder $aiImpactPreviewDetailShellBuilder,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $publicLocale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';
        $payload = $this->responseCache->jobDetailPayload($slug, $publicLocale);

        if ($payload === null) {
            $cnProxySurface = $this->cnProxySurfaceBuilder->buildBySlug($slug, $publicLocale);
            if ($cnProxySurface !== null) {
                return response()->json($this->projectReaderSafePayload($cnProxySurface));
            }

            $aiImpactPreviewShell = $this->aiImpactPreviewDetailShellBuilder->build($slug, $publicLocale);
            if ($aiImpactPreviewShell !== null) {
                return response()->json($this->projectReaderSafePayload($aiImpactPreviewShell));
            }

            return $this->notFoundResponse('career job detail bundle unavailable.');
        }

        return response()->json($this->projectReaderSafePayload($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function projectReaderSafePayload(array $payload): array
    {
        return $this->stripInternalReaderPayloadKeys($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripInternalReaderPayloadKeys(array $payload): array
    {
        foreach (self::INTERNAL_READER_PAYLOAD_KEYS as $key) {
            unset($payload[$key]);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->stripInternalReaderPayloadKeys($value);
            } elseif (is_string($value)) {
                $payload[$key] = $this->projectReaderSafeString($value);
            }
        }

        return $payload;
    }

    private function projectReaderSafeString(string $value): string
    {
        foreach (self::RAW_READER_PAYLOAD_VALUE_REPLACEMENTS as $rawValue => $readerSafeValue) {
            $value = str_replace($rawValue, $readerSafeValue, $value);
        }

        return $value;
    }
}
