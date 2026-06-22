<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\Batch2ResultPageReadbackReviewLedger;
use Illuminate\Console\Command;
use Throwable;

final class Batch2ResultPageReadbackReviewLedgerCommand extends Command
{
    protected $signature = 'result-page:batch2-readback-review-ledger
        {--run-id= : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root; defaults to backend/artifacts/result_page_batch2_readback_review_ledger}
        {--bigfive-candidate-dir= : Optional Big Five candidate batch directory}
        {--enneagram-source-ledger-dir= : Optional Enneagram source ledger directory}
        {--enneagram-public-payload-json= : Optional Enneagram public payload JSON}
        {--strict : Return non-zero when the Batch 2 readback/review ledger is blocked}
        {--json : Emit machine-readable summary}';

    protected $description = 'Run the backend-only Batch 2 readback/review ledger authority check without runtime or CMS writes.';

    public function handle(Batch2ResultPageReadbackReviewLedger $ledger): int
    {
        try {
            $payloadJson = trim((string) $this->option('enneagram-public-payload-json'));
            $enneagramPublicPayload = null;
            if ($payloadJson !== '') {
                $enneagramPublicPayload = json_decode($payloadJson, true);
                if (! is_array($enneagramPublicPayload)) {
                    $this->error('enneagram-public-payload-json must decode to an object');

                    return self::FAILURE;
                }
            }

            $summary = $ledger->run([
                'run_id' => trim((string) $this->option('run-id')),
                'artifact_dir' => trim((string) $this->option('artifact-dir')),
                'bigfive_candidate_dir' => trim((string) $this->option('bigfive-candidate-dir')),
                'enneagram_source_ledger_dir' => trim((string) $this->option('enneagram-source-ledger-dir')),
                'enneagram_public_payload' => $enneagramPublicPayload,
            ]);

            $this->render($summary);

            return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function render(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
        $this->line('run_id='.(string) ($summary['run_id'] ?? ''));
        $this->line('artifact_dir='.(string) ($summary['artifact_dir'] ?? ''));
        $this->line('go_no_go='.(string) data_get($summary, 'summary.go_no_go', ''));
        $this->line('production_go_no_go='.(string) data_get($summary, 'summary.production_go_no_go', ''));
        $this->line('next_allowed_pr='.(string) data_get($summary, 'summary.next_allowed_pr', ''));
        $this->line('authority_state='.(string) data_get($summary, 'summary.authority_state', ''));
        $this->line('bigfive_status='.(string) data_get($summary, 'summary.bigfive_status', ''));
        $this->line('enneagram_status='.(string) data_get($summary, 'summary.enneagram_status', ''));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', ''));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $this->line('error='.(string) $error);
        }
    }
}
