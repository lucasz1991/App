// Individuelles Dashboard: Bento-Raster mit zwei fest gestuften Achsen
// (Breite sm/lg, Hoehe 1/2 Zeilen auf --widget-row-h - siehe
// operations-workspace.css; keine gemessene Hoehe mehr, jede einzeilige
// Karte ist dadurch ueberall exakt gleich hoch). Plus Umsortieren per
// ganzer Karte, das Nachbarn schon waehrend des Ziehens live nachruecken
// laesst - wie beim Umsortieren von Apps auf dem iPhone. Delegierte
// Listener auf dem Raster selbst statt pro Karte - ueberleben jedes
// Livewire-Morph (wire:poll, Groessen-/Sichtbarkeits-Aenderung), weil jede
// Karte ihr eigenes wire:key traegt und hier keine Karten-Attribute mehr
// aus JS heraus dauerhaft berechnet werden. Pfeil-Tasten und die Buttons in
// widget-shell.blade.php rufen denselben Server-Endpunkt ohne JS auf -
// Ziehen ist hier bewusst die Zugabe, nicht der einzige Weg.
export const dashboardWidgetGrid = () => ({
    dragKey: null,
    lastReorderAt: 0,
    resizeCard: null,
    resizeAxis: null,
    resizeStart: 0,
    resizeStartValue: null,
    pressTimer: null,
    pressStart: null,

    init() {
        this.bindReorder();
        this.bindResize();
        this.bindLongPress();
        this.playEntrance();
    },

    // Start-Animation beim ersten Laden: Zahlen zaehlen sichtbar von 0 auf
    // ihren echten Wert hoch, leicht nach der Kartenreihenfolge gestaffelt
    // (passend zum bestehenden Karten-Fade-Up aus data-anim-stagger/gsap.js -
    // dort werden die Karten selbst animiert, hier zusaetzlich ihr
    // Kennzahlenwert). Laeuft nur beim ersten Mount der Komponente, nicht
    // bei jedem wire:poll-Refresh, weil init() durch Livewires gekeyten
    // Morph nicht erneut aufgerufen wird.
    playEntrance() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const items = Array.from(this.$root.querySelectorAll('[data-widget-item]'));

        items.forEach((card, index) => {
            const targets = card.querySelectorAll('.widget-primary-val, .ops-kpi-val, .widget-donut-val, .widget-ring-val, .wv-kpi b, .wv-ampel-col b, .wv-duo-tile b');

            targets.forEach((el) => {
                const text = el.textContent.trim();
                // Dezimalwerte wie "12,5 h" wuerden nur ihren Ganzzahlteil
                // hochzaehlen ("0,5 h" ... "12,5 h") - die bleiben stehen.
                if (/\d[.,]\d/.test(text)) return;
                const match = text.match(/\d+/);
                if (!match) return;

                const end = Number.parseInt(match[0], 10);
                if (! Number.isFinite(end) || end === 0) return;

                const prefix = text.slice(0, match.index);
                const suffix = text.slice(match.index + match[0].length);
                const duration = 640;
                const initialDelay = Math.min(index * 55, 440);
                const startAt = performance.now() + initialDelay;

                el.textContent = prefix + '0' + suffix;

                // rAF kann in Hintergrund-/verdeckten Tabs stillstehen (siehe
                // bindLongPress-Kommentar zu Pointer-Events an anderer
                // Stelle) - ein setTimeout-Watchdog schliesst deshalb IMMER
                // final auf den echten Wert ab, unabhaengig davon, ob die
                // rAF-Schleife ueberhaupt lief.
                let finished = false;
                const finish = () => {
                    if (finished) return;
                    finished = true;
                    el.textContent = prefix + end + suffix;
                };

                const tick = (now) => {
                    if (finished) return;

                    const elapsed = now - startAt;
                    if (elapsed < 0) {
                        requestAnimationFrame(tick);

                        return;
                    }

                    const progress = Math.min(1, elapsed / duration);
                    const eased = 1 - (1 - progress) ** 3;
                    el.textContent = prefix + Math.round(end * eased) + suffix;

                    if (progress < 1) {
                        requestAnimationFrame(tick);
                    } else {
                        finish();
                    }
                };

                requestAnimationFrame(tick);
                setTimeout(finish, initialDelay + duration + 200);
            });
        });
    },

    bindReorder() {
        this.$root.addEventListener('dragstart', (event) => {
            // Formfelder, Buttons, Links und die Rand-Resize-Griffe loesen ihre eigene
            // Geste aus statt eines Kartenzugs.
            if (event.target.closest('.widget-resize-handle, button, a, input, select, textarea, label')) {
                event.preventDefault();

                return;
            }

            const card = event.target.closest('[data-widget-item][draggable="true"]');

            if (!card) {
                event.preventDefault();

                return;
            }

            this.dragKey = card.dataset.widgetKey;
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';

            try {
                event.dataTransfer.setDragImage(card, event.offsetX, event.offsetY);
            } catch {
                // Manche Browser lehnen setDragImage in bestimmten Kontexten ab - der Browser-Standardghost reicht dann.
            }
        });

        this.$root.addEventListener('dragover', (event) => {
            if (!this.dragKey) return;

            // Muss auf jedem dragover laufen, sonst bleibt Drop verboten.
            event.preventDefault();

            const now = performance.now();
            if (now - this.lastReorderAt < 90) return;

            const target = event.target.closest('[data-widget-item]');

            if (!target || target.dataset.widgetKey === this.dragKey) return;

            const dragged = this.$root.querySelector(`[data-widget-item][data-widget-key="${this.dragKey}"]`);

            if (!dragged) return;

            this.lastReorderAt = now;
            this.reorderLive(dragged, target, event);
        });

        const finish = () => {
            if (!this.dragKey) return;

            this.dragKey = null;
            this.$root.querySelectorAll('[data-widget-item]').forEach((el) => el.classList.remove('is-dragging'));
            this.$wire.reorder(Array.from(this.$root.querySelectorAll('[data-widget-item]')).map((el) => el.dataset.widgetKey));
        };

        this.$root.addEventListener('drop', (event) => {
            event.preventDefault();
            finish();
        });
        this.$root.addEventListener('dragend', finish);
    },

    // FLIP: erst die aktuellen Positionen aller Karten messen, dann die
    // gezogene Karte an ihre neue Stelle im DOM verschieben, dann jede
    // verschobene Nachbarkarte von ihrer alten Position weg zur neuen
    // zurueckanimieren - so gleiten die anderen Kacheln schon waehrend des
    // Ziehens sichtbar auf, statt erst beim Loslassen zu springen.
    reorderLive(dragged, target, event) {
        const items = Array.from(this.$root.querySelectorAll('[data-widget-item]'));
        const firstRects = new Map(items.map((el) => [el, el.getBoundingClientRect()]));

        const rect = target.getBoundingClientRect();
        const before = (event.clientX - rect.left) < rect.width / 2;
        target.parentNode.insertBefore(dragged, before ? target : target.nextSibling);

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        items.forEach((el) => {
            if (el === dragged) return;

            const first = firstRects.get(el);
            const last = el.getBoundingClientRect();
            const dx = first.left - last.left;
            const dy = first.top - last.top;

            if (!dx && !dy) return;

            el.style.transition = 'none';
            el.style.transform = `translate(${dx}px, ${dy}px)`;
            requestAnimationFrame(() => {
                el.style.transition = 'transform 240ms cubic-bezier(.2,.8,.2,1)';
                el.style.transform = '';
            });
        });
    },

    // FLIP fuer Groessenaenderungen: Vorher/Nachher-Rechtecke ALLER Karten
    // messen (dense-Packing kann beim Aendern einer Karte auch Nachbarn
    // verschieben), dann die Differenz per transform wegtranslieren/-skalieren
    // und im naechsten Frame auf 0 zuruecktransitionieren - dieselbe Technik
    // wie reorderLive(), nur zusaetzlich mit Skalierung fuer die Karte, die
    // selbst die Groesse aendert (Breite/Hoehe sind diskret sm/lg bzw.
    // 1/2 Zeilen, daher genuegt eine Animation pro tatsaechlichem Wechsel).
    flipGrid(mutate) {
        const items = Array.from(this.$root.querySelectorAll('[data-widget-item]'));
        const firstRects = new Map(items.map((el) => [el, el.getBoundingClientRect()]));

        mutate();

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        items.forEach((el) => {
            const first = firstRects.get(el);
            const last = el.getBoundingClientRect();
            const dx = first.left - last.left;
            const dy = first.top - last.top;
            const sx = first.width / last.width;
            const sy = first.height / last.height;

            if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5 && Math.abs(sx - 1) < 0.01 && Math.abs(sy - 1) < 0.01) return;

            el.style.transformOrigin = 'top left';
            el.style.transition = 'none';
            el.style.transform = `translate(${dx}px, ${dy}px) scale(${sx}, ${sy})`;
            requestAnimationFrame(() => {
                el.style.transition = 'transform 320ms cubic-bezier(.32,.72,0,1)';
                el.style.transform = '';
            });
        });
    },

    // Rand-Griffe: eigene Pointer-Events-Gesten (nicht natives Drag) fuer
    // Breite (rechts, waagerecht) und Hoehe (unten, senkrecht) - live als
    // Vorschau waehrend des Ziehens, der eigentliche Wechsel (mit mehr/
    // weniger Detail) kommt erst mit dem Server-Aufruf beim Loslassen.
    bindResize() {
        const THRESHOLD = 56;

        const move = (event) => {
            if (!this.resizeCard) return;
            const card = this.resizeCard;

            if (this.resizeAxis === 'width') {
                const delta = event.clientX - this.resizeStart;
                const size = this.resizeStartValue === 'lg'
                    ? (delta < -THRESHOLD ? 'sm' : 'lg')
                    : (delta > THRESHOLD ? 'lg' : 'sm');

                if (card.dataset.widgetSize !== size) {
                    this.flipGrid(() => { card.dataset.widgetSize = size; });
                }

                return;
            }

            const delta = event.clientY - this.resizeStart;
            const rows = this.resizeStartValue === 2
                ? (delta < -THRESHOLD ? 1 : 2)
                : (delta > THRESHOLD ? 2 : 1);

            if (Number(card.dataset.widgetRows) !== rows) {
                this.flipGrid(() => { card.dataset.widgetRows = String(rows); });
            }
        };

        const finish = () => {
            if (!this.resizeCard) return;

            const key = this.resizeCard.dataset.widgetKey;
            this.resizeCard.classList.remove('is-resizing-x', 'is-resizing-y');
            document.body.style.cursor = '';

            if (this.resizeAxis === 'width') {
                const size = this.resizeCard.dataset.widgetSize;
                if (size !== this.resizeStartValue) this.$wire.setWidgetSize(key, size);
            } else {
                const rows = Number(this.resizeCard.dataset.widgetRows);
                if (rows !== this.resizeStartValue) this.$wire.setWidgetRows(key, rows);
            }

            this.resizeCard = null;
            this.resizeAxis = null;
        };

        this.$root.addEventListener('pointerdown', (event) => {
            const handle = event.target.closest('[data-widget-resize-handle]');
            const card = handle?.closest('[data-widget-item]');

            if (!handle || !card) return;

            event.preventDefault();
            this.resizeCard = card;
            this.resizeAxis = handle.dataset.widgetResizeHandle;

            if (this.resizeAxis === 'width') {
                this.resizeStart = event.clientX;
                this.resizeStartValue = card.dataset.widgetSize;
                card.classList.add('is-resizing-x');
                document.body.style.cursor = 'ew-resize';
            } else {
                this.resizeStart = event.clientY;
                this.resizeStartValue = Number(card.dataset.widgetRows);
                card.classList.add('is-resizing-y');
                document.body.style.cursor = 'ns-resize';
            }

            handle.setPointerCapture?.(event.pointerId);
        });
        this.$root.addEventListener('pointermove', move);
        this.$root.addEventListener('pointerup', finish);
        this.$root.addEventListener('pointercancel', finish);

        // Tastatur-Gegenstueck zu den Rand-Griffen: Pfeiltasten, waehrend
        // der jeweilige Griff fokussiert ist (links/rechts = Breite,
        // hoch/runter = Hoehe).
        this.$root.addEventListener('keydown', (event) => {
            const handle = event.target.closest('[data-widget-resize-handle]');
            const card = handle?.closest('[data-widget-item]');

            if (!handle || !card) return;

            const axis = handle.dataset.widgetResizeHandle;

            if (axis === 'width' && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
                event.preventDefault();
                const size = event.key === 'ArrowRight' ? 'lg' : 'sm';

                if (card.dataset.widgetSize !== size) {
                    this.flipGrid(() => { card.dataset.widgetSize = size; });
                    this.$wire.setWidgetSize(card.dataset.widgetKey, size);
                }
            } else if (axis === 'height' && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                event.preventDefault();
                const rows = event.key === 'ArrowDown' ? 2 : 1;

                if (Number(card.dataset.widgetRows) !== rows) {
                    this.flipGrid(() => { card.dataset.widgetRows = String(rows); });
                    this.$wire.setWidgetRows(card.dataset.widgetKey, rows);
                }
            }
        });
    },

    // Anpassen-Modus per Gedruecktkhalten betreten (ersetzt den frueheren
    // Kopf-Button, siehe widget-grid.blade.php) - nur zum EINTRETEN: im
    // Anpassen-Modus sind Karten nativ draggable, ein zweites
    // Gedruecktkhalten wuerde dort mit dem Drag-Start kollidieren.
    // Verlassen passiert per Klick ausserhalb einer Karte oder Escape
    // (@click/@keydown.escape auf dem Wurzelelement in widget-grid.blade.php).
    bindLongPress() {
        const HOLD_MS = 550;
        const MOVE_TOLERANCE = 10;

        const cancel = () => {
            if (this.pressTimer) {
                clearTimeout(this.pressTimer);
                this.pressTimer = null;
            }
            this.pressStart = null;
        };

        this.$root.addEventListener('pointerdown', (event) => {
            if (this.$root.dataset.editing === 'true') return;
            if (event.target.closest('.widget-resize-handle, button, a, input, select, textarea, label')) return;

            const card = event.target.closest('[data-widget-item]');
            if (!card) return;

            this.pressStart = { x: event.clientX, y: event.clientY };
            this.pressTimer = setTimeout(() => {
                this.pressTimer = null;
                navigator.vibrate?.(12);
                this.$wire.toggleEditing();
            }, HOLD_MS);
        });

        this.$root.addEventListener('pointermove', (event) => {
            if (!this.pressTimer || !this.pressStart) return;

            if (Math.hypot(event.clientX - this.pressStart.x, event.clientY - this.pressStart.y) > MOVE_TOLERANCE) {
                cancel();
            }
        });
        this.$root.addEventListener('pointerup', cancel);
        this.$root.addEventListener('pointercancel', cancel);

        // Auf Touch loest langes Halten oft zusaetzlich das native
        // Kontextmenue aus - das wuerde die Geste unterbrechen.
        this.$root.addEventListener('contextmenu', (event) => {
            if (event.target.closest('input, select, textarea, label')) return;
            if (event.target.closest('[data-widget-item]')) event.preventDefault();
        });
    },
});
