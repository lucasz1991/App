/**
 * Blocksatz und Leinwand-Stile des E-Mail-Modus im LMZ Page Builder.
 *
 * Getrennt von mail-builder.js, weil hier ausschliesslich Markup und Farben
 * stehen — der Startvorgang des Builders soll davon unberuehrt bleiben.
 *
 * DREI REGELN, die jedes Markup hier einhaelt:
 *
 *  1. Nur Tabellenlayout. Kein float, kein flex, kein grid. Outlook rendert
 *     mit Word und kennt nichts davon; der Bestand der RailTime-Vorlagen
 *     (resources/mail-templates/email-master.html) kommt mit 20 Tags aus.
 *
 *  2. Farben stehen als Palettenplatzhalter im Markup ({{SURFACE_BG}},
 *     {{TEXT_PRIMARY}} …), nicht als Hexwerte. So bleibt hell und dunkel EIN
 *     Dokument: EmailTemplateBuilder::emailThemeValues() setzt beim Rendern
 *     die passende Fassung ein.
 *
 *  3. Klassennamen kommen ausschliesslich aus der Liste, auf die
 *     resources/views/emails/parts/responsive-css.blade.php reagiert
 *     (rt-pad, rt-title, rt-stack, rt-card-cell …). Ein selbst erfundener
 *     Klassenname schaltet das mobile Layout still ab.
 */

/** Bloecke fuer Aufbau und Inhalt der Nachricht. */
export const MAIL_STRUCTURE_CATEGORY = 'RailTime E-Mail';

/** Bloecke, die einen Personenplatzhalter setzen. */
export const MAIL_PERSON_CATEGORY = 'RailTime Personendaten';

/** Erkennungsmerkmal aller Bloecke dieses Moduls (steuert die Leinwand-Stile). */
export const MAIL_BLOCK_ATTRIBUTE = 'data-rt-mail-block';

/**
 * Vorschaupalette der Leinwand.
 *
 * Spiegelt EmailTemplateBuilder::emailThemeValues(). Notwendig, weil
 * '{{SURFACE_BG}}' im style-Attribut fuer den Browser ein ungueltiger Wert
 * ist: er verwirft die Deklaration, und ohne Ersatz saehe der Editor
 * farblos aus. Die Regeln stehen NUR im Leinwand-Stylesheet und niemals im
 * gespeicherten HTML.
 */
const MAIL_CANVAS_PALETTE = Object.freeze({
    light: Object.freeze({
        PAGE_BG: '#e7eaed',
        SURFACE_BG: '#f4f2ed',
        CARD_BG: '#ffffff',
        SOFT_BG: '#eef1f3',
        TEXT_PRIMARY: '#111820',
        TEXT_SECONDARY: '#3f4852',
        TEXT_MUTED: '#89939e',
        BORDER: '#dfe3e6',
    }),
    dark: Object.freeze({
        PAGE_BG: '#070a0e',
        SURFACE_BG: '#111820',
        CARD_BG: '#18212b',
        SOFT_BG: '#202a35',
        TEXT_PRIMARY: '#f7f8fa',
        TEXT_SECONDARY: '#c3ccd6',
        TEXT_MUTED: '#8f9baa',
        BORDER: '#303944',
    }),
});

/**
 * Fuellbild fuer den Bildblock: eine 600x250 grosse Flaeche in #dbe2ea.
 *
 * Ein 1x1-Pixel waere kleiner, wuerde durch width:100%;height:auto aber
 * quadratisch aufgezogen und den Editor mit einem 600 px hohen Klotz
 * fuellen. Das Seitenverhaeltnis muss also im Bild selbst stecken.
 */
export const MAIL_PLACEHOLDER_IMAGE =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAlgAAAD6CAIAAADyT65HAAADDklEQVR42u3VMQ0AAAgEsfdvkY0RFWhg'
    + 'pkkV3HKpHgB4KxIAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQA'
    + 'YIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQAYIQA'
    + 'YIQAYIQAYIQAGKEKABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABgh'
    + 'ABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABghABgh'
    + 'ABghAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYI'
    + 'AEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYIAEYI'
    + 'AEYIgBGqAIARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoAR'
    + 'AoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoARAoAR'
    + 'AoARAoARAmCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEA'
    + 'GCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAGCEAHCxt812n7Tlk'
    + 'FEAAAAASUVORK5CYII=';

