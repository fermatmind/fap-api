<?php

declare(strict_types=1);

namespace App\Services\BigFive\Cms;

use App\Models\BigFiveV2EditorialRevision;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use App\Services\BigFive\ResultPageV2\Composer\BigFiveV2PilotPayloadComposer;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2ProjectionRouteInputAdapter;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteDrivenSelectorInputBuilder;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteMatrixLookup;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use RuntimeException;

final class BigFiveV2EditorialPreviewService
{
    public const PREVIEW_SCHEMA_VERSION = 'big5_v2_editorial_preview.v0_1';

    public function __construct(
        private readonly BigFiveV2ProjectionRouteInputAdapter $routeInputAdapter = new BigFiveV2ProjectionRouteInputAdapter(),
        private readonly BigFiveV2RouteMatrixLookup $routeMatrixLookup = new BigFiveV2RouteMatrixLookup(),
        private readonly BigFiveV2RouteDrivenSelectorInputBuilder $selectorInputBuilder = new BigFiveV2RouteDrivenSelectorInputBuilder(),
        private readonly BigFiveV2DeterministicSelector $selector = new BigFiveV2DeterministicSelector(),
        private readonly BigFiveV2PilotPayloadComposer $composer = new BigFiveV2PilotPayloadComposer(),
        private readonly BigFiveResultPageV2Validator $validator = new BigFiveResultPageV2Validator(),
    ) {}

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    public function preview(BigFiveV2EditorialRevision $revision, array $scoreResult): array
    {
        $this->assertPreviewable($revision);

        $routeInput = $this->routeInputAdapter->fromScoreResult($scoreResult);
        if ($routeInput === null) {
            throw new RuntimeException('Big Five V2 editorial preview requires a valid route-driven score result.');
        }

        $routeRow = $this->routeMatrixLookup->lookup($routeInput);
        if ($routeRow === null) {
            throw new RuntimeException('Big Five V2 editorial preview route matrix lookup failed closed.');
        }

        $selectorInput = $this->selectorInputBuilder->build($routeInput, $routeRow);
        $selection = $this->selector->select($selectorInput);
        $envelope = $this->composer->compose($selectorInput, $selection);
        $errors = $this->validator->validateEnvelope($envelope);
        if ($errors !== []) {
            throw new RuntimeException('Big Five V2 editorial preview payload failed contract validation: '.implode('; ', $errors));
        }

        $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? [];
        if (! is_array($payload)) {
            throw new RuntimeException('Big Five V2 editorial preview payload envelope is malformed.');
        }

        return [
            'preview_schema_version' => self::PREVIEW_SCHEMA_VERSION,
            'preview_mode' => 'isolated_editorial_preview',
            'runtime_contract_valid' => true,
            'preview_version_pinned' => true,
            'preview_release_linked' => true,
            'runtime_mutation_allowed' => false,
            'publish_action_allowed' => false,
            'revision' => [
                'id' => (string) $revision->id,
                'asset_key' => (string) $revision->asset_key,
                'asset_path' => (string) $revision->asset_path,
                'version_no' => (int) $revision->version_no,
                'workflow_state' => (string) $revision->workflow_state,
                'release_snapshot_id' => (string) $revision->release_snapshot_id,
                'release_snapshot_hash' => (string) $revision->release_snapshot_hash,
            ],
            'route' => [
                'combination_key' => $routeInput->combinationKey,
                'profile_key' => $routeRow->profileKey,
                'interpretation_scope' => $routeRow->interpretationScope,
            ],
            'payload_envelope' => $envelope,
            'payload_fingerprint' => hash(
                'sha256',
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            ),
        ];
    }

    private function assertPreviewable(BigFiveV2EditorialRevision $revision): void
    {
        if (! $revision->hasReleaseSnapshotLinkage()) {
            throw new RuntimeException('Big Five V2 editorial preview requires immutable release snapshot linkage.');
        }

        if ($revision->workflow_state === BigFiveV2EditorialRevision::STATE_ARCHIVED) {
            throw new RuntimeException('Big Five V2 archived editorial revisions are not previewable.');
        }
    }
}
