import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './app/Filament/Ops/**/*.php',
        './resources/views/filament/ops/**/*.blade.php',
        './resources/views/livewire/filament/ops/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
}
