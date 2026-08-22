<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DOMComment;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use Illuminate\Validation\ValidationException;

/**
 * Serverseitige Haertung fuer E-Mail-HTML.
 *
 * Der Editor laesst sich beschneiden, verlassen darf man sich aber nur auf
 * diese Pruefung: sie steht zwischen dem Builder und dem Postausgang. Kommt
 * hier unerlaubte Syntax durch, bricht die Vorlage in Outlook oder Gmail.
 *
 * ARBEITSWEISE — Allowlist ueber PHP-DOMDocument, keine neue Abhaengigkeit.
 * Die Listen sind nicht erfunden, sondern an den echten, funktionierenden
 * Vorlagen belegt (resources/mail-templates/*.html sowie
 * resources/views/emails/parts/*.blade.php).
 *
 *   Element verboten          -> samt Inhalt entfernen
 *   Element unbekannt         -> auspacken, Kinder behalten
 *   Attribut nicht gelistet   -> Attribut entfernen, Element behalten
 *   CSS-Eigenschaft unerlaubt -> Deklaration entfernen, Rest behalten
 *
 * ZWEI BETRIEBSARTEN:
 *   clean()        bereinigend — entfernen und protokollieren
 *   assertClean()  streng — jeder Verstoss wirft eine ValidationException
 *
 * WARUM UNVERAENDERTE EINGABE UNVERAENDERT ZURUECKKOMMT: gibt es nichts zu
 * beanstanden, liefert der Sanitizer die Eingabe zeichengleich zurueck
 * statt das Ergebnis der DOM-Serialisierung. Das ist kein Bequemlichkeits-
 * ausweg, sondern notwendig — saveHTML() zerstoert zwei Dinge, auf die die
 * Vorlagen angewiesen sind:
 *
 *   1. In href und src werden geschweifte Klammern URL-kodiert. Aus
 *      href="{{CTA_URL}}" wird href="%7B%7BCTA_URL%7D%7D" und die
 *      Platzhalter-Ersetzung findet den Schluessel nicht mehr.
 *   2. Nicht-ASCII wird zu benannten Entities (PERSOENLICHE -> PERS&Ouml;NLICHE).
 *
 * Der DOM-Durchlauf findet trotzdem immer statt; nur sein Ergebnis wird
 * verworfen, wenn er nichts gefunden hat. Konnte libxml das Markup nicht
 * eindeutig lesen, entfaellt die Durchreichung — dann gilt die
 * normalisierte Fassung, weil der Parser dort moeglicherweise etwas anders
 * gesehen hat als ein Mailclient. Auf dem serialisierenden Weg werden die
 * kodierten Platzhalter wieder zurueckgeschrieben.
 */
final class EmailHtmlSanitizer
{
    public const MODE_CLEAN = 'clean';

    public const MODE_STRICT = 'strict';

    /** Neutrales 1px-Bild des Editors; darf nie Teil einer echten Mail sein. */
    public const PREVIEW_TRANSPARENT_PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

    /**
     * Erlaubte Elemente mit ihren zusaetzlichen Attributen.
     *
     * Die mit "Markdown" markierten Elemente kommen in keiner heutigen
     * Vorlage vor, entstehen aber aus Illuminate\Mail\Markdown::parse(),
     * sobald jemand eine Aufzaehlung oder Tabelle schreibt. Ohne sie
     * verstuemmelt der Sanitizer den ersten Systemmailtext mit einer Liste.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TAGS = [
        // --- Dokumentruempfe (nur bei vollstaendigen Dokumenten) ---
        'html' => ['xmlns', 'xmlns:v', 'xmlns:o', 'xmlns:w'],
        'head' => [],
        'meta' => ['charset', 'name', 'content', 'http-equiv'],
        'title' => [],
        'style' => ['type', 'media'],
        'body' => ['bgcolor'],

        // --- Tabellengeruest: traegt das gesamte Layout ---
        'table' => ['role', 'width', 'height', 'border', 'cellpadding', 'cellspacing', 'bgcolor', 'align'],
        'thead' => [],
        'tbody' => [],
        'tfoot' => [],
        'tr' => ['bgcolor', 'align', 'valign'],
        'th' => ['width', 'height', 'align', 'valign', 'colspan', 'rowspan', 'bgcolor', 'nowrap', 'scope'],
        'td' => ['width', 'height', 'align', 'valign', 'colspan', 'rowspan', 'bgcolor', 'background', 'nowrap'],

        // --- Text ---
        'div' => ['align'],
        'p' => ['align'],
        'span' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'small' => [],
        'br' => [],
        'hr' => ['align', 'width'],
        'center' => [],
        'h1' => ['align'],
        'h2' => ['align'],
        'h3' => ['align'],
        'h4' => ['align'],
        'h5' => ['align'],
        'h6' => ['align'],
        'ul' => [],
        'ol' => ['start', 'type'],
        'li' => ['value'],
        'blockquote' => [],
        'code' => [],
        'pre' => [],

        // --- Verweise und Bilder ---
        'a' => ['href', 'target', 'rel', 'name'],
        'img' => ['src', 'alt', 'width', 'height', 'border'],
    ];

    /** Auf jedem erlaubten Element zulaessig. */
    private const GLOBAL_ATTRIBUTES = ['class', 'style', 'id', 'title', 'dir', 'lang'];

    /** Diese Elemente verschwinden samt Inhalt. */
    private const FORBIDDEN_TAGS = [
        'script', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'form', 'input', 'button', 'select', 'option', 'textarea', 'label',
        'base', 'link', 'svg', 'math', 'canvas', 'audio', 'video', 'source', 'track',
        'template', 'slot', 'portal', 'dialog', 'meta-refresh', 'marquee', 'map', 'area',
    ];

    /**
     * Verbotene Elemente, die in HTML 5 leer sind, die der libxml-Parser
     * aber als Behaelter liest. Bei ihnen wird der vermeintliche Inhalt
     * gerettet, siehe visitElement().
     *
     * @var list<string>
     */
    private const FORBIDDEN_VOID_TAGS = ['embed', 'source', 'track', 'param', 'area', 'input', 'base', 'link'];

    /**
     * Feste Attributwerte. Alles andere fliegt raus.
     *
     * align fehlt hier bewusst: der erlaubte Wertevorrat haengt am Element,
     * siehe ALLOWED_ALIGN_ON_TABLE.
     *
     * @var array<string, list<string>>
     */
    private const ATTRIBUTE_VALUE_ENUMS = [
        'role' => ['presentation', 'none'],
        'dir' => ['ltr', 'rtl', 'auto'],
        'target' => ['_blank', '_self'],
        'valign' => ['top', 'middle', 'bottom', 'baseline'],
        'scope' => ['col', 'row', 'colgroup', 'rowgroup'],
        'http-equiv' => ['content-type', 'x-ua-compatible'],
    ];

    /** Auf Zellen und Absaetzen unbedenklich. */
    private const ALLOWED_ALIGN = ['left', 'center', 'right', 'justify'];

    /**
     * Auf einer TABELLE laesst align="left" sie floaten und der Folgetext
     * rutscht daneben statt darunter — dokumentiert in
     * resources/views/vendor/mail/html/button.blade.php:13.
     */
    private const ALLOWED_ALIGN_ON_TABLE = ['center'];

    /**
     * Erlaubte CSS-Eigenschaften. Der Kern stammt aus dem Bestand, dazu
     * kommen die Gegenstuecke der bereits genutzten Kurzformen.
     *
     * Alles mit dem Praefix mso- ist zusaetzlich erlaubt, siehe
     * isAllowedProperty(): eine Pruefung gegen "gueltige CSS-Eigenschaften"
     * wirft sonst mso-hide, mso-line-height-rule und mso-table-lspace/rspace
     * still weg. Folge waere ein in Outlook sichtbarer Vorschautext, hoehere
     * Symbolzeilen und ein 0,75-pt-Steg an jeder Tabelle — nichts davon
     * faellt in einem Browser auf.
     *
     * @var list<string>
     */
    private const ALLOWED_CSS_PROPERTIES = [
        // Flaeche
        'background', 'background-color', 'background-image', 'background-position',
        'background-repeat', 'background-size',
        // Rahmen
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-style', 'border-width', 'border-collapse',
        'border-spacing', 'border-radius',
        // Box
        'box-sizing', 'width', 'min-width', 'max-width',
        'height', 'min-height', 'max-height', 'overflow',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        // Fluss
        'display', 'position', 'z-index', 'vertical-align', 'direction',
        'top', 'right', 'bottom', 'left',
        // Schrift
        'font', 'color', 'font-family', 'font-size', 'font-style', 'font-weight',
        'font-variant', 'line-height', 'letter-spacing', 'word-spacing',
        'text-align', 'text-decoration', 'text-indent', 'text-transform',
        'white-space', 'word-break', 'overflow-wrap', 'word-wrap',
        'text-size-adjust', '-webkit-text-size-adjust', '-ms-text-size-adjust',
        // Listen (Markdown)
        'list-style', 'list-style-type', 'list-style-position',
        // Sonstiges
        'opacity', 'outline', 'visibility', 'caption-side', 'table-layout',
    ];

