<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Model;

use Throwable;

final class FakeSeoCouncilModelClient implements SeoCouncilModelClient
{
    /** @var list<SeoCouncilModelResponse|Throwable> */
    private array $responses;

    /** @var list<SeoCouncilModelRequest> */
    private array $requests = [];

    /** @param list<SeoCouncilModelResponse|Throwable> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = array_values($responses);
    }

    public function complete(SeoCouncilModelRequest $request): SeoCouncilModelResponse
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if (! $response instanceof SeoCouncilModelResponse) {
            throw new SeoCouncilModelFailure('FAKE_MODEL_RESPONSE_MISSING');
        }

        return $response;
    }

    /** @return list<SeoCouncilModelRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}
