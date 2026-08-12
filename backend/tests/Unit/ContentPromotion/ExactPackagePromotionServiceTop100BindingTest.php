<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\PromotionContext;
use DomainException;
use ReflectionClass;
use Tests\TestCase;

final class ExactPackagePromotionServiceTop100BindingTest extends TestCase
{
    public function test_draft_import_accepts_only_the_exact_preflight_target_state_sha(): void
    {
        $sha = str_repeat('a', 64);
        $this->invokeBinding($this->adapter($sha), $sha);

        foreach ([str_repeat('b', 64), '', 'invalid'] as $approved) {
            try {
                $this->invokeBinding($this->adapter($sha), $approved);
                self::fail('Expected preflight target-state drift to fail closed.');
            } catch (DomainException $exception) {
                self::assertSame('top100_preflight_target_state_drift', $exception->getMessage());
            }
        }
    }

    public function test_publish_accepts_only_the_exact_approved_prestate_sha(): void
    {
        $sha = str_repeat('c', 64);
        $this->invokePublishBinding($this->adapter(str_repeat('a', 64), $sha), $sha);

        foreach ([str_repeat('d', 64), '', 'invalid'] as $approved) {
            try {
                $this->invokePublishBinding($this->adapter(str_repeat('a', 64), $sha), $approved);
                self::fail('Expected publish prestate drift to fail closed.');
            } catch (DomainException $exception) {
                self::assertSame('top100_publish_prestate_drift', $exception->getMessage());
            }
        }
    }

    private function invokeBinding(ExactPackagePromotionAdapter $adapter, string $approved): void
    {
        $service = (new ReflectionClass(ExactPackagePromotionService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($service))->getMethod('assertTop100PreflightBinding');
        $method->invoke($service, $adapter->preflight($this->context()), [
            'receipt' => ['target_state_sha256' => $approved],
            'sha256' => str_repeat('b', 64),
            'path' => '/tmp/preflight.json',
        ]);
    }

    private function invokePublishBinding(ExactPackagePromotionAdapter $adapter, string $approved): void
    {
        $service = (new ReflectionClass(ExactPackagePromotionService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($service))->getMethod('assertTop100PublishPrestateBinding');
        $method->invoke($service, $adapter->preflight($this->context()), [
            'receipt' => [
                'approved_prestate_sha256' => $approved,
                'rollback_reference' => 'content-release-snapshot:1',
            ],
            'sha256' => str_repeat('b', 64),
            'path' => '/tmp/draft-import.json',
        ]);
    }

    private function adapter(string $targetStateSha, string $approvedPrestateSha = ''): ExactPackagePromotionAdapter
    {
        return new class($targetStateSha, $approvedPrestateSha) implements ExactPackagePromotionAdapter
        {
            public function __construct(private readonly string $targetStateSha, private readonly string $approvedPrestateSha) {}

            public function id(): string
            {
                return 'test';
            }

            public function capability(): string
            {
                return 'audit_compatible';
            }

            public function supports(string $lane, ?string $subscope): bool
            {
                return true;
            }

            public function preflight(PromotionContext $context): array
            {
                return [
                    'target_state_sha256' => $this->targetStateSha,
                    'approved_prestate_sha256' => $this->approvedPrestateSha,
                ];
            }

            public function draftImport(PromotionContext $context): array
            {
                return [];
            }

            public function publish(PromotionContext $context): array
            {
                return [];
            }

            public function liveQa(PromotionContext $context): array
            {
                return [];
            }

            public function rollback(PromotionContext $context, string $rollbackReference): void {}
        };
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(base_path(), str_repeat('a', 64), 'TOP100', 'frozen-20260812-v1', str_repeat('b', 40), str_repeat('c', 64), str_repeat('d', 64), '1', 1, str_repeat('e', 64), 30, str_repeat('f', 64));
    }
}
