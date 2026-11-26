/*
 * Untermenues der App-Sidebar (ersetzt MetisMenu).
 *
 * Die Zustandsklassen bleiben bewusst die bisherigen, weil shell.css und das
 * Legacy-Stylesheet darauf aufbauen:
 *   li.mm-active            Gruppe offen bzw. Teil des aktiven Pfads
 *   ul.mm-collapse          Untermenue (ohne mm-show unsichtbar)
 *   ul.mm-collapse.mm-show  Untermenue offen
 *   ul.mm-collapsing        Hoehenuebergang laeuft (Hoehe steht inline)
 *
 * Anders als MetisMenu gibt es keine globale Sperre waehrend eines
 * Uebergangs: Jedes Untermenue fuehrt seinen eigenen Abschluss, ein erneuter
 * Klick kehrt den laufenden Uebergang um. Faellt transitionend aus (keine
 * Transition, eingefrorene Animationen, Bodytausch), schliesst ein Timer ab.
 */

const SUBMENU_SELECTOR = ':scope > ul[data-rt-sidebar-submenu]';
const TRIGGER_SELECTOR = ':scope > [data-rt-sidebar-group]';
const FALLBACK_MS = 420;

const pending = new WeakMap();

function settle(ul) {
    const cancel = pending.get(ul);
    if (cancel) {
        cancel();
    }
}

function runTransition(ul, finish) {
    settle(ul);

    const view = ul.ownerDocument?.defaultView;
    let timer = null;
    const done = () => {
        ul.removeEventListener('transitionend', onEnd);
        if (timer !== null) {
            view?.clearTimeout(timer);
            timer = null;
        }
        pending.delete(ul);
        finish();
    };
    // Kinder (Links mit eigenen Uebergaengen) melden transitionend ebenfalls;
    // nur die Hoehe des Untermenues selbst beendet den Vorgang.
    const onEnd = (event) => {
        if (event.target === ul && (!event.propertyName || event.propertyName === 'height')) {
            done();
        }
    };

    ul.addEventListener('transitionend', onEnd);
    timer = view ? view.setTimeout(done, FALLBACK_MS) : null;
    pending.set(ul, done);
}

function setTriggerExpanded(li, expanded) {
    li?.querySelector(TRIGGER_SELECTOR)?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
}

export function sidebarSubmenuOf(trigger) {
    return trigger?.closest('li')?.querySelector(SUBMENU_SELECTOR) ?? null;
}

export function isSidebarSubmenuOpen(ul) {
    return Boolean(ul?.closest('li')?.classList.contains('mm-active'));
}

export function hideSidebarSubmenu(ul) {
    if (!ul || (!ul.classList.contains('mm-show') && !ul.classList.contains('mm-collapsing'))) {
        return;
    }

    const li = ul.closest('li');
    li?.classList.remove('mm-active');
    setTriggerExpanded(li, false);

    ul.style.height = `${ul.getBoundingClientRect().height}px`;
    void ul.offsetHeight;
    ul.classList.add('mm-collapsing');
    ul.classList.remove('mm-collapse', 'mm-show');
    runTransition(ul, () => {
        ul.classList.remove('mm-collapsing');
        ul.classList.add('mm-collapse');
        ul.style.height = '';
    });
    ul.style.height = '0px';
}

export function showSidebarSubmenu(ul, { onShown } = {}) {
    if (!ul) {
        return;
    }

    const li = ul.closest('li');
    const alreadyOpen = ul.classList.contains('mm-show') && !ul.classList.contains('mm-collapsing');
    li?.classList.add('mm-active');
    setTriggerExpanded(li, true);

    // Akkordeon: offene Geschwister derselben Ebene schliessen.
    Array.from(li?.parentElement?.children ?? [])
        .filter((sibling) => sibling !== li)
        .forEach((sibling) => hideSidebarSubmenu(sibling.querySelector(SUBMENU_SELECTOR)));

    if (alreadyOpen) {
        return;
    }

    const startHeight = ul.classList.contains('mm-collapsing') ? ul.getBoundingClientRect().height : 0;
    ul.style.height = `${startHeight}px`;
    ul.classList.remove('mm-collapse', 'mm-show');
    ul.classList.add('mm-collapsing');
    void ul.offsetHeight;
    ul.classList.add('mm-collapse', 'mm-show');
    runTransition(ul, () => {
        ul.classList.remove('mm-collapsing');
        ul.style.height = '';
        onShown?.(ul);
    });
    // scrollHeight ohne Rahmen; mit box-sizing:border-box gehoert er dazu,
    // sonst springt das Menue am Ende des Uebergangs um die Rahmenbreite.
    ul.style.height = `${ul.scrollHeight + Math.max(0, ul.offsetHeight - ul.clientHeight)}px`;
}

export function toggleSidebarGroup(trigger, options = {}) {
    const ul = sidebarSubmenuOf(trigger);
    if (!ul) {
        return;
    }

    if (isSidebarSubmenuOpen(ul)) {
        hideSidebarSubmenu(ul);
    } else {
        showSidebarSubmenu(ul, options);
    }
}

/** Beendet laufende Uebergaenge sofort (vor einer neuen Zustandsrunde). */
export function settleSidebarSubmenus(root) {
    root?.querySelectorAll('ul[data-rt-sidebar-submenu]').forEach((ul) => {
        settle(ul);
        ul.classList.remove('mm-collapsing');
        ul.classList.add('mm-collapse');
        ul.style.removeProperty('height');
    });
}

/**
 * Bindet die Gruppenschalter per Delegation an `root` und gibt eine
 * Abmeldefunktion zurueck. Enter loest auf dem Link nativ einen Klick aus,
 * die Leertaste leitet das Blade-Bauteil per $el.click() weiter.
 */
export function bindSidebarAccordion(root, options = {}) {
    if (!root) {
        return () => {};
    }

    const onClick = (event) => {
        const trigger = typeof event.target?.closest === 'function' ? event.target.closest('[data-rt-sidebar-group]') : null;
        if (!trigger || !root.contains(trigger) || trigger.getAttribute('aria-disabled') === 'true') {
            return;
        }

        event.preventDefault();
        toggleSidebarGroup(trigger, options);
    };

    root.addEventListener('click', onClick);

    return () => root.removeEventListener('click', onClick);
}
