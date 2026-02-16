<?php

declare(strict_types=1);

namespace App\Services\Template;

use App\Models\Attempt;
use App\Models\Result;

final class TemplateContext
{
    /**
     * @param array<string,mixed> $data
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<string,mixed> $options
     */
    public static function fromReportCompose(Attempt $attempt, ?Result $result, array $options = []): self
    {
        $scoresPct = is_array($result?->scores_pct ?? null) ? (array) $result?->scores_pct : [];

        $data = [
            'attempt_id' => (string) ($attempt->id ?? ''),
            'scale_code' => (string) ($attempt->scale_code ?? $result?->scale_code ?? ''),
            'type_code' => (string) ($result?->type_code ?? ''),
            'type_name' => (string) data_get($result?->result_json, 'type_name', ''),
            'variant' => (string) ($options['variant'] ?? ''),
            'access_level' => (string) ($options['report_access_level'] ?? ''),
            'modules_allowed' => is_array($options['modules_allowed'] ?? null)
                ? array_values((array) $options['modules_allowed'])
                : [],
            'report_date' => now()->toDateString(),
            'score_axis_ei' => (float) ($scoresPct['EI'] ?? 0),
            'score_axis_sn' => (float) ($scoresPct['SN'] ?? 0),
            'score_axis_tf' => (float) ($scoresPct['TF'] ?? 0),
            'score_axis_jp' => (float) ($scoresPct['JP'] ?? 0),
            'score_axis_at' => (float) ($scoresPct['AT'] ?? 0),
            'percentile_extraversion' => (float) ($scoresPct['EI'] ?? 0),
        ];

        return new self($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function has(string $name): bool
    {
        return $this->resolve($name, false)['exists'];
    }

    public function get(string $name): mixed
    {
        $resolved = $this->resolve($name, false);

        return $resolved['exists'] ? $resolved['value'] : null;
    }

    public function getCtx(string $name): mixed
    {
        $resolved = $this->resolve($name, true);

        return $resolved['exists'] ? $resolved['value'] : null;
    }

    /**
     * @return array{exists:bool,value:mixed}
     */
    private function resolve(string $name, bool $fromCtx): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['exists' => false, 'value' => null];
        }

        $source = $this->data;
        if ($fromCtx) {
            $source = is_array($this->data['ctx'] ?? null) ? (array) $this->data['ctx'] : [];
        }

        $segments = explode('.', $name);
        $current = $source;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return ['exists' => false, 'value' => null];
            }
            $current = $current[$segment];
        }

        return ['exists' => true, 'value' => $current];
    }
}
