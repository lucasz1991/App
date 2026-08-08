import { createMailBlocks, mailCanvasStyles } from './mail-builder-blocks';

/**
 * E-Mail-Modus des LMZ Page Builders (Vendor 2.4.5).
 *
 * Bewusst OHNE eigenen Startvorgang: dieses Modul registriert nichts an
 * document und haengt an keinem Livewire-Ereignis. Es stellt nur
 * createMailBuilder() bereit; wer den Editor einhaengt, entscheidet die
 * aufrufende Stelle.
 *
 * Der Unterschied zum Marketing-Studio (resources/js/marketing-studio.js) ist
 * nicht kosmetisch: dort wird eine Bildflaeche mit fester Kantenlaenge
 * gestaltet, hier ein Dokument, das durch Outlook, Gmail und Apple Mail muss.
 * Deshalb faellt hier alles weg, was eine Mail nicht ueberlebt — Motion,
 * Geraetebreiten, Flex- und Grid-Eigenschaften — und der Style-Manager wird
 * auf die Eigenschaften beschnitten, die im Bestand der RailTime-Vorlagen
 * tatsaechlich vorkommen.
 */

const MAIL_CANVAS_STYLE_ID = 'rt-mail-canvas-style';
const MAIL_CANVAS_BODY_CLASS = 'rt-mail-canvas';

/** Schalter, die im Nur-Lese-Modus verschwinden muessen. */
const WRITE_CONTROL_SELECTORS = [
    '[data-lmz-action="save"]',
    '[data-lmz-action="undo"]',
    '[data-lmz-action="redo"]',
    '[data-lmz-action="assets"]',
    '[data-lmz-action="upload"]',
    '[data-lmz-upload-input]',
];

/** Bewegung hat in einer E-Mail nichts verloren — die Schalter auch nicht. */
const MOTION_CONTROL_SELECTORS = [
    '[data-lmz-action="motion-preview"]',
    '[data-lmz-action="motion-pause"]',
    '[data-lmz-action="motion-restart"]',
];

/**
 * Style-Manager des E-Mail-Modus.
 *
 * Enthaelt ausschliesslich Eigenschaften, die in den funktionierenden
 * RailTime-Vorlagen vorkommen und die ein Mailclient zuverlaessig umsetzt.
 * Nicht dabei und auch nicht nachtraeglich ergaenzen:
 *   - float/clear, flex-*, grid-*        zerlegen jedes Tabellenlayout
 *   - position/z-index                   in der Signatur noetig, dort aber
 *                                        serverseitig gesetzt; im Editor
 *                                        waere es nur eine Fehlerquelle
 *   - transform/transition/animation     Outlook kennt nichts davon
 *   - box-shadow/text-shadow             faellt in Outlook ersatzlos weg
 *
 * Zur Bauform: ein Sektor liest ENTWEDER buildProps ODER properties, nie
 * beides (grapesjs.js, Sector-Konstruktor). 'display' braucht eine eigene
 * Werteliste, weil die eingebaute Fassung flex anbietet — deshalb steht der
 * Fluss-Sektor auf properties mit { extend: 'display', options: [...] }.
 */
export const MAIL_STYLE_SECTORS = Object.freeze([
    Object.freeze({
        id: 'rt-mail-typography',
        name: 'Schrift',
        open: true,
        buildProps: [
            'font-family',
            'font-size',
            'font-weight',
            'line-height',
            'letter-spacing',
            'word-spacing',
            'color',
            'text-align',
            'text-decoration',
            'text-transform',
            'white-space',
        ],
    }),
    Object.freeze({
        id: 'rt-mail-spacing',
        name: 'Abstand & Größe',
        open: true,
        buildProps: [
            'padding',
            'padding-top',
            'padding-right',
            'padding-bottom',
            'padding-left',
            'margin',
            'margin-top',
            'margin-right',
            'margin-bottom',
            'margin-left',
            'width',
            'max-width',
            'height',
            'max-height',
            'box-sizing',
        ],
    }),
    Object.freeze({
        id: 'rt-mail-surface',
        name: 'Fläche',
        open: false,
        // 'background' als reine Farbe statt der eingebauten Ebenen-Fassung:
        // die Allowlist des Sanitizers kennt kein background-color, und
        // Hintergrundbilder faellt Outlook ohnehin weg. Der Bestand setzt
        // ebenfalls durchgaengig 'background:<Farbe>'.
        properties: [
            { property: 'background', name: 'Hintergrund', type: 'color' },
            { extend: 'opacity' },
        ],
    }),
    Object.freeze({
        id: 'rt-mail-border',
        name: 'Rahmen',
        open: false,
        buildProps: [
            'border',
            'border-top',
            'border-right',
            'border-bottom',
            'border-left',
            'border-radius',
        ],
    }),
    Object.freeze({
        id: 'rt-mail-flow',
        name: 'Anzeige',
        open: false,
        properties: [
            {
                extend: 'display',
                options: [
                    { id: 'block', label: 'Block' },
                    { id: 'inline-block', label: 'Inline-Block' },
                    { id: 'inline', label: 'Inline' },
                    { id: 'table', label: 'Tabelle' },
                    { id: 'table-row', label: 'Tabellenzeile' },
                    { id: 'table-cell', label: 'Tabellenzelle' },
                    { id: 'none', label: 'Ausgeblendet' },
                ],
            },
            'vertical-align',
            'border-collapse',
            'direction',
            'overflow',
        ],
    }),
]);

