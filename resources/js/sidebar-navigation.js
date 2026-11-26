import { Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import {
    MOBILE_SIDEBAR_BREAKPOINT,
    isMobileSidebarSwipeExcluded,
    advanceSidebarDrag,
    beginSidebarDrag,
    resolveMobileSidebarSwipe,
    settleSidebarDrag,
} from './mobile-sidebar-swipe';
import { sidebarScrollBehavior, sidebarScrollTarget } from './sidebar-scroll';
import { bindSidebarAccordion, settleSidebarSubmenus } from './sidebar-accordion';
import { queueFeatherInit } from './feather-icons';

let sidebarCollapseTimer = null;
let sidebarExpandTimer = null;
let sidebarSwipeStart = null;
const DESKTOP_SIDEBAR_EXPAND_DELAY = 750;
const DESKTOP_SIDEBAR_COLLAPSE_DELAY = 1500;

function sidebarNavigation() {
    return {
        unbindAccordion: null,

        init() {
            this.$nextTick(() => {
                if (!this.$root.isConnected) {
                    return;
                }

                // Der Server liefert den aktiven Pfad bereits mit (mm-active,
                // mm-show, aria-expanded). Hier nur Reste eines abgebrochenen
                // Uebergangs bereinigen und den Pfad clientseitig bestaetigen.
                settleSidebarSubmenus(this.$root);
                initActiveMenu(this.$root);
                this.unbindAccordion = bindSidebarAccordion(this.$root, {
                    onShown: (openedSubmenu) => {
                        window.requestAnimationFrame(() => {
                            if (openedSubmenu?.isConnected) {
                                scrollSidebarBlockIntoView(openedSubmenu);
                            }
                        });
                    },
                });

                initMenuItemScroll(this.$root);
            });
        },

        destroy() {
            this.unbindAccordion?.();
            this.unbindAccordion = null;
        },
    };
}

function clearSidebarCollapseTimer() {
    if (sidebarCollapseTimer) {
        window.clearTimeout(sidebarCollapseTimer);
        sidebarCollapseTimer = null;
    }
}

function clearSidebarExpandTimer() {
    if (sidebarExpandTimer) {
        window.clearTimeout(sidebarExpandTimer);
        sidebarExpandTimer = null;
    }
}

function isDesktopHoverSidebar() {
    return document.body.dataset.sidebarDrawer !== 'true'
        && window.innerWidth >= 1024 && Boolean(document.querySelector('.vertical-menu'));
}

function isSidebarHoveredOrFocused() {
    const activeElement = document.activeElement;
    const hoverInsideSidebar = document.querySelector('.vertical-menu:hover, .topbar-brand:hover');
    const focusInsideSidebar = activeElement?.closest('.vertical-menu, .topbar-brand');

    return Boolean(hoverInsideSidebar || focusInsideSidebar);
}

function setDesktopSidebarExpanded(expanded) {
    if (document.body.getAttribute('data-sidebar-collapsible') !== 'true') {
        return;
    }

    const nextExpanded = Boolean(expanded);
    const wasExpanded = document.body.getAttribute('data-sidebar-expanded') === 'true';

    Alpine.store('shell').setDesktopSidebarExpanded(nextExpanded);
    document.body.setAttribute('data-sidebar-expanded', nextExpanded ? 'true' : 'false');
    syncSidebarToggleState();
    if (nextExpanded && !wasExpanded) {
        window.dispatchEvent(new window.CustomEvent('railtime-sidebar-opened'));
    }
}

function captureDesktopSidebarState() {
    if (!document.body || !isDesktopHoverSidebar()) {
        return;
    }

    Alpine.store('shell').setDesktopSidebarExpanded(
        document.body.getAttribute('data-sidebar-expanded') === 'true'
    );
}

function restoreDesktopSidebarState() {
    if (!document.body || !isDesktopHoverSidebar()) {
        return;
    }

    // Direkte Zuweisung als synchroner Fallback fuer den kurzen Moment,
    // bevor Alpine das x-bind des neuen <body> ausgewertet hat.
    document.body.setAttribute(
        'data-sidebar-expanded',
        Alpine.store('shell').desktopSidebarExpanded ? 'true' : 'false'
    );
    syncSidebarToggleState();
}

function setMobileSidebarOpen(open) {
    const sidebar = document.getElementById('app-sidebar');
    const canOpen = (document.body.dataset.sidebarDrawer === 'true' || window.innerWidth < MOBILE_SIDEBAR_BREAKPOINT)
        && sidebar?.isConnected === true;
    const wasOpen = document.body.classList.contains('sidebar-enable');
    const nextOpen = Boolean(open) && canOpen;

    document.body.classList.toggle('sidebar-enable', nextOpen);
    if (!open && sidebar?.contains(document.activeElement)) {
        document.getElementById('vertical-menu-btn')?.focus({ preventScroll: true });
    }
    syncSidebarToggleState();
    if (nextOpen && !wasOpen) {
        window.dispatchEvent(new window.CustomEvent('railtime-sidebar-opened'));
    }
}

function initMobileSidebarSwipe() {
    if (window.__rtMobileSidebarSwipeBound === true) {
        return;
    }

    window.__rtMobileSidebarSwipeBound = true;

    // Fingerfuehrung: Die Sidebar folgt der Geste 1:1 (CSS-Variable
    // --rt-sidebar-drag-progress, Klasse rt-sidebar-dragging schaltet die
    // Transition ab). Erst beim Loslassen entscheidet settleSidebarDrag
    // ueber Position UND Geschwindigkeit, wohin die Sidebar federt.
    let sidebarDragState = null;
    let sidebarDragPainted = false;

    const clearSidebarDragPresentation = () => {
        if (sidebarDragPainted) {
            document.body.classList.remove('rt-sidebar-dragging');
            document.body.style.removeProperty('--rt-sidebar-drag-progress');
            sidebarDragPainted = false;
        }

        sidebarDragState = null;
    };

    const settleSidebarTo = (open) => {
        // Erst der Zielzustand, dann die Drag-Klasse entfernen: Beide
        // Aenderungen landen im selben Style-Recalc, die Transition startet
        // deshalb exakt an der zuletzt gezeichneten Fingerposition.
        setMobileSidebarOpen(open);

        if (open) {
            initMenuItemScroll();
        }

        clearSidebarDragPresentation();
    };

    const cancelSidebarSwipe = () => {
        // Der Body behaelt waehrend des Ziehens seinen bisherigen Zustand.
        // Nur die Praesentation loesen, nie einen inzwischen geaenderten
        // Zustand (Menuebutton, Navigation oder Desktopwechsel) ueberschreiben.
        sidebarSwipeStart = null;
        clearSidebarDragPresentation();
    };

    const sidebarGestureIsCurrent = () => sidebarSwipeStart
        && window.innerWidth < MOBILE_SIDEBAR_BREAKPOINT
        && document.getElementById('app-sidebar')?.isConnected === true
        && document.body.classList.contains('sidebar-enable') === sidebarSwipeStart.sidebarOpen;

    document.addEventListener('touchstart', (event) => {
        cancelSidebarSwipe();
        if (
            window.innerWidth >= MOBILE_SIDEBAR_BREAKPOINT
            || event.defaultPrevented
            || event.touches.length !== 1
            || document.getElementById('app-sidebar')?.isConnected !== true
        ) {
            return;
        }

        const target = event.target instanceof Element ? event.target : null;
        const touch = event.touches[0];
        const sidebarOpen = document.body.classList.contains('sidebar-enable');

        sidebarDragState = beginSidebarDrag({
            startX: touch.clientX,
            startY: touch.clientY,
            sidebarOpen,
            viewportWidth: window.innerWidth,
            timestamp: event.timeStamp,
        });

        if (!sidebarDragState || isMobileSidebarSwipeExcluded(target)) {
            cancelSidebarSwipe();
            return;
        }

        sidebarSwipeStart = {
            x: touch.clientX,
            y: touch.clientY,
            identifier: touch.identifier,
            target,
            sidebarOpen,
        };
    }, { passive: true });

    document.addEventListener('touchmove', (event) => {
        if (!sidebarDragState) {
            return;
        }

        if (!sidebarGestureIsCurrent() || event.touches.length !== 1
            || event.touches[0].identifier !== sidebarSwipeStart.identifier
            || event.defaultPrevented || (!sidebarDragState.claimed && !event.cancelable)) {
            cancelSidebarSwipe();
            return;
        }

        const touch = event.touches[0];
        sidebarDragState = advanceSidebarDrag(sidebarDragState, {
            x: touch.clientX,
            y: touch.clientY,
            timestamp: event.timeStamp,
        });

        if (!sidebarDragState || sidebarDragState.rejected) {
            cancelSidebarSwipe();
            return;
        }

        if (!sidebarDragState.claimed) {
            return;
        }

        if (!sidebarDragPainted) {
            document.body.classList.add('rt-sidebar-dragging');
            sidebarDragPainted = true;
        }

        document.body.style.setProperty(
            '--rt-sidebar-drag-progress',
            String(sidebarDragState.progress),
        );

        // Die Geste gehoert jetzt der Sidebar — kein paralleles Scrollen.
        if (event.cancelable) {
            event.preventDefault();
        }
    }, { passive: false });

    document.addEventListener('touchend', (event) => {
        if (!sidebarGestureIsCurrent() || event.touches.length !== 0
            || event.changedTouches.length !== 1
            || event.changedTouches[0].identifier !== sidebarSwipeStart.identifier
            || event.defaultPrevented) {
            cancelSidebarSwipe();
            return;
        }

        if (sidebarDragPainted && sidebarDragState?.claimed) {
            const action = settleSidebarDrag(sidebarDragState);

            if (event.cancelable) {
                event.preventDefault();
            }

            settleSidebarTo(action === 'open');
            sidebarSwipeStart = null;
            return;
        }

        clearSidebarDragPresentation();

        if (
            !sidebarSwipeStart
            || event.changedTouches.length !== 1
            || window.innerWidth >= MOBILE_SIDEBAR_BREAKPOINT
            || document.getElementById('app-sidebar')?.isConnected !== true
        ) {
            sidebarSwipeStart = null;
            return;
        }

        const touch = event.changedTouches[0];
        const action = resolveMobileSidebarSwipe({
            startX: sidebarSwipeStart.x,
            startY: sidebarSwipeStart.y,
            endX: touch.clientX,
            endY: touch.clientY,
            sidebarOpen: sidebarSwipeStart.sidebarOpen,
            viewportWidth: window.innerWidth,
        });

        // Nur eine bestaetigte horizontale Geste unterdrueckt den synthetischen
        // Folgeklick. Normales Tippen und vertikales Scrollen bleiben nativ.
        if (action && event.cancelable) {
            event.preventDefault();
        }

        if (action === 'open') {
            setMobileSidebarOpen(true);
            initMenuItemScroll();
        } else if (action === 'close') {
            setMobileSidebarOpen(false);
        }

        sidebarSwipeStart = null;
    }, { passive: false });

    document.addEventListener('touchcancel', () => {
        cancelSidebarSwipe();
    }, { passive: true });

    // Natives Scrollen hat Vorrang, bevor die Sidebar eine Geste uebernimmt.
    document.addEventListener('scroll', (event) => {
        if (sidebarSwipeStart && !sidebarDragState?.claimed
            && (event.target === document || event.target?.contains?.(sidebarSwipeStart.target))) {
            cancelSidebarSwipe();
        }
    }, { passive: true, capture: true });

    window.addEventListener('resize', cancelSidebarSwipe, { passive: true });

    // Tippen auf den Schleier schliesst die Navigation — konsistent mit
    // jedem Drawer-Muster und noetig, seit der Schleier Zeigerereignisse
    // annimmt (pointer-events: auto im offenen Zustand).
    document.addEventListener('click', (event) => {
        if (
            event.target instanceof Element
            && event.target.closest('.rt-mobile-sidebar-backdrop')
        ) {
            setMobileSidebarOpen(false);
        }
    });

    document.addEventListener('livewire:navigating', () => {
        cancelSidebarSwipe();
    });
}

function syncSidebarToggleState() {
    const expanded = isDesktopHoverSidebar()
        ? document.body.getAttribute('data-sidebar-expanded') === 'true'
        : document.body.classList.contains('sidebar-enable');

    document.querySelectorAll('.vertical-menu-btn').forEach((button) => {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    });
    const sidebar = document.getElementById('app-sidebar');
    if (sidebar && document.body.dataset.sidebarDrawer === 'true') {
        sidebar.inert = !expanded;
        sidebar.setAttribute('aria-hidden', expanded ? 'false' : 'true');
    }
}

function scheduleDesktopSidebarCollapse() {
    clearSidebarCollapseTimer();
    clearSidebarExpandTimer();

    sidebarCollapseTimer = window.setTimeout(() => {
        sidebarCollapseTimer = null;

        if (!isSidebarHoveredOrFocused()) {
            setDesktopSidebarExpanded(false);
        }
    }, DESKTOP_SIDEBAR_COLLAPSE_DELAY);
}

function scheduleDesktopSidebarExpand() {
    clearSidebarCollapseTimer();
    clearSidebarExpandTimer();

    if (document.body.getAttribute('data-sidebar-expanded') === 'true') {
        return;
    }

    sidebarExpandTimer = window.setTimeout(() => {
        sidebarExpandTimer = null;

        if (isSidebarHoveredOrFocused()) {
            setDesktopSidebarExpanded(true);
        }
    }, DESKTOP_SIDEBAR_EXPAND_DELAY);
}

function syncSidebarInteractionMode() {
    sidebarSwipeStart = null;

    const hasSidebar = Boolean(document.querySelector('.vertical-menu'));
    if (!hasSidebar) {
        return;
    }

    const desktopMode = isDesktopHoverSidebar();
    document.body.setAttribute('data-sidebar-collapsible', desktopMode ? 'true' : 'false');

    if (desktopMode) {
        setMobileSidebarOpen(false);

        const isExpanded = document.body.getAttribute('data-sidebar-expanded') === 'true';
        document.body.setAttribute('data-sidebar-expanded', isExpanded ? 'true' : 'false');

        if (!isExpanded && isSidebarHoveredOrFocused()) {
            scheduleDesktopSidebarExpand();
        }

        syncSidebarToggleState();
        return;
    }

    clearSidebarCollapseTimer();
    clearSidebarExpandTimer();
    document.body.setAttribute('data-sidebar-expanded', 'false');
    syncSidebarToggleState();
}

function initLeftMenuCollapse() {
    document.querySelectorAll('.vertical-menu-btn').forEach((button) => {
        if (button.dataset.rtSidebarBound === '1') {
            return;
        }

        button.dataset.rtSidebarBound = '1';

        button.addEventListener('click', (event) => {
            event.preventDefault();

            if (isDesktopHoverSidebar()) {
                clearSidebarCollapseTimer();
                clearSidebarExpandTimer();
                setDesktopSidebarExpanded(document.body.getAttribute('data-sidebar-expanded') !== 'true');
                return;
            }

            setMobileSidebarOpen(!document.body.classList.contains('sidebar-enable'));
            initMenuItemScroll();
            if (event.detail === 0 && document.body.classList.contains('sidebar-enable')) {
                document.querySelector('#app-sidebar a[href]')?.focus({ preventScroll: true });
            }
        });
    });
}

function initActiveMenu(sideMenu = document.getElementById('side-menu')) {
    if (!sideMenu) {
        return;
    }

    const pageUrl = new URL(window.location.href);
    const menuItems = Array.from(sideMenu.querySelectorAll('a'));
    // Gruppen-Trigger verwenden href="#". Der Browser loest dieses Attribut
    // zur aktuellen Seiten-URL auf; dadurch galten sie bisher faelschlich als
    // exakter Treffer und konnten neben dem echten Link aktiv bleiben.
    const navigableItems = menuItems.filter((item) =>
        item.matches('[data-rt-sidebar-link]')
        && item.getAttribute('href')
        && item.getAttribute('href') !== '#'
    );
    const nestedLists = sideMenu.querySelectorAll('ul');
    // Livewires wire:current wird vor unserem navigated-Handler aktualisiert
    // und kennt auch Unterpfade. Das ist fuer die via @persist erhaltene
    // Sidebar die verlaessliche Quelle, wenn der Server-Link selbst nicht
    // exakt der aktuellen Detail-URL entspricht.
    const currentMatches = navigableItems.filter((item) => item.hasAttribute('data-current'));
    const fallbackMatches = navigableItems.filter((item) => item.dataset.menuActive === 'true');
    // Full employee profiles use their authorized workspace entry even when
    // the persisted sidebar still holds markers from another page.
    let profileMatches = null;
    const profileEntry = document.querySelector('[data-employee-profile-entry]')?.dataset.employeeProfileEntry;
    if (profileEntry) {
        try {
            const entry = new URL(profileEntry, pageUrl);
            if (entry.origin === pageUrl.origin) {
                profileMatches = navigableItems.filter((item) => {
                    const target = new URL(item.getAttribute('href'), pageUrl);
                    return target.origin === entry.origin && target.pathname === entry.pathname && target.search === entry.search;
                });
            }
        } catch {
            // An invalid marker must not interrupt ordinary navigation.
        }
    }
    const pathMatches = navigableItems.filter((item) => {
        const target = new URL(item.getAttribute('href'), pageUrl);
        return target.origin === pageUrl.origin && target.pathname === pageUrl.pathname;
    });
    // Planungs- und Personalziele teilen Pfade. Der montierte Personalbereich
    // liefert den erlaubten Standard auch bei direkten Links ohne view-Parameter.
    // Such- und Filterparameter duerfen die Auswahl nicht aendern.
    const workspaceView = document.querySelector('[data-case-workspace]')?.dataset.caseWorkspace;
    const personalRoot = document.querySelector('[data-personal-page]');
    const personalWorkspace = personalRoot?.dataset.personalPage === pageUrl.pathname.split('/').pop()
        ? personalRoot.dataset : null;
    const personalSection = pageUrl.searchParams.get('section') ?? personalWorkspace?.personalSection ?? '';
    const exactMatches = pathMatches.filter((item) => {
        const target = new URL(item.getAttribute('href'), pageUrl);
        if (personalWorkspace && personalSection && target.searchParams.has('view') && !target.searchParams.has('section')) {
            return false;
        }
        return Array.from(target.searchParams).every(([key, value]) => {
            let current = pageUrl.searchParams.get(key);
            if (key === 'view' && !current) current = personalWorkspace?.personalView ?? workspaceView;
            if (key === 'section' && personalWorkspace) current = personalSection;
            if (key === 'section' && !current && target.searchParams.get('view') === 'shifts') current = 'plan';
            return current === value;
        });
    });
    // Ein eigener Unterpunkt gewinnt gegen den allgemeinen Seitenlink. Dieser
    // bleibt nur fuer Ansichten ohne eigenen Menueintrag der aktive Rueckfall.
    const specificity = (item) => Array.from(new URL(item.getAttribute('href'), pageUrl).searchParams).length;
    const bestSpecificity = Math.max(-1, ...exactMatches.map(specificity));
    const activeItems = profileMatches ?? (pathMatches.length > 0
        ? exactMatches.filter((item) => specificity(item) === bestSpecificity)
        : (currentMatches.length > 0 ? currentMatches : fallbackMatches));
    const activeClasses = ['bg-rt-accent-soft/70', 'text-rt-accent', 'font-semibold', 'shadow-rt-xs', 'before:h-5', 'before:opacity-100', 'dark:bg-rt-dark-nav-active', 'dark:text-rt-dark-accent'];
    const inactiveClasses = ['text-rt-muted', 'before:h-0', 'before:opacity-0', 'hover:bg-rt-nav-hover', 'hover:text-rt-accent', 'dark:text-white', 'dark:hover:bg-rt-dark-surface-muted', 'dark:hover:text-white'];
    const syncActiveStyle = (item, active) => {
        item.dataset.menuActive = active ? 'true' : 'false';
        activeClasses.forEach((name) => item.classList.toggle(name, active));
        inactiveClasses.forEach((name) => item.classList.toggle(name, !active));
    };

    menuItems.forEach((item) => {
        item.classList.remove('active');
        item.removeAttribute('aria-current');
        if (item.matches('[data-rt-sidebar-link], [data-rt-sidebar-group]')) syncActiveStyle(item, false);
    });
    sideMenu.querySelectorAll('[data-rt-sidebar-group]').forEach((trigger) => {
        trigger.setAttribute('aria-expanded', 'false');
    });
    sideMenu.querySelectorAll('li.mm-active').forEach((item) => item.classList.remove('mm-active'));
    nestedLists.forEach((list) => {
        list.classList.remove('mm-show');
    });

    activeItems.forEach((item) => {
        item.classList.add('active');
        item.setAttribute('aria-current', 'page');
        syncActiveStyle(item, true);

        let currentLi = item.closest('li');
        while (currentLi) {
            currentLi.classList.add('mm-active');

            const parentUl = currentLi.parentElement;
            if (parentUl && parentUl.tagName === 'UL' && parentUl.id !== 'side-menu') {
                parentUl.classList.add('mm-show');
            }

            currentLi = parentUl ? parentUl.closest('li') : null;
        }
    });

    sideMenu.querySelectorAll('[data-rt-sidebar-group]').forEach((trigger) => {
        const active = Boolean(trigger.closest('li')?.classList.contains('mm-active'));
        syncActiveStyle(trigger, active);
        trigger.setAttribute(
            'aria-expanded',
            active ? 'true' : 'false'
        );
    });
}

function scrollSidebarBlockIntoView(element) {
    const sidebar = element?.closest('.vertical-menu');
    const scroller = sidebar?.querySelector('.rt-sidebar-scroll');
    const block = element?.matches('li')
        ? element
        : element?.closest('li');

    if (!scroller || !block) {
        return;
    }

    const scrollerRect = scroller.getBoundingClientRect();
    const blockRect = block.getBoundingClientRect();
    const top = sidebarScrollTarget({
        scrollTop: scroller.scrollTop,
        clientHeight: scroller.clientHeight,
        scrollHeight: scroller.scrollHeight,
        containerTop: scrollerRect.top,
        containerBottom: scrollerRect.bottom,
        blockTop: blockRect.top,
        blockBottom: blockRect.bottom,
    });

    if (top === null) {
        return;
    }

    const behavior = sidebarScrollBehavior(
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
    );

    if (typeof scroller.scrollTo === 'function') {
        scroller.scrollTo({ top, behavior });
        return;
    }

    // Alter Browser, gleicher lokaler Scrollcontainer.
    scroller.scrollTop = top;
}

function initMenuItemScroll(sideMenu = document.getElementById('side-menu')) {
    window.setTimeout(() => {
        if (!sideMenu?.isConnected) {
            return;
        }

        const activeItem = sideMenu.querySelector('.mm-active .active');

        if (!activeItem) {
            return;
        }

        const containingSubmenu = activeItem.closest('ul.mm-collapse');
        const relevantBlock = containingSubmenu?.closest('li') || activeItem.closest('li');

        scrollSidebarBlockIntoView(relevantBlock);
    }, 150);
}

function initSidebarInteractions() {
    document.querySelectorAll('.vertical-menu, .topbar-brand').forEach((element) => {
        if (element.dataset.rtSidebarHoverBound === '1') {
            return;
        }

        element.dataset.rtSidebarHoverBound = '1';

        element.addEventListener('mouseenter', () => {
            if (!isDesktopHoverSidebar()) {
                return;
            }

            scheduleDesktopSidebarExpand();
        });

        element.addEventListener('mouseleave', () => {
            if (!isDesktopHoverSidebar()) {
                return;
            }

            clearSidebarExpandTimer();
            scheduleDesktopSidebarCollapse();
        });

        element.addEventListener('focusin', () => {
            if (!isDesktopHoverSidebar()) {
                return;
            }

            clearSidebarCollapseTimer();
            clearSidebarExpandTimer();
            setDesktopSidebarExpanded(true);
        });

        element.addEventListener('focusout', () => {
            if (!isDesktopHoverSidebar()) {
                return;
            }

            scheduleDesktopSidebarCollapse();
        });
    });

    // document/window ueberleben den body-Tausch von wire:navigate. Der
    // globale Marker verhindert, dass jeder Seitenwechsel weitere identische
    // Listener stapelt; die Element-Listener oben werden je DOM-Generation
    // dagegen bewusst neu gebunden.
    if (window.__rtSidebarGlobalInteractionsBound !== true) {
        window.__rtSidebarGlobalInteractionsBound = true;

        document.addEventListener(
            'pointerdown',
            (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (isDesktopHoverSidebar()) {
                    if (target?.closest('.vertical-menu, .topbar-brand')) {
                        clearSidebarCollapseTimer();
                        clearSidebarExpandTimer();
                        setDesktopSidebarExpanded(true);
                    } else {
                        clearSidebarCollapseTimer();
                        clearSidebarExpandTimer();
                        setDesktopSidebarExpanded(false);
                    }

                    return;
                }

                // Auf Touchgeraeten darf ein Backdrop-Tipp erst beim Click
                // schliessen. So bleibt der Ausgangszustand fuer eine echte
                // Rechts-nach-links-Geste bis touchend erhalten.
                if (event.pointerType === 'touch' && document.body.classList.contains('sidebar-enable')) {
                    return;
                }

                if (!target || !target.closest('.vertical-menu, .vertical-menu-btn')) {
                    setMobileSidebarOpen(false);
                }
            },
            true
        );

        document.addEventListener(
            'click',
            (event) => {
                if (isDesktopHoverSidebar() || !document.body.classList.contains('sidebar-enable')) {
                    return;
                }

                const target = event.target instanceof Element ? event.target : null;

                if (!target || !target.closest('.vertical-menu, .vertical-menu-btn')) {
                    setMobileSidebarOpen(false);
                }
            },
            true
        );

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            clearSidebarCollapseTimer();
            clearSidebarExpandTimer();
            setDesktopSidebarExpanded(false);
            setMobileSidebarOpen(false);
        });

        window.addEventListener('resize', syncSidebarInteractionMode);
    }

    syncSidebarInteractionMode();
}

