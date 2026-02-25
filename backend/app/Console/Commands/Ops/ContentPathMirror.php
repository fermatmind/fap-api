<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ContentPathMirror extends Command
{
    protected $signature = 'ops:content-path-mirror
        {--scope=* : Alias scopes to process (backend_content_packs|content_packages)}
        {--old-path=* : Restrict to specific old_path values}
        {--dry-run : Compute and report only; do not write files}
        {--sync : Mirror source tree to mapped path}
        {--verify-hash : Compare source and target file hashes recursively}';

    protected $description = 'Mirror content path aliases into mapped directories and verify hash consistency';

    public function handle(): int
    {
        if (! Schema::hasTable('content_path_aliases')) {
            $this->warn('content_path_aliases table missing, skipping.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $doSync = (bool) $this->option('sync');
        $verifyHash = (bool) $this->option('verify-hash');
        if (! $doSync && ! $verifyHash) {
            $dryRun = true;
        }

        $scopeFilter = $this->normalizedOptionList('scope');
        $oldPathFilter = $this->normalizedOptionList('old-path');

        $query = DB::table('content_path_aliases')
            ->select(['scope', 'old_path', 'new_path', 'is_active'])
            ->where('is_active', true)
            ->orderBy('scope')
            ->orderBy('old_path');

        if ($scopeFilter !== []) {
            $query->whereIn('scope', $scopeFilter);
        }
        if ($oldPathFilter !== []) {
            $query->whereIn('old_path', $oldPathFilter);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->warn('content_path_aliases empty for selected scope/filter.');
            $this->emitSummary(
                scannedAliases: 0,
                sourceMissingAliases: 0,
                syncCopiedFiles: 0,
                syncUpdatedFiles: 0,
                verifyComparedFiles: 0,
                verifyMatchedFiles: 0,
                verifyMismatchFiles: 0,
                verifyTargetMissingFiles: 0,
                dryRun: $dryRun,
                sync: $doSync,
                verifyHash: $verifyHash
            );

            return self::SUCCESS;
        }

        $scannedAliases = 0;
        $sourceMissingAliases = 0;
        $syncCopiedFiles = 0;
        $syncUpdatedFiles = 0;
        $verifyComparedFiles = 0;
        $verifyMatchedFiles = 0;
        $verifyMismatchFiles = 0;
        $verifyTargetMissingFiles = 0;

        foreach ($rows as $row) {
            $scope = strtolower(trim((string) ($row->scope ?? '')));
            $oldPath = trim((string) ($row->old_path ?? ''));
            $newPath = trim((string) ($row->new_path ?? ''));
            if ($scope === '' || $oldPath === '' || $newPath === '') {
                continue;
            }

            $source = $this->resolveAbsolutePath($scope, $oldPath);
            $target = $this->resolveAbsolutePath($scope, $newPath);
            $scannedAliases++;

            if (! is_dir($source)) {
                $sourceMissingAliases++;
                $this->line(sprintf('[skip] source missing scope=%s old_path=%s source=%s', $scope, $oldPath, $source));
                continue;
            }

            $this->line(sprintf(
                '[alias] scope=%s old_path=%s new_path=%s source=%s target=%s',
                $scope,
                $oldPath,
                $newPath,
                $source,
                $target
            ));

            if ($doSync) {
                $syncStats = $this->syncDirectory($source, $target, $dryRun);
                $syncCopiedFiles += (int) ($syncStats['copied'] ?? 0);
                $syncUpdatedFiles += (int) ($syncStats['updated'] ?? 0);
            }

            if ($verifyHash) {
                $verifyStats = $this->verifyDirectoryHashes($source, $target);
                $verifyComparedFiles += (int) ($verifyStats['compared'] ?? 0);
                $verifyMatchedFiles += (int) ($verifyStats['matched'] ?? 0);
                $verifyMismatchFiles += (int) ($verifyStats['mismatch'] ?? 0);
                $verifyTargetMissingFiles += (int) ($verifyStats['target_missing'] ?? 0);
            }
        }

        $this->emitSummary(
            scannedAliases: $scannedAliases,
            sourceMissingAliases: $sourceMissingAliases,
            syncCopiedFiles: $syncCopiedFiles,
            syncUpdatedFiles: $syncUpdatedFiles,
            verifyComparedFiles: $verifyComparedFiles,
            verifyMatchedFiles: $verifyMatchedFiles,
            verifyMismatchFiles: $verifyMismatchFiles,
            verifyTargetMissingFiles: $verifyTargetMissingFiles,
            dryRun: $dryRun,
            sync: $doSync,
            verifyHash: $verifyHash
        );

        return self::SUCCESS;
    }

    /**
     * @return array{copied:int,updated:int}
     */
    private function syncDirectory(string $source, string $target, bool $dryRun): array
    {
        if (! is_dir($source)) {
            return ['copied' => 0, 'updated' => 0];
        }

        if (! $dryRun) {
            File::ensureDirectoryExists($target);
        }

        $copied = 0;
        $updated = 0;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $sourceFile = $item->getPathname();
            $relative = ltrim(substr($sourceFile, strlen(rtrim($source, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                continue;
            }

            $targetFile = rtrim($target, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
            $targetExists = is_file($targetFile);
            $sameHash = $targetExists && hash_file('sha256', $sourceFile) === hash_file('sha256', $targetFile);
            if ($sameHash) {
                continue;
            }

            if (! $dryRun) {
                File::ensureDirectoryExists(dirname($targetFile));
                File::copy($sourceFile, $targetFile);
            }

            if ($targetExists) {
                $updated++;
            } else {
                $copied++;
            }
        }

        $this->line(sprintf('[sync] copied=%d updated=%d dry_run=%d', $copied, $updated, $dryRun ? 1 : 0));

        return [
            'copied' => $copied,
            'updated' => $updated,
        ];
    }

    /**
     * @return array{compared:int,matched:int,mismatch:int,target_missing:int}
     */
    private function verifyDirectoryHashes(string $source, string $target): array
    {
        if (! is_dir($source)) {
            return ['compared' => 0, 'matched' => 0, 'mismatch' => 0, 'target_missing' => 0];
        }

        $compared = 0;
        $matched = 0;
        $mismatch = 0;
        $targetMissing = 0;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $sourceFile = $item->getPathname();
            $relative = ltrim(substr($sourceFile, strlen(rtrim($source, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                continue;
            }

            $targetFile = rtrim($target, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
            $compared++;

            if (! is_file($targetFile)) {
                $mismatch++;
                $targetMissing++;
                continue;
            }

            $sameHash = hash_file('sha256', $sourceFile) === hash_file('sha256', $targetFile);
            if ($sameHash) {
                $matched++;
            } else {
                $mismatch++;
            }
        }

        $this->line(sprintf(
            '[verify] compared=%d matched=%d mismatch=%d target_missing=%d',
            $compared,
            $matched,
            $mismatch,
            $targetMissing
        ));

        return [
            'compared' => $compared,
            'matched' => $matched,
            'mismatch' => $mismatch,
            'target_missing' => $targetMissing,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizedOptionList(string $name): array
    {
        $values = $this->option($name);
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            $item = trim((string) $value);
            if ($item === '') {
                continue;
            }
            $out[] = $item;
        }

        return array_values(array_unique($out));
    }

    private function resolveAbsolutePath(string $scope, string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return base_path();
        }

        if ($scope === 'backend_content_packs') {
            if (! str_starts_with($normalized, 'content_packs/')) {
                $normalized = 'content_packs/'.$normalized;
            }

            return base_path(str_replace('/', DIRECTORY_SEPARATOR, $normalized));
        }

        if ($scope === 'content_packages') {
            $root = rtrim((string) config('content_packs.root', base_path('../content_packages')), '/\\');
            if ($root === '') {
                $root = base_path('../content_packages');
            }

            if (! str_starts_with($root, '/') && ! preg_match('/^[A-Za-z]:\\\\/', $root)) {
                $root = base_path($root);
            }

            if (str_starts_with($normalized, 'content_packages/')) {
                $normalized = substr($normalized, strlen('content_packages/'));
            }

            return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        }

        return base_path(str_replace('/', DIRECTORY_SEPARATOR, $normalized));
    }

    private function emitSummary(
        int $scannedAliases,
        int $sourceMissingAliases,
        int $syncCopiedFiles,
        int $syncUpdatedFiles,
        int $verifyComparedFiles,
        int $verifyMatchedFiles,
        int $verifyMismatchFiles,
        int $verifyTargetMissingFiles,
        bool $dryRun,
        bool $sync,
        bool $verifyHash
    ): void {
        $this->info(sprintf(
            'content_path_mirror scanned_aliases=%d source_missing_aliases=%d sync_copied_files=%d sync_updated_files=%d verify_compared_files=%d verify_matched_files=%d verify_mismatch_files=%d verify_target_missing_files=%d dry_run=%d sync=%d verify_hash=%d',
            $scannedAliases,
            $sourceMissingAliases,
            $syncCopiedFiles,
            $syncUpdatedFiles,
            $verifyComparedFiles,
            $verifyMatchedFiles,
            $verifyMismatchFiles,
            $verifyTargetMissingFiles,
            $dryRun ? 1 : 0,
            $sync ? 1 : 0,
            $verifyHash ? 1 : 0
        ));
    }
}

