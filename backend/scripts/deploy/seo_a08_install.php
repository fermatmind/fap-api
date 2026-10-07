<?php

declare(strict_types=1);

use App\Services\SeoCouncil\Platform12\Platform12ActivationEvidence;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $bytes = stream_get_contents(STDIN, 65537);
    $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    $sha = trim(file_get_contents(config('seo_council.release_revision_path')));
    $evidence = app(Platform12ActivationEvidence::class);
    if (strlen($bytes) > 65536 || ($manifest['schema_version'] ?? null) !== Platform12ActivationEvidence::SCHEMA
        || $evidence->validate($manifest, $sha) !== 'READY') {
        throw new RuntimeException('A08_PACKAGE_HOLD');
    }
    app(Platform12RuntimeControl::class)->withControlLock(function () use ($bytes): void {
        $path = config('seo_council.activation_receipt_path');
        if (is_link($path) || is_link($path.'.sha256') || is_link($path.'.atomic.json')) {
            throw new RuntimeException('A08_PATH_HOLD');
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $temp = tempnam(dirname($path), '.a08-');
        try {
            if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
            chmod($temp, 0640);
            if (file_put_contents($temp.'.sha256', hash('sha256', $bytes)."\n", LOCK_EX) !== 65) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
            chmod($temp.'.sha256', 0640);
            $atomic = json_encode(['bytes' => $bytes, 'sha256' => hash('sha256', $bytes)], JSON_THROW_ON_ERROR);
            if (file_put_contents($temp.'.atomic.json', $atomic, LOCK_EX) !== strlen($atomic)) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
            chmod($temp.'.atomic.json', 0640);
            // One authoritative rename. Legacy mirrors remain readable for software rollback.
            if (! rename($temp.'.atomic.json', $path.'.atomic.json')) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
            if (! rename($temp, $path) || ! rename($temp.'.sha256', $path.'.sha256')) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
            if (is_file($temp.'.atomic.json')) {
                unlink($temp.'.atomic.json');
            }
            if (is_file($temp.'.sha256')) {
                unlink($temp.'.sha256');
            }
        }
    });
    if ($evidence->inspect()['state'] !== 'READY') {
        throw new RuntimeException('A08_READBACK_HOLD');
    }
    echo "a08_gate_policy=SCOPED_PER_MISSION evidence_installed=true runtime_state_unchanged=true\n";
} catch (Throwable) {
    fwrite(STDERR, "A08_EVIDENCE_INSTALL_HOLD\n");
    exit(1);
}