function initAdminLayout() {
    syncSidebarInteractionMode();
    initLeftMenuCollapse();
    initMobileSidebarSwipe();
    initSidebarInteractions();
    initActiveMenu();
    initMenuItemScroll();
    queueFeatherInit();
}

let sidebarLayoutFrame = null;

function queueAdminLayoutInit() {
    if (sidebarLayoutFrame !== null) {
        window.cancelAnimationFrame(sidebarLayoutFrame);
    }

    sidebarLayoutFrame = window.requestAnimationFrame(() => {
        sidebarLayoutFrame = null;
        initAdminLayout();
    });
}

function installAdminLayout() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', queueAdminLayoutInit, { once: true });
    } else {
        queueAdminLayoutInit();
    }

    // Livewire 3 ersetzt bei wire:navigate den Body; die Sidebar selbst wird
    // dabei via @persist weiterverwendet. Der Store stellt den
    // Breitenzustand synchron am neuen Body wieder her; danach aktualisiert der
    // Frame nur noch aktive Route, globale Shell-Interaktionen und Scrollposition.
    document.addEventListener('livewire:navigate', captureDesktopSidebarState);
    document.addEventListener('livewire:navigating', captureDesktopSidebarState);
    document.addEventListener('livewire:navigated', restoreDesktopSidebarState);
    document.addEventListener('livewire:navigated', queueAdminLayoutInit);
    // Lokale Workspace-Tabs ersetzen nur die Query und morphen ihren Inhalt;
    // dafuer ist kein neuer Seiten-/Sidebar-Lifecycle erforderlich.
    document.addEventListener('rt-workspace-url-updated', () => initActiveMenu());
}

export {
    sidebarNavigation,
    clearSidebarCollapseTimer,
    clearSidebarExpandTimer,
    setDesktopSidebarExpanded,
    setMobileSidebarOpen,
    initActiveMenu,
    installAdminLayout,
};
