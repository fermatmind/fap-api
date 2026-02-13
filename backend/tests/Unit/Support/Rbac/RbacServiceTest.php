<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Rbac;

use App\Models\AdminUser;
use App\Support\Rbac\RbacService;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class RbacServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_assert_can_throws_not_found_when_permission_missing(): void
    {
        $user = Mockery::mock(AdminUser::class);
        $user->shouldReceive('hasPermission')
            ->once()
            ->with('admin.events.read')
            ->andReturn(false);

        $service = new RbacService();

        $this->expectException(NotFoundHttpException::class);
        $service->assertCan($user, 'admin.events.read');
    }

    public function test_assert_can_passes_when_permission_exists(): void
    {
        $user = Mockery::mock(AdminUser::class);
        $user->shouldReceive('hasPermission')
            ->once()
            ->with('admin.events.read')
            ->andReturn(true);

        $service = new RbacService();

        $service->assertCan($user, 'admin.events.read');

        $this->assertTrue(true);
    }
}
