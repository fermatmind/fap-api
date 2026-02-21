<?php

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;

class MeAttemptsIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $page = (int) $this->query('page', 1);
        $pageSizeRaw = $this->query('page_size', $this->query('per_page', 20));
        $pageSize = (int) $pageSizeRaw;
        $scale = strtoupper(trim((string) $this->query('scale', '')));

        $this->merge([
            'page' => $page > 0 ? $page : 1,
            'page_size' => $pageSize,
            'per_page' => $pageSize,
            'scale' => $scale,
        ]);
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'scale' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function page(): int
    {
        return max(1, (int) ($this->validated()['page'] ?? 1));
    }

    public function pageSize(): int
    {
        $validated = $this->validated();
        $size = (int) ($validated['page_size'] ?? $validated['per_page'] ?? 20);

        if ($size <= 0) {
            return 20;
        }

        return min(50, $size);
    }

    public function scaleCode(): ?string
    {
        $validated = $this->validated();
        $scale = strtoupper(trim((string) ($validated['scale'] ?? '')));
        if ($scale === '') {
            return null;
        }

        return $scale;
    }
}
