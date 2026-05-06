<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\Cms;

use App\Models\BigFiveV2EditorialAssetIndexEntry;
use App\Models\BigFiveV2EditorialRevision;
use App\Services\BigFive\Cms\BigFiveV2EditorialAssetIndex;
use App\Services\BigFive\Cms\BigFiveV2EditorialPreviewService;
use App\Services\BigFive\Cms\BigFiveV2EditorialWorkflow;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class BigFiveV2EditorialPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_route_driven_runtime_contract_valid_and_release_linked(): void
    {
        $revision = $this->draftRevision();
        $preview = $this->service()->preview($revision, $this->scoreResult());
        $payload = $preview['payload_envelope'][BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;

        $this->assertSame(BigFiveV2EditorialPreviewService::PREVIEW_SCHEMA_VERSION, $preview['preview_schema_version']);
        $this->assertSame('isolated_editorial_preview', $preview['preview_mode']);
        $this->assertTrue($preview['runtime_contract_valid']);
        $this->assertTrue($preview['preview_version_pinned']);
        $this->assertTrue($preview['preview_release_linked']);
        $this->assertFalse($preview['runtime_mutation_allowed']);
        $this->assertFalse($preview['publish_action_allowed']);
        $this->assertSame((string) $revision->release_snapshot_id, $preview['revision']['release_snapshot_id']);
        $this->assertSame((int) $revision->version_no, $preview['revision']['version_no']);
        $this->assertIsString($preview['route']['combination_key']);
        $this->assertNotSame('', $preview['route']['combination_key']);
        $this->assertIsArray($payload);
        $this->assertSame([], (new BigFiveResultPageV2Validator())->validateEnvelope($preview['payload_envelope']));
    }

    public function test_preview_does_not_expose_forbidden_metadata_or_direct_publish_controls(): void
    {
        $preview = $this->service()->preview($this->draftRevision(), $this->scoreResult());
        $keys = $this->recursiveKeys($preview);

        foreach ([
            'internal_metadata',
            'selector_basis',
            'source_reference',
            'runtime_use',
            'production_use_allowed',
            'review_status',
            'qa_notes',
        ] as $forbiddenKey) {
            $this->assertNotContains($forbiddenKey, $keys, $forbiddenKey);
        }

        $this->assertFalse($preview['publish_action_allowed']);
        $this->assertFalse($preview['runtime_mutation_allowed']);
    }

    public function test_preview_fails_closed_without_release_linkage_or_valid_score_result(): void
    {
        $revision = $this->draftRevision();
        $revision->forceFill([
            'release_snapshot_id' => null,
            'release_snapshot_hash' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->preview($revision, $this->scoreResult());
    }

    public function test_archived_revision_is_not_previewable(): void
    {
        $workflow = new BigFiveV2EditorialWorkflow();
        $revision = $workflow->archive($this->draftRevision(), 202, 'archived draft');

        $this->expectException(RuntimeException::class);
        $this->service()->preview($revision, $this->scoreResult());
    }

    public function test_runtime_and_production_remain_disabled(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
    }

    private function service(): BigFiveV2EditorialPreviewService
    {
        return new BigFiveV2EditorialPreviewService();
    }

    private function draftRevision(): BigFiveV2EditorialRevision
    {
        return (new BigFiveV2EditorialWorkflow())->createDraftFromAsset($this->linkedAsset(), 201);
    }

    private function linkedAsset(): BigFiveV2EditorialAssetIndexEntry
    {
        foreach ((new BigFiveV2EditorialAssetIndex())->entries() as $entry) {
            if ($entry->linkedReleaseSnapshotIds !== []) {
                return $entry;
            }
        }

        $this->fail('Expected at least one Big Five V2 asset linked to an immutable release snapshot.');
    }

    /**
     * @return array<string,mixed>
     */
    private function scoreResult(): array
    {
        return [
            'scale_code' => 'BIG5_OCEAN',
            'scores_0_100' => [
                'domains_percentile' => [
                    'O' => 59,
                    'C' => 32,
                    'E' => 20,
                    'A' => 55,
                    'N' => 68,
                ],
                'facets_percentile' => [
                    'N1' => 82,
                    'C1' => 24,
                ],
            ],
            'quality' => ['level' => 'A'],
            'norms' => ['status' => 'CALIBRATED'],
        ];
    }

    /**
     * @param  array<string,mixed>  $value
     * @return list<string>
     */
    private function recursiveKeys(array $value): array
    {
        $keys = [];
        foreach ($value as $key => $item) {
            $keys[] = (string) $key;
            if (is_array($item)) {
                array_push($keys, ...$this->recursiveKeys($item));
            }
        }

        return $keys;
    }
}
