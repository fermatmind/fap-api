<x-filament-panels::page.simple>
    @if (filament()->hasRegistration())
        <x-slot name="subheading">
            {{ __('filament-panels::pages/auth/login.actions.register.before') }}

            {{ $this->registerAction }}
        </x-slot>
    @endif

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

    <form
        id="form"
        method="post"
        wire:submit="authenticate"
        novalidate
        x-data="{ isProcessing: false }"
        x-init="
            const syncAutofill = () => {
                const emailInput = $el.querySelector('input[autocomplete=\'username\'], input[type=\'email\'], input[name=\'email\']')
                const passwordInput = $el.querySelector('input[autocomplete=\'current-password\'], input[type=\'password\'], input[name=\'password\']')

                if (emailInput && emailInput.value) {
                    $wire.set('data.email', emailInput.value)
                }

                if (passwordInput && passwordInput.value) {
                    $wire.set('data.password', passwordInput.value)
                }
            }

            setTimeout(syncAutofill, 300)
            setTimeout(syncAutofill, 900)
        "
        x-on:submit="
            if (isProcessing) {
                $event.preventDefault()
                return
            }

            const emailInput = $el.querySelector('input[autocomplete=\'username\'], input[type=\'email\'], input[name=\'email\']')
            const passwordInput = $el.querySelector('input[autocomplete=\'current-password\'], input[type=\'password\'], input[name=\'password\']')

            if (emailInput && emailInput.value) {
                $wire.set('data.email', emailInput.value)
            }

            if (passwordInput && passwordInput.value) {
                $wire.set('data.password', passwordInput.value)
            }
        "
        x-on:form-processing-started="isProcessing = true"
        x-on:form-processing-finished="isProcessing = false"
        class="fi-form grid gap-y-6"
    >
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </form>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}
</x-filament-panels::page.simple>
