<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use Illuminate\Console\Command;

final class ScaleIdentityModeAudit extends Command
{
    protected $signature = 'ops:scale-identity-mode-audit
        {--json=1 : Output JSON payload}
        {--strict=0 : Exit non-zero when violations exist}';

    protected $description = 'Audit scale identity mode combinations and block invalid rollouts.';

    public function handle(): int
    {
        $modes = $this->collectModes();
        $allowed = $this->allowedValues();

        $violations = [];
        $warnings = [];

        $this->validateAllowedModes($modes, $allowed, $violations);
        $this->validateSemanticConstraints($modes, $violations, $warnings);

        $strict = $this->isTruthy($this->option('strict'));
        $payload = [
            'ok' => true,
            'checked_at' => now()->toISOString(),
            'strict' => $strict,
            'pass' => count($violations) === 0,
            'modes' => $modes,
            'allowed_values' => $allowed,
            'violations' => $violations,
            'warnings' => $warnings,
        ];

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('scale identity mode audit');
            foreach ($modes as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $this->line(sprintf('%s=%s', $key, (string) $value));
            }

            foreach ($warnings as $warning) {
                $this->warn(sprintf(
                    '[warning] %s value=%s hint=%s',
                    (string) ($warning['key'] ?? ''),
                    (string) ($warning['value'] ?? ''),
                    (string) ($warning['hint'] ?? '')
                ));
            }

            foreach ($violations as $violation) {
                $this->error(sprintf(
                    '[violation] %s value=%s expected=%s reason=%s',
                    (string) ($violation['key'] ?? ''),
                    (string) ($violation['value'] ?? ''),
                    (string) ($violation['expected'] ?? ''),
                    (string) ($violation['reason'] ?? '')
                ));
            }
        }

        if ($strict && $violations !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   write_mode:string,
     *   read_mode:string,
     *   content_path_mode:string,
     *   content_publish_mode:string,
     *   api_response_scale_code_mode:string,
     *   accept_legacy_scale_code:bool,
     *   allow_demo_scales:bool
     * }
     */
    private function collectModes(): array
    {
        return [
            'write_mode' => strtolower(trim((string) config('scale_identity.write_mode', 'legacy'))),
            'read_mode' => strtolower(trim((string) config('scale_identity.read_mode', 'legacy'))),
            'content_path_mode' => strtolower(trim((string) config('scale_identity.content_path_mode', 'legacy'))),
            'content_publish_mode' => strtolower(trim((string) config('scale_identity.content_publish_mode', 'legacy'))),
            'api_response_scale_code_mode' => strtolower(trim((string) config('scale_identity.api_response_scale_code_mode', 'legacy'))),
            'accept_legacy_scale_code' => (bool) config('scale_identity.accept_legacy_scale_code', true),
            'allow_demo_scales' => (bool) config('scale_identity.allow_demo_scales', true),
        ];
    }

    /**
     * @return array{
     *   write_mode:list<string>,
     *   read_mode:list<string>,
     *   content_path_mode:list<string>,
     *   content_publish_mode:list<string>,
     *   api_response_scale_code_mode:list<string>
     * }
     */
    private function allowedValues(): array
    {
        return [
            'write_mode' => ['legacy', 'dual', 'v2'],
            'read_mode' => ['legacy', 'dual_prefer_old', 'dual_prefer_new', 'v2'],
            'content_path_mode' => ['legacy', 'dual_prefer_old', 'dual_prefer_new', 'v2'],
            'content_publish_mode' => ['legacy', 'dual', 'v2'],
            'api_response_scale_code_mode' => ['legacy', 'dual', 'v2'],
        ];
    }

    /**
     * @param  array<string,mixed>  $modes
     * @param  array<string,mixed>  $allowed
     * @param  array<int,array<string,string>>  $violations
     */
    private function validateAllowedModes(array $modes, array $allowed, array &$violations): void
    {
        foreach ($allowed as $key => $set) {
            $value = strtolower(trim((string) ($modes[$key] ?? '')));
            $allowedSet = array_values(array_map(
                static fn (string $item): string => strtolower(trim($item)),
                (array) $set
            ));
            if (! in_array($value, $allowedSet, true)) {
                $violations[] = [
                    'key' => $key,
                    'value' => $value,
                    'expected' => implode('|', $allowedSet),
                    'reason' => 'unsupported mode value',
                ];
            }
        }
    }

    /**
     * @param  array{
     *   write_mode:string,
     *   read_mode:string,
     *   content_path_mode:string,
     *   content_publish_mode:string,
     *   api_response_scale_code_mode:string,
     *   accept_legacy_scale_code:bool,
     *   allow_demo_scales:bool
     * }  $modes
     * @param  array<int,array<string,string>>  $violations
     * @param  array<int,array<string,string>>  $warnings
     */
    private function validateSemanticConstraints(array $modes, array &$violations, array &$warnings): void
    {
        $writeMode = (string) ($modes['write_mode'] ?? 'legacy');
        $readMode = (string) ($modes['read_mode'] ?? 'legacy');
        $contentPathMode = (string) ($modes['content_path_mode'] ?? 'legacy');
        $contentPublishMode = (string) ($modes['content_publish_mode'] ?? 'legacy');
        $responseMode = (string) ($modes['api_response_scale_code_mode'] ?? 'legacy');
        $acceptLegacy = (bool) ($modes['accept_legacy_scale_code'] ?? true);

        if ($readMode === 'v2' && $writeMode === 'legacy') {
            $violations[] = [
                'key' => 'read_mode',
                'value' => $readMode,
                'expected' => 'write_mode=dual|v2',
                'reason' => 'v2 read requires dual/v2 write path to avoid stale identity projection',
            ];
        }

        if ($readMode === 'v2' && $acceptLegacy) {
            $violations[] = [
                'key' => 'accept_legacy_scale_code',
                'value' => 'true',
                'expected' => 'false when read_mode=v2',
                'reason' => 'v2 read mode conflicts with accepting legacy request code as primary input',
            ];
        }

        if ($contentPathMode === 'v2' && $contentPublishMode === 'legacy') {
            $warnings[] = [
                'key' => 'content_publish_mode',
                'value' => $contentPublishMode,
                'hint' => 'consider dual/v2 publish mode when content_path_mode=v2 to reduce fallback dependency',
            ];
        }

        if ($contentPathMode === 'legacy' && $contentPublishMode === 'v2') {
            $warnings[] = [
                'key' => 'content_path_mode',
                'value' => $contentPathMode,
                'hint' => 'legacy content path read with v2 publish may hide fresh v2 packs behind fallback',
            ];
        }

        if ($responseMode === 'v2' && $readMode === 'legacy') {
            $warnings[] = [
                'key' => 'api_response_scale_code_mode',
                'value' => $responseMode,
                'hint' => 'response v2 with read_mode=legacy is allowed but increases config coupling risk',
            ];
        }
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
