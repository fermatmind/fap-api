<?php

declare(strict_types=1);

use App\Domain\Career\Display\CareerAuthoringStructure;
use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3BatchUpdater;
use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerContentV3FactResolver;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if (in_array($argv[1] ?? '', ['render', 'repair-render'], true) && $argc === 2) {
        $page = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if ($argv[1] === 'repair-render') {
            class_exists(CareerCurrentAuthorityPackage::class);
            (new \App\Domain\Career\Display\CareerPageDisplayResolver)->resolve($page);
            foreach ($page['authoring_structure']['slots'] as &$slot) {
                if ($slot['status'] !== 'mapped' || $slot['ref']['source'] !== 'display') {
                    continue;
                }
                try {
                    $value = CareerAuthoringStructure::reference($page, $slot['ref']);
                } catch (\App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure) {
                    $value = null;
                }
                if (! is_string($value) || trim($value) === '') {
                    $slot = ['ref' => null, 'status' => 'unfilled'];
                }
            }
            unset($slot);
        }
        // Display slots already address their owners by ID. Keep those bindings and
        // validate the resolved display while refreshing the authoring inventory.
        $page['authoring_structure']['inventory'] = CareerAuthoringStructure::inventory(
            $page,
            $page['authoring_structure']['slots'],
        );
        unset($page['source_content_sha256']);
        $page['source_content_sha256'] = CareerCurrentAuthorityPackage::hashValue($page);
        CareerContentV3Contract::assert($page);
        (new CareerContentV3FactResolver)->resolve($page);
        echo CareerCurrentAuthorityPackage::encodePrettyCanonical($page);
        exit(0);
    }
    if (($argv[1] ?? '') === 'install' && $argc === 8) {
        [, , $backend, $scratch, $slug, $locale, $sha, $baseSha] = $argv;
        $relative = 'careers/'.$slug.'/'.$locale.'.json';
        $root = $backend.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        // Lock the existing directory so installers do not leave lock files behind.
        $lock = fopen($root, 'r');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('could not lock Career Current');
        }
        try {
            if (hash_file('sha256', $root.'/'.$relative) !== $baseSha) {
                throw new RuntimeException('stale patch: Current page changed before installation');
            }
            $package = new CareerContentV3AuthorityPackage;
            $updater = new CareerContentV3BatchUpdater(
                $package,
                new CareerContentV3FactResolver,
                new CareerCurrentAuthorityReleaseIntent(new CareerCurrentAuthorityPackageLoader($package)),
            );
            echo json_encode($updater->update($backend, $scratch, [[
                'slug' => $slug, 'locale' => $locale, 'path' => $relative, 'sha256' => $sha,
            ]], true), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
        } finally {
            fclose($lock);
        }
        exit(0);
    }
    throw new InvalidArgumentException('invalid patcher runtime arguments');
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
