<?php

declare(strict_types=1);

namespace Tests\Unit\SeoCouncil;

use App\Services\SeoCouncil\Platform12\Platform12ActivationInstallFence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Platform12ActivationInstallFenceTest extends TestCase
{
    private function state(): array
    {
        return ['sha' => str_repeat('a', 40), 'generation' => str_repeat('b', 32),
            'version_vector_hash' => str_repeat('c', 64), 'lock_identity' => str_repeat('d', 64), 'release_id' => 'release-1'];
    }

    public function test_unchanged_fence_accepts_running_and_paused_generations(): void
    {
        foreach ([$this->state(), [...$this->state(), 'generation' => null]] as $state) {
            Platform12ActivationInstallFence::assertMatches($state, $state);
        }
        $this->addToAssertionCount(2);
    }

    #[DataProvider('changedFences')]
    public function test_switch_rollback_same_sha_new_generation_and_lock_identity_are_rejected(string $key, mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        Platform12ActivationInstallFence::assertMatches($this->state(), [...$this->state(), $key => $value]);
    }

    public static function changedFences(): array
    {
        return [['sha', str_repeat('e', 40)], ['generation', str_repeat('e', 32)],
            ['version_vector_hash', str_repeat('e', 64)], ['lock_identity', str_repeat('e', 64)],
            ['release_id', 'rollback-release'], ['generation', null]];
    }

    public function test_missing_fence_cannot_authorize_an_install(): void
    {
        $this->expectException(RuntimeException::class);
        Platform12ActivationInstallFence::assertMatches([], $this->state());
    }

    #[DataProvider('changedFences')]
    public function test_current_change_after_atomic_rename_restores_prior_proof_without_changing_rollback_mirrors(string $key, mixed $value): void
    {
        $directory = sys_get_temp_dir().'/a08-publish-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/activation.json';
        file_put_contents($path, 'prior-bytes');
        file_put_contents($path.'.sha256', hash('sha256', 'prior-bytes')."\n");
        file_put_contents($path.'.atomic.json', 'prior-envelope');
        $calls = 0;
        try {
            Platform12ActivationInstallFence::publish($path, 'candidate-bytes', $this->state(), function () use (&$calls, $key, $value, $path): array {
                if (++$calls === 2) {
                    $this->assertNotSame('prior-envelope', file_get_contents($path.'.atomic.json'));

                    return [...$this->state(), $key => $value];
                }

                return $this->state();
            });
            $this->fail('A post-write current change must reject installation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A08_CURRENT_SUPERSEDED', $exception->getMessage());
            $this->assertSame('prior-envelope', file_get_contents($path.'.atomic.json'));
            $this->assertSame('prior-bytes', file_get_contents($path));
            $this->assertSame(hash('sha256', 'prior-bytes')."\n", file_get_contents($path.'.sha256'));
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_failed_old_writer_does_not_restore_over_a_new_owner_envelope(): void
    {
        $directory = sys_get_temp_dir().'/a08-new-owner-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/activation.json';
        $calls = 0;
        try {
            Platform12ActivationInstallFence::publish($path, 'candidate-bytes', $this->state(), function () use (&$calls, $path): array {
                if (++$calls === 2) {
                    file_put_contents($path.'.atomic.json', 'new-owner-envelope');

                    return [...$this->state(), 'generation' => str_repeat('e', 32)];
                }

                return $this->state();
            });
            $this->fail('A superseded writer must fail.');
        } catch (RuntimeException) {
            $this->assertSame('new-owner-envelope', file_get_contents($path.'.atomic.json'));
            $this->assertFileDoesNotExist($path);
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
