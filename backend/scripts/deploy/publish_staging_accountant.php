<?php

declare(strict_types=1);

use App\Domain\Career\Publish\CareerStagingAccountantPublication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $sha = (string) getenv('DEPLOY_REVISION');
    if (trim((string) file_get_contents(dirname(__DIR__, 3).'/REVISION')) !== $sha) {
        throw new RuntimeException('staging_accountant_release_mismatch');
    }
    $publisher = $app->make(CareerStagingAccountantPublication::class);
    $mode = $argv[1] ?? '';
    if ($mode === 'rollback') {
        $publisher->rollback($sha);
        echo "staging_accountant_publication_rollback_checked\n";
    } elseif ($mode === 'publish') {
        $result = $publisher->publish($sha, static function (array $page): void {
            $response = Http::connectTimeout(5)->timeout(45)->withoutRedirecting()
                ->withHeaders(['Cache-Control' => 'no-cache'])
                ->get('https://staging-api.fermatmind.com/api/v0.5/career/jobs/'.CareerStagingAccountantPublication::SLUG, [
                    'locale' => CareerStagingAccountantPublication::LOCALE,
                    'projection_contract' => 'career.detail.page.v1',
                ]);
            CareerStagingAccountantPublication::assertResponse($response->status(), $response->json(), $page);
        });
        echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    } else {
        throw new RuntimeException('staging_accountant_operation_invalid');
    }
} catch (Throwable $error) {
    // No HTTP bodies, private paths or transport credentials in deployment logs.
    $message = $error->getMessage();
    fwrite(STDERR, preg_match('/^staging_accountant_[a-z_]+$/', $message) ? $message."\n" : "staging_accountant_publication_failed\n");
    exit(1);
}