/** Platzhalter, den EmailTemplateBuilder durch MailSignature::render() ersetzt. */
export const SIGNATURE_PLACEHOLDER = '{{SIGNATURE_BLOCK}}';

/**
 * Personendaten mit ihren Platzhaltern und den Beispielwerten fuer den Editor.
 *
 * Die Platzhalter sind die des Renderpfads (EmailTemplateBuilder::profileValues
 * beziehungsweise MailSignature::companyAsSender): der sichtbare Wert und die
 * Wahl-/Verweisform sind getrennte Schluessel, weil telHref() aus "04171 546803"
 * ein "+494171546803" macht.
 */
export const MAIL_PERSON_FIELDS = Object.freeze([
    Object.freeze({
        placeholder: 'VORNAME_NACHNAME',
        label: 'Name der Person',
        sample: 'Max Mustermann',
        tone: 'primary',
        style: 'color:{{TEXT_PRIMARY}};font-family:Arial,Helvetica,sans-serif;font-size:25px;line-height:29px;font-weight:bold;letter-spacing:-.5px;',
    }),
    Object.freeze({
        placeholder: 'POSITION',
        label: 'Funktion',
        sample: 'Disposition',
        tone: 'muted',
        style: "color:{{TEXT_MUTED}};font-family:Consolas,'Courier New',monospace;font-size:10px;line-height:16px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;",
    }),
    Object.freeze({
        placeholder: 'DURCHWAHL',
        label: 'Durchwahl',
        sample: '+49 201 123456-78',
        href: 'tel:{{DURCHWAHL_TEL}}',
        tone: 'primary',
        style: 'color:{{TEXT_PRIMARY}};font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;',
    }),
    Object.freeze({
        placeholder: 'MOBIL',
        label: 'Mobilnummer',
        sample: '+49 170 1234567',
        href: 'tel:{{MOBIL_TEL}}',
        tone: 'primary',
        style: 'color:{{TEXT_PRIMARY}};font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;',
    }),
    Object.freeze({
        placeholder: 'E_MAIL',
        label: 'E-Mail-Adresse',
        sample: 'max.mustermann@rail-time.de',
        href: 'mailto:{{E_MAIL}}',
        tone: 'primary',
        style: 'color:{{TEXT_PRIMARY}};font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;',
    }),
    Object.freeze({
        placeholder: 'FIRMEN_WEBSITE_LABEL',
        label: 'Website',
        sample: 'rail-time.de',
        href: '{{FIRMEN_WEBSITE_HREF}}',
        tone: 'primary',
        style: 'color:{{TEXT_PRIMARY}};font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;',
    }),
]);

const FONT_STACK = 'Arial,Helvetica,sans-serif';
const ACCENT = '#e4002b';

