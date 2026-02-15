<x-filament-panels::page>
    <x-filament::section>
        <div class="max-w-md space-y-3">
            <h3 class="text-lg font-semibold text-gray-900">Two-Factor Authentication</h3>
            <p class="text-sm text-gray-600">Enter your authenticator code or one-time recovery code.</p>

            <div>
                <label class="block text-sm font-medium text-gray-700" for="totp-code">Verification code</label>
                <input
                    id="totp-code"
                    type="text"
                    wire:model.defer="code"
                    autocomplete="one-time-code"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                />
            </div>

            <x-filament::button color="primary" wire:click="verify">
                Verify
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
