<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\Career2786ReadinessPolicyClassifier;
use App\Domain\Career\Audit\CareerBaselineMetadataInventoryAuditor;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRunContext;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRunContextApprovalGate;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRunContextRequirement;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRunContextStatus;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayerStatus;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use App\Domain\Career\Audit\CareerCanonicalEligibilityScope;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySidecar;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerEntityContextArtifact;
use App\Domain\Career\Audit\CareerEntityContextArtifactIssue;
use App\Domain\Career\Audit\CareerEntityContextArtifactReader;
use App\Domain\Career\Audit\CareerIndexStateAuthorityAuditor;
use App\Domain\Career\Audit\CareerIndexStateAuthorityIssue;
use App\Domain\Career\Audit\CareerIndexStateContextArtifact;
use App\Domain\Career\Audit\CareerIndexStateContextArtifactIssue;
use App\Domain\Career\Audit\CareerIndexStateContextArtifactReader;
use App\Domain\Career\Audit\CareerIndexStateContextArtifactRow;
use App\Domain\Career\Audit\CareerOccupationEntityInventoryAuditor;
use App\Domain\Career\Audit\CareerOccupationEntityInventoryIssue;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanResolver;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use App\Domain\Career\Audit\CareerRuntimeProjectionTruthEligibilityAuditor;
use App\Domain\Career\Audit\CareerSeoGeoReadinessAuditor;
use App\Domain\Career\Audit\CareerSurfaceContextArtifact;
use App\Domain\Career\Audit\CareerSurfaceContextArtifactIssue;
use App\Domain\Career\Audit\CareerSurfaceContextArtifactReader;
use App\Domain\Career\Audit\CareerSurfaceReadinessAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class CareerAuditCanonicalEligibility extends Command
{
    protected $signature = 'career:audit-canonical-eligibility
        {--scope=all : Audit scope: all, batch, or slugs}
        {--slugs= : Comma-separated canonical slugs when scope=slugs}
        {--locales= : Comma-separated locales, defaults to en,zh}
        {--public-resolution-plan= : Optional public-resolution planner JSON artifact}
        {--entity-context= : Optional read-only entity context JSON artifact}
        {--index-state-context= : Optional read-only index-state context JSON artifact}
        {--surface-context= : Optional read-only surface context JSON artifact}
        {--projection= : Optional runtime publish projection JSON artifact}
        {--truth= : Optional canonical runtime truth JSON artifact}
        {--ledger= : Optional full release ledger JSON artifact}
        {--json : Emit JSON output}
        {--output= : Optional output path for JSON payload}
        {--context-output= : Optional output path for run context requirements JSON}
        {--include-surfaces : Include surface layer context}
        {--include-live-html : Include optional live HTML surface context}
        {--base-url= : Required only when live HTML verification is requested later}';

    protected $description = 'Read-only Career canonical eligibility audit schema integration.';

    public function handle(): int
    {
        $scope = $this->scopeOption();
        $locales = $this->csvOption('locales', default: 'en,zh');
        $planRows = $this->planRowsForScope($scope);
        $slugs = $this->slugsFromPlanRows($planRows);
        $issues = [];
        $sidecars = [];

        if ($scope !== CareerCanonicalEligibilityScope::SLUGS) {
            $planPath = $this->stringOption('public-resolution-plan');
            if ($planPath === null) {
                $issues['public_resolution_plan_missing'] = 1;
                $sidecars[] = $this->contextSidecar(
                    sidecarId: 'public_resolution_plan_missing',
                    title: 'Public resolution planner path was not supplied.',
                    evidence: [['option' => '--public-resolution-plan']]
                );
            } else {
                $planResult = CareerPublicResolutionPlanResolver::fromPath($planPath);
                if ($planResult->issues !== []) {
                    $issues = $planResult->byReason();
                }
                $planRows = $planResult->rows();
                $slugs = $this->slugsFromPlanRows($planRows);
            }
        }

        $auditContext = $this->auditContext($scope, $planRows, $slugs, $locales);
        $rows = $auditContext['rows'];
        $sidecars = [...$sidecars, ...$auditContext['sidecars']];

        $byReason = $this->mergeReasons($issues, CareerCanonicalEligibilityReport::byReasonFromRows($rows));
        $blockedCount = count(array_filter(
            $rows,
            static fn (CareerCanonicalEligibilityAuditRow $row): bool => $row->overallStatus !== CareerCanonicalEligibilityStatus::PASS
        ));
        $eligibleCount = $this->eligibleSlugCount($rows);
        $report = new CareerCanonicalEligibilityReport(
            status: $byReason === [] && $blockedCount === 0 ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            scope: $scope,
            expectedOccupations: count(array_unique($slugs)),
            auditedOccupations: count(array_unique($slugs)),
            eligibleCount: $eligibleCount,
            blockedCount: $blockedCount,
            byReason: $byReason,
            rows: $rows,
            sidecars: $sidecars,
        );
        $policyResult = (new Career2786ReadinessPolicyClassifier)->classify($report);
        $policySummary = $policyResult->summary();
        $runContext = $this->runContext(
            scope: $scope,
            planRows: $planRows,
            slugs: $slugs,
            locales: $locales,
            byReason: $byReason
        );
        $contextPayload = [
            'context_summary' => $runContext->summary(),
            'run_context' => $runContext->toArray(),
            'read_only' => true,
            'writes_database' => false,
            'audit_command' => 'career:audit-canonical-eligibility',
        ];
        $payload = [
            ...$report->toArray(),
            'context_summary' => $contextPayload['context_summary'],
            'policy_summary' => $policySummary,
            'run_context' => $contextPayload['run_context'],
            'read_only' => true,
            'writes_database' => false,
            'audit_command' => 'career:audit-canonical-eligibility',
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            $this->error('failed to encode career canonical eligibility audit payload');

            return self::FAILURE;
        }

        $output = $this->stringOption('output');
        if ($output !== null) {
            File::put($output, $encoded.PHP_EOL);
        }

        $contextOutput = $this->stringOption('context-output');
        if ($contextOutput !== null) {
            $contextEncoded = json_encode($contextPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($contextEncoded)) {
                $this->error('failed to encode career canonical eligibility audit context payload');

                return self::FAILURE;
            }

            File::put($contextOutput, $contextEncoded.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line('status='.$payload['status']);
            $this->line('scope='.$payload['scope']);
            $this->line('audited_occupations='.(string) $payload['audited_occupations']);
            $this->line('blocked_count='.(string) $payload['blocked_count']);
        }

        return $payload['status'] === CareerCanonicalEligibilityStatus::PASS ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array<string, int>  $byReason
     */
    private function runContext(string $scope, array $planRows, array $slugs, array $locales, array $byReason): CareerCanonicalEligibilityAuditRunContext
    {
        $planPath = $this->stringOption('public-resolution-plan');
        $entityContextPath = $this->stringOption('entity-context');
        $indexStateContextPath = $this->stringOption('index-state-context');
        $surfaceContextPath = $this->stringOption('surface-context');
        $projectionPath = $this->stringOption('projection');
        $truthPath = $this->stringOption('truth');
        $ledgerPath = $this->stringOption('ledger');
        $includeSurfaces = $surfaceContextPath !== null || (bool) $this->option('include-surfaces') || (bool) $this->option('include-live-html');
        $includeLiveHtml = (bool) $this->option('include-live-html');
        $baseUrl = $this->stringOption('base-url');

        $requirements = [
            $this->contextRequirement(
                contextId: 'public_resolution_plan',
                label: 'Public resolution planner JSON',
                status: $planPath !== null || $scope === CareerCanonicalEligibilityScope::SLUGS
                    ? CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
                    : CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
                requiredForMeaningfulRerun: $scope !== CareerCanonicalEligibilityScope::SLUGS,
                blocks80Readiness: true,
                requiresApproval: false,
                approvalGateId: null,
                suppliedInput: $planPath,
                requiredInput: '--public-resolution-plan=/path/to/plan.json',
                reason: $scope === CareerCanonicalEligibilityScope::SLUGS
                    ? 'Explicit slug scope was supplied; full 2786 rerun still requires the planner artifact.'
                    : 'The full 2786 audit must start from the authorized public-resolution planner JSON.',
                evidence: [['scope' => $scope, 'found_rows' => count($planRows)]]
            ),
            $this->contextRequirement(
                contextId: 'entity_db_context',
                label: 'Occupation entity read-only context',
                status: $entityContextPath !== null
                    ? ($this->entityContextSupplied($entityContextPath, $byReason)
                        ? CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
                        : CareerCanonicalEligibilityAuditRunContextStatus::MISSING)
                    : ($this->reasonPresent($byReason, 'entity_db_context_missing')
                    ? CareerCanonicalEligibilityAuditRunContextStatus::MISSING
                    : CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED),
                requiredForMeaningfulRerun: true,
                blocks80Readiness: true,
                requiresApproval: $entityContextPath === null && $this->reasonPresent($byReason, 'entity_db_context_missing'),
                approvalGateId: $entityContextPath === null && $this->reasonPresent($byReason, 'entity_db_context_missing') ? 'production_readonly_db_context' : null,
                suppliedInput: $entityContextPath ?? ($this->reasonPresent($byReason, 'entity_db_context_missing') ? null : 'configured read-only DB connection'),
                requiredInput: '--entity-context=/path/to/entity_context.json',
                reason: 'Entity inventory can be satisfied by an approved read-only entity context artifact or by a configured read-only DB context.',
                evidence: [['row_reason_count' => $byReason['entity_db_context_missing'] ?? 0]]
            ),
            $this->contextRequirement(
                contextId: 'index_state_context',
                label: 'Index-state read-only context',
                status: $indexStateContextPath !== null
                    ? ($this->indexContextSupplied($indexStateContextPath, $byReason)
                        ? CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
                        : CareerCanonicalEligibilityAuditRunContextStatus::MISSING)
                    : ($this->reasonPresent($byReason, 'index_state_context_missing')
                    ? CareerCanonicalEligibilityAuditRunContextStatus::MISSING
                    : CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED),
                requiredForMeaningfulRerun: true,
                blocks80Readiness: true,
                requiresApproval: $indexStateContextPath === null && $this->reasonPresent($byReason, 'index_state_context_missing'),
                approvalGateId: $indexStateContextPath === null && $this->reasonPresent($byReason, 'index_state_context_missing') ? 'production_readonly_db_context' : null,
                suppliedInput: $indexStateContextPath ?? ($this->reasonPresent($byReason, 'index_state_context_missing') ? null : 'configured read-only DB connection'),
                requiredInput: '--index-state-context=/path/to/index_state_context.json',
                reason: 'Index-state authority can be satisfied by an approved read-only index-state context artifact or by a configured read-only DB context.',
                evidence: [['row_reason_count' => $byReason['index_state_context_missing'] ?? 0]]
            ),
            $this->contextRequirement(
                contextId: 'runtime_projection_context',
                label: 'Runtime publish projection artifact',
                status: $projectionPath !== null && is_file($projectionPath)
                    ? CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
                    : CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
                requiredForMeaningfulRerun: true,
                blocks80Readiness: true,
                requiresApproval: $projectionPath === null,
                approvalGateId: $projectionPath === null ? 'production_runtime_projection_export' : null,
                suppliedInput: $projectionPath,
                requiredInput: '--projection=/path/to/runtime_projection.json',
                reason: 'Runtime projection eligibility must be audited from a read-only projection artifact before readiness planning.',
                evidence: [['row_reason_count' => $byReason['runtime_projection_context_missing'] ?? 0]]
            ),
            $this->contextRequirement(
                contextId: 'runtime_truth_context',
                label: 'Canonical runtime truth artifact',
                status: $truthPath !== null && is_file($truthPath)
                    ? CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
                    : CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
                requiredForMeaningfulRerun: true,
                blocks80Readiness: true,
                requiresApproval: $truthPath === null,
                approvalGateId: $truthPath === null ? 'production_truth_export' : null,
                suppliedInput: $truthPath,
                requiredInput: '--truth=/path/to/runtime_truth.json',
                reason: 'Canonical runtime truth must be supplied as a read-only artifact before runtime readiness can be interpreted.',
                evidence: [['row_reason_count' => $byReason['runtime_truth_context_missing'] ?? 0]]
            ),
            $this->contextRequirement(
                contextId: 'surface_context',
                label: 'Surface readiness artifact mode',
                status: $surfaceContextPath !== null
                    ? ($this->surfaceContextSupplied($surfaceContextPath, $byReason)
                        ? CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
                        : CareerCanonicalEligibilityAuditRunContextStatus::MISSING)
                    : ($includeSurfaces
                        ? CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
                        : CareerCanonicalEligibilityAuditRunContextStatus::MISSING),
                requiredForMeaningfulRerun: true,
                blocks80Readiness: true,
                requiresApproval: false,
                approvalGateId: null,
                suppliedInput: $surfaceContextPath ?? ($includeSurfaces ? '--include-surfaces' : null),
                requiredInput: '--surface-context=/path/to/surface_context.json or --include-surfaces',
                reason: 'Surface readiness is supplied by a read-only surface context artifact or explicitly requested safe artifact mode; missing surface context is not 5572 independent data defects.',
                evidence: [[
                    'row_reason_count' => $byReason['surface_context_missing'] ?? 0,
                    'artifact_issue_count' => array_sum(array_intersect_key($byReason, array_flip(CareerSurfaceContextArtifactIssue::reasons()))),
                ]]
            ),
            $this->contextRequirement(
                contextId: 'live_html_context',
                label: 'Optional live HTML verification context',
                status: $includeLiveHtml && $baseUrl !== null
                    ? CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
                    : ($includeLiveHtml ? CareerCanonicalEligibilityAuditRunContextStatus::MISSING : CareerCanonicalEligibilityAuditRunContextStatus::NOT_REQUESTED),
                requiredForMeaningfulRerun: false,
                blocks80Readiness: $includeLiveHtml && $baseUrl === null,
                requiresApproval: $includeLiveHtml && $baseUrl === null,
                approvalGateId: $includeLiveHtml && $baseUrl === null ? 'live_html_crawl' : null,
                suppliedInput: $baseUrl,
                requiredInput: '--base-url=https://example.com',
                reason: 'Live HTML checks are optional and require an explicit base URL plus approval before production-scale crawling.',
                evidence: [['include_live_html' => $includeLiveHtml]]
            ),
        ];

        $staticSources = $this->staticSourceContext($byReason);

        return new CareerCanonicalEligibilityAuditRunContext(
            planner: [
                'status' => $planPath !== null || $scope === CareerCanonicalEligibilityScope::SLUGS
                    ? CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
                    : CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
                'public_resolution_plan_path' => $planPath,
                'expected_rows' => count(array_unique($slugs)),
                'found_rows' => count($planRows),
                'source_type' => $planPath !== null ? 'public_resolution_plan_json' : ($scope === CareerCanonicalEligibilityScope::SLUGS ? 'explicit_slugs' : 'missing'),
            ],
            entity: [
                'entity_db_context' => $requirements[1]->status,
                'entity_context_path' => $entityContextPath,
                'local_db_available' => $entityContextPath === null && ! $this->reasonPresent($byReason, 'entity_db_context_missing'),
                'production_readonly_db_needed' => $entityContextPath === null && $this->reasonPresent($byReason, 'entity_db_context_missing'),
            ],
            index: [
                'index_state_context' => $requirements[2]->status,
                'index_state_context_path' => $indexStateContextPath,
                'local_db_available' => $indexStateContextPath === null && ! $this->reasonPresent($byReason, 'index_state_context_missing'),
                'production_readonly_db_needed' => $indexStateContextPath === null && $this->reasonPresent($byReason, 'index_state_context_missing'),
            ],
            runtime: [
                'ledger_path' => $ledgerPath,
                'projection_path' => $projectionPath,
                'truth_path' => $truthPath,
                'runtime_projection_context' => $requirements[3]->status,
                'runtime_truth_context' => $requirements[4]->status,
            ],
            surface: [
                'include_surfaces' => $includeSurfaces,
                'include_live_html' => $includeLiveHtml,
                'base_url' => $baseUrl,
                'surface_context_path' => $surfaceContextPath,
                'surface_context' => $requirements[5]->status,
                'live_html_context' => $requirements[6]->status,
            ],
            staticSources: $staticSources,
            requirements: $requirements,
            approvalGates: $this->approvalGates(),
            suggestedRerunModes: [
                'planner_only',
                'planner_plus_local_db',
                'planner_plus_runtime_artifacts',
                'planner_plus_surface_base_url',
                'full_readonly_context',
            ],
        );
    }

    /**
     * @param  array<string, int>  $byReason
     * @return array<string, mixed>
     */
    private function staticSourceContext(array $byReason): array
    {
        $repoRoot = dirname(base_path());
        $zhBaseline = $repoRoot.'/content_baselines/career_jobs/career_jobs.zh-CN.json';
        $enBaseline = $repoRoot.'/content_baselines/career_jobs/career_jobs.en.json';

        return [
            'baseline_sources' => [
                'zh_baseline_path' => $zhBaseline,
                'zh_baseline_exists' => is_file($zhBaseline),
                'en_baseline_path' => $enBaseline,
                'en_baseline_exists' => is_file($enBaseline),
            ],
            'seo_geo_sources' => [
                'sitemap_source_available' => ! $this->reasonPresent($byReason, 'sitemap_missing'),
                'llms_source_available' => ! $this->reasonPresent($byReason, 'llms_missing'),
                'llms_full_source_available' => ! $this->reasonPresent($byReason, 'llms_full_missing'),
                'structured_data_source_available' => ! $this->reasonPresent($byReason, 'structured_data_missing'),
                'citation_metadata_source_available' => ! $this->reasonPresent($byReason, 'citation_metadata_missing'),
            ],
            'context_note' => 'Static source gaps are artifact/metadata blockers, not approval to mutate DB or publish.',
        ];
    }

    /**
     * @param  list<mixed>  $evidence
     */
    private function contextRequirement(
        string $contextId,
        string $label,
        string $status,
        bool $requiredForMeaningfulRerun,
        bool $blocks80Readiness,
        bool $requiresApproval,
        ?string $approvalGateId,
        ?string $suppliedInput,
        ?string $requiredInput,
        string $reason,
        array $evidence,
    ): CareerCanonicalEligibilityAuditRunContextRequirement {
        return new CareerCanonicalEligibilityAuditRunContextRequirement(
            contextId: $contextId,
            label: $label,
            status: $status,
            requiredForMeaningfulRerun: $requiredForMeaningfulRerun,
            blocks80Readiness: $blocks80Readiness,
            requiresApproval: $requiresApproval,
            approvalGateId: $approvalGateId,
            suppliedInput: $suppliedInput,
            requiredInput: $requiredInput,
            reason: $reason,
            evidence: $evidence,
        );
    }

    /**
     * @return list<CareerCanonicalEligibilityAuditRunContextApprovalGate>
     */
    private function approvalGates(): array
    {
        return [
            new CareerCanonicalEligibilityAuditRunContextApprovalGate(
                gateId: 'production_readonly_db_context',
                title: 'Approved read-only DB context for Career 2786 audit',
                required: true,
                reason: 'Entity and index-state findings cannot be interpreted as production data defects until an approved read-only DB context is supplied.',
                approvalPhraseTemplate: 'I approve a read-only Career 2786 audit DB query against <environment> using <credential/profile>, with no writes.',
                allowedAction: 'Read-only audit queries for occupations and index_states.',
                forbiddenActions: ['insert', 'update', 'delete', 'backfill', 'apply', 'rollback', 'quarantine'],
                preconditions: ['read-only credentials', 'no mutation command flags', 'output path outside production storage'],
            ),
            new CareerCanonicalEligibilityAuditRunContextApprovalGate(
                gateId: 'production_runtime_projection_export',
                title: 'Approved read-only runtime projection artifact export',
                required: true,
                reason: 'Runtime projection must be supplied as an artifact; export approval must not imply rollout apply.',
                approvalPhraseTemplate: 'I approve read-only runtime projection export for Career 2786 audit; no apply or publish is approved.',
                allowedAction: 'Read-only export of runtime projection JSON.',
                forbiddenActions: ['rollout apply', 'publish', 'backfill', 'production mutation'],
                preconditions: ['export command inspected', 'artifact output path selected', 'no apply flags'],
            ),
            new CareerCanonicalEligibilityAuditRunContextApprovalGate(
                gateId: 'production_truth_export',
                title: 'Approved read-only canonical runtime truth artifact export',
                required: true,
                reason: 'Canonical runtime truth must be supplied as an artifact before runtime eligibility can be interpreted.',
                approvalPhraseTemplate: 'I approve read-only canonical runtime truth export for Career 2786 audit; no apply or publish is approved.',
                allowedAction: 'Read-only export of canonical runtime truth JSON.',
                forbiddenActions: ['rollout apply', 'publish', 'backfill', 'production mutation'],
                preconditions: ['export command inspected', 'artifact output path selected', 'no apply flags'],
            ),
            new CareerCanonicalEligibilityAuditRunContextApprovalGate(
                gateId: 'live_html_crawl',
                title: 'Approved live HTML verification crawl',
                required: false,
                reason: 'Live HTML verification can create external traffic and must be explicitly scoped before use.',
                approvalPhraseTemplate: 'I approve read-only live HTML verification for Career 2786 against <base-url> with rate limit <limit>; no deploy is approved.',
                allowedAction: 'Read-only live HTML fetches within the approved rate limit.',
                forbiddenActions: ['deploy', 'frontend mutation', 'rollout apply', 'publication'],
                preconditions: ['base URL confirmed', 'rate limit defined', 'small-sample verifier passed'],
            ),
            new CareerCanonicalEligibilityAuditRunContextApprovalGate(
                gateId: 'db_backfill_apply',
                title: 'DB backfill apply approval',
                required: false,
                reason: 'Backfills mutate DB state and are outside this command run context PR.',
                approvalPhraseTemplate: 'I approve Career 2786 DB backfill apply for reviewed artifact <path> in <environment>.',
                allowedAction: 'Apply the reviewed DB remediation plan only.',
                forbiddenActions: ['unreviewed slug mutation', 'rollout apply', 'deploy'],
                preconditions: ['reviewed dry-run artifact', 'explicit slug list', 'rollback plan'],
            ),
            new CareerCanonicalEligibilityAuditRunContextApprovalGate(
                gateId: 'index_state_apply',
                title: 'Index-state apply approval',
                required: false,
                reason: 'Index-state writes can change public eligibility and require a reviewed plan.',
                approvalPhraseTemplate: 'I approve index_state remediation apply for reviewed slugs <path> in <environment>.',
                allowedAction: 'Apply reviewed index_state changes only.',
                forbiddenActions: ['unreviewed index changes', 'rollout apply', 'deploy'],
                preconditions: ['previous-state snapshot', 'reviewed dry-run artifact', 'explicit slug list'],
            ),
            new CareerCanonicalEligibilityAuditRunContextApprovalGate(
                gateId: 'rollout_apply',
                title: 'Expansion rollout apply approval',
                required: false,
                reason: 'Expansion apply publishes/exposes occupations and must wait for eligibility proof.',
                approvalPhraseTemplate: 'I approve Career canonical expansion apply for batch <80|300|800|2786> using manifest <path>.',
                allowedAction: 'Apply only the reviewed expansion manifest.',
                forbiddenActions: ['unreviewed publication', 'implicit deploy', 'implicit backfill'],
                preconditions: ['full audit pass or approved sidecars', 'manifest reviewed', 'rollback group slug list reviewed'],
            ),
        ];
    }

    /**
     * @param  array<string, int>  $byReason
     */
    private function reasonPresent(array $byReason, string $reason): bool
    {
        return ($byReason[$reason] ?? 0) > 0;
    }

    /**
     * @param  array<string, int>  $byReason
     */
    private function entityContextSupplied(?string $path, array $byReason): bool
    {
        if ($path === null) {
            return false;
        }

        foreach ([CareerEntityContextArtifactIssue::FILE_MISSING, CareerEntityContextArtifactIssue::JSON_INVALID, CareerEntityContextArtifactIssue::ROWS_MISSING] as $reason) {
            if ($this->reasonPresent($byReason, $reason)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, int>  $byReason
     */
    private function indexContextSupplied(?string $path, array $byReason): bool
    {
        if ($path === null) {
            return false;
        }

        foreach ([CareerIndexStateContextArtifactIssue::FILE_MISSING, CareerIndexStateContextArtifactIssue::JSON_INVALID, CareerIndexStateContextArtifactIssue::ROWS_MISSING] as $reason) {
            if ($this->reasonPresent($byReason, $reason)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, int>  $byReason
     */
    private function surfaceContextSupplied(?string $path, array $byReason): bool
    {
        if ($path === null) {
            return false;
        }

        foreach ([CareerSurfaceContextArtifactIssue::FILE_MISSING, CareerSurfaceContextArtifactIssue::JSON_INVALID, CareerSurfaceContextArtifactIssue::ROWS_MISSING] as $reason) {
            if ($this->reasonPresent($byReason, $reason)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $values
     */
    private function containsString(string $rawValue, array $values, string $needle): bool
    {
        if (str_contains($rawValue, $needle)) {
            return true;
        }

        foreach ($values as $value) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{rows: list<CareerCanonicalEligibilityAuditRow>, sidecars: list<CareerCanonicalEligibilitySidecar>}
     */
    private function auditContext(string $scope, array $planRows, array $slugs, array $locales): array
    {
        $sidecars = [];
        $plan = $this->planFromRows($planRows);

        $baselineResult = (new CareerBaselineMetadataInventoryAuditor)->auditRows($planRows);
        [$entityStatuses, $entitySidecars] = $this->entityStatuses($slugs, $scope);
        [$indexStatuses, $indexSidecars] = $this->indexStatuses($slugs);

        [$runtimeStatuses, $runtimeSidecars] = $this->runtimeStatuses($plan, $planRows, $slugs, $locales);
        [$surfaceStatuses, $surfaceSidecars] = $this->surfaceStatuses($planRows, $slugs, $locales);
        $seoGeoResult = (new CareerSeoGeoReadinessAuditor)->audit($planRows, $locales, $this->seoGeoArtifact($planRows, $locales));

        $sidecars = [
            ...$sidecars,
            ...$entitySidecars,
            ...$baselineResult->sidecars,
            ...$indexSidecars,
            ...$runtimeSidecars,
            ...$seoGeoResult->sidecars,
            ...$surfaceSidecars,
        ];

        $baselineStatuses = $this->statusMapBySlug($baselineResult->rows, 'canonicalSlug', 'baselineStatus');
        $seoGeoStatuses = $this->statusMapByKey($seoGeoResult->rows, 'canonicalSlug', 'locale', 'seoGeoStatus');

        $rows = [];
        foreach (array_values(array_unique($slugs)) as $slug) {
            foreach ($locales as $locale) {
                $runtimeStatus = $runtimeStatuses[$this->rowKey($slug, $locale)] ?? $this->unverifiedLayer(
                    CareerCanonicalEligibilityLayer::RUNTIME,
                    ['runtime_projection_context_missing', 'runtime_truth_context_missing'],
                    [['projection' => $this->stringOption('projection'), 'truth' => $this->stringOption('truth')]],
                    'runtime_projection_truth'
                );
                $surfaceStatus = $surfaceStatuses[$this->rowKey($slug, $locale)] ?? $this->unverifiedLayer(
                    CareerCanonicalEligibilityLayer::SURFACE,
                    ['surface_context_missing'],
                    [['include_surfaces' => (bool) $this->option('include-surfaces')]],
                    'surface_artifacts'
                );
                $rows[] = $this->auditRow(
                    scope: $scope,
                    slug: $slug,
                    locale: $locale,
                    entityStatus: $entityStatuses[$slug] ?? $this->unverifiedLayer(CareerCanonicalEligibilityLayer::ENTITY, ['entity_db_context_missing'], [['slug' => $slug]], 'occupations'),
                    baselineStatus: $baselineStatuses[$slug] ?? $this->unverifiedLayer(CareerCanonicalEligibilityLayer::BASELINE, ['baseline_context_missing'], [['slug' => $slug]], 'career_baselines'),
                    indexStatus: $indexStatuses[$slug] ?? $this->unverifiedLayer(CareerCanonicalEligibilityLayer::INDEX, ['index_state_context_missing'], [['slug' => $slug]], 'index_states'),
                    runtimeStatus: $runtimeStatus,
                    seoGeoStatus: $seoGeoStatuses[$this->rowKey($slug, $locale)] ?? $this->unverifiedLayer(CareerCanonicalEligibilityLayer::SEO_GEO, ['seo_geo_context_missing'], [['slug' => $slug, 'locale' => $locale]], 'seo_geo_artifacts'),
                    surfaceStatus: $surfaceStatus
                );
            }
        }

        return ['rows' => $rows, 'sidecars' => $sidecars];
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     */
    private function planFromRows(array $planRows): CareerPublicResolutionPlan
    {
        return new CareerPublicResolutionPlan(
            sourcePath: $this->stringOption('public-resolution-plan') ?? 'command_slugs',
            checksum: null,
            rows: $planRows,
        );
    }

    /**
     * @param  list<string>  $slugs
     * @return array{0: array<string, CareerCanonicalEligibilityLayerStatus>, 1: list<CareerCanonicalEligibilitySidecar>}
     */
    private function entityStatuses(array $slugs, string $scope): array
    {
        $entityContextPath = $this->stringOption('entity-context');
        if ($entityContextPath !== null) {
            $artifact = CareerEntityContextArtifactReader::fromPath($entityContextPath);

            return [
                $this->entityStatusesFromArtifact($artifact, $slugs),
                $artifact->issues === [] ? [] : [
                    $this->contextSidecar(
                        sidecarId: 'entity_context_artifact_issue',
                        title: 'Entity context artifact has structured validation issues.',
                        evidence: [['artifact' => $artifact->toArray()]]
                    ),
                ],
            ];
        }

        $entityResult = (new CareerOccupationEntityInventoryAuditor)->auditSlugs($slugs, $scope);
        $entityByReason = $entityResult->byReason();
        if (array_key_exists('occupation_query_failed', $entityByReason)) {
            return [
                $this->statusMapForSlugs($slugs, CareerCanonicalEligibilityLayer::ENTITY, ['entity_db_context_missing'], [['reason' => 'occupation_query_failed']], 'occupations'),
                [
                    $this->contextSidecar(
                        sidecarId: 'entity_db_context_missing',
                        title: 'Occupation entity inventory DB context could not be queried.',
                        scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_INSIDE,
                        mayContinueTrain: false,
                        evidence: [['reason' => 'occupation_query_failed']]
                    ),
                ],
            ];
        }

        return [$this->statusMapBySlug($entityResult->rows, 'canonicalSlug', 'entityStatus'), $entityResult->sidecars];
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function entityStatusesFromArtifact(CareerEntityContextArtifact $artifact, array $slugs): array
    {
        $rowsBySlug = $artifact->rowsBySlug();
        $issuesBySlug = $artifact->issuesBySlug();
        $globalReasons = array_values(array_unique(array_map(
            static fn (CareerEntityContextArtifactIssue $issue): string => $issue->reason,
            $artifact->globalIssues()
        )));
        $globalEvidence = array_map(
            static fn (CareerEntityContextArtifactIssue $issue): array => $issue->toArray(),
            $artifact->globalIssues()
        );

        $statuses = [];
        foreach ($slugs as $slug) {
            $row = $rowsBySlug[$slug] ?? null;
            $rowIssues = $issuesBySlug[$slug] ?? [];
            $reasons = [
                ...$globalReasons,
                ...array_map(static fn (CareerEntityContextArtifactIssue $issue): string => $issue->reason, $rowIssues),
            ];
            $evidence = [
                ...$globalEvidence,
                ...array_map(static fn (CareerEntityContextArtifactIssue $issue): array => $issue->toArray(), $rowIssues),
            ];

            if ($row === null) {
                $statuses[$slug] = new CareerCanonicalEligibilityLayerStatus(
                    layer: CareerCanonicalEligibilityLayer::ENTITY,
                    status: CareerCanonicalEligibilityStatus::BLOCKED,
                    reasons: array_values(array_unique([...$reasons, CareerOccupationEntityInventoryIssue::OCCUPATION_MISSING])),
                    evidence: [...$evidence, ['canonical_slug' => $slug, 'context_row_present' => false]],
                    source: 'entity_context_artifact'
                );

                continue;
            }

            if (! $row->occupationExists) {
                $reasons[] = CareerOccupationEntityInventoryIssue::OCCUPATION_MISSING;
            }
            if ($row->missingEntityFields !== []) {
                $reasons[] = CareerOccupationEntityInventoryIssue::ENTITY_FIELD_MISSING;
            }

            $statuses[$slug] = new CareerCanonicalEligibilityLayerStatus(
                layer: CareerCanonicalEligibilityLayer::ENTITY,
                status: $reasons === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
                reasons: array_values(array_unique($reasons)),
                evidence: [
                    ...$evidence,
                    [
                        'canonical_slug' => $slug,
                        'occupation_exists' => $row->occupationExists,
                        'occupation_id' => $row->occupationId,
                        'missing_entity_fields' => $row->missingEntityFields,
                    ],
                ],
                source: 'entity_context_artifact'
            );
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $slugs
     * @return array{0: array<string, CareerCanonicalEligibilityLayerStatus>, 1: list<CareerCanonicalEligibilitySidecar>}
     */
    private function indexStatuses(array $slugs): array
    {
        $indexContextPath = $this->stringOption('index-state-context');
        if ($indexContextPath !== null) {
            $artifact = CareerIndexStateContextArtifactReader::fromPath($indexContextPath);

            return [
                $this->indexStatusesFromArtifact($artifact, $slugs),
                $artifact->issues === [] ? [] : [
                    $this->contextSidecar(
                        sidecarId: 'index_context_artifact_issue',
                        title: 'Index-state context artifact has structured validation issues.',
                        evidence: [['artifact' => $artifact->toArray()]]
                    ),
                ],
            ];
        }

        try {
            $indexResult = (new CareerIndexStateAuthorityAuditor)->auditSlugs($slugs);

            return [$this->statusMapBySlug($indexResult->rows, 'canonicalSlug', 'indexStatus'), $indexResult->sidecars];
        } catch (Throwable $exception) {
            return [
                [],
                [
                    $this->contextSidecar(
                        sidecarId: 'index_state_context_missing',
                        title: 'Index-state authority context could not be queried.',
                        scopeRelation: CareerCanonicalEligibilitySidecar::SCOPE_RELATION_INSIDE,
                        mayContinueTrain: false,
                        evidence: [$this->exceptionEvidence($exception)]
                    ),
                ],
            ];
        }
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function indexStatusesFromArtifact(CareerIndexStateContextArtifact $artifact, array $slugs): array
    {
        $rowsBySlug = $artifact->rowsBySlug();
        $issuesBySlug = $artifact->issuesBySlug();
        $globalReasons = array_values(array_unique(array_map(
            static fn (CareerIndexStateContextArtifactIssue $issue): string => $issue->reason,
            $artifact->globalIssues()
        )));
        $globalEvidence = array_map(
            static fn (CareerIndexStateContextArtifactIssue $issue): array => $issue->toArray(),
            $artifact->globalIssues()
        );

        $statuses = [];
        foreach ($slugs as $slug) {
            $row = $rowsBySlug[$slug] ?? null;
            $rowIssues = $issuesBySlug[$slug] ?? [];
            $reasons = [
                ...$globalReasons,
                ...array_map(static fn (CareerIndexStateContextArtifactIssue $issue): string => $issue->reason, $rowIssues),
            ];
            $evidence = [
                ...$globalEvidence,
                ...array_map(static fn (CareerIndexStateContextArtifactIssue $issue): array => $issue->toArray(), $rowIssues),
            ];

            if ($row === null) {
                $statuses[$slug] = new CareerCanonicalEligibilityLayerStatus(
                    layer: CareerCanonicalEligibilityLayer::INDEX,
                    status: CareerCanonicalEligibilityStatus::BLOCKED,
                    reasons: array_values(array_unique([...$reasons, CareerIndexStateAuthorityIssue::INDEX_STATE_MISSING])),
                    evidence: [...$evidence, ['canonical_slug' => $slug, 'context_row_present' => false]],
                    source: 'index_state_context_artifact'
                );

                continue;
            }

            $reasons = [...$reasons, ...$this->indexArtifactRowReasons($row)];
            $statuses[$slug] = new CareerCanonicalEligibilityLayerStatus(
                layer: CareerCanonicalEligibilityLayer::INDEX,
                status: $reasons === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
                reasons: array_values(array_unique($reasons)),
                evidence: [
                    ...$evidence,
                    [
                        'canonical_slug' => $slug,
                        'latest_index_state' => $row->latestIndexState,
                        'public_facing_state' => $row->publicFacingState,
                        'index_eligible' => $row->indexEligible,
                        'reason_codes' => $row->reasonCodes,
                    ],
                ],
                source: 'index_state_context_artifact'
            );
        }

        return $statuses;
    }

    /**
     * @return list<string>
     */
    private function indexArtifactRowReasons(CareerIndexStateContextArtifactRow $row): array
    {
        $state = strtolower((string) ($row->publicFacingState ?? $row->latestIndexState ?? ''));
        $rawState = strtolower((string) ($row->latestIndexState ?? $row->publicFacingState ?? ''));
        $reasonCodes = array_map('strtolower', $row->reasonCodes);
        $reasons = [];

        if ($state === '' && $rawState === '') {
            $reasons[] = CareerIndexStateAuthorityIssue::INDEX_STATE_MISSING;
        }
        if ($row->indexEligible === false) {
            $reasons[] = CareerIndexStateAuthorityIssue::INDEX_ELIGIBLE_FALSE;
        }
        if ($state === 'noindex' || $rawState === 'noindex') {
            $reasons[] = CareerIndexStateAuthorityIssue::EXPLICIT_NOINDEX_BLOCK;
        }
        if ($this->containsString($rawState, $reasonCodes, 'quarantine')) {
            $reasons[] = CareerIndexStateAuthorityIssue::QUARANTINE_BLOCK;
        }
        if ($this->containsString($rawState, $reasonCodes, 'rollback')) {
            $reasons[] = CareerIndexStateAuthorityIssue::ROLLBACK_BLOCK;
        }
        if ($reasons === [] && ! in_array($state, ['indexed', 'indexable'], true)) {
            $reasons[] = CareerIndexStateAuthorityIssue::INDEX_STATE_NOT_INDEXED_LIKE;
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{0: array<string, CareerCanonicalEligibilityLayerStatus>, 1: list<CareerCanonicalEligibilitySidecar>}
     */
    private function runtimeStatuses(CareerPublicResolutionPlan $plan, array $planRows, array $slugs, array $locales): array
    {
        $projectionPath = $this->stringOption('projection');
        $truthPath = $this->stringOption('truth');
        $ledgerPath = $this->stringOption('ledger');
        $sidecars = [];

        if ($projectionPath === null || $truthPath === null) {
            $reasons = [];
            $evidence = [];
            if ($projectionPath === null) {
                $reasons[] = 'runtime_projection_context_missing';
                $evidence[] = ['missing_option' => '--projection'];
                $sidecars[] = $this->contextSidecar(
                    sidecarId: 'runtime_projection_context_missing',
                    title: 'Runtime projection artifact was not supplied.',
                    evidence: [['missing_option' => '--projection']]
                );
            }
            if ($truthPath === null) {
                $reasons[] = 'runtime_truth_context_missing';
                $evidence[] = ['missing_option' => '--truth'];
                $sidecars[] = $this->contextSidecar(
                    sidecarId: 'runtime_truth_context_missing',
                    title: 'Runtime truth artifact was not supplied.',
                    evidence: [['missing_option' => '--truth']]
                );
            }

            return [$this->statusMapForSlugLocales($slugs, $locales, CareerCanonicalEligibilityLayer::RUNTIME, $reasons, $evidence, 'runtime_projection_truth'), $sidecars];
        }

        [$projection, $projectionSidecar] = $this->jsonArtifact($projectionPath, 'runtime_projection_context_missing', '--projection');
        [$truth, $truthSidecar] = $this->jsonArtifact($truthPath, 'runtime_truth_context_missing', '--truth');
        $sidecars = array_values(array_filter([$projectionSidecar, $truthSidecar]));
        $ledger = null;
        if ($ledgerPath !== null) {
            [$ledger, $ledgerSidecar] = $this->jsonArtifact($ledgerPath, 'runtime_ledger_context_missing', '--ledger');
            if ($ledgerSidecar !== null) {
                $sidecars[] = $ledgerSidecar;
            }
        }

        if ($projection === null || $truth === null) {
            return [$this->statusMapForSlugLocales($slugs, $locales, CareerCanonicalEligibilityLayer::RUNTIME, ['runtime_artifact_invalid'], [['projection' => $projectionPath, 'truth' => $truthPath]], 'runtime_projection_truth'), $sidecars];
        }

        $runtimeResult = (new CareerRuntimeProjectionTruthEligibilityAuditor)->auditPlan($plan, $locales, $projection, $truth, $ledger);

        return [$this->statusMapByKey($runtimeResult->rows, 'canonicalSlug', 'locale', 'runtimeStatus'), [...$sidecars, ...$runtimeResult->sidecars]];
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{0: array<string, CareerCanonicalEligibilityLayerStatus>, 1: list<CareerCanonicalEligibilitySidecar>}
     */
    private function surfaceStatuses(array $planRows, array $slugs, array $locales): array
    {
        $surfaceContextPath = $this->stringOption('surface-context');
        if ($surfaceContextPath !== null) {
            $artifact = CareerSurfaceContextArtifactReader::fromPath($surfaceContextPath);

            return [
                $this->surfaceStatusesFromArtifact(
                    artifact: $artifact,
                    planRows: $planRows,
                    slugs: $slugs,
                    locales: $locales,
                    includeLiveHtml: (bool) $this->option('include-live-html'),
                    baseUrl: $this->stringOption('base-url'),
                ),
                $artifact->issues === [] ? [] : [
                    $this->contextSidecar(
                        sidecarId: 'surface_context_artifact_issue',
                        title: 'Surface context artifact has structured validation issues.',
                        evidence: [['artifact' => $artifact->toArray()]]
                    ),
                ],
            ];
        }

        $includeSurfaces = (bool) $this->option('include-surfaces') || (bool) $this->option('include-live-html');
        if (! $includeSurfaces) {
            return [
                $this->statusMapForSlugLocales($slugs, $locales, CareerCanonicalEligibilityLayer::SURFACE, ['surface_context_missing'], [['include_surfaces' => false]], 'surface_artifacts'),
                [
                    $this->contextSidecar(
                        sidecarId: 'surface_context_missing',
                        title: 'Surface artifact mode was not requested.',
                        evidence: [['option' => '--include-surfaces']]
                    ),
                ],
            ];
        }

        $includeLiveHtml = (bool) $this->option('include-live-html');
        $baseUrl = $this->stringOption('base-url');
        $sidecars = [];
        if ($includeLiveHtml && $baseUrl === null) {
            $sidecars[] = $this->contextSidecar(
                sidecarId: 'surface_live_html_context_missing',
                title: 'Live HTML validation was requested without a base URL.',
                evidence: [['missing_option' => '--base-url']]
            );
        }

        $surfaceResult = (new CareerSurfaceReadinessAuditor)->audit(
            planRows: $planRows,
            locales: $locales,
            apiArtifact: $this->surfaceApiArtifact($slugs, $locales),
            includeLiveHtml: $includeLiveHtml,
            baseUrl: $baseUrl,
            liveHtmlByKey: [],
        );

        return [$this->statusMapByKey($surfaceResult->rows, 'canonicalSlug', 'locale', 'surfaceStatus'), [...$sidecars, ...$surfaceResult->sidecars]];
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function surfaceStatusesFromArtifact(CareerSurfaceContextArtifact $artifact, array $planRows, array $slugs, array $locales, bool $includeLiveHtml, ?string $baseUrl): array
    {
        $globalIssues = $artifact->globalIssues();
        $globalReasons = array_values(array_unique(array_map(
            static fn (CareerSurfaceContextArtifactIssue $issue): string => $issue->reason,
            $globalIssues
        )));
        $globalEvidence = array_map(
            static fn (CareerSurfaceContextArtifactIssue $issue): array => $issue->toArray(),
            $globalIssues
        );

        if ($this->hasBlockingSurfaceArtifactIssue($globalReasons)) {
            return $this->statusMapForSlugLocales(
                slugs: $slugs,
                locales: $locales,
                layer: CareerCanonicalEligibilityLayer::SURFACE,
                reasons: $globalReasons,
                evidence: $globalEvidence,
                source: 'surface_context_artifact'
            );
        }

        $surfaceResult = (new CareerSurfaceReadinessAuditor)->audit(
            planRows: $planRows,
            locales: $locales,
            apiArtifact: ['items' => $artifact->surfaceApiRows()],
            includeLiveHtml: $includeLiveHtml,
            baseUrl: $baseUrl,
            liveHtmlByKey: [],
        );
        $auditedStatuses = $this->statusMapByKey($surfaceResult->rows, 'canonicalSlug', 'locale', 'surfaceStatus');
        $rowsByKey = $artifact->rowsByKey();
        $issuesByKey = $artifact->issuesByKey();
        $statuses = [];

        foreach (array_values(array_unique($slugs)) as $slug) {
            foreach ($locales as $locale) {
                $key = $this->rowKey($slug, $locale);
                if (! isset($rowsByKey[$key])) {
                    $statuses[$key] = new CareerCanonicalEligibilityLayerStatus(
                        layer: CareerCanonicalEligibilityLayer::SURFACE,
                        status: CareerCanonicalEligibilityStatus::UNVERIFIED,
                        reasons: array_values(array_unique([...$globalReasons, CareerSurfaceContextArtifactIssue::ROW_MISSING])),
                        evidence: [
                            ...$globalEvidence,
                            ['canonical_slug' => $slug, 'locale' => $locale, 'context_row_present' => false],
                        ],
                        source: 'surface_context_artifact',
                    );

                    continue;
                }

                $artifactIssues = [
                    ...$globalIssues,
                    ...($issuesByKey[$key] ?? []),
                ];
                $artifactReasons = array_map(
                    static fn (CareerSurfaceContextArtifactIssue $issue): string => $issue->reason,
                    $artifactIssues
                );
                $artifactEvidence = array_map(
                    static fn (CareerSurfaceContextArtifactIssue $issue): array => $issue->toArray(),
                    $artifactIssues
                );
                $auditedStatus = $auditedStatuses[$key] ?? $this->unverifiedLayer(
                    CareerCanonicalEligibilityLayer::SURFACE,
                    [CareerSurfaceContextArtifactIssue::ROW_MISSING],
                    [['canonical_slug' => $slug, 'locale' => $locale, 'context_row_present' => false]],
                    'surface_context_artifact'
                );

                $statuses[$key] = new CareerCanonicalEligibilityLayerStatus(
                    layer: CareerCanonicalEligibilityLayer::SURFACE,
                    status: $artifactReasons === []
                        ? $auditedStatus->status
                        : ($auditedStatus->status === CareerCanonicalEligibilityStatus::PASS ? CareerCanonicalEligibilityStatus::BLOCKED : $auditedStatus->status),
                    reasons: array_values(array_unique([...$auditedStatus->reasons, ...$artifactReasons])),
                    evidence: [...$auditedStatus->evidence, ...$artifactEvidence],
                    source: 'surface_context_artifact',
                );
            }
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function hasBlockingSurfaceArtifactIssue(array $reasons): bool
    {
        foreach ([CareerSurfaceContextArtifactIssue::FILE_MISSING, CareerSurfaceContextArtifactIssue::JSON_INVALID, CareerSurfaceContextArtifactIssue::ROWS_MISSING] as $reason) {
            if (in_array($reason, $reasons, true)) {
                return true;
            }
        }

        return false;
    }

    private function auditRow(
        string $scope,
        string $slug,
        string $locale,
        CareerCanonicalEligibilityLayerStatus $entityStatus,
        CareerCanonicalEligibilityLayerStatus $baselineStatus,
        CareerCanonicalEligibilityLayerStatus $indexStatus,
        CareerCanonicalEligibilityLayerStatus $runtimeStatus,
        CareerCanonicalEligibilityLayerStatus $seoGeoStatus,
        CareerCanonicalEligibilityLayerStatus $surfaceStatus,
    ): CareerCanonicalEligibilityAuditRow {
        $safetyStatus = new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::SAFETY,
            status: CareerCanonicalEligibilityStatus::PASS,
            reasons: [],
            evidence: [['read_only' => true, 'writes_database' => false]],
            source: 'career_audit_command',
        );
        $layers = [$entityStatus, $baselineStatus, $indexStatus, $runtimeStatus, $seoGeoStatus, $surfaceStatus, $safetyStatus];
        $reasons = [];
        $evidence = [['slug' => $slug, 'locale' => $locale]];
        foreach ($layers as $layer) {
            $reasons = [...$reasons, ...$layer->reasons];
            if ($layer->evidence !== []) {
                $evidence[] = [$layer->layer => $layer->evidence];
            }
        }
        $reasons = array_values(array_unique($reasons));
        $overallStatus = $this->overallStatus($layers);

        return new CareerCanonicalEligibilityAuditRow(
            slug: $slug,
            locale: $locale,
            sourceScope: $scope,
            entityStatus: $entityStatus,
            baselineStatus: $baselineStatus,
            indexStatus: $indexStatus,
            runtimeStatus: $runtimeStatus,
            seoGeoStatus: $seoGeoStatus,
            surfaceStatus: $surfaceStatus,
            safetyStatus: $safetyStatus,
            overallStatus: $overallStatus,
            severity: $overallStatus === CareerCanonicalEligibilityStatus::PASS
                ? CareerCanonicalEligibilitySeverity::INFO
                : CareerCanonicalEligibilitySeverity::HIGH,
            reasons: $reasons,
            evidence: $evidence,
            sidecars: [],
        );
    }

    /**
     * @param  list<CareerCanonicalEligibilityLayerStatus>  $layers
     */
    private function overallStatus(array $layers): string
    {
        foreach ($layers as $layer) {
            if ($layer->status === CareerCanonicalEligibilityStatus::BLOCKED || $layer->status === CareerCanonicalEligibilityStatus::FAIL) {
                return CareerCanonicalEligibilityStatus::BLOCKED;
            }
        }

        foreach ($layers as $layer) {
            if ($layer->status === CareerCanonicalEligibilityStatus::UNVERIFIED) {
                return CareerCanonicalEligibilityStatus::BLOCKED;
            }
        }

        foreach ($layers as $layer) {
            if ($layer->status === CareerCanonicalEligibilityStatus::WARNING) {
                return CareerCanonicalEligibilityStatus::WARNING;
            }
        }

        return CareerCanonicalEligibilityStatus::PASS;
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     */
    private function eligibleSlugCount(array $rows): int
    {
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row->slug] ??= true;
            $bySlug[$row->slug] = $bySlug[$row->slug] && $row->overallStatus === CareerCanonicalEligibilityStatus::PASS;
        }

        return count(array_filter($bySlug));
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function statusMapForSlugs(array $slugs, string $layer, array $reasons, array $evidence, string $source): array
    {
        $statuses = [];
        foreach ($slugs as $slug) {
            $statuses[$slug] = $this->unverifiedLayer($layer, $reasons, $evidence, $source);
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function statusMapForSlugLocales(array $slugs, array $locales, string $layer, array $reasons, array $evidence, string $source): array
    {
        $statuses = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $statuses[$this->rowKey($slug, $locale)] = $this->unverifiedLayer($layer, $reasons, $evidence, $source);
            }
        }

        return $statuses;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function statusMapBySlug(array $rows, string $slugProperty, string $statusProperty): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (property_exists($row, $slugProperty) && property_exists($row, $statusProperty)) {
                $map[$row->{$slugProperty}] = $row->{$statusProperty};
            }
        }

        return $map;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, CareerCanonicalEligibilityLayerStatus>
     */
    private function statusMapByKey(array $rows, string $slugProperty, string $localeProperty, string $statusProperty): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (property_exists($row, $slugProperty) && property_exists($row, $localeProperty) && property_exists($row, $statusProperty)) {
                $map[$this->rowKey($row->{$slugProperty}, $row->{$localeProperty})] = $row->{$statusProperty};
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     */
    private function unverifiedLayer(string $layer, array $reasons, array $evidence, string $source): CareerCanonicalEligibilityLayerStatus
    {
        return new CareerCanonicalEligibilityLayerStatus(
            layer: $layer,
            status: CareerCanonicalEligibilityStatus::UNVERIFIED,
            reasons: array_values(array_unique($reasons)),
            evidence: $evidence,
            source: $source,
        );
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @param  list<string>  $locales
     * @return array{items: list<array<string, mixed>>}
     */
    private function seoGeoArtifact(array $planRows, array $locales): array
    {
        $items = [];
        foreach ($planRows as $row) {
            if ($row->canonicalSlug === null) {
                continue;
            }
            foreach ($locales as $locale) {
                $items[] = [
                    'slug' => $row->canonicalSlug,
                    'locale' => $locale,
                    'canonical_path' => '/'.$locale.'/career/jobs/'.$row->canonicalSlug,
                    'robots_indexable' => true,
                    'sitemap_eligible' => $this->boolFieldForPlanRow($row, ['ready_for_sitemap', 'Ready_For_Sitemap']),
                    'llms_eligible' => $this->boolFieldForPlanRow($row, ['ready_for_llms', 'Ready_For_LLMS'])
                        ?? ($this->hasLlmsMetadata($row, $locale) ? false : null),
                    'llms_full_eligible' => $this->boolFieldForPlanRow($row, ['ready_for_llms_full', 'Ready_For_LLMS_Full', 'ready_for_llms', 'Ready_For_LLMS'])
                        ?? ($this->hasLlmsMetadata($row, $locale) ? false : null),
                    'structured_data_ready' => $this->hasStructuredData($row, $locale),
                    'dataset_eligible' => true,
                    'search_eligible' => true,
                    'citation_metadata_ready' => $this->hasSeoMetadata($row, $locale),
                ];
            }
        }

        return ['items' => $items];
    }

    /**
     * @param  list<string>  $keys
     */
    private function boolFieldForPlanRow(CareerPublicResolutionPlanRow $row, array $keys): ?bool
    {
        return $this->boolField($row->raw, $keys)
            ?? (is_array($row->raw['raw'] ?? null) ? $this->boolField($row->raw['raw'], $keys) : null);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function boolField(array $row, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value)) {
                return $value === 1;
            }
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['1', 'true', 'yes', 'ready', 'eligible', 'live'], true)) {
                    return true;
                }
                if (in_array($normalized, ['0', 'false', 'no', 'missing', 'not_ready'], true)) {
                    return false;
                }
            }
        }

        return null;
    }

    private function hasStructuredData(CareerPublicResolutionPlanRow $row, string $locale): bool
    {
        $schemaKeys = $locale === 'zh'
            ? ['CN_Occupation_Schema_JSON', 'zh_occupation_schema_json', 'structured_data_json_zh', 'structured_data_zh']
            : ['EN_Occupation_Schema_JSON', 'en_occupation_schema_json', 'structured_data_json_en', 'structured_data_en'];

        if ($this->hasAnyPlanString($row, $schemaKeys)) {
            return true;
        }

        return $row->canonicalSlug !== null
            && $this->nonEmptyString($locale === 'zh' ? $row->titleZh : $row->titleEn)
            && $this->nonEmptyString($row->sourceCode)
            && $this->hasSeoMetadata($row, $locale);
    }

    private function hasSeoMetadata(CareerPublicResolutionPlanRow $row, string $locale): bool
    {
        if ($locale === 'zh') {
            return $this->hasAnyPlanString($row, ['CN_SEO_Title', 'zh_seo_title'])
                && $this->hasAnyPlanString($row, ['CN_SEO_Description', 'zh_seo_description']);
        }

        return $this->hasAnyPlanString($row, ['EN_SEO_Title', 'en_seo_title'])
            && $this->hasAnyPlanString($row, ['EN_SEO_Description', 'en_seo_description']);
    }

    private function hasLlmsMetadata(CareerPublicResolutionPlanRow $row, string $locale): bool
    {
        $queryKeys = $locale === 'zh'
            ? ['CN_Target_Queries', 'zh_target_queries']
            : ['EN_Target_Queries', 'en_target_queries'];

        return $this->hasAnyPlanString($row, $queryKeys)
            && $this->hasAnyPlanString($row, ['Search_Intent_Type', 'search_intent_type']);
    }

    /**
     * @param  list<string>  $keys
     */
    private function hasAnyPlanString(CareerPublicResolutionPlanRow $row, array $keys): bool
    {
        foreach ([$row->raw, is_array($row->raw['raw'] ?? null) ? $row->raw['raw'] : [], is_array($row->raw['seo'] ?? null) ? $row->raw['seo'] : []] as $source) {
            foreach ($keys as $key) {
                if ($this->nonEmptyString($source[$key] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    /**
     * @return array{exception: class-string<Throwable>, message: string}
     */
    private function exceptionEvidence(Throwable $exception): array
    {
        $message = $exception->getMessage();
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500).'...';
        }

        return [
            'exception' => $exception::class,
            'message' => $message,
        ];
    }

    /**
     * @return array{0: array<string, mixed>|list<mixed>|null, 1: CareerCanonicalEligibilitySidecar|null}
     */
    private function jsonArtifact(string $path, string $sidecarId, string $option): array
    {
        if (! is_file($path)) {
            return [
                null,
                $this->contextSidecar(
                    sidecarId: $sidecarId,
                    title: 'Audit artifact path was not found.',
                    evidence: [['option' => $option, 'path' => $path]]
                ),
            ];
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            return [
                null,
                $this->contextSidecar(
                    sidecarId: $sidecarId,
                    title: 'Audit artifact JSON could not be parsed.',
                    evidence: [['option' => $option, 'path' => $path, 'json_error' => json_last_error_msg()]]
                ),
            ];
        }

        return [$payload, null];
    }

    private function contextSidecar(
        string $sidecarId,
        string $title,
        array $evidence,
        string $scopeRelation = CareerCanonicalEligibilitySidecar::SCOPE_RELATION_EXTERNAL,
        bool $mayContinueTrain = true,
    ): CareerCanonicalEligibilitySidecar {
        return new CareerCanonicalEligibilitySidecar(
            sidecarId: $sidecarId,
            title: $title,
            ownerRepo: CareerCanonicalEligibilitySidecar::OWNER_REPO_FAP_API,
            scopeRelation: $scopeRelation,
            introducedByCurrentPr: false,
            affectedSlugs: [],
            affectedLocales: [],
            evidence: $evidence,
            severity: CareerCanonicalEligibilitySeverity::HIGH,
            nextGoal: 'RUN-1 rerun real 2786 canonical eligibility audit',
            mayContinueTrain: $mayContinueTrain,
        );
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     * @return list<string>
     */
    private function slugsFromPlanRows(array $planRows): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (CareerPublicResolutionPlanRow $row): ?string => $row->canonicalSlug,
            $planRows
        ))));
    }

    /**
     * @return list<CareerPublicResolutionPlanRow>
     */
    private function planRowsForScope(string $scope): array
    {
        if ($scope !== CareerCanonicalEligibilityScope::SLUGS) {
            return [];
        }

        return array_map(
            static fn (string $slug): CareerPublicResolutionPlanRow => new CareerPublicResolutionPlanRow(
                rowNumber: null,
                canonicalSlug: $slug,
                publicResolutionState: 'explicit_slug',
                canonicalPublicType: null,
                rolloutState: null,
                projectionState: null,
                indexStateHint: null,
                titleEn: null,
                titleZh: null,
                sourceCode: null,
                family: null,
                batchId: null,
                locales: [],
                raw: ['slug' => $slug, 'status' => 'explicit_slug'],
            ),
            $this->slugsForScope($scope)
        );
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return list<CareerCanonicalEligibilityAuditRow>
     */
    private function surfaceApiArtifact(array $slugs, array $locales): array
    {
        $items = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'api_canonical_path' => '/'.$locale.'/career/jobs/'.$slug,
                    'api_indexable' => true,
                ];
            }
        }

        return ['items' => $items];
    }

    private function rowKey(string $slug, string $locale): string
    {
        return strtolower($slug).'|'.strtolower($locale);
    }

    private function scopeOption(): string
    {
        $scope = $this->stringOption('scope') ?? CareerCanonicalEligibilityScope::ALL;
        CareerCanonicalEligibilityScope::assertValid($scope);

        return $scope;
    }

    /**
     * @return list<string>
     */
    private function slugsForScope(string $scope): array
    {
        if ($scope !== CareerCanonicalEligibilityScope::SLUGS) {
            return [];
        }

        return $this->csvOption('slugs', required: true);
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name, ?string $default = null, bool $required = false): array
    {
        $value = $this->stringOption($name) ?? $default;
        if ($value === null || trim($value) === '') {
            if ($required) {
                throw new \InvalidArgumentException('--'.$name.' is required.');
            }

            return [];
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            $normalized = strtolower(trim($item));
            if ($normalized !== '') {
                $items[] = $normalized;
            }
        }

        return array_values(array_unique($items));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, int>  $left
     * @param  array<string, int>  $right
     * @return array<string, int>
     */
    private function mergeReasons(array $left, array $right): array
    {
        foreach ($right as $reason => $count) {
            $left[$reason] = ($left[$reason] ?? 0) + $count;
        }
        ksort($left);

        return $left;
    }
}
