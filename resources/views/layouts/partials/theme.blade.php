{{-- Theme handling shared by the app & guest layouts. --}}
{{-- Runs in <head> before first paint (anti-flicker) AND re-applies after every --}}
{{-- Livewire SPA navigation, which morphs <html> and would otherwise drop the class. --}}
<script>
    (function () {
        window.applyTheme = function () {
            var stored = localStorage.getItem('theme');
            var dark = stored === 'dark' ||
                (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            return dark;
        };

        // First paint.
        window.applyTheme();

        // Re-apply on every wire:navigate transition.
        document.addEventListener('livewire:navigated', window.applyTheme);
    })();
</script>