/**
 * GrapesJS-Optionen, die zuletzt ueber die fertige Konfiguration gelegt
 * werden und deshalb gewinnen.
 *
 * avoidInlineStyle: false ist der wichtigste Eintrag. Die Vorgabe von
 * GrapesJS ist true — dann landen alle im Editor gesetzten Stile als
 * '#id{...}'-Regeln in editor.getCss() und NICHT im style-Attribut. Fuer
 * eine E-Mail ist das genau falsch herum: viele Clients verwerfen den
 * style-Block, und die Nachricht kaeme unformatiert an.
 */
export const MAIL_GJS_OPTIONS = Object.freeze({
    // Ersetzt die vier Standardgeraete vollstaendig: ohne Geraetewechsel
    // entstehen keine Media Queries. Die Umbrueche der Mail kommen aus
    // resources/views/emails/parts/responsive-css.blade.php.
    deviceManager: { devices: [] },
    canvas: { styles: [], scripts: [] },
    assetManager: { upload: false },
    styleManager: { sectors: MAIL_STYLE_SECTORS },
    avoidInlineStyle: false,
    keepUnusedStyles: false,
    richTextEditor: { actions: ['bold', 'italic', 'underline', 'link'] },
});

export const MAIL_AUTOSAVE = Object.freeze({
    enabled: true,
    intervalMs: 10000,
    changesBeforeSave: 30,
});

/**
 * Leerzustand des Builders.
 *
 * storage.onLoad darf niemals null, undefined oder ein Array liefern: der
 * Builder normalisiert das zu seinem Standardprojekt und laedt die
 * Joomla-Demoseite "Neue Seite" samt Tailwind-Klassen in die Mail.
 */
export function normalizeMailProject(project) {
    if (!project || typeof project !== 'object' || Array.isArray(project)) {
        return {};
    }

    return project;
}

/**
 * Projektdaten eines Entwurfs fuer storage.onLoad.
 *
 * Das CSS liegt in einer eigenen Spalte, weil es getrennt geprueft wird —
 * fuer den Editor muss es zurueck in die Stilregeln des Projekts.
 *
 * @param {{builderData?: object, css?: string}} draft
 * @param {(css: string) => Array} parseCss
 */
export function projectForMailDocument(draft, parseCss = () => []) {
    const project = normalizeMailProject(structuredClone(draft?.builderData || {}));

    if (draft?.css && (!Array.isArray(project.styles) || project.styles.length === 0)) {
        project.styles = parseCss(draft.css) || project.styles || [];
    }

    return project;
}

/**
 * Schalter ENTFERNEN statt sperren.
 *
 * Die Shell des Builders setzt undo/redo bei jedem Editorereignis neu aus
 * dem UndoManager (syncShellState). Ein gesetztes disabled waere beim
 * naechsten Klick in die Leinwand wieder weg.
 */
export function removeBuilderControls(root, selectors) {
    const controls = root?.querySelectorAll?.(selectors.join(', ')) || [];

    controls.forEach((control) => control.remove());

    return controls.length;
}

/**
 * Leinwand-Stylesheet in das Editor-iframe haengen.
 *
 * Muss nach jedem canvas:frame:load erneut laufen — der Builder baut das
 * iframe bei einem Neuladen des Projekts komplett neu auf.
 */
export function applyMailCanvasStyles(editor, css) {
    const canvasDocument = editor?.Canvas?.getDocument?.();

    if (!canvasDocument?.head) {
        return false;
    }

    let style = canvasDocument.getElementById(MAIL_CANVAS_STYLE_ID);

    if (!style) {
        style = canvasDocument.createElement('style');
        style.id = MAIL_CANVAS_STYLE_ID;
        canvasDocument.head.appendChild(style);
    }

    if (style.textContent !== css) {
        style.textContent = css;
    }

    return true;
}

