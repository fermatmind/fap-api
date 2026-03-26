<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Support\Database\SchemaIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ArtifactSchemaFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('tableProvider')]
    public function test_new_schema_tables_expose_expected_columns_and_indexes(
        string $table,
        array $columns,
        array $indexes
    ): void {
        $this->assertTrue(Schema::hasTable($table), sprintf('Table %s should exist', $table));

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                sprintf('Table %s should have column %s', $table, $column)
            );
        }

        foreach ($indexes as $index) {
            $this->assertTrue(
                SchemaIndex::indexExists($table, $index),
                sprintf('Table %s should have index %s', $table, $index)
            );
        }
    }

    public static function tableProvider(): array
    {
        return [
            'attempt_receipts' => [
                'attempt_receipts',
                [
                    'id',
                    'attempt_id',
                    'seq',
                    'receipt_type',
                    'source_system',
                    'source_ref',
                    'actor_type',
                    'actor_id',
                    'idempotency_key',
                    'payload_json',
                    'occurred_at',
                    'recorded_at',
                    'created_at',
                    'updated_at',
                ],
                [
                    'attempt_receipts_attempt_seq_unique',
                    'attempt_receipts_receipt_type_idx',
                    'attempt_receipts_idempotency_key_idx',
                ],
            ],
            'artifact_lifecycle_jobs' => [
                'artifact_lifecycle_jobs',
                [
                    'id',
                    'attempt_id',
                    'artifact_slot_id',
                    'job_type',
                    'state',
                    'reason_code',
                    'blocked_reason_code',
                    'idempotency_key',
                    'request_payload_json',
                    'result_payload_json',
                    'attempt_count',
                    'started_at',
                    'finished_at',
                    'created_at',
                    'updated_at',
                ],
                [
                    'artifact_lifecycle_jobs_attempt_id_idx',
                    'artifact_lifecycle_jobs_artifact_slot_id_idx',
                    'artifact_lifecycle_jobs_job_type_idx',
                    'artifact_lifecycle_jobs_state_idx',
                    'artifact_lifecycle_jobs_idempotency_key_idx',
                ],
            ],
            'artifact_lifecycle_events' => [
                'artifact_lifecycle_events',
                [
                    'id',
                    'job_id',
                    'attempt_id',
                    'artifact_slot_id',
                    'event_type',
                    'from_state',
                    'to_state',
                    'reason_code',
                    'payload_json',
                    'occurred_at',
                    'created_at',
                    'updated_at',
                ],
                [
                    'artifact_lifecycle_events_job_id_idx',
                    'artifact_lifecycle_events_attempt_id_idx',
                    'artifact_lifecycle_events_artifact_slot_id_idx',
                    'artifact_lifecycle_events_event_type_idx',
                ],
            ],
            'report_artifact_slots' => [
                'report_artifact_slots',
                [
                    'id',
                    'attempt_id',
                    'slot_code',
                    'required_by_product',
                    'current_version_id',
                    'render_state',
                    'delivery_state',
                    'access_state',
                    'integrity_state',
                    'last_error_code',
                    'last_materialized_at',
                    'last_verified_at',
                    'created_at',
                    'updated_at',
                ],
                [
                    'report_artifact_slots_attempt_slot_unique',
                    'report_artifact_slots_attempt_id_idx',
                    'report_artifact_slots_current_version_id_idx',
                ],
            ],
            'report_artifact_versions' => [
                'report_artifact_versions',
                [
                    'id',
                    'artifact_slot_id',
                    'version_no',
                    'source_type',
                    'report_snapshot_id',
                    'storage_blob_id',
                    'created_from_receipt_id',
                    'supersedes_version_id',
                    'manifest_hash',
                    'dir_version',
                    'scoring_spec_version',
                    'report_engine_version',
                    'content_hash',
                    'byte_size',
                    'metadata_json',
                    'created_at',
                    'updated_at',
                ],
                [
                    'report_artifact_versions_slot_version_unique',
                    'report_artifact_versions_artifact_slot_id_idx',
                    'report_artifact_versions_source_type_idx',
                    'report_artifact_versions_report_snapshot_id_idx',
                    'report_artifact_versions_storage_blob_id_idx',
                    'report_artifact_versions_created_from_receipt_id_idx',
                    'report_artifact_versions_supersedes_version_id_idx',
                ],
            ],
            'unified_access_projections' => [
                'unified_access_projections',
                [
                    'id',
                    'attempt_id',
                    'access_state',
                    'report_state',
                    'pdf_state',
                    'reason_code',
                    'projection_version',
                    'actions_json',
                    'payload_json',
                    'produced_at',
                    'refreshed_at',
                    'created_at',
                    'updated_at',
                ],
                [
                    'unified_access_projections_attempt_id_unique',
                    'unified_access_projections_attempt_id_idx',
                ],
            ],
            'report_artifact_postures' => [
                'report_artifact_postures',
                [
                    'id',
                    'attempt_id',
                    'slot_code',
                    'current_version_id',
                    'active_job_id',
                    'render_state',
                    'delivery_state',
                    'access_state',
                    'integrity_state',
                    'attention_state',
                    'blocked_reason_code',
                    'payload_json',
                    'projection_fresh_at',
                    'created_at',
                    'updated_at',
                ],
                [
                    'report_artifact_postures_attempt_slot_unique',
                    'report_artifact_postures_attempt_id_idx',
                    'report_artifact_postures_current_version_id_idx',
                    'report_artifact_postures_active_job_id_idx',
                ],
            ],
            'artifact_reconcile_cases' => [
                'artifact_reconcile_cases',
                [
                    'id',
                    'attempt_id',
                    'slot_code',
                    'case_type',
                    'status',
                    'suspected_cause',
                    'opened_by',
                    'assigned_to',
                    'resolution_code',
                    'resolution_notes',
                    'payload_json',
                    'opened_at',
                    'resolved_at',
                    'created_at',
                    'updated_at',
                ],
                [
                    'artifact_reconcile_cases_attempt_id_idx',
                    'artifact_reconcile_cases_case_type_idx',
                    'artifact_reconcile_cases_status_idx',
                ],
            ],
            'storage_blob_locations' => [
                'storage_blob_locations',
                [
                    'id',
                    'blob_hash',
                    'disk',
                    'storage_path',
                    'location_kind',
                    'size_bytes',
                    'checksum',
                    'etag',
                    'storage_class',
                    'verified_at',
                    'meta_json',
                    'created_at',
                    'updated_at',
                ],
                [
                    'sbl_disk_path_uq',
                    'sbl_blob_hash_idx',
                    'sbl_verified_idx',
                ],
            ],
            'content_release_exact_manifest_files' => [
                'content_release_exact_manifest_files',
                [
                    'id',
                    'content_release_exact_manifest_id',
                    'logical_path',
                    'blob_hash',
                    'size_bytes',
                    'role',
                    'content_type',
                    'encoding',
                    'checksum',
                    'created_at',
                    'updated_at',
                ],
                [
                    'cremf_manifest_path_uq',
                    'cremf_blob_hash_idx',
                ],
            ],
        ];
    }
}
