<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use Illuminate\Filesystem\Filesystem;
use Throwable;

/** One-pass, local-only migration; the existing release pipeline installs the validated package. */
final class CareerAuthoringMigration
{
    public function run(string $backendRoot, bool $write = false): array
    {
        $package = new CareerContentV3AuthorityPackage;
        $index = $package->manifestIndex($backendRoot);
        (new CareerCurrentAuthorityReleaseIntent(new CareerCurrentAuthorityPackageLoader($package)))->verify($backendRoot);
        $manifest = $index['manifest'];
        $layout = new CareerAuthoringLayout;
        $baseline = $layout->baseline($package->pageFromIndex($index, 'accountants-and-auditors', 'zh-CN'));
        $schema = CareerAuthoringStructure::schema();
        if (array_keys($baseline['slots']) !== array_keys($schema['slots']) || $baseline['module_order'] !== $schema['module_order']) {
            throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_BASELINE_DRIFT');
        }
        $files = new Filesystem;
        $temporary = sys_get_temp_dir().'/career-authoring-'.bin2hex(random_bytes(8));
        $candidateRoot = $temporary.'/candidate';
        $current = $candidateRoot.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $intentRelative = CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH;
        $intentPath = rtrim($backendRoot, '/').'/'.$intentRelative;
        $intent = json_decode(file_get_contents($intentPath), true, 512, JSON_THROW_ON_ERROR);
        $files->makeDirectory($current, 0700, true);
        $updates = [];
        $originalHashes = [$intentRelative => hash_file('sha256', $intentPath), CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json' => hash_file('sha256', $index['root'].'/manifest.json')];
        foreach ($manifest['files'] as $entry) {
            $originalHashes[CareerCurrentAuthorityPackage::RELATIVE_PATH.'/'.$entry['path']] = $entry['sha256'];
        }
        $counts = ['zh_pages' => 0, 'enhanced' => 0, 'legacy' => 0, 'mapped_slots' => 0, 'unfilled_slots' => 0, 'pending_mapping' => 0];
        $projector = new CareerPageProjector(new CareerContentV3CanonicalReader($package, $backendRoot), $package, new CareerContentV3FactResolver);
        try {
            foreach ($manifest['files'] as &$entry) {
                $original = $package->pageFromIndex($index, $entry['canonical_slug'], $entry['locale']);
                $relative = CareerCurrentAuthorityPackage::RELATIVE_PATH.'/'.$entry['path'];
                $target = $candidateRoot.'/'.$relative;
                $files->ensureDirectoryExists(dirname($target));
                $sourcePath = rtrim($backendRoot, '/').'/'.$relative;
                if ($entry['locale'] !== 'zh-CN') {
                    $files->copy($sourcePath, $target);

                    continue;
                }
                $page = $layout->migrate($original, $baseline);
                $before = CareerAuthoringStructure::publicContent($original);
                $after = CareerAuthoringStructure::publicContent($page);
                if ($before !== $after || $projector->project($original) !== $projector->project($page)) {
                    throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_PUBLIC_CONTENT_DRIFT');
                }
                $semantic = $page;
                unset($semantic['source_content_sha256']);
                $page['source_content_sha256'] = CareerCurrentAuthorityPackage::hashValue($semantic);
                CareerContentV3Contract::assert($page);
                $bytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($page);
                file_put_contents($target, $bytes);
                $entry['bytes'] = strlen($bytes);
                $entry['sha256'] = hash('sha256', $bytes);
                $entry['source_content_sha256'] = $page['source_content_sha256'];
                if (! hash_equals(hash_file('sha256', $sourcePath), $entry['sha256'])) {
                    $updates[] = $relative;
                }
                $counts['zh_pages']++;
                $counts[$page['content_state']]++;
                foreach ($page['authoring_structure']['slots'] as $slot) {
                    $counts[$slot['status'].'_slots']++;
                }
                $counts['pending_mapping'] += count(array_filter($page['authoring_structure']['inventory'], static fn (array $item): bool => $item['status'] === 'pending_mapping'));
            }
            unset($entry);
            if ($counts['zh_pages'] !== 1046 || $counts['enhanced'] !== 144 || $counts['legacy'] !== 902) {
                throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_MIGRATION_COHORT_DRIFT');
            }
            $manifest['set_hashes']['source_semantic_aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue(array_column($manifest['files'], 'source_content_sha256'));
            $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue(array_intersect_key($manifest, array_flip([
                'authority_path', 'compiler_version', 'contract_version', 'coverage', 'files', 'locales',
                'schema_version', 'set_hashes', 'source_registry_sha256', 'identity_aliases', 'identity_scopes',
            ])));
            $manifestBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest);
            file_put_contents($current.'/manifest.json', $manifestBytes);
            $intent['aggregate_sha256'] = $manifest['aggregate_sha256'];
            $intent['manifest_sha256'] = hash('sha256', $manifestBytes);
            $intent['versionless_projection_sha256'] = $manifest['set_hashes']['source_semantic_aggregate_sha256'];
            $intentBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($intent);
            file_put_contents($candidateRoot.'/'.$intentRelative, $intentBytes);
            foreach ([$intentRelative, CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json'] as $relative) {
                if (! hash_equals(hash_file('sha256', rtrim($backendRoot, '/').'/'.$relative), hash_file('sha256', $candidateRoot.'/'.$relative))) {
                    $updates[] = $relative;
                }
            }
            // Complete candidate validation before the first repository write.
            $candidateIndex = $package->manifestIndex($candidateRoot);
            foreach ($candidateIndex['manifest']['files'] as $entry) {
                $package->pageFromIndex($candidateIndex, $entry['canonical_slug'], $entry['locale']);
            }
            (new CareerCurrentAuthorityReleaseIntent(new CareerCurrentAuthorityPackageLoader($package)))->verify($candidateRoot);
            if ($write && $updates !== []) {
                $applied = [];
                try {
                    foreach ($updates as $relative) {
                        $path = rtrim($backendRoot, '/').'/'.$relative;
                        // Reject concurrent edits instead of overwriting them.
                        if (! hash_equals($originalHashes[$relative], hash_file('sha256', $path))) {
                            throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_CONCURRENT_EDIT');
                        }
                        $backup = $temporary.'/backup/'.$relative;
                        $files->ensureDirectoryExists(dirname($backup));
                        $files->copy($path, $backup);
                        $this->replace($path, file_get_contents($candidateRoot.'/'.$relative));
                        $applied[] = $relative;
                    }
                    $package->manifestIndex($backendRoot);
                    (new CareerCurrentAuthorityReleaseIntent(new CareerCurrentAuthorityPackageLoader($package)))->verify($backendRoot);
                } catch (Throwable $error) {
                    foreach (array_reverse($applied) as $relative) {
                        $this->replace(rtrim($backendRoot, '/').'/'.$relative, file_get_contents($temporary.'/backup/'.$relative));
                    }
                    throw $error;
                }
            }

            return ['status' => 'PASS', 'written' => $write, 'changed_files' => count($updates), 'slots_per_page' => count($baseline['slots']), ...$counts, 'database_writes' => 0, 'cache_writes' => 0, 'publication_state_changes' => 0];
        } finally {
            $files->deleteDirectory($temporary);
        }
    }

    private function replace(string $path, string $bytes): void
    {
        $temporary = tempnam(dirname($path), '.career-authoring-');
        if ($temporary === false) {
            throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_WRITE_FAILED');
        }
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
                || ! chmod($temporary, fileperms($path) & 0777) || ! rename($temporary, $path)) {
                throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_WRITE_FAILED');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
