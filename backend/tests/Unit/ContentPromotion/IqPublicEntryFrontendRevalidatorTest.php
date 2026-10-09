<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\IqPublicEntryFrontendRevalidator;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class IqPublicEntryFrontendRevalidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ops.content_release_observability.hmac_revalidation_url' => 'https://frontend.example/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-revalidation-secret',
        ]);
        Http::preventStrayRequests();
    }

    public function test_exact_paths_are_signed_without_discoverability_or_broadcast(): void
    {
        Http::fake(['https://frontend.example/*' => Http::response($this->receipt())]);
        (new IqPublicEntryFrontendRevalidator)->revalidate();
        Http::assertSent(function (Request $request): bool {
            $body = $request->body();
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $timestamp = $request->header('X-FM-Content-Release-Timestamp')[0];
            $nonce = $request->header('X-FM-Content-Release-Nonce')[0];
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, 'test-revalidation-secret');

            return $request->method() === 'POST'
                && $request->header('X-FM-Content-Release-Signature') === [$expected]
                && $payload['content']['type'] === 'scale'
                && $payload['cache_signal'] === ['paths' => IqPublicEntryFrontendRevalidator::PATHS];
        });
        Http::assertSentCount(1);
    }

    public function test_missing_secret_fails_before_http(): void
    {
        config(['ops.content_release_observability.hmac_revalidation_secret' => '']);
        try {
            (new IqPublicEntryFrontendRevalidator)->assertConfigured();
            self::fail('Missing secret must fail before publication.');
        } catch (DomainException $error) {
            self::assertSame('iq_public_frontend_revalidation_not_configured', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_wrong_or_expanded_receipts_and_http_failures_are_rejected(): void
    {
        foreach ([
            [$this->receipt() + ['unexpected' => true], 503],
            [array_replace($this->receipt(), ['ok' => false]), 200],
            [array_replace($this->receipt(), ['revalidated_paths' => [...IqPublicEntryFrontendRevalidator::PATHS, '/sitemap.xml']]), 200],
            [array_replace($this->receipt(), ['invalidated_tags' => ['all-content']]), 200],
            [array_replace($this->receipt(), ['rejected_paths' => [['path' => '/en/tests/iq-test-intelligence-quotient-assessment']]]), 200],
            [[], 200],
        ] as [$receipt, $status]) {
            Http::fake(['https://frontend.example/*' => Http::response($receipt, $status)]);
            try {
                (new IqPublicEntryFrontendRevalidator)->revalidate();
                self::fail('Failed or expanded cache receipt must fail publication.');
            } catch (DomainException $error) {
                self::assertSame('iq_public_frontend_revalidation_failed', $error->getMessage());
            }
        }
    }

    public function test_transport_redirect_cannot_follow_to_another_endpoint(): void
    {
        Http::fake(['https://frontend.example/*' => Http::response('', 302, ['Location' => 'https://other.example/'])]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_public_frontend_revalidation_failed');
        (new IqPublicEntryFrontendRevalidator)->revalidate();
    }

    private function receipt(): array
    {
        return ['ok' => true, 'revalidated_paths' => IqPublicEntryFrontendRevalidator::PATHS, 'rejected_paths' => [], 'invalidated_tags' => []];
    }
}
