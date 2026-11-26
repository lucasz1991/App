function initFeather() {
    if (window.feather && typeof window.feather.replace === 'function') {
        window.feather.replace();
    }
}

let featherFrame = null;

function queueFeatherInit() {
    if (featherFrame !== null) {
        return;
    }

    featherFrame = window.requestAnimationFrame(() => {
        featherFrame = null;
        initFeather();
    });
}

// Livewire morphs can introduce fresh <i data-feather> placeholders without
// triggering a full wire:navigate lifecycle. Coalesce the element-level hook
// into one replacement pass per frame so newly rendered actions keep both
// their icon and their intended touch target.
function installFeatherRefresh(Livewire) {
    Livewire.hook('morph.updated', queueFeatherInit);
}

export { initFeather, queueFeatherInit, installFeatherRefresh };
