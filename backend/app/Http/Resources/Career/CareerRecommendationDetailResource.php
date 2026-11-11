<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\Domain\Career\Display\CareerCurrentIdentity;
use App\DTO\Career\CareerRecommendationDetailBundle;
use App\Services\Career\CareerFilePageReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerRecommendationDetailBundle
 */
final class CareerRecommendationDetailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerRecommendationDetailBundle $bundle */
        $bundle = $this->resource;

        $requestedLocale = $request->query('locale');
        $locale = is_string($requestedLocale) ? $requestedLocale : 'zh-CN';
        $payload = app(CareerCurrentIdentity::class)->projectPayload($bundle->toArray(), $locale);
        if (strtolower($locale) !== 'zh-tw') {
            return $payload;
        }

        $reader = app(CareerFilePageReader::class);
        foreach ($payload['matched_jobs'] as $index => $job) {
            $title = $reader->traditionalTitle($job['canonical_slug']);
            if ($title !== null) {
                $payload['matched_jobs'][$index]['title_zh_tw'] = $title;
            }
        }

        return $payload;
    }
}
