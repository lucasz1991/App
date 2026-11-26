import {
    clearSidebarCollapseTimer,
    clearSidebarExpandTimer,
    setDesktopSidebarExpanded,
    setMobileSidebarOpen,
} from './sidebar-navigation';

/*
 * Zurueck-Navigation ueber einen selbst gefuehrten Seitenverlauf.
 *
 * Die fruehere Loesung traversierte die Browser-Historie mit history.go(-1)
 * und kaempfte per popstate-Schleife gegen die Folgen: Livewire stellt bei
 * popstate eingefrorene HTML-Snapshots samt altem Alpine-Zustand wieder her —
 * offene Menues, tote Listener, veralteter Komponentenzustand. Deshalb wird
 * der Verlauf der besuchten Seiten jetzt selbst gefuehrt (sessionStorage:
 * pro Tab, ueberlebt Reloads) und "Zurueck" ist ein regulaerer
 * Livewire.navigate()-Besuch der Vorseite: frischer Serverzustand, frisches
 * Alpine, keine Snapshot-Wiederherstellung.
 */
const RT_BACK_STACK_KEY = 'railtime:back-stack';
const RT_BACK_STACK_LIMIT = 50;
let rtBackNavigationTarget = null;

function readBackStack() {
    try {
        const parsed = JSON.parse(window.sessionStorage.getItem(RT_BACK_STACK_KEY) || '[]');

        return Array.isArray(parsed) ? parsed.filter((entry) => typeof entry === 'string') : [];
    } catch (_) {
        return [];
    }
}

function writeBackStack(stack) {
    try {
        window.sessionStorage.setItem(
            RT_BACK_STACK_KEY,
            JSON.stringify(stack.slice(-RT_BACK_STACK_LIMIT)),
        );
    } catch (_) {
        // Privater Modus: Zurueck faellt dann auf den Fallback-Link zurueck.
    }
}

function rtCurrentPageUrl() {
    return window.location.pathname + window.location.search;
}

function recordPageVisit() {
    const current = rtCurrentPageUrl();

    // Nach einem programmatischen Zurueck-Sprung liegt die Zielseite bereits
    // als Spitze im Verlauf — erneutes Aufsetzen ergaebe eine Schleife
    // (zurueck, zurueck fuehrte wieder VORWAERTS).
    if (rtBackNavigationTarget === current) {
        rtBackNavigationTarget = null;

        return;
    }

    rtBackNavigationTarget = null;

    const stack = readBackStack();

    if (stack[stack.length - 1] !== current) {
        stack.push(current);
        writeBackStack(stack);
    }
}

function closeTransientNavigationUi() {
    clearSidebarCollapseTimer();
    clearSidebarExpandTimer();
    setDesktopSidebarExpanded(false);
    setMobileSidebarOpen(false);

    document.documentElement.classList.remove('rt-topbar-search-open');

    // Alpine-Komponenten in teleportierten Ebenen koennen nicht verlaesslich
    // ueber einen gemeinsamen DOM-Elternknoten geschlossen werden. Ein
    // Fensterereignis erreicht dagegen Sidebar, Suche, Dropdowns und Modale
    // auch dann, wenn Livewire den eigentlichen Seiten-Body gerade ersetzt.
    window.dispatchEvent(new CustomEvent('rt-navigation:prepare'));
    window.dispatchEvent(new CustomEvent('rt-topbar-search-close', {
        detail: { restoreFocus: false },
    }));
    window.dispatchEvent(new CustomEvent('close'));
}

function backToPreviousPage(fallbackUrl = '/') {
    closeTransientNavigationUi();

    const stack = readBackStack();
    const current = rtCurrentPageUrl();

    // Spitze = aktuelle Seite; darunter liegt das Ziel.
    if (stack[stack.length - 1] === current) {
        stack.pop();
    }

    const target = stack.length > 0
        ? stack[stack.length - 1]
        : String(fallbackUrl || '/');

    // Der Verlauf soll nach dem Sprung wieder mit der Zielseite als Spitze
    // enden — auch wenn er leer war und der Fallback greift.
    if (stack.length === 0) {
        stack.push(target);
    }

    writeBackStack(stack);
    rtBackNavigationTarget = target;

    // Regulaerer SPA-Besuch statt history.go(-1): Livewire mountet die
    // Vorseite frisch, nichts wird aus einem Snapshot wiederbelebt.
    if (window.Livewire?.navigate) {
        window.Livewire.navigate(target);
    } else {
        window.location.assign(target);
    }
}

function installBackNavigation() {
    // Jede angekommene Seite in den Verlauf aufnehmen: livewire:navigated feuert
    // bei SPA-Wechseln UND beim ersten Laden; der DOMContentLoaded-Fallback deckt
    // Seiten ab, auf denen Livewire nicht startet. Doppelte Eintraege verhindert
    // der Spitzen-Vergleich in recordPageVisit().
    document.addEventListener('livewire:navigated', recordPageVisit);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', recordPageVisit, { once: true });
    } else {
        recordPageVisit();
    }

    window.RTNavigation = {
        backToPreviousPage,
        closeTransientUi: closeTransientNavigationUi,
    };
}

export { backToPreviousPage, closeTransientNavigationUi, installBackNavigation };
