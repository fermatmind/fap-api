<x-filament-panels::page>
    <div class="mx-auto max-w-xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold">Set up two-factor authentication</h1>
            <p class="text-sm text-gray-500">Add this secret to your authenticator, store the recovery codes securely, then enter the current six-digit code.</p>
        </div>

        <div class="rounded-lg border p-4 font-mono text-sm break-all">{{ $secret }}</div>

        <div class="rounded-lg border p-4">
            <p class="mb-2 text-sm font-medium">One-time recovery codes</p>
            <ul class="grid grid-cols-2 gap-2 font-mono text-sm">
                @foreach ($recoveryCodes as $recoveryCode)
                    <li>{{ $recoveryCode }}</li>
                @endforeach
            </ul>
        </div>

        <form wire:submit="enroll" class="space-y-4">
            <x-filament::input.wrapper>
                <x-filament::input wire:model="code" inputmode="numeric" autocomplete="one-time-code" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit">Enable 2FA</x-filament::button>
        </form>
    </div>
</x-filament-panels::page>
