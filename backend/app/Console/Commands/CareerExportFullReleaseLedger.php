<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportFullReleaseLedger extends Command
{
    private const PUBLIC_RESOLUTION_LEDGER_KIND = 'career_public_resolution_ledger';

    private const PUBLIC_RESOLUTION_LEDGER_VERSION = 'career.public_resolution_ledger.2786.v1';

    private const PUBLIC_RESOLUTION_SCOPE = 'career_2786_public_resolution';

    /**
     * @var list<string>
     */
    private const PUBLIC_RESOLUTION_TYPES = [
        'public_canonical_job',
        'public_alias_redirect',
        'public_family_hub',
        'public_cn_proxy_page',
        'public_nonindex_reference',
        'keep_non_public_with_policy',
        'blocked_until_governance_approval',
    ];

    protected $signature = 'career:export-full-release-ledger
        {--timestamp= : Optional output directory timestamp segment}
        {--public-resolution-plan= : Optional Career full-upload planner JSON for 2786-row public resolution ledger}
        {--json : Emit JSON output}';

    protected $description = 'Materialize internal full release ledger authority to storage/app/private/career_release_ledger.';

    public function __construct(
        private readonly CareerFullReleaseLedgerProjectionService $projectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
            $rootDir = storage_path('app/private/career_release_ledger');
            $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
            $tmpDir = $finalDir.'.tmp';

            if (is_dir($finalDir) || is_dir($tmpDir)) {
                throw new \RuntimeException('release ledger output dir already exists: '.$finalDir);
            }

            $projected = $this->projectionService->build();
            $ledger = (array) ($projected[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? []);
            if ($ledger === []) {
                throw new \RuntimeException('empty full release ledger payload');
            }

            $publicResolutionPlanPath = $this->resolvePublicResolutionPlanPath();
            if ($publicResolutionPlanPath !== null) {
                $ledger['public_resolution'] = $this->buildPublicResolutionLedger($publicResolutionPlanPath);
            }

            File::ensureDirectoryExists($tmpDir);
            $path = $tmpDir.DIRECTORY_SEPARATOR.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME;
            $encoded = json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode full release ledger payload');
            }
            File::put($path, $encoded.PHP_EOL);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize release ledger output dir: '.$finalDir);
            }

            $payload = [
                'status' => 'materialized',
                'output_dir' => $finalDir,
                'artifacts' => [
                    CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME => $finalDir.DIRECTORY_SEPARATOR.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME,
                ],
                'ledger' => $ledger,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=materialized');
            $this->line('output_dir='.$finalDir);
            $this->line('career-full-release-ledger='.(string) data_get($payload, 'artifacts.career-full-release-ledger.json', ''));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            $normalized = now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for full release ledger export');
        }

        return $normalized;
    }

    private function resolvePublicResolutionPlanPath(): ?string
    {
        $optionValue = $this->option('public-resolution-plan');
        if ($optionValue !== null && trim((string) $optionValue) !== '') {
            return trim((string) $optionValue);
        }

        $envValue = env('CAREER_PUBLIC_RESOLUTION_PLAN_PATH');
        if (is_string($envValue) && trim($envValue) !== '') {
            return trim($envValue);
        }

        $localPlan = '/tmp/career_full_upload_plan_after_directory_draft.json';
        if (app()->environment(['local', 'testing']) && is_file($localPlan)) {
            return $localPlan;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublicResolutionLedger(string $planPath): array
    {
        $normalizedPath = trim($planPath);
        if ($normalizedPath === '' || ! is_file($normalizedPath)) {
            throw new \RuntimeException('career public resolution source plan not found: '.$planPath);
        }

        $payload = json_decode((string) file_get_contents($normalizedPath), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('career public resolution source plan is not valid JSON: '.$planPath);
        }

        $rows = (array) ($payload['rows'] ?? []);
        if ($rows === []) {
            throw new \RuntimeException('career public resolution source plan has no rows: '.$planPath);
        }

        $ledgerRows = [];
        $counts = [
            'total_rows' => 0,
            'public_eligible' => 0,
            'public_canonical_job' => 0,
            'public_alias_redirect' => 0,
            'public_family_hub' => 0,
            'public_cn_proxy_page' => 0,
            'public_nonindex_reference' => 0,
            'keep_non_public_with_policy' => 0,
            'blocked_until_governance_approval' => 0,
            'sitemap_eligible' => 0,
            'llms_eligible' => 0,
            'llms_full_eligible' => 0,
            'CN_proxy_hold' => 0,
            'duplicate_identity_hold' => 0,
            'broad_group_hold' => 0,
            'manual_hold' => 0,
            'software_developers_public' => 0,
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ledgerRow = $this->buildPublicResolutionRow($row, $payload, $normalizedPath);
            $ledgerRows[] = $ledgerRow;

            $counts['total_rows']++;
            $resolutionType = (string) $ledgerRow['public_resolution_type'];
            $counts[$resolutionType] = (int) ($counts[$resolutionType] ?? 0) + 1;

            $currentStatus = (string) $ledgerRow['current_status'];
            if (array_key_exists($currentStatus, $counts)) {
                $counts[$currentStatus]++;
            }

            if ((bool) $ledgerRow['public_eligible']) {
                $counts['public_eligible']++;
            }
            if ((bool) $ledgerRow['sitemap_eligible']) {
                $counts['sitemap_eligible']++;
            }
            if ((bool) $ledgerRow['llms_eligible']) {
                $counts['llms_eligible']++;
            }
            if ((bool) $ledgerRow['llms_full_eligible']) {
                $counts['llms_full_eligible']++;
            }
            if (($ledgerRow['source_slug'] ?? null) === 'software-developers' && (bool) $ledgerRow['public_eligible']) {
                $counts['software_developers_public']++;
            }
        }

        usort($ledgerRows, static function (array $left, array $right): int {
            return ((int) ($left['row_number'] ?? 0)) <=> ((int) ($right['row_number'] ?? 0));
        });

        return [
            'ledger_kind' => self::PUBLIC_RESOLUTION_LEDGER_KIND,
            'ledger_version' => self::PUBLIC_RESOLUTION_LEDGER_VERSION,
            'scope' => self::PUBLIC_RESOLUTION_SCOPE,
            'source' => [
                'kind' => 'career_full_upload_planner',
                'path' => $normalizedPath,
                'workbook_path' => $this->normalizeNullableString(data_get($payload, 'workbook.path')),
                'source_workbook_sha' => $this->normalizeNullableString(data_get($payload, 'workbook.sha256')),
                'source_sheet' => $this->normalizeNullableString(data_get($payload, 'workbook.sheet')),
            ],
            'allowed_public_resolution_types' => self::PUBLIC_RESOLUTION_TYPES,
            'counts' => $counts,
            'guards' => [
                'no_decision_no_public_eligibility' => true,
                'hold_rows_non_public_by_default' => true,
                'software_developers_manual_hold_non_public' => true,
                'public_urls_created' => false,
                'db_writes' => false,
                'sitemap_llms_changed' => false,
            ],
            'later_phase_guards' => [
                'manifest_requires_public_resolution_type_public_canonical_job',
                'alias_family_cn_routes_require_explicit_ledger_decision',
                'sitemap_llms_include_only_public_type_gate_eligible_rows',
            ],
            'rows' => $ledgerRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildPublicResolutionRow(array $row, array $payload, string $planPath): array
    {
        $sourceSlug = $this->normalizeNullableString($row['slug'] ?? null)
            ?? $this->normalizeNullableString($row['canonical_slug'] ?? null)
            ?? '';
        $canonicalSlug = $this->normalizeNullableString($row['canonical_slug'] ?? null) ?? $sourceSlug;
        $currentStatus = $this->normalizeNullableString($row['status'] ?? null) ?? 'blocked_until_governance_approval';

        $decision = $this->defaultGovernanceDecision($sourceSlug, $currentStatus);
        $resolutionType = $decision['public_resolution_type'];
        if (! in_array($resolutionType, self::PUBLIC_RESOLUTION_TYPES, true)) {
            throw new \RuntimeException('unsupported public resolution type for '.$sourceSlug.': '.$resolutionType);
        }

        $publicEligible = (bool) $decision['public_eligible'];

        return [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'source_workbook_sha' => $this->normalizeNullableString(data_get($payload, 'workbook.sha256')),
            'source_sheet' => $this->normalizeNullableString(data_get($payload, 'workbook.sheet')),
            'source_slug' => $sourceSlug,
            'current_status' => $currentStatus,
            'governance_decision' => $decision['governance_decision'],
            'public_resolution_type' => $resolutionType,
            'target_canonical_slug' => $publicEligible ? $canonicalSlug : null,
            'redirect_target' => null,
            'family_hub_slug' => null,
            'cn_proxy_policy' => $currentStatus === 'CN_proxy_hold' ? 'blocked_until_CN_authority_policy' : null,
            'indexability' => $decision['indexability'],
            'sitemap_eligible' => $publicEligible,
            'llms_eligible' => $publicEligible,
            'llms_full_eligible' => $publicEligible,
            'public_eligible' => $publicEligible,
            'source_authority_model' => $publicEligible ? 'existing_approved_canonical_career_asset' : 'governance_required_before_publication',
            'identity_resolution_reason' => $this->defaultIdentityResolutionReason($currentStatus),
            'reviewer' => $publicEligible ? 'career_canonical_baseline' : null,
            'approved_at' => null,
            'evidence_refs' => [
                'planner' => [
                    'kind' => 'career_full_upload_planner',
                    'path' => $planPath,
                ],
                'workbook' => [
                    'kind' => 'career_workbook',
                    'path' => $this->normalizeNullableString(data_get($payload, 'workbook.path')),
                    'sha256' => $this->normalizeNullableString(data_get($payload, 'workbook.sha256')),
                    'sheet' => $this->normalizeNullableString(data_get($payload, 'workbook.sheet')),
                    'row_number' => (int) ($row['row_number'] ?? 0),
                ],
            ],
            'schema_policy' => $publicEligible ? 'career_job_schema_existing_release_gate' : 'requires_public_type_policy_before_public_schema',
            'boundary_disclaimer_required' => $currentStatus === 'CN_proxy_hold',
            'rollback_condition' => $publicEligible ? 'revert_public_resolution_ledger_decision' : 'remove_or_revert_governance_decision_before_publication',
        ];
    }

    /**
     * @return array{governance_decision:string,public_resolution_type:string,indexability:string,public_eligible:bool}
     */
    private function defaultGovernanceDecision(string $sourceSlug, string $currentStatus): array
    {
        if (
            $sourceSlug !== 'software-developers'
            && in_array($currentStatus, ['already_imported_validated', 'upload_candidate'], true)
        ) {
            return [
                'governance_decision' => 'baseline_public_canonical',
                'public_resolution_type' => 'public_canonical_job',
                'indexability' => 'indexable',
                'public_eligible' => true,
            ];
        }

        if ($currentStatus === 'manual_hold') {
            return [
                'governance_decision' => 'manual_hold_default_non_public',
                'public_resolution_type' => 'keep_non_public_with_policy',
                'indexability' => 'not_public',
                'public_eligible' => false,
            ];
        }

        return [
            'governance_decision' => 'default_hold_requires_governance',
            'public_resolution_type' => 'blocked_until_governance_approval',
            'indexability' => 'not_public',
            'public_eligible' => false,
        ];
    }

    private function defaultIdentityResolutionReason(string $currentStatus): ?string
    {
        return match ($currentStatus) {
            'duplicate_identity_hold' => 'duplicate_identity_requires_canonical_winner',
            'broad_group_hold' => 'broad_group_requires_family_or_split_policy',
            'CN_proxy_hold' => 'CN_proxy_requires_authority_policy',
            'manual_hold' => 'manual_release_decision_required',
            default => null,
        };
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
