<script>
    (() => {
        if (window.__opsSidebarAccessibilityHookInstalled) {
            return
        }

        window.__opsSidebarAccessibilityHookInstalled = true

        const syncSidebarItemLabels = () => {
            document.querySelectorAll('.fi-sidebar-item-button').forEach((item) => {
                const label = item.querySelector('.fi-sidebar-item-label')?.textContent?.trim()

                if (! label) {
                    return
                }

                item.setAttribute('aria-label', label)
                item.setAttribute('title', label)
            })
        }

        syncSidebarItemLabels()
        document.addEventListener('DOMContentLoaded', syncSidebarItemLabels, { once: true })
        document.addEventListener('livewire:navigated', syncSidebarItemLabels)

        new MutationObserver(syncSidebarItemLabels).observe(document.body, {
            childList: true,
            subtree: true,
        })
    })()
</script>