function htmlEscape(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/**
 * Aeussere Tabelle eines Blocks.
 *
 * role="presentation" haelt Screenreader davon ab, ein Layoutgeruest als
 * Datentabelle vorzulesen; die Nullwerte fuer border/cellspacing/cellpadding
 * sind Pflicht, weil aeltere Clients sonst eigene Stege setzen.
 */
function shell(inner, { attributes = '', style = '' } = {}) {
    return '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0"'
        + (attributes ? ' ' + attributes : '')
        + ` style="width:100%;border-collapse:collapse;${style}">`
        + `<tr>${inner}</tr>`
        + '</table>';
}

/**
 * Der Beispielwert steht im data-Attribut, der Platzhalter im Markup.
 *
 * Das Leinwand-Stylesheet blendet den Traeger [data-rt-mail-token] aus und
 * setzt den Beispielwert ueber ::after wieder ein — dadurch erbt er Schrift
 * und Farbe des umgebenden Elements, ohne dass der Platzhalter das Dokument
 * verlaesst. Gespeichert wird also '{{DURCHWAHL}}', angezeigt
 * '+49 201 123456-78'.
 */
function personValue(field) {
    const token = `<span data-rt-mail-token="${field.placeholder}">{{${field.placeholder}}}</span>`;

    if (!field.href) {
        return `<span data-rt-mail-sample="${htmlEscape(field.sample)}">${token}</span>`;
    }

    return `<a href="${field.href}" data-rt-mail-sample="${htmlEscape(field.sample)}"`
        + ` style="color:inherit;text-decoration:none;">${token}</a>`;
}

function personBlock(field) {
    const cell = `<td class="rt-pad" ${MAIL_BLOCK_ATTRIBUTE}="person"`
        + ` data-rt-mail-field="${field.placeholder}" data-rt-mail-text="${field.tone}"`
        + ` align="left" style="padding:0 46px 6px;text-align:left;${field.style}">`
        + personValue(field)
        + '</td>';

    return {
        id: 'rt-mail-person-' + field.placeholder.toLowerCase().replaceAll('_', '-'),
        definition: {
            label: field.label,
            category: MAIL_PERSON_CATEGORY,
            content: shell(cell),
        },
    };
}

/**
 * Alle Bloecke des E-Mail-Modus.
 *
 * @param {object}  options
 * @param {string}  options.imageSrc  Fuellbild des Bildblocks
 * @param {string}  options.ctaUrl    Ziel der Schaltflaeche
 * @param {string}  options.ctaLabel  Beschriftung der Schaltflaeche
 * @returns {Array<{id: string, definition: object}>}
 */
export function createMailBlocks({
    imageSrc = MAIL_PLACEHOLDER_IMAGE,
    ctaUrl = 'https://www.rail-time.de',
    ctaLabel = 'Jetzt ansehen',
} = {}) {
    const structure = [
        {
            id: 'rt-mail-section',
            definition: {
                label: 'Abschnitt',
                category: MAIL_STRUCTURE_CATEGORY,
                content: shell(
                    `<td class="rt-pad" ${MAIL_BLOCK_ATTRIBUTE}="section" data-rt-mail-surface="surface"`
                    + ' align="left" style="padding:32px 46px;background:{{SURFACE_BG}};text-align:left;">'
                    + `<p data-rt-mail-text="secondary" style="margin:0;color:{{TEXT_SECONDARY}};font-family:${FONT_STACK};font-size:16px;line-height:27px;">`
                    + 'Text des Abschnitts.</p>'
                    + '</td>',
                    {
                        attributes: 'bgcolor="{{SURFACE_BG}}"',
                        style: 'background:{{SURFACE_BG}};',
                    },
                ),
            },
        },
        {
            id: 'rt-mail-two-columns',
            definition: {
                label: 'Zwei Spalten',
                category: MAIL_STRUCTURE_CATEGORY,
                // rt-stack auf der ZEILE ist der Umbruchschalter: die
                // Regel 'tr.rt-stack > td' in responsive-css.blade.php
                // stellt die Zellen ab 860 px untereinander. rt-card-cell
                // auf den Zellen sorgt dafuer, dass der Innenabstand dabei
                // auf beiden Seiten gleich wird statt einseitig zu haengen.
                content: '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0"'
                    + ' style="width:100%;border-collapse:collapse;">'
                    + '<tr class="rt-stack">'
                    + `<td class="rt-card-cell" ${MAIL_BLOCK_ATTRIBUTE}="column" data-rt-mail-text="secondary"`
                    + ` width="50%" valign="top" align="left" style="width:50%;padding:0 14px 0 0;vertical-align:top;text-align:left;color:{{TEXT_SECONDARY}};font-family:${FONT_STACK};font-size:16px;line-height:27px;">`
                    + '<p style="margin:0;">Linke Spalte.</p>'
                    + '</td>'
                    + `<td class="rt-card-cell" ${MAIL_BLOCK_ATTRIBUTE}="column" data-rt-mail-text="secondary"`
                    + ` width="50%" valign="top" align="left" style="width:50%;padding:0 0 0 14px;vertical-align:top;text-align:left;color:{{TEXT_SECONDARY}};font-family:${FONT_STACK};font-size:16px;line-height:27px;">`
                    + '<p style="margin:0;">Rechte Spalte.</p>'
                    + '</td>'
                    + '</tr></table>',
            },
        },
        {
            id: 'rt-mail-heading',
            definition: {
                label: 'Überschrift',
                category: MAIL_STRUCTURE_CATEGORY,
                // rt-title wird von den Umbruchregeln in drei Stufen
                // verkleinert — ohne die Klasse bliebe die Zeile auf dem
                // Telefon bei 34 px stehen.
                content: shell(
                    '<td class="rt-pad" align="left" style="padding:0 46px 18px;text-align:left;">'
                    + `<h1 class="rt-title" ${MAIL_BLOCK_ATTRIBUTE}="heading" data-rt-mail-text="primary"`
                    + ` style="margin:0;color:{{TEXT_PRIMARY}};font-family:${FONT_STACK};font-size:34px;line-height:40px;font-weight:400;letter-spacing:-1.1px;">`
                    + 'Sicher abgestimmt.</h1>'
                    + '</td>',
                ),
            },
        },
        {
            id: 'rt-mail-paragraph',
            definition: {
                label: 'Absatz',
                category: MAIL_STRUCTURE_CATEGORY,
                content: shell(
                    '<td class="rt-pad" align="left" style="padding:0 46px 16px;text-align:left;">'
                    + `<p ${MAIL_BLOCK_ATTRIBUTE}="paragraph" data-rt-mail-text="secondary"`
                    + ` style="margin:0;color:{{TEXT_SECONDARY}};font-family:${FONT_STACK};font-size:16px;line-height:27px;">`
                    + 'Hier steht der Text der Nachricht.</p>'
                    + '</td>',
                ),
            },
        },
        {
            id: 'rt-mail-button',
            definition: {
                label: 'Schaltfläche',
                category: MAIL_STRUCTURE_CATEGORY,
                // Die Flaeche ist eine Tabellenzelle, nicht das a-Element:
                // Outlook ignoriert padding auf Inline-Elementen, der Knopf
                // waere dort nur so gross wie sein Text. Die aeussere
                // Knopftabelle traegt bewusst KEIN align — align="left" auf
                // einer Tabelle laesst sie floaten (vendor/mail/html/button.blade.php).
                content: shell(
                    '<td class="rt-pad" align="left" style="padding:8px 46px 26px;text-align:left;">'
                    + `<table role="presentation" border="0" cellspacing="0" cellpadding="0" ${MAIL_BLOCK_ATTRIBUTE}="button"`
                    + ' style="border-collapse:collapse;">'
                    + `<tr><td bgcolor="${ACCENT}" align="center" style="background:${ACCENT};border-radius:4px;text-align:center;">`
                    + `<a href="${htmlEscape(ctaUrl)}" target="_blank"`
                    + ` style="display:inline-block;padding:16px 26px;color:#ffffff;font-family:${FONT_STACK};font-size:13px;line-height:18px;font-weight:bold;letter-spacing:.6px;text-decoration:none;text-transform:uppercase;">`
                    + `${htmlEscape(ctaLabel)} &nbsp;&rarr;</a>`
                    + '</td></tr></table>'
                    + '</td>',
                ),
            },
        },
        {
            id: 'rt-mail-divider',
            definition: {
                label: 'Trennlinie',
                category: MAIL_STRUCTURE_CATEGORY,
                // Die Linie ist ein border-top auf einer eigenen Tabelle und
                // die Zelle traegt ein &nbsp; mit font-size:0. Eine wirklich
                // leere Zelle laesst Outlook zusammenfallen.
                content: shell(
                    '<td class="rt-pad" style="padding:0 46px;">'
                    + `<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" ${MAIL_BLOCK_ATTRIBUTE}="divider"`
                    + ' data-rt-mail-border="1" style="width:100%;border-collapse:collapse;border-top:1px solid {{BORDER}};">'
                    + '<tr><td height="1" style="height:1px;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td></tr>'
                    + '</table>'
                    + '</td>',
                ),
            },
        },
        {
            id: 'rt-mail-spacer',
            definition: {
                label: 'Abstandhalter',
                category: MAIL_STRUCTURE_CATEGORY,
                content: shell(
                    `<td ${MAIL_BLOCK_ATTRIBUTE}="spacer" height="24"`
                    + ' style="height:24px;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td>',
                ),
            },
        },
        {
            id: 'rt-mail-image',
            definition: {
                label: 'Bild',
                category: MAIL_STRUCTURE_CATEGORY,
                // display:block gegen den Zeilenabstand unter Bildern,
                // font-size:0 in der Zelle gegen dieselbe Luecke in Outlook.
                content: shell(
                    '<td class="rt-pad" align="left" style="padding:0 46px 24px;text-align:left;font-size:0;line-height:0;mso-line-height-rule:exactly;">'
                    + `<img ${MAIL_BLOCK_ATTRIBUTE}="image" data-rt-image-align="left" src="${imageSrc}" width="600" alt=""`
                    + ' style="display:block;width:100%;max-width:600px;height:auto;margin:0;border:0;outline:none;">'
                    + '</td>',
                ),
            },
        },
        {
            id: 'rt-mail-signature',
            definition: {
                label: 'Signaturblock',
                category: MAIL_STRUCTURE_CATEGORY,
                // ALS BAUTEILDEFINITION, NICHT ALS HTML-ZEICHENKETTE:
                // MailSignature::render() liefert zwei <tr> ohne umgebende
                // <table>, der Platzhalter muss also direkt in der Tabelle
                // stehen. Beim Parsen einer HTML-Zeichenkette schiebt der
                // Browser losen Text nach den Regeln des "foster parenting"
                // aus der Tabelle heraus — der Platzhalter landete VOR der
                // Tabelle und die eingesetzten Zeilen stuenden ohne Tabelle
                // da. Eine Bauteildefinition umgeht das Parsen vollstaendig.
                content: {
                    tagName: 'table',
                    attributes: {
                        role: 'presentation',
                        width: '100%',
                        border: '0',
                        cellspacing: '0',
                        cellpadding: '0',
                        [MAIL_BLOCK_ATTRIBUTE]: 'signature',
                    },
                    style: {
                        width: '100%',
                        'border-collapse': 'collapse',
                    },
                    components: [{ type: 'textnode', content: SIGNATURE_PLACEHOLDER }],
                },
            },
        },
    ];

    return [...structure, ...MAIL_PERSON_FIELDS.map(personBlock)];
}

/**
 * Stylesheet der Leinwand — wird als <style> in das Editor-iframe gehaengt
 * und ist NICHT Teil des gespeicherten Dokuments.
 *
 * Drei Aufgaben:
 *   1. Die Palettenplatzhalter sichtbar machen (siehe MAIL_CANVAS_PALETTE).
 *   2. Personendaten als Beispielwerte zeigen, waehrend im Markup der
 *      Platzhalter stehen bleibt.
 *   3. Token-Bilder nur im iframe durch echte RailTime-Vorschauassets ersetzen.
 *
 * BEWUSST OHNE !important bei den Farbregeln: ein ungueltiger Wert wie
 * 'color:{{TEXT_PRIMARY}}' wird vom Browser verworfen, dann greift die Regel
 * hier. Sobald jemand im Style-Manager eine echte Farbe setzt, gewinnt das
 * style-Attribut wieder — genau so soll es sein.
 *
 * @param {'light'|'dark'} theme
 * @param {object} previewAssets Hell-/Dunkel-Logo, Zugbild und Kontakticons
 */
export function mailCanvasStyles(theme = 'light', previewAssets = {}) {
    const palette = MAIL_CANVAS_PALETTE[theme] || MAIL_CANVAS_PALETTE.light;
    const themedAssets = previewAssets?.[theme] || {};
    const icons = previewAssets?.icons || {};
    const cssImage = (value) => String(value || '')
        .replaceAll('\\', '\\\\')
        .replaceAll('"', '\\"')
        .replaceAll('\n', '');
    const imageRule = (token, value) => value
        ? `img[src="{{${token}}}"] { content: url("${cssImage(value)}"); }`
        : '';

    return `
body.rt-mail-canvas {
    margin: 0;
    padding: 28px 0;
    background: ${palette.PAGE_BG};
    font-family: ${FONT_STACK};
}

/* ---- Vorschau der Palettenplatzhalter ---- */
[data-rt-mail-surface="page"] { background: ${palette.PAGE_BG}; }
[data-rt-mail-surface="surface"] { background: ${palette.SURFACE_BG}; }
[data-rt-mail-surface="card"] { background: ${palette.CARD_BG}; }
[data-rt-mail-surface="soft"] { background: ${palette.SOFT_BG}; }
[data-rt-mail-text="primary"] { color: ${palette.TEXT_PRIMARY}; }
[data-rt-mail-text="secondary"] { color: ${palette.TEXT_SECONDARY}; }
[data-rt-mail-text="muted"] { color: ${palette.TEXT_MUTED}; }
[data-rt-mail-border] { border-color: ${palette.BORDER}; }

/* ---- Tokens des bestehenden RailTime-Masters ----
 * style- und bgcolor-Attribute behalten ihren Rohtext, selbst wenn der
 * Browser den unaufgeloesten Wert verwirft. Die Vorschau kann ihn deshalb
 * selektieren, ohne einen einzigen Token im gespeicherten Projekt zu ersetzen. */
[bgcolor="{{PAGE_BG}}"], [style*="background:{{PAGE_BG}}"] { background-color: ${palette.PAGE_BG} !important; }
[bgcolor="{{SURFACE_BG}}"], [style*="background:{{SURFACE_BG}}"] { background-color: ${palette.SURFACE_BG} !important; }
[bgcolor="{{CARD_BG}}"], [style*="background:{{CARD_BG}}"] { background-color: ${palette.CARD_BG} !important; }
[bgcolor="{{SOFT_BG}}"], [style*="background:{{SOFT_BG}}"] { background-color: ${palette.SOFT_BG} !important; }
[style*="color:{{TEXT_PRIMARY}}"] { color: ${palette.TEXT_PRIMARY} !important; }
[style*="color:{{TEXT_SECONDARY}}"] { color: ${palette.TEXT_SECONDARY} !important; }
[style*="color:{{TEXT_MUTED}}"] { color: ${palette.TEXT_MUTED} !important; }
[style*="{{BORDER}}"] { border-color: ${palette.BORDER} !important; }

[bgcolor="{{SIGNATURE_BG}}"], [style*="background:{{SIGNATURE_BG}}"] { background-color: ${theme === 'dark' ? '#0c1017' : '#f7f6f3'} !important; }
[bgcolor="{{SIGNATURE_LEGAL_BG}}"], [style*="background:{{SIGNATURE_LEGAL_BG}}"] { background-color: ${theme === 'dark' ? '#080b10' : '#efece7'} !important; }
[style*="color:{{SIGNATURE_TEXT_PRIMARY}}"] { color: ${theme === 'dark' ? '#ffffff' : '#111820'} !important; }
[style*="color:{{SIGNATURE_CONTACT_TEXT}}"] { color: ${theme === 'dark' ? '#b9c1ca' : '#5c6671'} !important; }
[style*="color:{{SIGNATURE_META_TEXT}}"] { color: ${theme === 'dark' ? '#8e98a5' : '#66717c'} !important; }
[style*="color:{{SIGNATURE_TEXT_MUTED}}"] { color: ${theme === 'dark' ? '#77818d' : '#7b858f'} !important; }
[style*="color:{{SIGNATURE_LEGAL_TEXT}}"] { color: ${theme === 'dark' ? '#77818d' : '#8a939d'} !important; }
[style*="color:{{SIGNATURE_ACCENT}}"] { color: ${theme === 'dark' ? '#ff5570' : '#e4002b'} !important; }
[style*="{{SIGNATURE_BORDER}}"], [style*="{{SIGNATURE_RULE}}"] { border-color: ${theme === 'dark' ? '#313944' : '#dfe3e6'} !important; }

${imageRule('LOGO_SRC', themedAssets.logo)}
${imageRule('ICON_PHONE_SRC', icons.phone)}
${imageRule('ICON_MOBILE_SRC', icons.mobile)}
${imageRule('ICON_EMAIL_SRC', icons.email)}
${imageRule('ICON_WEB_SRC', icons.web)}
${imageRule('ICON_LOCATION_SRC', icons.location)}

/* ---- Personendaten: Platzhalter aus, Beispielwert an ---- */
[data-rt-mail-sample] > [data-rt-mail-token] {
    display: none !important;
}
[data-rt-mail-sample]::after {
    content: attr(data-rt-mail-sample);
}
[data-rt-mail-sample] {
    outline: 1px dashed rgba(228, 0, 43, .45);
    outline-offset: 2px;
}

/* ---- Signaturblock: Platzhaltertext durch eine Beschriftung ersetzen ---- */
[${MAIL_BLOCK_ATTRIBUTE}="signature"] {
    font-size: 0 !important;
    outline: 2px dashed rgba(228, 0, 43, .5);
    outline-offset: 3px;
}
[${MAIL_BLOCK_ATTRIBUTE}="signature"]::before {
    content: 'Signaturblock · wird beim Versand aus der zentralen Quelle eingesetzt';
    display: block;
    padding: 20px 24px;
    background: rgba(228, 0, 43, .06);
    color: ${ACCENT};
    font: bold 12px/18px ${FONT_STACK};
    letter-spacing: .08em;
    text-transform: uppercase;
}

/* ---- Leere Bauteile bleiben greifbar ---- */
[${MAIL_BLOCK_ATTRIBUTE}="spacer"],
[${MAIL_BLOCK_ATTRIBUTE}="divider"] {
    outline: 1px dashed rgba(137, 147, 158, .5);
    outline-offset: -1px;
}
`.trim();
}
