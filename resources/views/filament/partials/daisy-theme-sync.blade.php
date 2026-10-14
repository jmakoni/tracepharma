{{-- Sync Filament html.dark ↔ daisyUI data-theme (tracepharma / tracepharma-dark). --}}
<script>
    (() => {
        const syncDaisyTheme = () => {
            const isDark = document.documentElement.classList.contains('dark');
            document.documentElement.setAttribute(
                'data-theme',
                isDark ? 'tracepharma-dark' : 'tracepharma',
            );
        };

        syncDaisyTheme();

        new MutationObserver(syncDaisyTheme).observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });

        document.addEventListener('livewire:navigated', syncDaisyTheme);
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (typeof loadDarkMode === 'function') {
                loadDarkMode();
            }
            syncDaisyTheme();
        });
    })();
</script>
