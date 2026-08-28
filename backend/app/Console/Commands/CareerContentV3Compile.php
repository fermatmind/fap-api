<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Compilation\CareerContentV3Compiler;
use Illuminate\Console\Command;
use Throwable;

final class CareerContentV3Compile extends Command
{
    protected $signature = 'career:content-v3-compile {--output-root= : Existing task temp directory for a pre-install conversion}';

    protected $description = 'Deterministically validate the content v3 projection for all Career locale pages';

    public function handle(CareerContentV3Compiler $compiler): int
    {
        ini_set('memory_limit', '2048M');
        try {
            $root = $this->outputRoot((string) $this->option('output-root'));
            $receipt = $compiler->compile(base_path(), $root);
            $this->line((string) json_encode([
                'status' => 'PASS_CAREER_CONTENT_V3_COMPILE',
                'receipt' => $receipt,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'status' => 'FAIL_CAREER_CONTENT_V3_COMPILE',
                'safe_error_code' => $throwable->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    private function outputRoot(string $root): ?string
    {
        if (trim($root) === '') {
            return null;
        }
        $resolved = is_link($root) ? false : realpath($root);
        $temp = realpath(sys_get_temp_dir());
        $sharedTemp = realpath('/tmp');
        if ($resolved === false || ! is_dir($resolved) || $temp === false
            || (! str_starts_with($resolved.'/', rtrim($temp, '/').'/')
                && ($sharedTemp === false || ! str_starts_with($resolved.'/', rtrim($sharedTemp, '/').'/')))) {
            throw new \RuntimeException('CONTENT_V3_OUTPUT_ROOT_FORBIDDEN');
        }

        return $resolved;
    }
}
