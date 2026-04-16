<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Internal\Career;

use App\Domain\Career\Operations\CareerCrosswalkReviewQueueReadModelService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\Internal\CareerCrosswalkReviewQueueItemResource;
use App\Http\Resources\Career\Internal\CareerCrosswalkReviewQueueResource;
use Illuminate\Http\Request;

final class CareerCrosswalkReviewQueueController extends Controller
{
    public function __construct(
        private readonly CareerCrosswalkReviewQueueReadModelService $readModelService,
    ) {}

    public function index(Request $request): CareerCrosswalkReviewQueueResource
    {
        return new CareerCrosswalkReviewQueueResource($this->readModelService->list($this->filters($request)));
    }

    public function show(string $slug, Request $request): CareerCrosswalkReviewQueueItemResource
    {
        $item = $this->readModelService->item($slug, $this->filters($request));
        abort_if(! is_array($item), 404, 'career_crosswalk_queue_item_not_found');

        return new CareerCrosswalkReviewQueueItemResource($item);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'crosswalk_mode' => $request->query('crosswalk_mode'),
            'requires_editorial_patch' => $request->query('requires_editorial_patch'),
            'publish_track' => $request->query('publish_track'),
            'batch_origin' => $request->query('batch_origin'),
            'queue_reason' => $request->query('queue_reason'),
            'sort' => $request->query('sort'),
        ];
    }
}