    /**
     * Eigenschaften, die nie durchgehen — auch dann nicht, wenn sie
     * spaeter einmal in ALLOWED_CSS_PROPERTIES rutschen sollten.
     *
     * @var list<string>
     */
    private const FORBIDDEN_CSS_PROPERTIES = [
        'float', 'clear',
        'flex', 'flex-direction', 'flex-wrap', 'flex-flow', 'flex-grow',
        'flex-shrink', 'flex-basis', 'order', 'justify-content', 'align-items',
        'align-content', 'align-self', 'place-items', 'place-content',
        'gap', 'row-gap', 'column-gap',
        'grid', 'grid-template', 'grid-template-columns', 'grid-template-rows',
        'grid-template-areas', 'grid-area', 'grid-column', 'grid-row',
        'grid-auto-flow', 'grid-auto-columns', 'grid-auto-rows',
        'transform', 'transform-origin', 'transform-style', 'perspective',
        'rotate', 'scale', 'translate',
        'animation', 'animation-name', 'animation-duration', 'transition',
        'filter', 'backdrop-filter', 'mix-blend-mode', 'will-change',
        'behavior', '-moz-binding', 'content', 'cursor', 'pointer-events',
    ];

    /**
     * Wertebeschraenkungen — nur dort, wo ein falscher Wert das Layout
     * zerlegt.
     *
     * position:relative wird weiterhin von der bestehenden Tabellenquelle
     * fuer stabile Inhaltscontainer verwendet. Ein pauschales Verbot waere
     * deshalb ein sichtbarer Schaden,
     * absolute/fixed/sticky brechen dagegen jede Mailansicht.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_CSS_VALUES = [
        'position' => ['static', 'relative'],
        'display' => ['block', 'inline', 'inline-block', 'none', 'table', 'table-row', 'table-cell', 'inline-table'],
        'visibility' => ['visible', 'hidden'],
    ];

    /**
     * Zeichenketten, die eine Deklaration sofort verwerfen. CSS-Escapes
     * verstecken gern Schemata, deshalb steht auch der Backslash hier.
     *
     * @var list<string>
     */
    private const FORBIDDEN_VALUE_PATTERNS = [
        'javascript:', 'vbscript:', 'livescript:', 'mocha:',
        'data:text/html', 'data:text/javascript', 'data:application',
        'expression(', 'behavior:', '-moz-binding', '@import', '\\', '/*', '*/', '&#',
    ];

    /** Begrenzung fuer eingebettete Mailbilder nach Base64-Dekodierung. */
    private const MAX_DATA_URI_BYTES = 512_000;

    private const MAX_DATA_URI_DIMENSION = 4096;

    private const MAX_DATA_URI_PIXELS = 16_000_000;

    /** In url() nur eingebettete Bilder — kein data:text/html. */
    private const ALLOWED_DATA_URI_MIME = [
        'image/png', 'image/gif', 'image/jpeg', 'image/jpg', 'image/webp',
    ];

    /** Im style-Block erlaubte At-Regeln. @import und @font-face bleiben draussen. */
    private const ALLOWED_AT_RULES = ['media'];

    /**
     * Innerhalb eines bedingten mso-Kommentars zugelassenes Vokabular.
     * Rein textliche Pruefung, kein Nachparsen — siehe visitComment().
     *
     * @var list<string>
     */
    private const ALLOWED_MSO_COMMENT_TAGS = [
        'table', 'tr', 'td', 'tbody', 'div', 'center', 'xml', 'br', 'p', 'span', 'a', 'img',
        'v:rect', 'v:fill', 'v:textbox', 'v:image', 'v:shape', 'w:anchorlock',
        'o:officedocumentsettings', 'o:pixelsperinch', 'o:allowpng',
    ];

    /**
     * Zusaetzliche Attribute des Outlook-/VML-Vokabulars.
     *
     * Normale HTML-Elemente verwenden weiter ALLOWED_TAGS. Die VML-Liste ist
     * bewusst eng: bedingte Kommentare sind fuer normale Clients unsichtbar,
     * werden von Outlook aber als echtes Markup ausgefuehrt.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_MSO_COMMENT_ATTRIBUTES = [
        'xml' => ['xmlns:o', 'xmlns:v', 'xmlns:w'],
        'v:rect' => ['arcsize', 'coordorigin', 'coordsize', 'fill', 'fillcolor', 'href', 'stroke', 'stroked', 'strokeweight'],
        'v:fill' => ['color', 'color2', 'opacity', 'origin', 'position', 'src', 'type'],
        'v:textbox' => ['inset'],
        'v:image' => ['href', 'src'],
        'v:shape' => ['coordorigin', 'coordsize', 'fillcolor', 'href', 'path', 'stroked', 'strokeweight', 'type'],
        'w:anchorlock' => [],
        'o:officedocumentsettings' => [],
        'o:pixelsperinch' => [],
        'o:allowpng' => [],
    ];

    /**
     * Die Schnittmarken der Signatur. EmailTemplateBuilder::stripEmptyContactRows()
     * haengt daran; ohne sie bleiben leere Durchwahl-, Mobil- und
     * Website-Zeilen stehen.
     */
    private const CONTACT_MARKER_PATTERN = '/^\s*RT_(?:PHONE|MOBILE|WEBSITE|COMPANY_PHONE|COMPANY_EMAIL)_(?:START|END)\s*$/';

    /** Bedingter Outlook-Kommentar, ausschliesslich in der "downlevel-hidden"-Form. */
    private const MSO_COMMENT_PATTERN = '/^\[if\s+([^\]]{1,60})\]>(.*)<!\[endif\]$/si';

    /** Zugelassene Bedingungen darin — nur Outlook, kein IE-Vokabular. */
    private const MSO_CONDITION_PATTERN = '/^(?:(?:gte|lte|gt|lt)\s+)?mso(?:\s+\d{1,4})?$/i';