/**
 * Startet den Builder im E-Mail-Modus.
 *
 * @param {object}   options
 * @param {object}   options.runtime            window.LMZBuilder
 * @param {Element|string} options.root         Wurzelknoten; sein Inhalt wird geleert
 * @param {string}   options.projectId          nur Durchreichung an onLoad/onSave
 * @param {object}   options.vendor             { grapesJs, grapesCss }
 * @param {object}   options.storage            { onLoad, onSave } — Pflicht
 * @param {Array}    options.assets             Bildliste fuer den Asset-Manager
 * @param {object}   options.blockOptions       Durchreichung an createMailBlocks
 * @param {'light'|'dark'} options.theme        Vorschaufarben der Leinwand
 * @param {boolean}  options.readOnly           kein Speichern, kein Hochladen
 * @param {object}   options.autosave           Abweichende Autosave-Einstellung
 * @param {string[]} options.canvasBodyClasses  Zusaetzliche Klassen am iframe-body
 */
export async function createMailBuilder({
    runtime,
    root,
    projectId = 'mail',
    vendor = {},
    storage = {},
    assets = [],
    blockOptions = {},
    theme = 'light',
    readOnly = false,
    autosave = MAIL_AUTOSAVE,
    canvasBodyClasses = [],
} = {}) {
    if (typeof runtime?.create !== 'function') {
        throw new TypeError('Der E-Mail-Editor benoetigt die LMZBuilder-Laufzeit.');
    }

    if (!root) {
        throw new TypeError('Der E-Mail-Editor benoetigt einen Wurzelknoten.');
    }

    if (typeof storage.onLoad !== 'function' || typeof storage.onSave !== 'function') {
        throw new TypeError('Der E-Mail-Editor benoetigt storage.onLoad und storage.onSave.');
    }

    const canvasCss = mailCanvasStyles(theme);

    const instance = await runtime.create({
        root,
        projectId,
        gjsScript: vendor.grapesJs,
        gjsStyle: vendor.grapesCss,

        // Ohne die ausdrueckliche Leerung laedt der Builder das Website-
        // Tailwind in die Leinwand und haengt es alle zwei Sekunden erneut
        // ein. In einer Mail gibt es kein Tailwind — der Editor wuerde etwas
        // anderes zeigen als der Empfaenger spaeter sieht.
        canvasStyles: [],
        canvasScripts: [],
        canvasBaseUrl: window.location.origin + '/',
        canvasBodyClasses: [MAIL_CANVAS_BODY_CLASS, ...canvasBodyClasses],
        canvasLanguage: 'de',

        motion: { enabled: false, preview: false },
        autosave,

        blocks: {
            // Sonst kommen die vier Joomla-Standardbloecke dazu — mit
            // Tailwind-Klassen und einem Bildpfad der Website.
            addDefault: false,
            custom: createMailBlocks(blockOptions),
        },

        storage: {
            onLoad: async (context) => normalizeMailProject(await storage.onLoad(context)),
            onSave: (context) => storage.onSave(context),
        },

        assets: {
            onLoad: async () => (Array.isArray(assets) ? assets : []),
            // onUpload bewusst weggelassen: Bilder kommen aus der
            // Dateiverwaltung, nicht aus dem Editor.
        },

        useStudioWebDefaults: false,
        // Lieber ein harter Fehler als stillschweigend die Joomla-Demoseite.
        allowFallbackProject: false,

        gjsOptions: MAIL_GJS_OPTIONS,
    });

    const editor = instance.editor;
    const onFrameLoad = () => applyMailCanvasStyles(editor, canvasCss);

    applyMailCanvasStyles(editor, canvasCss);
    editor.on?.('canvas:frame:load', onFrameLoad);

    const rootElement = typeof root === 'string' ? document.querySelector(root) : root;

    removeBuilderControls(rootElement, MOTION_CONTROL_SELECTORS);

    // setActionLocked ist KEIN Nur-Lese-Modus: es unterbindet nur Autosave
    // und Strg+S. Die Leinwand bleibt bearbeitbar und ein Klick auf
    // "Speichern" ginge weiterhin durch — deshalb zusaetzlich die Schalter
    // entfernen und in save() selbst abfangen.
    instance.setActionLocked(readOnly);

    if (readOnly) {
        removeBuilderControls(rootElement, WRITE_CONTROL_SELECTORS);
    }

    return {
        instance,
        editor,
        readOnly,

        /**
         * Speichern.
         *
         * Der Builder faengt Fehler aus onSave selbst ab und liefert dann
         * false — ohne Auswertung des Rueckgabewerts haelt man einen
         * abgelehnten Speichervorgang faelschlich fuer erfolgreich.
         *
         * @returns {Promise<boolean>}
         */
        async save(reason = 'manual') {
            if (readOnly) {
                return false;
            }

            return instance.save(reason);
        },

        hasUnsavedChanges: () => Boolean(instance.hasUnsavedChanges?.()),

        /** Leinwand-Stile erneut setzen, etwa nach einem Themenwechsel. */
        refreshCanvasStyles(nextTheme = theme) {
            return applyMailCanvasStyles(editor, mailCanvasStyles(nextTheme));
        },

        destroy() {
            editor.off?.('canvas:frame:load', onFrameLoad);
            instance.destroy?.();
        },
    };
}
