<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use InvalidArgumentException;

final class MissionSubmissionService
{
    private const CALLERS = ['local_skill', 'cli', 'scheduler', 'api', 'seo_operations_ui'];

    public function __construct(
        private readonly CouncilContractValidator $validator,
        private readonly SeoRegistryHasher $hasher,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly SeoCouncilOrchestrator $orchestrator,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(array $input, string $boundCallerType): array
    {
        if (! in_array($boundCallerType, self::CALLERS, true)) {
            throw new InvalidArgumentException('CALLER_TYPE_DENIED');
        }
        unset($input['caller_type']);
        $request = MissionRequestData::fromInput($input, $boundCallerType, $this->validator, $this->hasher);
        $this->binding->validateRequestScope($request);

        return $this->orchestrator->run($request);
    }
}
