<?php

declare(strict_types=1);

use App\Services\SeoCouncil\Platform12\Platform12ActivationEvidence;
use App\Services\SeoCouncil\Platform12\Platform12ActivationInstallFence;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $requestBytes = $a08RequestBytes ?? stream_get_contents(STDIN, 131073);
    $request = json_decode($requestBytes, true, 64, JSON_THROW_ON_ERROR);
    if (strlen($requestBytes) > 131072 || ($request['schema_version'] ?? null) !== 'seo.a08_install_request.v1'
        || ! is_string($request['manifest'] ?? null) || ! is_array($request['expected'] ?? null)) {
        throw new RuntimeException('A08_INSTALL_REQUEST_HOLD');
    }
    $bytes = $request['manifest'];
    $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    $sha = trim(file_get_contents(config('seo_council.release_revision_path')));
    $evidence = app(Platform12ActivationEvidence::class);
    if (strlen($bytes) > 65536 || ($manifest['schema_version'] ?? null) !== Platform12ActivationEvidence::SCHEMA
        || $evidence->validate($manifest, $sha) !== 'READY') {
        throw new RuntimeException('A08_PACKAGE_HOLD');
    }
    app(Platform12RuntimeControl::class)->withControlLock(function () use ($bytes, $manifest, $request, $evidence): void {
        $root = (string) getenv('A08_DEPLOY_ROOT');
        if ($root === '') {
            throw new RuntimeException('A08_INSTALL_ROOT_HOLD');
        }
        Platform12ActivationInstallFence::publish(
            config('seo_council.activation_receipt_path'), $bytes, $request['expected'],
            function () use ($root, $manifest, $evidence): array {
                $current = Platform12ActivationInstallFence::snapshot($root);
                if ($evidence->validate($manifest, $current['sha']) !== 'READY') {
                    throw new RuntimeException('A08_PACKAGE_HOLD');
                }

                return $current;
            },
        );
    });
    if ($evidence->inspect()['state'] !== 'READY') {
        throw new RuntimeException('A08_READBACK_HOLD');
    }
    echo "a08_gate_policy=SCOPED_PER_MISSION evidence_installed=true runtime_state_unchanged=true\n";
} catch (Throwable) {
    fwrite(STDERR, "A08_EVIDENCE_INSTALL_HOLD\n");
    exit(1);
}
