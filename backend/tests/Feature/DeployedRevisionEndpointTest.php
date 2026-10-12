<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\DeployedRevisionController;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DeployedRevisionEndpointTest extends TestCase
{
    private string $temporaryDirectory;

    private string $revisionPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/deployed-revision-'.bin2hex(random_bytes(8));
        $this->revisionPath = $this->temporaryDirectory.'/REVISION';
        (new Filesystem)->makeDirectory($this->temporaryDirectory, 0700, true);
        $this->app->bind(
            DeployedRevisionController::class,
            fn (): DeployedRevisionController => new DeployedRevisionController($this->revisionPath),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_exposes_only_the_active_lowercase_revision(): void
    {
        $revision = str_repeat('a', 40);
        file_put_contents($this->revisionPath, $revision."\n");

        $response = $this->get('https://api.fermatmind.com/revision');

        $response
            ->assertOk()
            ->assertExactJson(['revision' => $revision])
            ->assertHeader('X-Revision', $revision)
            ->assertHeader('CDN-Cache-Control', 'no-store')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Set-Cookie');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_it_fails_closed_when_the_marker_is_missing(): void
    {
        $this->assertRevisionUnavailable();
    }

    public function test_it_fails_closed_when_the_marker_is_a_symlink(): void
    {
        $target = $this->temporaryDirectory.'/target';
        file_put_contents($target, str_repeat('b', 40));
        symlink($target, $this->revisionPath);

        $this->assertRevisionUnavailable();
    }

    public function test_it_fails_closed_when_the_marker_is_not_a_regular_file(): void
    {
        (new Filesystem)->makeDirectory($this->revisionPath, 0700);

        $this->assertRevisionUnavailable();
    }

    #[DataProvider('invalidMarkerProvider')]
    public function test_it_fails_closed_for_malformed_marker_bytes(string $contents): void
    {
        file_put_contents($this->revisionPath, $contents);

        $this->assertRevisionUnavailable();
    }

    /** @return array<string, array{string}> */
    public static function invalidMarkerProvider(): array
    {
        return [
            'uppercase' => [str_repeat('A', 40)],
            'too short' => [str_repeat('a', 39)],
            'too long' => [str_repeat('a', 41)],
            'trailing space' => [str_repeat('a', 40).' '],
            'trailing content' => [str_repeat('a', 40)."\nextra"],
            'extra newline' => [str_repeat('a', 40)."\n\n"],
        ];
    }

    private function assertRevisionUnavailable(): void
    {
        $response = $this->get('https://api.fermatmind.com/revision');

        $response
            ->assertStatus(503)
            ->assertExactJson([
                'ok' => false,
                'error' => 'revision_unavailable',
            ])
            ->assertHeaderMissing('X-Revision')
            ->assertHeader('CDN-Cache-Control', 'no-store')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Set-Cookie');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
