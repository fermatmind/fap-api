<?php

namespace App\Http\Requests\V0_2;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $currency = strtoupper(trim((string) $this->input('currency', 'CNY')));
        $provider = strtolower(trim((string) $this->input('provider', '')));
        $providerOrderId = trim((string) $this->input('provider_order_id', ''));

        $this->merge([
            'item_sku' => strtoupper(trim((string) $this->input('item_sku', ''))),
            'currency' => $currency !== '' ? $currency : 'CNY',
            'device_id' => $this->input('device_id', $this->header('X-Device-Id', null)),
            'provider' => $provider !== '' ? $provider : null,
            'provider_order_id' => $providerOrderId !== '' ? $providerOrderId : null,
            'request_id' => $this->header('X-Request-Id', $this->input('request_id', null)),
        ]);
    }

    public function rules(): array
    {
        return [
            'item_sku' => ['required', 'string', 'max:64', $this->skuExistsRule()],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'max:8'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'provider' => ['nullable', 'string', 'max:32'],
            'provider_order_id' => ['nullable', 'string', 'max:128'],
            'request_id' => ['nullable', 'string', 'max:128'],
            'user_id' => ['nullable', 'string', 'max:64'],
            'anon_id' => ['nullable', 'string', 'max:64'],
            'attempt_id' => ['nullable', 'string', 'max:64'],
            'platform' => ['nullable', 'string', 'max:32'],
            'pay_source' => ['nullable', 'string', 'max:64'],
            'ip' => ['nullable', 'string', 'max:45'],
        ];
    }

    public function itemSku(): string
    {
        return (string) $this->validated()['item_sku'];
    }

    public function currency(): string
    {
        return (string) $this->validated()['currency'];
    }

    public function quantity(): int
    {
        return (int) ($this->validated()['quantity'] ?? 1);
    }

    public function deviceId(): ?string
    {
        $v = $this->validated()['device_id'] ?? null;
        $v = is_string($v) ? trim($v) : null;
        return $v !== '' ? $v : null;
    }

    public function provider(): ?string
    {
        $v = $this->validated()['provider'] ?? null;
        $v = is_string($v) ? trim($v) : null;
        return $v !== '' ? $v : null;
    }

    public function providerOrderId(): ?string
    {
        $v = $this->validated()['provider_order_id'] ?? null;
        $v = is_string($v) ? trim($v) : null;
        return $v !== '' ? $v : null;
    }

    public function requestId(): ?string
    {
        $v = $this->validated()['request_id'] ?? null;
        $v = is_string($v) ? trim($v) : null;
        return $v !== '' ? $v : null;
    }

    public function orderData(): array
    {
        return [
            'user_id' => $this->stringOrNull($this->input('user_id', null)),
            'anon_id' => $this->stringOrNull($this->input('anon_id', null)),
            'device_id' => $this->deviceId(),
            'attempt_id' => $this->stringOrNull($this->input('attempt_id', null)),
            'platform' => $this->stringOrNull($this->input('platform', null)),
            'pay_source' => $this->stringOrNull($this->input('pay_source', null)),
            'item_sku' => $this->itemSku(),
            'quantity' => $this->quantity(),
            'currency' => $this->currency(),
            'provider' => $this->provider(),
            'provider_order_id' => $this->providerOrderId(),
            'request_id' => $this->requestId(),
            'org_id' => $this->legacyOrgId(),
            'ip' => $this->stringOrNull($this->input('ip', null)),
        ];
    }

    private function skuExistsRule(): Exists
    {
        $currency = strtoupper(trim((string) $this->input('currency', 'CNY')));
        $currency = $currency !== '' ? $currency : 'CNY';

        return Rule::exists('skus', 'sku')->where(function (Builder $query) use ($currency): void {
            $query->where('currency', $currency)
                ->where('is_active', 1)
                ->where('org_id', $this->legacyOrgId());
        });
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $v = trim((string) $value);
        return $v !== '' ? $v : null;
    }

    private function legacyOrgId(): int
    {
        $orgId = (int) config('fap.legacy_org_id', 1);

        return $orgId > 0 ? $orgId : 1;
    }
}
