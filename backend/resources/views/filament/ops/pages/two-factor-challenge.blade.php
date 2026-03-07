<x-filament-panels::page>
    <x-filament::section>
        <div class="ops-two-factor-stack max-w-md space-y-4">
            <p class="ops-shell-inline-intro__meta">
                Confirm your admin session with an authenticator code or one-time recovery code.
            </p>

            <div class="ops-control-stack">
                <label class="ops-control-label" for="totp-code">Verification code</label>
                <input
                    id="totp-code"
                    type="text"
                    wire:model.defer="code"
                    autocomplete="one-time-code"
                    class="ops-input"
                />
                <p class="ops-control-hint">Use the current code from your authenticator app or a one-time recovery code.</p>
            </div>

            <x-filament::button color="primary" wire:click="verify">
                Verify
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
