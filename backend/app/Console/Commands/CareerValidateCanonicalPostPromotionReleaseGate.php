<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Expansion\CanonicalBatchCloseoutResultDTO;
use App\Domain\Career\Expansion\CanonicalPostPromotionReleaseGateService;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use Illuminate\Console\Command;

final class CareerValidateCanonicalPostPromotionReleaseGate extends Command
{
    protected $signature = 'career:validate-canonical-post-promotion-release-gate
        {--manifest= : Canonical expansion manifest JSON artifact}
        {--truth= : Canonical runtime truth JSON artifact}
        {--projection= : Career runtime publish projection JSON artifact}
        {--ledger= : Career full release ledger JSON artifact}
        {--json : Emit JSON output}';

    protected $description = 'Validate post-promotion canonical runtime public release gate acceptance for closeout.';

    public function __construct(
        private readonly CanonicalPostPromotionReleaseGateService $service,
        private readonly CareerCanonicalRuntimeTruthExporter $truthExporter,
        private readonly CareerRuntimePublishProjectionExporter $projectionExporter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $manifest = $this->readManifestArtifact();

            $truth = $this->pathOption('truth') !== null
                ? $this->readArtifact((string) $this->pathOption('truth'))
                : $this->truthExporter->build(
                    ledgerPath: $this->pathOption('ledger'),
                    projectionPath: $this->pathOption('projection'),
                );

            $projection = $this->pathOption('projection') !== null
                ? $this->readArtifact((string) $this->pathOption('projection'))
                : $this->projectionExporter->build($this->pathOption('ledger'));

            $result = $this->service->evaluate($manifest, $this->stripEnvelope($truth), $this->stripEnvelope($projection));
            $payload = $this->normalize($result);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->line('status='.$payload['status']);
                $this->line('closeout_allowed='.($payload['closeout_allowed'] ? 'true' : 'false'));
                $this->line('rollback_required='.($payload['rollback_required'] ? 'true' : 'false'));
                $this->line('quarantine_required='.($payload['quarantine_required'] ? 'true' : 'false'));
                $this->line('release_gate_pass_count='.(string) $payload['release_gate_pass_count']);
                $this->line('release_gate_blocked_count='.(string) $payload['release_gate_blocked_count']);
            }

            return (bool) $payload['closeout_allowed'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function normalize(array $result): array
    {
        $payload = array_merge([
            'validator' => 'career.canonical_post_promotion_release_gate.v1',
            'read_only' => true,
            'writes_database' => false,
            'status' => 'blocked',
        ], $result);

        $payload['result_kind'] = CanonicalBatchCloseoutResultDTO::class;
        $payload['closeout_allowed'] = (bool) data_get($payload, 'closeout_allowed');
        $payload['rollback_required'] = (bool) data_get($payload, 'rollback_required');
        $payload['quarantine_required'] = (bool) data_get($payload, 'quarantine_required');
        $payload['release_gate_pass_count'] = (int) data_get($payload, 'release_gate_pass_count', 0);
        $payload['release_gate_blocked_count'] = (int) data_get($payload, 'release_gate_blocked_count', 0);
        $payload['rollback_group'] = (array) data_get($payload, 'rollback_group', []);
        $payload['promoted_rows'] = (array) data_get($payload, 'promoted_rows', []);
        $payload['failed_slugs'] = (array) data_get($payload, 'failed_slugs', []);
        $payload['failure_reasons'] = (array) data_get($payload, 'failure_reasons', []);

        if ((bool) $payload['closeout_allowed']) {
            $payload['status'] = 'pass';
        }

        return $payload;
    }

    private function pathOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function readManifestArtifact(): array
    {
        $path = $this->pathOption('manifest');
        if ($path === null) {
            return [];
        }

        $artifact = $this->readArtifact($path);

        return is_array($artifact['manifest'] ?? null) ? $artifact['manifest'] : $artifact;
    }

    /**
     * @return array<string, mixed>
     */
    private function readArtifact(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('canonical post-promotion release gate input file not found: '.$path);
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('canonical post-promotion release gate input file is not valid JSON: '.$path);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function stripEnvelope(array $payload): array
    {
        return is_array($payload['items'] ?? null) ? ['items' => $payload['items']] : $payload;
    }
}
