<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReviewGovernance\ReviewAttestationService;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class ReviewAttestationPreflight extends Command
{
    private const JSON_FAILURE_CODE = 'INVALID_SOLO_OWNER_ATTESTATION_PREFLIGHT';

    private const JSON_FAILURE_MESSAGE = 'Solo-owner attestation preflight validation failed.';

    protected $signature = 'review:attestation-preflight
        {--attestation= : Path to the private compact attestation JSON}
        {--targets= : Path to the exact trusted target-set JSON}
        {--expected-package-sha256= : Optional exact package SHA-256}
        {--json : Emit a redacted JSON result}';

    protected $description = 'Validate a compact solo-owner review attestation without database or production writes.';

    public function __construct(
        private readonly ReviewAttestationService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $attestation = $this->readJsonObject((string) $this->option('attestation'), 'attestation');
            $targetDocument = $this->readJson((string) $this->option('targets'), 'targets');
            $targets = array_is_list($targetDocument)
                ? $targetDocument
                : ($targetDocument['targets'] ?? null);
            if (! is_array($targets) || ! array_is_list($targets)) {
                throw new JsonException('Targets JSON must be a list or an object containing a targets list.');
            }

            $expectedPackageSha256 = trim((string) $this->option('expected-package-sha256'));
            $result = $this->service->preflight(
                $attestation,
                $targets,
                $expectedPackageSha256 === '' ? null : $expectedPackageSha256,
            );
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'status' => 'FAIL_SOLO_OWNER_ATTESTATION_PREFLIGHT',
                    'error_code' => self::JSON_FAILURE_CODE,
                    'error' => self::JSON_FAILURE_MESSAGE,
                    'database_writes' => 0,
                    'production_execution_authorized' => false,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($throwable->getMessage());
            }

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonObject(string $path, string $label): array
    {
        $value = $this->readJson($path, $label);
        if (array_is_list($value)) {
            throw new JsonException(ucfirst($label).' JSON must be an object.');
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private function readJson(string $path, string $label): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new JsonException(ucfirst($label).' JSON path must reference a readable file.');
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new JsonException(ucfirst($label).' JSON must decode to an array or object.');
        }

        return $decoded;
    }
}
