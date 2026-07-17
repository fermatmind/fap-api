<?php

declare(strict_types=1);

namespace Tests\Feature\ReviewGovernance;

use App\Services\ReviewGovernance\ReviewAttestationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ReviewAttestationPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_validates_exact_files_without_database_writes(): void
    {
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        $targets = [
            ['target_identity' => 'content-page:privacy', 'target_sha256' => str_repeat('a', 64)],
        ];
        $attestation = app(ReviewAttestationFactory::class)->make(
            'resource',
            'content-page:privacy',
            'approved_all',
            $targets,
        );
        $directory = storage_path('framework/testing/review-governance');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $attestationPath = $directory.'/attestation.json';
        $targetsPath = $directory.'/targets.json';
        file_put_contents($attestationPath, json_encode($attestation, JSON_THROW_ON_ERROR));
        file_put_contents($targetsPath, json_encode(['targets' => $targets], JSON_THROW_ON_ERROR));

        $exitCode = Artisan::call('review:attestation-preflight', [
            '--attestation' => $attestationPath,
            '--targets' => $targetsPath,
            '--json' => true,
            '--no-ansi' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('PASS_SOLO_OWNER_ATTESTATION_PREFLIGHT', $payload['status']);
        $this->assertSame(0, $payload['database_writes']);
        $this->assertFalse($payload['production_execution_authorized']);
        $this->assertDatabaseCount('review_attestations', 0);
        $this->assertDatabaseCount('review_attestation_target_evidences', 0);
    }
}
