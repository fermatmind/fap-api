<script>
    document.addEventListener('livewire:init', () => {
        if (window.__opsLivewirePageExpiredRecoveryHookInstalled) {
            return
        }

        window.__opsLivewirePageExpiredRecoveryHookInstalled = true

        const autoRefreshStorageKeyPrefix = 'ops-livewire-page-expired-at:'
        const autoRefreshCooldownMs = 30_000

        window.Livewire?.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                const pathname = window.location.pathname || ''

                if (status !== 419 || ! pathname.startsWith('/ops')) {
                    return
                }

                const autoRefreshStorageKey = `${autoRefreshStorageKeyPrefix}${pathname}`
                const lastAutoRefreshAt = Number(window.sessionStorage.getItem(autoRefreshStorageKey) || '0')
                const now = Date.now()

                if (Number.isFinite(lastAutoRefreshAt) && (now - lastAutoRefreshAt) < autoRefreshCooldownMs) {
                    return
                }

                window.sessionStorage.setItem(autoRefreshStorageKey, String(now))
                preventDefault()
                window.location.reload()
            })
        })
    })
</script>
