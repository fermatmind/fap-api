<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Compilation\CareerContentV3Compiler;
use Illuminate\Console\Command;
use Throwable;

final class CareerContentV3Compile extends Command
{
    protected $signature = 'career:content-v3-compile {--output-root= : Required existing task temp directory}';

    protected $description = 'Deterministically validate the content v3 projection for all Career locale pages';

    public function handle(CareerContentV3Compiler $compiler): int
    {
        ini_set('memory_limit', '2048M');
        try {
            $root = $this->outputRoot((string) $this->option('output-root'));
            $receipt = $compiler->compile(base_path());
            $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($root.'/content-v3-compile-receipt.json', $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('CONTENT_V3_RECEIPT_WRITE_FAILED');
            }
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

    private function outputRoot(string $root): string
    {
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
