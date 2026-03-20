<?php

declare(strict_types=1);

return [
    'manifest_schema_version' => 'storage_manifest.v1',
    'snapshot_schema_version' => 'storage_snapshot.v1',
    'blob_offload_plan_schema_version' => 'storage_blob_offload_plan.v1',
    'blob_catalog_enabled' => false,
    'manifest_catalog_enabled' => false,
    'snapshot_catalog_enabled' => false,
    'artifact_dual_write_enabled' => false,
    'content_pack_v2_dual_write_enabled' => false,
    'resolver_materialization_enabled' => false,
    'blob_offload_disk' => env('STORAGE_BLOB_OFFLOAD_DISK', 's3'),
    'blob_offload_prefix' => env('STORAGE_BLOB_OFFLOAD_PREFIX', 'rollout/blobs'),
    'blob_offload_storage_class' => env('STORAGE_BLOB_OFFLOAD_STORAGE_CLASS'),
];