    /** Ein Platzhalter der Form {{KEY}} — an jeder Wertestelle gueltig. */
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([A-Za-z0-9_.\-]{1,64})\s*\}\}/';

    /** Ein Platzhalter als vollstaendiger semantischer Wert. */
    private const WHOLE_PLACEHOLDER_PATTERN = '/^\s*\{\{\s*([A-Za-z0-9_.\-]{1,64})\s*\}\}\s*$/';

    /** Sichere feste Kommunikationsschemata vor genau einem Platzhalter. */
    private const FIXED_HREF_PLACEHOLDER_PATTERN = '/^\s*(mailto|tel):\s*\{\{\s*([A-Za-z0-9_.\-]{1,64})\s*\}\}\s*$/i';

    /** CID bleibt auch nach Einsetzung des Platzhalters ein lokaler Verweis. */
    private const FIXED_CID_PLACEHOLDER_PATTERN = '/^\s*cid:\s*\{\{\s*([A-Za-z0-9_.\-]{1,64})\s*\}\}\s*$/i';

    /** Vollstaendige Linkziele, die nach dem Sanitizer bewusst ersetzt werden. */
    private const HREF_URL_PLACEHOLDERS = [
        'CTA_URL', 'FIRMEN_WEBSITE_HREF',
    ];

    /** Werte hinter einem feststehenden mailto:-Schema. */
    private const MAILTO_PLACEHOLDERS = [
        'E_MAIL', 'FIRMEN_EMAIL',
    ];

    /** Bereits normalisierte Werte hinter einem feststehenden tel:-Schema. */
    private const TEL_PLACEHOLDERS = [
        'DURCHWAHL_TEL', 'MOBIL_TEL', 'FIRMEN_TELEFON_TEL',
    ];

    /** Ausschliesslich serverkontrollierte Bildquellen fuer src und CSS-url(). */
    private const IMAGE_URL_PLACEHOLDERS = [
        'LOGO_SRC', 'LOGO_STILL_SRC', 'TRAIN_SRC', 'TRAIN_STILL_SRC', 'TRAIN_IDLE_SRC',
        'ICON_RT_SRC', 'ICON_RT_STILL_SRC',
        'ICON_PHONE_SRC', 'ICON_MOBILE_SRC', 'ICON_EMAIL_SRC',
        'ICON_WEB_SRC', 'ICON_LOCATION_SRC',
    ];

    /** Lokale MIME-Content-IDs, nie Profil- oder Freitextwerte. */
    private const CID_PLACEHOLDERS = [
        'LOGO_CID', 'TRAIN_CID',
        'ICON_PHONE_CID', 'ICON_MOBILE_CID', 'ICON_EMAIL_CID',
        'ICON_WEB_CID', 'ICON_LOCATION_CID',
    ];

    /** Feste Palettenwerte, die erst beim Rendern in CSS eingesetzt werden. */
    private const CSS_VALUE_PLACEHOLDERS = [
        'PAGE_BG', 'SURFACE_BG', 'CARD_BG', 'SOFT_BG',
        'TEXT_PRIMARY', 'TEXT_SECONDARY', 'TEXT_MUTED', 'BORDER',
        'SIGNATURE_BG', 'SIGNATURE_TRAIN_WASH', 'SIGNATURE_LEGAL_BG',
        'SIGNATURE_TEXT_PRIMARY', 'SIGNATURE_CONTACT_TEXT',
        'SIGNATURE_META_TEXT', 'SIGNATURE_TEXT_MUTED',
        'SIGNATURE_LEGAL_TEXT', 'SIGNATURE_ACCENT',
        'SIGNATURE_BORDER', 'SIGNATURE_RULE',
    ];

    /**
     * Die Klassennamen, auf die die Umbruchregeln tatsaechlich zeigen.
     * Alles andere ist in einer E-Mail wirkungslos und wird als Hinweis
     * gemeldet, siehe checkClasses().
     *
     * @var list<string>
     */
    private const KNOWN_CLASSES = [
        'break-all', 'table',
        'rt-shell', 'rt-pad', 'rt-title', 'rt-stack', 'rt-logo', 'rt-mark',
        'rt-card', 'rt-card-cell',
        'rt-sign-cell', 'rt-sign-content', 'rt-sign-layout',
        'rt-sign-top-row', 'rt-sign-company-row',
        'rt-sign-logo', 'rt-sign-identity', 'rt-sign-company', 'rt-sign-name',
        'rt-sign-stage', 'rt-sign-train', 'rt-sign-train-layer',
        'rt-train-idle-overlay', 'rt-train-idle-runtime-layer', 'rt-train-idle-surface',
        'rt-train-idle-image',
        // Die Anschrift steht seit dem symmetrischen Umbau nur noch einmal
        // in der Firmenspalte. Die beiden Namen bleiben zugelassen, damit
        // frueher veroeffentlichte Dokumente keinen Hinweis ausloesen.
        'rt-only-wide', 'rt-only-narrow', 'rt-marke-mobil',
        'rt-person-kopf', 'rt-firma-breit', 'rt-firma-schmal',
        'rt-firma-links', 'rt-firma-rechts',
        'rt-contact', 'rt-contact-icon', 'rt-contact-text',
        'rt-company-contact', 'rt-company-contact-icon', 'rt-company-contact-text',
    ];

    /** @var list<string> Eigene Hosts, deren Bilder und url() durchgehen. */
    private array $allowedHosts;

    /** @var list<array{code: string, severity: string, message: string, context: string}> */
    private array $findings = [];

    /**
     * @param  list<string>  $allowedHosts  zusaetzliche eigene Hosts (ohne Schema)
     */
    public function __construct(array $allowedHosts = [])
    {
        $hosts = array_map(
            static fn ($host): string => strtolower(trim((string) $host)),
            [...$allowedHosts, ...self::configuredHosts()],
        );

        $this->allowedHosts = array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Bereinigender Betrieb: entfernen und protokollieren.
     */
    public function clean(string $html): EmailHtmlReport
    {
        return $this->run($html);
    }

    /**
     * Strenger Betrieb: jeder Verstoss wirft. Reine Hinweise (unbekannte
     * Klasse, relativer Verweis) werfen nicht — sie haben nichts entfernt
     * und stehen nur im Bericht.
     *
     * @throws ValidationException
     */
    public function assertClean(string $html): EmailHtmlReport
    {
        $report = $this->run($html);

        if ($report->hasViolations()) {
            throw ValidationException::withMessages([
                'html' => array_merge(
                    ['Das HTML enthaelt Syntax, die in E-Mails nicht erlaubt ist.'],
                    $report->violationMessages(),
                ),
            ]);
        }

        return $report;
    }

    /**
     * @throws ValidationException im strengen Betrieb
     */
    public function sanitize(string $html, string $mode = self::MODE_CLEAN): EmailHtmlReport
    {
        return $mode === self::MODE_STRICT
            ? $this->assertClean($html)
            : $this->clean($html);
    }

    // -----------------------------------------------------------------
    // Ablauf
    // -----------------------------------------------------------------

    private function run(string $html): EmailHtmlReport
    {
        $this->findings = [];

        if (trim($html) === '') {
            return new EmailHtmlReport($html);
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // Der XML-Prolog ist Pflicht: ohne Kodierungshinweis zerlegt
        // loadHTML jedes Ue in Mojibake. Ein vorangestelltes <meta charset>
        // waere der naheliegende Weg, loescht zusammen mit NOIMPLIED aber
        // den gesamten Fragmentinhalt.
        // NOIMPLIED ist ebenfalls Pflicht: der Signaturblock ist ein
        // Fragment, das mit <tr> beginnt (signature.blade.php:12).
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        $parseTrouble = false;

        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                $parseTrouble = true;
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $this->violation('markup.unreadable', 'Das Markup liess sich nicht lesen.');

            return new EmailHtmlReport('', $this->findings);
        }

        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node instanceof DOMProcessingInstruction) {
                $document->removeChild($node);
            }
        }

        $this->walk($document);

        // Durchreichen nur, wenn nichts zu beanstanden war UND der Parser
        // das Markup eindeutig lesen konnte. Stolperte libxml, koennte er
        // etwas anderes gesehen haben als ein Mailclient — dann gilt die
        // normalisierte Fassung.
        if (! $parseTrouble) {
            if ($this->findings === []) {
                return new EmailHtmlReport($html);
            }
        } else {
            // Ohne diese Meldung kaeme aus einer begradigten Eingabe ein
            // Bericht ohne einen einzigen Eintrag zurueck, obwohl das
            // Ergebnis nicht mehr der Eingabe entspricht.
            $this->notice(
                'markup.normalised',
                'Das Markup war nicht eindeutig lesbar und wurde beim Lesen begradigt; ausgeliefert wird die begradigte Fassung.',
            );
        }

        return new EmailHtmlReport(
            $this->serialize($document, $html),
            $this->findings,
        );
    }

    /**
     * saveHTML() normalisiert mehr, als in einer E-Mail gut ist. Zwei
     * Schaeden werden hier zurueckgenommen: die grossgeschriebene
     * Dokumenttypangabe und die URL-Kodierung der Platzhalter in href/src.
     */
    private function serialize(DOMDocument $document, string $original): string
    {
        $html = (string) $document->saveHTML();

        // %7B%7BKEY%7D%7D -> {{KEY}}. Ohne diese Rueckgabe findet
        // EmailTemplateBuilder::substitute() den Schluessel nicht mehr.
        $html = (string) preg_replace_callback(
            '/%7B%7B\s*([A-Za-z0-9_.\-]{1,64})\s*%7D%7D/i',
            static fn (array $match): string => '{{'.$match[1].'}}',
            $html,
        );

        // saveHTML() schreibt die Dokumenttypangabe gross. Zeichengleich
        // ersetzen statt per preg_replace: die Ersetzung darf hier keine
        // Rueckverweise deuten.
        if (preg_match('/^\s*<!doctype[^>]*>/i', $original, $before) === 1
            && preg_match('/^<!DOCTYPE[^>]*>/i', $html, $after) === 1) {
            $html = trim($before[0]).substr($html, strlen($after[0]));
        }

        return $html;
    }

    // -----------------------------------------------------------------
    // Baumdurchlauf
    // -----------------------------------------------------------------

    private function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->visit($child);
        }
    }

    private function visit(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $this->visitElement($node);

            return;
        }

        if ($node instanceof DOMComment) {
            $this->visitComment($node);

            return;
        }

        if ($node instanceof DOMDocumentType) {
            $this->visitDoctype($node);

            return;
        }

        if ($node instanceof DOMProcessingInstruction) {
            $this->violation('pi.forbidden', 'Verarbeitungsanweisung entfernt.', $node->target);
            $node->parentNode?->removeChild($node);
        }
    }

    private function visitElement(DOMElement $element): void
    {
        $name = strtolower($element->nodeName);
        $path = $this->pathOf($element);

        if (in_array($name, self::FORBIDDEN_TAGS, true)) {
            // libxml2 parst nach HTML 4 und kennt embed/source/track nicht
            // als leere Elemente. Alles, was danach folgt, landet dort als
            // Inhalt. Wuerde das Element samt Inhalt fallen, risse ein
            // einzelnes <embed> den halben Rest der Mail mit.
            if (in_array($name, self::FORBIDDEN_VOID_TAGS, true) && $element->hasChildNodes()) {
                $this->violation('element.forbidden', "Element <{$name}> ist in E-Mails verboten und wurde entfernt.", $path);
                $this->liftChildren($element);

                return;
            }

            $this->violation('element.forbidden', "Element <{$name}> ist in E-Mails verboten und wurde samt Inhalt entfernt.", $path);
            $element->parentNode?->removeChild($element);

            return;
        }

        if (! array_key_exists($name, self::ALLOWED_TAGS)) {
            $this->unwrap($element, $name, $path);

            return;
        }

        $this->visitAttributes($element, $name, $path);

        // Faellt http-equiv="refresh" weg, bleibt sonst
        // content="0;url=https://..." als Rest stehen — wertlos, aber es
        // traegt eine fremde Adresse durch die Pruefung.
        if ($name === 'meta'
            && $element->hasAttribute('content')
            && ! $element->hasAttribute('name')
            && ! $element->hasAttribute('http-equiv')) {
            $this->violation('element.meta', 'Angabe <meta> ohne gueltigen Traeger entfernt.', $path);
            $element->parentNode?->removeChild($element);

            return;
        }

        // Der Stilbogen ist eine Rohtext-Insel und wird getrennt geprueft;
        // ein Baumdurchlauf haette dort nichts zu suchen.
        if ($name === 'style') {
            $this->checkStyleElement($element, $path);

            return;
        }

        $this->walk($element);
    }

    /** Unbekanntes Element auspacken: der Inhalt bleibt, die Huelle geht. */
    private function unwrap(DOMElement $element, string $name, string $path): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        // Auf oberster Ebene eines Fragments duldet DOMDocument nur
        // Elemente und Kommentare. Auspacken wuerde dort werfen.
        if ($parent instanceof DOMDocument) {
            $this->violation('element.unknown', "Unbekanntes Element <{$name}> auf oberster Ebene entfernt.", $path);
            $parent->removeChild($element);

            return;
        }

        $this->violation('element.unknown', "Unbekanntes Element <{$name}> ausgepackt, der Inhalt blieb erhalten.", $path);
        $this->liftChildren($element);
    }

    /** Kinder an die Stelle des Elements heben, Element entfernen, Kinder pruefen. */
    private function liftChildren(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        $children = iterator_to_array($element->childNodes);

        foreach ($children as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);

        foreach ($children as $child) {
            $this->visit($child);
        }
    }

    private function visitAttributes(DOMElement $element, string $name, string $path): void
    {
        /** @var list<\DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes ?? [], false);

        foreach ($attributes as $attribute) {
            $attributeName = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            // Ereignis-Attribute zuerst: sie sind der Hauptgrund fuer diese Klasse.
            if (str_starts_with($attributeName, 'on') && strlen($attributeName) > 2) {
                $this->violation('attribute.event', "Ereignis-Attribut {$attributeName} entfernt.", $path);
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            // data-rt-mail-* gehoert ausschliesslich zur Editorleinwand.
            // Im gespeicherten Dokument waeren diese Marker wirkungslos und
            // koennten insbesondere neutrale Vorschauquellen legitimieren.
            if (str_starts_with($attributeName, 'data-rt-mail-')) {
                $this->violation('attribute.editor', "Editor-Attribut {$attributeName} entfernt.", $path);
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            // data-* traegt Editor- und Exportkennzeichen (data-rt-theme,
            // data-rt-outlook-train, die Bindungen des Builders) und ist in
            // jedem Mailclient wirkungslos.
            if (str_starts_with($attributeName, 'data-')) {
                continue;
            }

            if ($attributeName === 'style') {
                $this->applyStyleAttribute($element, $value, $path);

                continue;
            }

            if ($attributeName === 'class') {
                $this->checkClasses($value, $path);

                continue;
            }

            if (! $this->isAllowedAttribute($name, $attributeName)) {
                $this->violation('attribute.unknown', "Attribut {$attributeName} ist an <{$name}> nicht erlaubt und wurde entfernt.", $path);
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($attributeName === 'href' || $attributeName === 'src') {
                if (! $this->checkUrlAttribute($attributeName, $value, $path)) {
                    $element->removeAttribute($attribute->nodeName);
                }

                continue;
            }

            // Das obsolete background-Attribut ist nur an <td> erlaubt und
            // dient dort als Classic-Outlook-Fallback. Sicherheitsseitig ist
            // es exakt eine Bildquelle: dieselben erlaubten Hosts, data-/cid-
            // Regeln und serverkontrollierten Platzhalter wie bei img[src].
            if ($attributeName === 'background') {
                if (! $this->checkUrlAttribute('src', $value, $path)) {
                    $element->removeAttribute($attribute->nodeName);
                }

                continue;
            }

            if (! $this->isAllowedAttributeValue($name, $attributeName, $value)) {
                $this->violation('attribute.value', "Wert \"{$value}\" ist fuer {$attributeName} an <{$name}> nicht erlaubt.", $path);
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    private function isAllowedAttribute(string $tag, string $attribute): bool
    {
        return in_array($attribute, self::GLOBAL_ATTRIBUTES, true)
            || in_array($attribute, self::ALLOWED_TAGS[$tag] ?? [], true);
    }

    private function isAllowedAttributeValue(string $tag, string $attribute, string $value): bool
    {
        if ($this->isWholePlaceholder($value)) {
            return true;
        }

        $normalized = strtolower(trim($value));

        if ($attribute === 'align') {
            return in_array(
                $normalized,
                // align="left" auf einer Tabelle laesst sie floaten.
                $tag === 'table' ? self::ALLOWED_ALIGN_ON_TABLE : self::ALLOWED_ALIGN,
                true,
            );
        }

        $enum = self::ATTRIBUTE_VALUE_ENUMS[$attribute] ?? null;

        return $enum === null || in_array($normalized, $enum, true);
    }

    /**
     * Klassennamen werden GEMELDET, nie entfernt.
     *
     * Die 20 bekannten Namen tragen das gesamte responsive Verhalten:
     * resources/views/vendor/mail/html/themes/default.css ist leer, es gibt
     * also keinen CssToInlineStyles-Umbau, und die Medienabfragen in
     * emails/parts/responsive-css.blade.php greifen ausschliesslich ueber
     * Klassen. Eine verworfene Klasse schaltete das mobile Layout ab, ohne
     * dass in der Schreibtischvorschau etwas auffiele — deshalb Hinweis
     * statt Verstoss.
     */
    private function checkClasses(string $value, string $path): void
    {
        foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
            if ($this->containsPlaceholder($class) || in_array($class, self::KNOWN_CLASSES, true)) {
                continue;
            }

            $this->notice(
                'class.unknown',
                "Klasse \"{$class}\" ist keine der bekannten Umbruchklassen und bleibt in der E-Mail wirkungslos.",
                $path,
            );
        }
    }

    private function visitDoctype(DOMDocumentType $doctype): void
    {
        if (strtolower($doctype->name) === 'html' && $doctype->publicId === '' && $doctype->systemId === '') {
            return;
        }

        $this->violation('doctype.unexpected', 'Nur <!doctype html> ist zulaessig.', $doctype->name);
        $doctype->parentNode?->removeChild($doctype);
    }

    // -----------------------------------------------------------------
    // Kommentare
    // -----------------------------------------------------------------

    /**
     * Kommentare pauschal zu loeschen — die uebliche Standardregel — waere
     * hier gleich zwei Schaeden auf einmal: Outlook verlaere die bedingten
     * Bloecke (Breitenrahmen und PixelsPerInch), und
     * stripEmptyContactRows() faende seine RT_*-Marker nicht mehr.
     */
    private function visitComment(DOMComment $comment): void
    {
        $text = (string) $comment->nodeValue;
        $path = $this->pathOf($comment);

        if (str_contains($text, '--')) {
            $this->violation('comment.malformed', 'Kommentar mit doppeltem Bindestrich entfernt.', $path);
            $comment->parentNode?->removeChild($comment);

            return;
        }

        if (preg_match(self::CONTACT_MARKER_PATTERN, $text) === 1) {
            return;
        }

        if (stripos($text, '[if') !== false || stripos($text, '[endif') !== false) {
            $this->visitConditionalComment($comment, $text, $path);

            return;
        }

        // Gewoehnlicher Kommentar: reiner Text, kein Markup. Damit sind die
        // Erlaeuterungen in den Mastern zulaessig, eingeschleustes Markup
        // ist es nicht.
        if (str_contains($text, '<')) {
            $this->violation('comment.markup', 'Kommentar mit Markup entfernt.', $path);
            $comment->parentNode?->removeChild($comment);
        }
    }

    private function visitConditionalComment(DOMComment $comment, string $text, string $path): void
    {
        // Nur die "downlevel-hidden"-Form. Die "downlevel-revealed"-Form
        // <!--[if !mso]><!--> bricht aus dem Kommentar aus und ist der
        // klassische Einschleusweg; sie scheitert an diesem Muster.
        if (preg_match(self::MSO_COMMENT_PATTERN, $text, $match) !== 1) {
            $this->violation('comment.conditional', 'Bedingter Kommentar in unzulaessiger Form entfernt.', $path);
            $comment->parentNode?->removeChild($comment);

            return;
        }

        [, $condition, $body] = $match;

        if (preg_match(self::MSO_CONDITION_PATTERN, trim($condition)) !== 1) {
            $this->violation('comment.conditional', "Bedingung \"{$condition}\" ist nicht zugelassen.", $path);
            $comment->parentNode?->removeChild($comment);

            return;
        }

        // Tokenpruefung statt DOM-Nachparsen: der schliessende Block besteht
        // ausschliesslich aus Endtags (email-master.html:90). Ein HTML-Parser
        // liefert dafuer ein leeres Ergebnis und wuerde ihn loeschen.
        if (! $this->isSafeMsoCommentBody($body)) {
            $this->violation(
                'comment.conditional',
                'Bedingter Outlook-Kommentar enthaelt nicht erlaubtes Markup, Attribute, CSS oder Ressourcen und wurde entfernt.',
                $path,
            );
            $comment->parentNode?->removeChild($comment);
        }
    }

    /**
     * Outlook fuehrt den Kommentarinhalt als echtes Markup aus. Ein blosses
     * Suchen nach Teilzeichenketten reicht deshalb nicht: src, href, style
     * und unbekannte Attribute waeren sonst ein ungepruefter Kanal.
     *
     * Das Fragment wird absichtlich tokenweise geprueft. Die beiden
     * Tabellen-Kommentare der echten Vorlage sind jeweils unausgeglichen
     * (einer oeffnet, der andere schliesst), weshalb DOMDocument den
     * schliessenden Teil allein verwerfen wuerde.
     */
    private function isSafeMsoCommentBody(string $body): bool
    {
        if (strlen($body) > 100_000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body) === 1) {
            return false;
        }

        $tokens = self::msoCommentTokens($body);
        if ($tokens === null) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! str_starts_with($token, '<')) {
                continue;
            }

            if (preg_match('/^<\s*\/\s*([a-z][a-z0-9:_-]*)\s*>$/i', $token, $match) === 1) {
                if (! in_array(strtolower($match[1]), self::ALLOWED_MSO_COMMENT_TAGS, true)) {
                    return false;
                }

                continue;
            }

            $inside = trim(substr($token, 1, -1));
            if (str_ends_with($inside, '/')) {
                $inside = rtrim(substr($inside, 0, -1));
            }

            if (preg_match('/^([a-z][a-z0-9:_-]*)(.*)$/is', $inside, $match) !== 1) {
                return false;
            }

            $tag = strtolower($match[1]);
            if (! in_array($tag, self::ALLOWED_MSO_COMMENT_TAGS, true)) {
                return false;
            }

            $attributes = self::parseMsoCommentAttributes($match[2]);
            if ($attributes === null) {
                return false;
            }

            foreach ($attributes as $attribute => $value) {
                if (! $this->isSafeMsoCommentAttribute($tag, $attribute, $value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<string>|null */
    private static function msoCommentTokens(string $body): ?array
    {
        $tokens = [];
        $length = strlen($body);
        $offset = 0;

        while ($offset < $length) {
            if ($body[$offset] !== '<') {
                $next = strpos($body, '<', $offset);
                $end = $next === false ? $length : $next;
                $tokens[] = substr($body, $offset, $end - $offset);
                $offset = $end;

                continue;
            }

            $quote = null;
            $end = null;

            for ($index = $offset + 1; $index < $length; $index++) {
                $character = $body[$index];

                if ($quote !== null) {
                    if ($character === $quote) {
                        $quote = null;
                    }

                    continue;
                }

                if ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '<') {
                    return null;
                } elseif ($character === '>') {
                    $end = $index;

                    break;
                }
            }

            if ($end === null || $quote !== null) {
                return null;
            }

            $tokens[] = substr($body, $offset, $end - $offset + 1);
            $offset = $end + 1;
        }

        return $tokens;
    }

    /** @return array<string, string>|null */
    private static function parseMsoCommentAttributes(string $source): ?array
    {
        $attributes = [];
        $length = strlen($source);
        $offset = 0;

        while ($offset < $length) {
            while ($offset < $length && preg_match('/\s/', $source[$offset]) === 1) {
                $offset++;
            }

            if ($offset >= $length) {
                break;
            }

            if (preg_match('/\G([a-z_:][a-z0-9:._-]*)/i', $source, $match, 0, $offset) !== 1) {
                return null;
            }

            $attribute = strtolower($match[1]);
            if (array_key_exists($attribute, $attributes)) {
                return null;
            }

            $offset += strlen($match[0]);
            while ($offset < $length && preg_match('/\s/', $source[$offset]) === 1) {
                $offset++;
            }

            if ($offset >= $length || $source[$offset] !== '=') {
                return null;
            }

            $offset++;
            while ($offset < $length && preg_match('/\s/', $source[$offset]) === 1) {
                $offset++;
            }

            if ($offset >= $length) {
                return null;
            }

            $quote = $source[$offset];
            if ($quote === '"' || $quote === "'") {
                $offset++;
                $end = strpos($source, $quote, $offset);
                if ($end === false) {
                    return null;
                }

                $value = substr($source, $offset, $end - $offset);
                $offset = $end + 1;
            } else {
                $remaining = substr($source, $offset);
                if (preg_match('/^([^\s"\'=<>`]+)/', $remaining, $match) !== 1) {
                    return null;
                }

                $value = $match[1];
                $offset += strlen($match[0]);
            }

            $attributes[$attribute] = $value;
        }

        return $attributes;
    }

    private function isSafeMsoCommentAttribute(string $tag, string $attribute, string $value): bool
    {
        if (str_starts_with($attribute, 'on') && strlen($attribute) > 2) {
            return false;
        }

        $allowed = in_array($attribute, self::GLOBAL_ATTRIBUTES, true)
            || in_array($attribute, self::ALLOWED_TAGS[$tag] ?? [], true)
            || in_array($attribute, self::ALLOWED_MSO_COMMENT_ATTRIBUTES[$tag] ?? [], true);

        if (! $allowed) {
            return false;
        }

        $decoded = self::decodeMsoCommentAttribute($value);
        if (preg_match('/[<>`\x00-\x1F\x7F]/', $decoded) === 1) {
            return false;
        }

        if ($attribute === 'style') {
            if (str_contains($decoded, '\\')) {
                return false;
            }

            [$filtered, $findings] = $this->filterDeclarationList($decoded, 'mso-comment');

            return $findings === [] && $filtered === $decoded;
        }

        if ($attribute === 'class') {
            return preg_match('/^[A-Za-z0-9_\-\s{}\.]+$/', $decoded) === 1;
        }

        if ($attribute === 'href' || $attribute === 'src') {
            return $this->isSafeMsoCommentUrl($attribute, $decoded);
        }

        if (str_starts_with($attribute, 'xmlns:')) {
            return in_array($decoded, [
                'urn:schemas-microsoft-com:vml',
                'urn:schemas-microsoft-com:office:office',
                'urn:schemas-microsoft-com:office:word',
            ], true);
        }

        return $this->isAllowedAttributeValue($tag, $attribute, $decoded);
    }

    private function isSafeMsoCommentUrl(string $attribute, string $value): bool
    {
        $url = trim($value);
        if ($url === '') {
            return true;
        }

        if (hash_equals(self::PREVIEW_TRANSPARENT_PIXEL, $url)) {
            return false;
        }

        if ($this->containsPlaceholder($url)
            && ! $this->isAllowedTokenizedUrl($attribute, $url)) {
            return false;
        }

        $scheme = self::schemeOf($url);
        if ($scheme === 'protocol-relative') {
            return false;
        }

        if ($attribute === 'href') {
            return $scheme === null
                || in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
        }

        if ($scheme === null || $scheme === 'cid') {
            return true;
        }

        if ($scheme === 'data') {
            return self::isAllowedDataUri($url);
        }

        return in_array($scheme, ['http', 'https'], true) && $this->isOwnHost($url);
    }

    private static function decodeMsoCommentAttribute(string $value): string
    {
        for ($pass = 0; $pass < 3; $pass++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        return $value;
    }

    // -----------------------------------------------------------------
    // CSS
    // -----------------------------------------------------------------

    private function applyStyleAttribute(DOMElement $element, string $css, string $path): void
    {
        [$filtered, $findings] = $this->filterDeclarationList(
            $css,
            $path,
            $this->allowsAbsoluteTrainPosition($element),
        );

        if ($findings === []) {
            return;
        }

        $this->findings = [...$this->findings, ...$findings];

        if (trim($filtered) === '') {
            $element->removeAttribute('style');

            return;
        }

        $element->setAttribute('style', $filtered);
    }

    /**
     * Ein <style>-Block ist eine Rohtext-Insel: er wird geprueft, nicht neu
     * gebaut. Wer seinen Inhalt durch eine eigene Escaping-Stufe schickt,
     * macht aus "tr.rt-stack > td" ein "tr.rt-stack &gt; td" und die
     * Stapelregel greift nicht mehr.
     */
    private function checkStyleElement(DOMElement $element, string $path): void
    {
        $css = $element->textContent;

        if (trim($css) === '') {
            return;
        }

        [$filtered, $findings] = $this->filterStyleSheet($css, $path);

        if ($findings === []) {
            return;
        }

        $this->findings = [...$this->findings, ...$findings];
        $element->textContent = $filtered;
    }

    /**
     * Deklarationsliste filtern (Inhalt eines style-Attributs oder eines
     * Regelrumpfs).
     *
     * Es wird ausschliesslich ENTFERNT, nie neu zusammengesetzt: faellt
     * nichts weg, kommt die Zeichenkette unveraendert zurueck. Nur so
     * ueberleben Formatierung, Data-URIs und Platzhalter.
     *
     * @return array{0: string, 1: list<array{code: string, severity: string, message: string, context: string}>}
     */
    private function filterDeclarationList(string $css, string $path, bool $allowAbsolutePosition = false): array
    {
        $mask = self::maskPlaceholders($css);
        $findings = [];
        $cuts = [];

        foreach (self::splitDeclarations($mask, 0, strlen($mask)) as [$start, $end, $cutEnd]) {
            $colon = self::topLevelColon($mask, $start, $end);

            // Ohne Doppelpunkt ist es keine Deklaration — etwa der blanke
            // Platzhalter {{RESPONSIVE_CSS}}. Unangetastet lassen.
            if ($colon === null) {
                continue;
            }

            $property = strtolower(trim(substr($css, $start, $colon - $start)));
            $value = trim(substr($css, $colon + 1, $end - $colon - 1));

            $verdict = $this->judgeDeclaration($property, $value, $allowAbsolutePosition);

            if ($verdict === null) {
                continue;
            }

            $findings[] = EmailHtmlReport::finding($verdict[0], $verdict[1], $path);
            $cuts[] = [$start, $cutEnd];
        }

        foreach (array_reverse($cuts) as [$start, $cutEnd]) {
            $css = substr_replace($css, '', $start, $cutEnd - $start);
        }

        return [$css, $findings];
    }

    /**
     * Kompletten Stilbogen filtern: At-Regeln auf oberster Ebene und die
     * Deklarationen in den innersten Bloecken.
     *
     * @return array{0: string, 1: list<array{code: string, severity: string, message: string, context: string}>}
     */
    private function filterStyleSheet(string $css, string $path): array
    {
        $mask = self::maskPlaceholders($css);
        $findings = [];
        $cuts = [];

        // scanAtRules liefert seit der Verschachtelungskorrektur AUCH innen
        // liegende At-Regeln. Die Schnitte werden weiter unten mit
        // substr_replace von hinten nach vorne angewandt — ein Schnitt, der
        // vollstaendig in einem anderen liegt, wuerde dabei zweimal greifen
        // und die Zeichenkette zerreissen. Deshalb nur die aeusserste
        // beanstandete Regel schneiden; ihr Rumpf faellt ohnehin mit weg.
        $beanstandet = [];

        foreach (self::scanAtRules($mask) as [$start, $end, $name]) {
            if (in_array($name, self::ALLOWED_AT_RULES, true)) {
                continue;
            }

            $beanstandet[] = [$start, $end, $name];
        }

        foreach ($beanstandet as [$start, $end, $name]) {
            $liegtInnerhalb = false;

            foreach ($beanstandet as [$aussenStart, $aussenEnde]) {
                if ($aussenStart < $start && $end <= $aussenEnde) {
                    $liegtInnerhalb = true;

                    break;
                }
            }

            $findings[] = EmailHtmlReport::finding(
                'css.at-rule',
                "At-Regel @{$name} ist in E-Mails nicht erlaubt und wurde entfernt.",
                $path,
            );

            if (! $liegtInnerhalb) {
                $cuts[] = [$start, $end];
            }
        }

        foreach (self::scanDeclarationBlocks($mask) as [$blockStart, $blockEnd]) {
            if (self::overlapsAny($blockStart, $blockEnd, $cuts)) {
                continue;
            }

            [$filtered, $blockFindings] = $this->filterDeclarationList(
                substr($css, $blockStart, $blockEnd - $blockStart),
                $path,
            );

            if ($blockFindings === []) {
                continue;
            }

            $findings = [...$findings, ...$blockFindings];
            $cuts[] = [$blockStart, $blockEnd, $filtered];
        }

        usort($cuts, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($cuts as $cut) {
            $css = substr_replace($css, $cut[2] ?? '', $cut[0], $cut[1] - $cut[0]);
        }

        return [$css, $findings];
    }

    /**
     * @return array{0: string, 1: string}|null [Code, Meldung] bei Verstoss
     */
    private function judgeDeclaration(string $property, string $value, bool $allowAbsolutePosition = false): ?array
    {
        if ($property === '') {
            return null;
        }

        if (in_array($property, self::FORBIDDEN_CSS_PROPERTIES, true)) {
            return ['css.property.forbidden', "CSS-Eigenschaft {$property} ist in E-Mails verboten und wurde entfernt."];
        }

        if (! $this->isAllowedProperty($property)) {
            return ['css.property.unknown', "CSS-Eigenschaft {$property} steht nicht auf der Freigabeliste und wurde entfernt."];
        }

        $probe = strtolower(preg_replace('/[\s\x00-\x1f\x7f]+/', '', $value) ?? $value);

        foreach (self::FORBIDDEN_VALUE_PATTERNS as $pattern) {
            if (str_contains($probe, $pattern)) {
                return ['css.value.forbidden', "Wert von {$property} enthaelt \"{$pattern}\" und wurde entfernt."];
            }
        }

        foreach (self::extractUrls($value) as $url) {
            $reason = $this->judgeCssUrl($url);

            if ($reason !== null) {
                return ['css.url', "url() in {$property}: {$reason}"];
            }
        }

        foreach ($this->placeholderNames($value) as $placeholder) {
            if (! in_array($placeholder, [
                ...self::CSS_VALUE_PLACEHOLDERS,
                ...self::IMAGE_URL_PLACEHOLDERS,
                ...self::CID_PLACEHOLDERS,
            ], true)) {
                return ['css.placeholder', "Platzhalter {{$placeholder}} ist kein freigegebener CSS- oder Bildwert und wurde entfernt."];
            }
        }

        // Platzhalter stehen fuer Palettenwerte, die erst spaeter eingesetzt
        // werden. Ein strenger Wertevergleich wuerde sie hier verwerfen.
        $wholePlaceholder = $this->wholePlaceholderName($value);
        if ($wholePlaceholder !== null
            && in_array($wholePlaceholder, self::CSS_VALUE_PLACEHOLDERS, true)) {
            return null;
        }

        $enum = self::ALLOWED_CSS_VALUES[$property] ?? null;

        if ($enum !== null) {
            $bare = strtolower(trim(str_ireplace('!important', '', $value)));

            if ($property === 'position' && $allowAbsolutePosition && $bare === 'absolute') {
                return null;
            }

            if (! in_array($bare, $enum, true)) {
                return ['css.value.forbidden', "{$property}:{$bare} ist in E-Mails nicht zulaessig und wurde entfernt."];
            }
        }

        return null;
    }

    /**
     * position:absolute bleibt global verboten. Die einzige Ausnahme sind
     * die beiden serverkontrollierten Teile des kanonischen Zug-Layers; der
     * Signaturvertrag prueft Anzahl, Verschachtelung und Quelle nochmals.
     */
    private function allowsAbsoluteTrainPosition(DOMElement $element): bool
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tag = strtolower($element->tagName);
        $layer = $tag === 'div' ? $element : $element->parentNode;
        if (! $layer instanceof DOMElement
            || strtolower($layer->tagName) !== 'div'
            || ! $layer->hasAttribute('data-rt-layer-train')
            || (preg_split('/\s+/', trim($layer->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: []) !== ['rt-sign-train-layer']) {
            return false;
        }

        $stage = $layer->parentNode;
        $carrier = $stage instanceof DOMElement
            && strtolower($stage->tagName) === 'div'
            && (preg_split('/\s+/', trim($stage->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: []) === ['rt-sign-stage']
                ? $stage->parentNode
                : $stage;
        if (! $carrier instanceof DOMElement
            || strtolower($carrier->tagName) !== 'td'
            || ! in_array('rt-sign-cell', preg_split('/\s+/', trim($carrier->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [], true)) {
            return false;
        }

        $images = [];
        foreach ($layer->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $images[] = $child;
            }
        }
        $image = $images[0] ?? null;
        if (count($images) !== 1
            || ! $image instanceof DOMElement
            || strtolower($image->tagName) !== 'img'
            || ! $image->hasAttribute('data-rt-train')
            || (preg_split('/\s+/', trim($image->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: []) !== ['rt-sign-train']
            || trim($image->getAttribute('src')) !== '{{TRAIN_SRC}}') {
            return false;
        }

        return $element->isSameNode($layer) || $element->isSameNode($image);
    }

    private function isAllowedProperty(string $property): bool
    {
        // mso-* pauschal: Outlook kennt Dutzende davon, alle sind in jedem
        // anderen Client wirkungslos. Eine feste Liste liefe der
        // Wirklichkeit hinterher und wuerde still Darstellung zerstoeren.
        return str_starts_with($property, 'mso-')
            || in_array($property, self::ALLOWED_CSS_PROPERTIES, true);
    }

    private function judgeCssUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (hash_equals(self::PREVIEW_TRANSPARENT_PIXEL, $url)) {
            return 'der transparente Editor-Vorschaupixel ist nicht auslieferbar.';
        }

        if ($this->containsPlaceholder($url)) {
            if ($this->isAllowedTokenizedUrl('src', $url)) {
                return null;
            }

            return 'Nur freigegebene Bildplatzhalter sind in url() als vollstaendiger Wert oder hinter cid: zulaessig.';
        }

        $scheme = self::schemeOf($url);

        if ($scheme === null) {
            return null; // relativer Pfad — im Outlook-Paket der Normalfall
        }

        // In einer E-Mail gibt es kein Seitenschema, das ein "//" erben
        // koennte. Der Client ergaenzt https und laedt vom fremden Host —
        // deshalb hier immer verboten, auch fuer den eigenen Host.
        if ($scheme === 'protocol-relative') {
            return 'protokollrelative Adressen (//) sind nicht zulaessig.';
        }

        if ($scheme === 'data') {
            return self::isAllowedDataUri($url) ? null : 'nur eingebettete Bilder sind zulaessig.';
        }

        if ($scheme === 'cid') {
            return null;
        }

        if ($scheme === 'http' || $scheme === 'https') {
            return $this->isOwnHost($url)
                ? null
                : 'externe Adressen sind nicht zulaessig.';
        }

        return "Schema {$scheme}: ist nicht zulaessig.";
    }

    // -----------------------------------------------------------------
    // Verweise
    // -----------------------------------------------------------------

    /** @return bool  false, wenn das Attribut entfernt werden muss */
    private function checkUrlAttribute(string $attribute, string $value, string $path): bool
    {
        $url = trim($value);

        if ($url === '') {
            return true;
        }

        if (hash_equals(self::PREVIEW_TRANSPARENT_PIXEL, $url)) {
            $this->violation(
                $attribute === 'href' ? 'href.preview' : 'src.preview',
                'Der transparente Editor-Vorschaupixel wurde entfernt.',
                $path,
            );

            return false;
        }

        if ($this->containsPlaceholder($url)) {
            if (! $this->isAllowedTokenizedUrl($attribute, $url)) {
                $this->violation(
                    $attribute === 'href' ? 'href.placeholder' : 'src.placeholder',
                    "Adresse \"{$url}\" kombiniert einen Platzhalter mit nicht festgelegtem Schema oder Pfad und wurde entfernt.",
                    $path,
                );

                return false;
            }

            return true;
        }

        $scheme = self::schemeOf($url);

        // Protokollrelativ zuerst: ohne diesen Zweig meldete href faelschlich
        // "fuehrt ins Leere", obwohl der Verweis sehr wohl irgendwo hinfuehrt.
        if ($scheme === 'protocol-relative') {
            $this->violation(
                $attribute === 'href' ? 'href.scheme' : 'src.external',
                "Protokollrelative Adresse \"{$url}\" entfernt — in einer E-Mail gibt es kein Seitenschema, das \"//\" erben koennte.",
                $path,
            );

            return false;
        }

        if ($attribute === 'href') {
            if ($scheme === null) {
                if (! str_starts_with($url, '#')) {
                    $this->notice('href.relative', "Relativer Verweis \"{$url}\" fuehrt aus einer E-Mail heraus ins Leere.", $path);
                }

                return true;
            }

            if (in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
                return true;
            }

            $this->violation('href.scheme', "Verweis mit Schema {$scheme}: entfernt.", $path);

            return false;
        }

        // src: data:image/*, cid: und relative Pfade. Externe Bilder bleiben
        // draussen — sie verraten beim Oeffnen die IP des Empfaengers.
        if ($scheme === null || $scheme === 'cid') {
            return true;
        }

        if ($scheme === 'data') {
            if (self::isAllowedDataUri($url)) {
                return true;
            }

            $this->violation('src.data', 'Eingebettete Quelle ist kein zulaessiges Bild und wurde entfernt.', $path);

            return false;
        }

        if (($scheme === 'http' || $scheme === 'https') && $this->isOwnHost($url)) {
            return true;
        }

        $this->violation('src.external', "Externe Bildquelle \"{$url}\" entfernt.", $path);

        return false;
    }

    private function isOwnHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && in_array($host, $this->allowedHosts, true);
    }

    private static function isAllowedDataUri(string $url): bool
    {
        if (preg_match('#^data:\s*(image/(?:png|gif|jpe?g|webp))\s*;\s*base64\s*,([A-Za-z0-9+/]*={0,2})$#i', $url, $match) !== 1) {
            return false;
        }

        $declaredMime = strtolower($match[1]);
        if (! in_array($declaredMime, self::ALLOWED_DATA_URI_MIME, true)
            || strlen($match[2]) % 4 !== 0) {
            return false;
        }

        $binary = base64_decode($match[2], true);
        if (! is_string($binary)
            || $binary === ''
            || strlen($binary) > self::MAX_DATA_URI_BYTES) {
            return false;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $image = getimagesizefromstring($binary);
        } finally {
            restore_error_handler();
        }

        if (! is_array($image)) {
            return false;
        }

        $actualMime = strtolower((string) ($image['mime'] ?? ''));
        $declaredMime = $declaredMime === 'image/jpg' ? 'image/jpeg' : $declaredMime;
        $width = (int) ($image[0] ?? 0);
        $height = (int) ($image[1] ?? 0);

        return $actualMime === $declaredMime
            && $width > 0
            && $height > 0
            && $width <= self::MAX_DATA_URI_DIMENSION
            && $height <= self::MAX_DATA_URI_DIMENSION
            && $width * $height <= self::MAX_DATA_URI_PIXELS;
    }

    private static function schemeOf(string $url): ?string
    {
        // Steuerzeichen und Leerraum raus: "java\nscript:" ist derselbe
        // Verweis, sobald ein Client ihn normalisiert.
        $probe = preg_replace('/[\s\x00-\x1f\x7f]+/', '', $url) ?? $url;

        // Protokollrelativ (//host/pfad) ist KEIN relativer Pfad, auch wenn
        // kein Schema davorsteht: jeder Client ergaenzt das Schema der
        // umgebenden Seite und laedt von einem fremden Host. Ohne diesen Fall
        // liefe die gesamte Pruefung auf externe Ressourcen ins Leere — ein
        // Zaehlpixel als <img src="//fremd.example/p.png"> kam unbeanstandet
        // durch und haette beim Oeffnen die Adresse des Empfaengers verraten.
        if (str_starts_with($probe, '//')) {
            return 'protocol-relative';
        }

        return preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $match) === 1
            ? strtolower($match[1])
            : null;
    }

    // -----------------------------------------------------------------
    // CSS-Zerlegung
    // -----------------------------------------------------------------

    /**
     * Platzhalter durch gleich lange, harmlose Buchstaben ersetzen.
     *
     * Die Maske dient nur der Strukturerkennung: {{RESPONSIVE_CSS}} wuerde
     * sonst die Klammerzaehlung verschieben. Werte werden immer aus dem
     * ORIGINAL gelesen, damit Platzhalter erkennbar bleiben.
     */
    private static function maskPlaceholders(string $css): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static fn (array $match): string => str_repeat('a', strlen($match[0])),
            $css,
        );
    }

    private function containsPlaceholder(string $value): bool
    {
        return preg_match(self::PLACEHOLDER_PATTERN, $value) === 1;
    }

    private function isWholePlaceholder(string $value): bool
    {
        return preg_match(self::WHOLE_PLACEHOLDER_PATTERN, $value) === 1;
    }

    private function wholePlaceholderName(string $value): ?string
    {
        return preg_match(self::WHOLE_PLACEHOLDER_PATTERN, $value, $match) === 1
            ? strtoupper($match[1])
            : null;
    }

    /** @return list<string> */
    private function placeholderNames(string $value): array
    {
        if (preg_match_all(self::PLACEHOLDER_PATTERN, $value, $matches) < 1) {
            return [];
        }

        return array_values(array_unique(array_map('strtoupper', $matches[1])));
    }

    /**
     * Teil-Platzhalter in URLs sind nur hinter einem feststehenden, sicheren
     * Schema erlaubt. Dadurch kann die Einsetzung das Schema nicht nachtraeglich
     * zu javascript: oder eine lokale Quelle zu einem fremden Host machen.
     */
    private function isAllowedTokenizedUrl(string $attribute, string $url): bool
    {
        $wholePlaceholder = $this->wholePlaceholderName($url);
        if ($wholePlaceholder !== null) {
            return in_array(
                $wholePlaceholder,
                $attribute === 'href'
                    ? self::HREF_URL_PLACEHOLDERS
                    : self::IMAGE_URL_PLACEHOLDERS,
                true,
            );
        }

        if ($attribute === 'href'
            && preg_match(self::FIXED_HREF_PLACEHOLDER_PATTERN, $url, $match) === 1) {
            $allowed = strtolower($match[1]) === 'mailto'
                ? self::MAILTO_PLACEHOLDERS
                : self::TEL_PLACEHOLDERS;

            return in_array(strtoupper($match[2]), $allowed, true);
        }

        return $attribute === 'src'
            && preg_match(self::FIXED_CID_PLACEHOLDER_PATTERN, $url, $match) === 1
            && in_array(strtoupper($match[1]), self::CID_PLACEHOLDERS, true);
    }

    /**
     * Deklarationen einer Liste finden.
     *
     * Ein blosses explode(';') waere falsch: Data-URIs enthalten Semikolon
     * ("data:image/png;base64,..."), und ein solcher Schnitt zerlegt die
     * halbe Signatur.
     *
     * @return list<array{0: int, 1: int, 2: int}> [Anfang, Ende, Ende inkl. Semikolon]
     */
    private static function splitDeclarations(string $mask, int $from, int $to): array
    {
        $spans = [];
        $start = $from;
        $quote = null;
        $paren = 0;

        for ($i = $from; $i < $to; $i++) {
            $char = $mask[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '(') {
                $paren++;

                continue;
            }

            if ($char === ')') {
                $paren = max(0, $paren - 1);

                continue;
            }

            if ($char === ';' && $paren === 0) {
                $spans[] = [$start, $i, $i + 1];
                $start = $i + 1;
            }
        }

        if ($start < $to && trim(substr($mask, $start, $to - $start)) !== '') {
            $spans[] = [$start, $to, $to];
        }

        return $spans;
    }

    /** Doppelpunkt ausserhalb von Zeichenketten und Klammern. */
    private static function topLevelColon(string $mask, int $from, int $to): ?int
    {
        $quote = null;
        $paren = 0;

        for ($i = $from; $i < $to; $i++) {
            $char = $mask[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $paren++;
            } elseif ($char === ')') {
                $paren = max(0, $paren - 1);
            } elseif ($char === ':' && $paren === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Innerste Regelruempfe finden — nur dort stehen Deklarationen.
     * "@media { .x { ... } }" liefert damit den Rumpf von .x, nicht den
     * der Medienabfrage.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function scanDeclarationBlocks(string $mask): array
    {
        $blocks = [];
        $stack = [];
        $quote = null;
        $paren = 0;
        $length = strlen($mask);

        for ($i = 0; $i < $length; $i++) {
            $char = $mask[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '(') {
                $paren++;

                continue;
            }

            if ($char === ')') {
                $paren = max(0, $paren - 1);

                continue;
            }

            if ($paren > 0) {
                continue;
            }

            if ($char === '{') {
                if ($stack !== []) {
                    $stack[count($stack) - 1]['nested'] = true;
                }

                $stack[] = ['start' => $i + 1, 'nested' => false];

                continue;
            }

            if ($char === '}' && $stack !== []) {
                $frame = array_pop($stack);

                if (! $frame['nested']) {
                    $blocks[] = [$frame['start'], $i];
                }
            }
        }

        return $blocks;
    }

    /**
     * At-Regeln auf oberster Ebene samt ihrer vollen Ausdehnung.
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private static function scanAtRules(string $mask): array
    {
        $rules = [];
        $offset = 0;

        while (preg_match('/@([a-z-]+)/i', $mask, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $name = strtolower($match[1][0]);
            $end = self::atRuleEnd($mask, $start + strlen($match[0][0]));

            // JEDE Ebene melden, nicht nur die oberste.
            //
            // Vorher wurde alles mit depthAt() > 0 uebersprungen. Damit kam
            // "@media screen{@import url(http://fremd/x.css);}" zeichengleich
            // durch — und ein nachgeladener Stilbogen wird nie geprueft. Die
            // ganze Eigenschaften-Allowlist waere damit optional gewesen:
            // position:fixed, display:flex und transform kaemen ueber den
            // Import zurueck.
            $rules[] = [$start, $end, $name];

            // Nicht hinter die At-Regel springen, sondern hinein — sonst
            // bleiben verschachtelte At-Regeln erneut unentdeckt.
            $offset = $start + strlen($match[0][0]);
        }

        return $rules;
    }

    private static function depthAt(string $mask, int $position): int
    {
        $depth = 0;
        $quote = null;

        for ($i = 0; $i < $position; $i++) {
            $char = $mask[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth = max(0, $depth - 1);
            }
        }

        return $depth;
    }

    /** Ende einer At-Regel: entweder das naechste ';' oder der Block dahinter. */
    private static function atRuleEnd(string $mask, int $from): int
    {
        $length = strlen($mask);
        $quote = null;
        $depth = 0;

        for ($i = $from; $i < $length; $i++) {
            $char = $mask[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '{') {
                $depth++;

                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth <= 0) {
                    return $i + 1;
                }

                continue;
            }

            if ($char === ';' && $depth === 0) {
                return $i + 1;
            }
        }

        return $length;
    }

    /**
     * @param  list<array{0: int, 1: int, 2?: string}>  $cuts
     */
    private static function overlapsAny(int $start, int $end, array $cuts): bool
    {
        foreach ($cuts as $cut) {
            if ($start < $cut[1] && $cut[0] < $end) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function extractUrls(string $value): array
    {
        // Negierte Zeichenklasse statt ".*?": ein Data-URI kann 50 kB gross
        // sein, und Rueckverfolgung ueber diese Laenge ist teuer.
        //
        // Die schliessende Klammer ist ABSICHTLICH optional. Verlangte man
        // sie, liesse ein unbeendetes "url(http://fremd.example/x" gar keinen
        // Treffer entstehen — die Adresse waere nie geprueft worden, obwohl
        // Browser und Mailclients sie bis zum Zeilenende lesen.
        preg_match_all('/url\(\s*([\'"]?)([^\'")]*)\1\s*\)?/i', $value, $matches);

        return array_values(array_filter(array_map('trim', $matches[2] ?? [])));
    }

    // -----------------------------------------------------------------
    // Kleinkram
    // -----------------------------------------------------------------

    private function violation(string $code, string $message, string $context = ''): void
    {
        $this->findings[] = EmailHtmlReport::finding($code, $message, $context);
    }

    private function notice(string $code, string $message, string $context = ''): void
    {
        $this->findings[] = EmailHtmlReport::finding(
            $code,
            $message,
            $context,
            EmailHtmlReport::SEVERITY_NOTICE,
        );
    }

    /** Kurzer Pfad zur Fundstelle, damit eine Meldung auffindbar bleibt. */
    private function pathOf(DOMNode $node): string
    {
        $parts = [];

        for ($current = $node; $current !== null; $current = $current->parentNode) {
            if (! $current instanceof DOMElement) {
                continue;
            }

            array_unshift($parts, strtolower($current->nodeName));

            if (count($parts) >= 4) {
                break;
            }
        }

        return implode(' > ', $parts);
    }

    /** @return list<string> */
    private static function configuredHosts(): array
    {
        if (! function_exists('config')) {
            return [];
        }

        $hosts = [];

        foreach (['app.url', 'app.asset_url'] as $key) {
            try {
                $value = config($key);
            } catch (\Throwable) {
                // Ohne laufende Anwendung gibt es keinen eigenen Host —
                // dann bleibt jede externe Adresse draussen.
                continue;
            }

            if (! is_string($value) || $value === '') {
                continue;
            }

            $host = parse_url($value, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return $hosts;
    }
}
