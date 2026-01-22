<?php

namespace App\Http\Requests\V0_2;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount_total', null);
        if (is_numeric($amount)) {
            $amount = (int) $amount;
        }

        $currency = strtoupper(trim((string) $this->input('currency', 'CNY')));
        $provider = strtolower(trim((string) $this->input('provider', '')));
        $providerOrderId = trim((string) $this->input('provider_order_id', ''));

        $this->merge([
            'item_sku' => trim((string) $this->input('item_sku', '')),
            'currency' => $currency !== '' ? $currency : 'CNY',
            'amount_total' => $amount,
            'device_id' => $this->input('device_id', $this->header('X-Device-Id', null)),
            'provider' => $provider !== '' ? $provider : null,
            'provider_order_id' => $providerOrderId !== '' ? $providerOrderId : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'item_sku' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'max:8'],
            'amount_total' => ['required', 'integer', 'min:1'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'provider' => ['nullable', 'string', 'max:32'],
            'provider_order_id' => ['nullable', 'string', 'max:128'],
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

    public function amountTotal(): int
    {
        return (int) $this->validated()['amount_total'];
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
        $v = $this->header('X-Request-Id', $this->input('request_id', null));
        $v = is_string($v) ? trim($v) : null;
        return $v !== '' ? $v : null;
    }
}
