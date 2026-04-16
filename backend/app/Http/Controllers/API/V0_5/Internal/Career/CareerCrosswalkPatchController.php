<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Internal\Career;

use App\Domain\Career\Operations\CareerEditorialPatchHistoryReadModelService;
use App\Domain\Career\Operations\CareerEditorialPatchMutationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\Internal\CareerCrosswalkPatchHistoryResource;
use App\Http\Resources\Career\Internal\CareerCrosswalkPatchMutationResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

final class CareerCrosswalkPatchController extends Controller
{
    public function __construct(
        private readonly CareerEditorialPatchHistoryReadModelService $historyReadModelService,
        private readonly CareerEditorialPatchMutationService $mutationService,
    ) {}

    public function history(string $slug): CareerCrosswalkPatchHistoryResource
    {
        return new CareerCrosswalkPatchHistoryResource($this->historyReadModelService->forSubject($slug));
    }

    public function store(Request $request): CareerCrosswalkPatchMutationResource
    {
        $payload = $request->validate([
            'subject_kind' => ['required', Rule::in(['career_job_detail'])],
            'subject_slug' => ['required', 'string'],
            'target_kind' => ['required', Rule::in(['occupation', 'family'])],
            'target_slug' => ['required', 'string'],
            'crosswalk_mode_override' => ['required', 'string'],
            'review_notes' => ['nullable', 'string'],
        ]);

        try {
            $patch = $this->mutationService->create(array_merge($payload, [
                'created_by' => (string) ($request->attributes->get('fm_admin_user_id') ?? ''),
            ]));
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return new CareerCrosswalkPatchMutationResource([
            'mutation_kind' => 'career_crosswalk_patch_create',
            'status' => 'ok',
            'patch' => $patch,
        ]);
    }

    public function approve(string $patchKey, Request $request): CareerCrosswalkPatchMutationResource
    {
        $payload = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        try {
            $patch = $this->mutationService->approve(
                patchKey: $patchKey,
                reviewNotes: is_string($payload['review_notes'] ?? null) ? $payload['review_notes'] : null,
                reviewedBy: (string) ($request->attributes->get('fm_admin_user_id') ?? ''),
            );
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return new CareerCrosswalkPatchMutationResource([
            'mutation_kind' => 'career_crosswalk_patch_approve',
            'status' => 'ok',
            'patch' => $patch,
        ]);
    }

    public function reject(string $patchKey, Request $request): CareerCrosswalkPatchMutationResource
    {
        $payload = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        try {
            $patch = $this->mutationService->reject(
                patchKey: $patchKey,
                reviewNotes: is_string($payload['review_notes'] ?? null) ? $payload['review_notes'] : null,
                reviewedBy: (string) ($request->attributes->get('fm_admin_user_id') ?? ''),
            );
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return new CareerCrosswalkPatchMutationResource([
            'mutation_kind' => 'career_crosswalk_patch_reject',
            'status' => 'ok',
            'patch' => $patch,
        ]);
    }
}
