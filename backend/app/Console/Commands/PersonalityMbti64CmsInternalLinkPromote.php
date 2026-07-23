<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Mbti64CmsInternalLinkPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** @review-surface mbti_approval_batch */
final class PersonalityMbti64CmsInternalLinkPromote extends Command
{
    private const CONTENT_WRITE_FLAGS = [
        'production-content-write-authorized',
        'no-publication-change',
        'no-indexability-change',
        'no-sitemap',
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:mbti64-cms-internal-link-promote
        {--dry-run : Build the exact 32-revision promotion package without writes}
        {--bind-review : Bind only the exact 32-target solo-owner review evidence}
        {--write : Promote the exact reviewed 32-revision internal-link cohort}
        {--rollback : Delete only the exact receipt-bound 32 promoted sections}
        {--cache-closeout-only : Retry only the exact 32-type read-model cache closeout}
        {--confirm-writer-deploy-sha= : Exact deployed REVISION SHA}
        {--confirm-release= : Exact active release directory name}
        {--expected-graph-sha256= : Exact source graph SHA256}
        {--expected-cohort-sha256= : Exact bounded cohort SHA256}
        {--expected-checkpoint112-inventory-sha256= : Checkpoint 112 full inventory SHA256}
        {--expected-revision-identity-sha256= : Exact live 32-revision identity SHA256}
        {--expected-section-inventory-sha256= : Checkpoint 112 target-section inventory SHA256}
        {--expected-rollback-markers-sha256= : Exact 32-target absence markers SHA256}
        {--expected-rows= : Must be 32}
        {--expected-edges= : Must be 64}
        {--attestation= : Exact compact review attestation JSON; bind-review only}
        {--actor-admin-user-id= : Configured solo-owner admin user ID}
        {--expected-review-evidence-sha256= : Exact bound review evidence SHA256}
        {--expected-promotion-authorization-sha256= : Exact promotion authorization SHA256}
        {--expected-promotion-receipt-sha256= : Exact committed promotion receipt SHA256}
        {--expected-rollback-authorization-sha256= : Exact rollback authorization SHA256}
        {--expected-live-state= : promoted or rolled_back; cache-closeout-only}
        {--review-write-authorized : Required with bind-review}
        {--production-content-write-authorized : Required with write or rollback}
        {--cache-mutation-authorized : Required with write, rollback or cache-closeout-only}
        {--rollback-on-readback-failure-authorized : Required with rollback}
        {--no-publication-change : Required with write or rollback}
        {--no-indexability-change : Required with write or rollback}
        {--no-sitemap : Required with write or rollback}
        {--no-llms : Required with write or rollback}
        {--no-search-release : Required with write or rollback}
        {--json : Emit JSON}';

    protected $description = 'Fail-closed revision-bound MBTI EN64 internal-link review, promotion, rollback and cache closeout.';

    public function handle(Mbti64CmsInternalLinkPromotionService $service): int
    {
        try {
            $summary = $this->runMode($service);
        } catch (Throwable $throwable) {
            $summary = [
                'artifact' => Mbti64CmsInternalLinkPromotionService::ARTIFACT,
                'ok' => false,
                'status' => 'fail',
                'writes_committed' => false,
                'errors' => [[
                    'field' => 'command',
                    'code' => $throwable instanceof RuntimeException
                        ? 'runtime_error'
                        : 'unexpected_error',
                    'message' => $throwable->getMessage(),
                ]],
            ];
        }

        $this->line((string) json_encode(
            $summary,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
        ));

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function runMode(Mbti64CmsInternalLinkPromotionService $service): array
    {
        $modes = [
            'dry_run' => (bool) $this->option('dry-run'),
            'bind_review' => (bool) $this->option('bind-review'),
            'write' => (bool) $this->option('write'),
            'rollback' => (bool) $this->option('rollback'),
            'cache_closeout_only' => (bool) $this->option('cache-closeout-only'),
        ];
        if (count(array_filter($modes)) !== 1) {
            throw new RuntimeException(
                'Exactly one of --dry-run, --bind-review, --write, --rollback or --cache-closeout-only is required.'
            );
        }

        if ($modes['bind_review'] && ! (bool) $this->option('review-write-authorized')) {
            throw new RuntimeException('--review-write-authorized is required with --bind-review.');
        }
        if ($modes['write'] || $modes['rollback']) {
            foreach (self::CONTENT_WRITE_FLAGS as $flag) {
                if (! (bool) $this->option($flag)) {
                    throw new RuntimeException('--'.$flag.' is required with --write/--rollback.');
                }
            }
        }
        if (($modes['write'] || $modes['rollback'] || $modes['cache_closeout_only'])
            && ! (bool) $this->option('cache-mutation-authorized')) {
            throw new RuntimeException(
                '--cache-mutation-authorized is required with --write, --rollback or --cache-closeout-only.'
            );
        }
        if ($modes['rollback']
            && ! (bool) $this->option('rollback-on-readback-failure-authorized')) {
            throw new RuntimeException(
                '--rollback-on-readback-failure-authorized is required with --rollback.'
            );
        }

        $options = $this->optionsPayload();

        return match (true) {
            $modes['dry_run'] => $service->plan($options),
            $modes['bind_review'] => $service->bindReview(
                $options,
                $this->requiredAttestation(),
                (int) $this->option('actor-admin-user-id')
            ),
            $modes['write'] => $service->promote($options),
            $modes['rollback'] => $service->rollback($options),
            default => $service->cacheCloseout($options),
        };
    }

    /**
     * @return array<string,string|int>
     */
    private function optionsPayload(): array
    {
        return [
            'confirm_writer_deploy_sha' => trim((string) $this->option('confirm-writer-deploy-sha')),
            'confirm_release' => trim((string) $this->option('confirm-release')),
            'expected_graph_sha256' => trim((string) $this->option('expected-graph-sha256')),
            'expected_cohort_sha256' => trim((string) $this->option('expected-cohort-sha256')),
            'expected_checkpoint112_inventory_sha256' => trim(
                (string) $this->option('expected-checkpoint112-inventory-sha256')
            ),
            'expected_revision_identity_sha256' => trim(
                (string) $this->option('expected-revision-identity-sha256')
            ),
            'expected_section_inventory_sha256' => trim(
                (string) $this->option('expected-section-inventory-sha256')
            ),
            'expected_rollback_markers_sha256' => trim(
                (string) $this->option('expected-rollback-markers-sha256')
            ),
            'expected_rows' => (int) $this->option('expected-rows'),
            'expected_edges' => (int) $this->option('expected-edges'),
            'expected_review_evidence_sha256' => trim(
                (string) $this->option('expected-review-evidence-sha256')
            ),
            'expected_promotion_authorization_sha256' => trim(
                (string) $this->option('expected-promotion-authorization-sha256')
            ),
            'expected_promotion_receipt_sha256' => trim(
                (string) $this->option('expected-promotion-receipt-sha256')
            ),
            'expected_rollback_authorization_sha256' => trim(
                (string) $this->option('expected-rollback-authorization-sha256')
            ),
            'expected_live_state' => trim((string) $this->option('expected-live-state')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function requiredAttestation(): array
    {
        $path = trim((string) $this->option('attestation'));
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if ($path === '' || ! File::isFile($resolved)) {
            throw new RuntimeException('A readable --attestation JSON file is required with --bind-review.');
        }
        $decoded = json_decode((string) File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('The review attestation must be a JSON object.');
        }

        return $decoded;
    }
}
