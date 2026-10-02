/*
 * Inertia and Livewire replace the page without reloading it, so the widget
 * loader never sees the containers of the page navigated to. After each such
 * navigation, ask it to look again. The loader marks what it already mounted,
 * so scanning twice is harmless; before the loader has loaded, nothing to do.
 */
(function () {
    function scan() {
        setTimeout(function () {
            try {
                window.XeradsWidgets && window.XeradsWidgets.scan();
            } catch (error) {
                // Never let a widget break the page around it.
            }
        }, 0);
    }

    document.addEventListener('inertia:navigate', scan);
    document.addEventListener('livewire:navigated', scan);
})();
