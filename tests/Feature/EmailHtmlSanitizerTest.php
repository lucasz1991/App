<?php

namespace Tests\Feature;

use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlReport;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\MailSignature;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/**
 * Haertung fuer E-Mail-HTML.
 *
 * Die Pruefung hat zwei Seiten, die gleich wichtig sind: kein verbotenes
 * Konstrukt darf durchkommen, und kein erlaubtes darf verlorengehen. Der
 * zweite Teil ist der schwerere — deshalb laufen die drei echten Master
 * und die echte gerenderte Signatur hier durch den Sanitizer.
 */
class EmailHtmlSanitizerTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const MASTERS = [
        'email-master.html',
        'signature-light-master.html',
        'signature-dark-master.html',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    private function sanitizer(): EmailHtmlSanitizer
    {
        return new EmailHtmlSanitizer(['www.rail-time.de', 'rail-time.de']);
    }

    private function masterPath(string $file): string
    {
        return resource_path('mail-templates/'.$file);
    }

    // -----------------------------------------------------------------
    // Der eigentliche Beweis: die echten Vorlagen kommen durch
    // -----------------------------------------------------------------

    public function test_die_drei_echten_master_kommen_zeichengleich_durch(): void
    {
        foreach (self::MASTERS as $file) {
            $original = file_get_contents($this->masterPath($file));
            $this->assertIsString($original, "{$file} nicht lesbar");

            $report = $this->sanitizer()->clean($original);

            $this->assertSame(
                [],
                $report->findings,
                "{$file} wurde beanstandet: ".implode(' | ', $report->messages())
                    ."\nDann ist die Freigabeliste falsch, nicht die Vorlage.",
            );

            $this->assertSame(
                $original,
                $report->html,
                "{$file} wurde veraendert, obwohl es nichts zu beanstanden gab.",
            );
        }
    }

    public function test_die_echten_master_ueberstehen_auch_den_strengen_betrieb(): void
    {
        foreach (self::MASTERS as $file) {
            $original = file_get_contents($this->masterPath($file));

            $report = $this->sanitizer()->assertClean($original);

            $this->assertTrue($report->isClean(), "{$file} wurde im strengen Betrieb beanstandet.");
        }
    }

    /**
     * Die Master enthalten nur Rahmen und Platzhalter. Der Inhalt, an dem
     * sich ein Sanitizer wirklich verschluckt — Data-URIs, verschachtelte
     * Tabellen, RT_-Marker, position:relative — steckt in der gerenderten
     * Signatur.
     */
    public function test_die_echte_gerenderte_signatur_kommt_zeichengleich_durch(): void
    {
        $signature = MailSignature::forCompany()->render();

        // Die firmenweite Signatur ist der Weg der VERSENDETEN Mail und
        // VERLINKT ihre Bilder seit dem Umbau. Eingebettet erschienen sie
        // bei vielen Empfaengern nie: Outlook-Desktop kennt keine data:-URIs,
        // Gmail entfernt sie in Hintergrundangaben und kappt Nachrichten ab
        // 102 kB — allein die Bilder waren 104,6 kB.
        //
        // Wichtig fuer diesen Test: der Sanitizer laesst eigene Hosts zu
        // (isOwnHost), die verlinkte Fassung muss also unveraendert
        // durchkommen. Genau das wird unten geprueft.
        $this->assertStringContainsString('mail-assets/', $signature);
        $this->assertStringNotContainsString('data:image', $signature);
        $this->assertStringContainsString('position:relative', $signature);

        $report = $this->sanitizer()->clean($signature);

        $this->assertSame(
            [],
            $report->findings,
            'Signaturblock beanstandet: '.implode(' | ', $report->messages()),
        );
        $this->assertSame($signature, $report->html);
    }

    /**
     * Der Signaturblock ist ein Fragment, das mit <tr> beginnt — die
     * umgebende <table> stellt der Aufrufer. Ohne LIBXML_HTML_NOIMPLIED
     * baut libxml eine Tabelle darum oder wirft die Zeilen weg.
     */
    public function test_das_signaturfragment_bleibt_ein_fragment(): void
    {
        $signature = MailSignature::forCompany()->render();

        $this->assertStringStartsWith('<tr', $signature);

        $html = $this->sanitizer()->clean($signature)->html;

        $this->assertStringStartsWith('<tr', $html);
        $this->assertStringNotContainsString('<table><tr', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('<body', $html);
    }

    public function test_die_echten_umbruchregeln_ueberstehen_den_stilbogen(): void
    {
        $css = EmailTemplateBuilder::responsiveCss('#e6e8ec');

        $this->assertStringContainsString('@media', $css);
        $this->assertStringContainsString('tr.rt-stack > td', $css);

        $document = "<!doctype html>\n<html lang=\"de\">\n<head>\n<style>\n{$css}\n</style>\n</head>\n<body><p>x</p></body>\n</html>";

        $report = $this->sanitizer()->clean($document);

        $this->assertSame([], $report->findings, implode(' | ', $report->messages()));
        $this->assertSame($document, $report->html);
    }

    /**
     * Der realistische Schadensfall: in einer echten Vorlage steckt EIN
     * Verstoss. Damit entfaellt die Durchreichung und das ganze Dokument
     * laeuft durch saveHTML(). Danach muss alles Tragende noch stehen.
     */
    public function test_ein_verstoss_in_der_echten_vorlage_laesst_alles_andere_stehen(): void
    {
        $original = file_get_contents($this->masterPath('email-master.html'));
        $angefasst = str_replace('<body ', '<body onload="alert(1)" ', $original);

        $this->assertNotSame($original, $angefasst);

        $report = $this->sanitizer()->clean($angefasst);

        $this->assertTrue($report->has('attribute.event'));
        $this->assertStringNotContainsString('onload', $report->html);

        // Dokumenttypangabe bleibt kleingeschrieben wie in der Quelle.
        $this->assertStringStartsWith('<!doctype html>', $report->html);

        // Platzhalter — auch die in href und src, die saveHTML() sonst
        // URL-kodiert.
        foreach ([
            '{{RESPONSIVE_CSS}}', '{{SIGNATURE_BLOCK}}', '{{CTA_URL}}', '{{CTA_TEXT}}',
            '{{PAGE_BG}}', '{{SURFACE_BG}}', '{{THEME}}', '{{COLOR_SCHEME}}', '{{NACHRICHT}}',
        ] as $platzhalter) {
            $this->assertStringContainsString($platzhalter, $report->html, "verloren: {$platzhalter}");
        }
        $this->assertStringNotContainsString('%7B', $report->html);

        // Outlook-Vorkehrungen.
        $this->assertStringContainsString('<!--[if mso]><xml><o:OfficeDocumentSettings>', $report->html);
        $this->assertStringContainsString('<!--[if mso]></td></tr></table><![endif]-->', $report->html);
        $this->assertStringContainsString('mso-table-lspace: 0pt !important', $report->html);
        $this->assertStringContainsString('mso-hide:all', $report->html);

        // Die Umbruchklassen — ohne sie gibt es kein mobiles Layout.
        foreach (['rt-shell', 'rt-pad', 'rt-title', 'rt-stack', 'rt-card-cell'] as $klasse) {
            $this->assertStringContainsString($klasse, $report->html, "Klasse verloren: {$klasse}");
        }

        // Umlaute duerfen zu Entities werden, verschwinden duerfen sie nicht.
        $this->assertStringContainsString(
            'PERSÖNLICHE',
            html_entity_decode($report->html, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    /**
     * Der Kindselektor ist die Stapelregel. Wird der Inhalt eines
     * geaenderten Stilbogens durch eine Escaping-Stufe geschickt, wird aus
     * "tr.rt-stack > td" ein "tr.rt-stack &gt; td" und die Regel greift
     * nicht mehr.
     */
    public function test_kindselektor_ueberlebt_einen_geaenderten_stilbogen(): void
    {
        $report = $this->sanitizer()->clean(
            '<style>@media only screen and (max-width: 860px) {'
            .'tr.rt-stack > td { display: block !important; float: left; }'
            .'}</style>'
        );

        $this->assertTrue($report->has('css.property.forbidden'));
        $this->assertStringContainsString('tr.rt-stack > td', $report->html);
        $this->assertStringNotContainsString('&gt;', $report->html);
        $this->assertStringContainsString('display: block !important', $report->html);
        $this->assertStringNotContainsString('float', $report->html);
        $this->assertStringContainsString('@media only screen and (max-width: 860px)', $report->html);
    }

    // -----------------------------------------------------------------
    // Outlook: die empfindlichsten Stellen
    // -----------------------------------------------------------------

    /**
     * DOMDocument neigt dazu, Kommentare und ungewoehnliche Konstrukte zu
     * verschlucken. Geprueft wird deshalb nicht nur der saubere Weg,
     * sondern auch der serialisierende: ein Verstoss im selben Dokument
     * erzwingt die Neuausgabe, und danach muessen die bedingten Bloecke
     * immer noch dastehen.
     */
    public function test_bedingte_outlook_kommentare_bleiben_erhalten(): void
    {
        $opening = '<!--[if mso]><table role="presentation" width="100%"><tr><td><![endif]-->';
        $closing = '<!--[if mso]></td></tr></table><![endif]-->';
        $settings = '<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->';

        $clean = '<div>'.$settings.$opening.'<p>Inhalt</p>'.$closing.'</div>';

        $report = $this->sanitizer()->clean($clean);
        $this->assertSame([], $report->findings, implode(' | ', $report->messages()));
        $this->assertSame($clean, $report->html);

        // Derselbe Inhalt mit einem Verstoss: jetzt wird serialisiert.
        $dirty = '<div><script>alert(1)</script>'.$settings.$opening.'<p>Inhalt</p>'.$closing.'</div>';

        $report = $this->sanitizer()->clean($dirty);

        $this->assertTrue($report->has('element.forbidden'));
        $this->assertStringNotContainsString('<script', $report->html);
        $this->assertStringContainsString($settings, $report->html);
        $this->assertStringContainsString($opening, $report->html);
        $this->assertStringContainsString($closing, $report->html);
    }

    /**
     * Der schliessende bedingte Kommentar besteht ausschliesslich aus
     * Endtags. Wer seinen Inhalt zur Pruefung durch einen HTML-Parser
     * schickt, bekommt ein leeres Ergebnis und loescht ihn — Outlook
     * bekaeme dann ein offenes Tabellenpaar.
     */
    public function test_der_schliessende_bedingte_kommentar_ueberlebt_die_pruefung(): void
    {
        $html = '<div><!--[if mso]></td></tr></table><![endif]--></div>';

        $report = $this->sanitizer()->clean($html);

        $this->assertSame([], $report->findings);
        $this->assertStringContainsString('</td></tr></table><![endif]', $report->html);
    }

    /**
     * Outlook interpretiert den Kommentarinhalt als echtes Markup. Die
     * Attribute darin muessen deshalb denselben Schutz wie normales HTML
     * erhalten; eine reine Tag-Namensliste wuerde all diese Kanaele erlauben.
     */
    public function test_bedingte_outlook_kommentare_pruefen_attribute_urls_und_css(): void
    {
        foreach ([
            '<!--[if mso]><img src="https://tracker.example/pixel.png" width="1" height="1"><![endif]-->',
            '<!--[if mso]><v:fill src="//tracker.example/background.png"></v:fill><![endif]-->',
            '<!--[if mso]><a href="&#106;avascript:alert(1)">Link</a><![endif]-->',
            '<!--[if mso]><td style="background:url(//tracker.example/bg.png)">Text</td><![endif]-->',
            '<!--[if mso]><td style="behavior:url(x.htc)">Text</td><![endif]-->',
            '<!--[if mso]><table formaction="https://tracker.example"><tr><td>Text</td></tr></table><![endif]-->',
            '<!--[if mso]><img src="cid:logo" src="https://tracker.example/zweite.png"><![endif]-->',
            '<!--[if mso]><a href="javascript:{{CTA_URL}}">Link</a><![endif]-->',
            '<!--[if mso]><img src="https://tracker.example/{{LOGO_SRC}}" alt=""><![endif]-->',
            '<!--[if mso]><td style="background:url(https://tracker.example/{{TRAIN_SRC}})">Text</td><![endif]-->',
            '<!--[if mso]><td style="background:URL(\\68 TTP://tracker.example/p.gif)">Text</td><![endif]-->',
            '<!--[if mso]><td style="background:url(ht/**/tps://tracker.example/p.gif)">Text</td><![endif]-->',
        ] as $html) {
            $report = $this->sanitizer()->clean('<div>'.$html.'</div>');

            $this->assertTrue($report->has('comment.conditional'), "nicht beanstandet: {$html}");
            $this->assertStringNotContainsString('[if', $report->html, "Kommentar blieb stehen: {$html}");
        }
    }

    public function test_erlaubte_outlook_attribute_und_lokale_quellen_bleiben_erhalten(): void
    {
        $html = '<div><!--[if mso]><table role="presentation" width="100%" style="width:100%;mso-table-lspace:0pt;">'
            .'<tr><td><img src="cid:railtime-logo" alt="RailTime" width="240" style="display:block;width:240px;">'
            .'</td></tr></table><![endif]--></div>';

        $report = $this->sanitizer()->clean($html);

        $this->assertSame([], $report->findings, implode(' | ', $report->messages()));
        $this->assertSame($html, $report->html);
    }

    /**
     * Eine Pruefung gegen "gueltige CSS-Eigenschaften" wirft die
     * mso-Angaben still weg. Folge: der Vorschautext wird in Outlook
     * sichtbar, Symbolzeilen werden hoeher und jede Tabelle bekommt einen
     * 0,75-pt-Steg. In keinem Browser faellt das auf.
     */
    public function test_mso_eigenschaften_bleiben_erhalten(): void
    {
        $html = '<div style="display:none;mso-hide:all;">Vorschau</div>'
            .'<table style="mso-table-lspace:0pt;mso-table-rspace:0pt;"><tr>'
            .'<td style="font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td>'
            .'</tr></table>'
            .'<style>table, td { mso-table-lspace: 0pt !important; mso-table-rspace: 0pt !important; }</style>';

        $report = $this->sanitizer()->clean($html);

        $this->assertSame([], $report->findings, implode(' | ', $report->messages()));
        $this->assertSame($html, $report->html);
    }

    /** position:relative hebt die Personenspalte ueber das Zug-Hintergrundbild. */
    public function test_position_relative_und_z_index_bleiben_absolute_faellt_weg(): void
    {
        $report = $this->sanitizer()->clean(
            '<td style="direction:ltr;position:relative;z-index:1;text-align:left;">x</td>'
        );

        $this->assertSame([], $report->findings);
        $this->assertStringContainsString('position:relative', $report->html);
        $this->assertStringContainsString('z-index:1', $report->html);

        foreach (['absolute', 'fixed', 'sticky'] as $value) {
            $report = $this->sanitizer()->clean('<td style="color:#000;position:'.$value.';">x</td>');

            $this->assertTrue($report->has('css.value.forbidden'), "position:{$value} kam durch");
            $this->assertStringNotContainsString('position:'.$value, $report->html);
            $this->assertStringContainsString('color:#000', $report->html);
        }
    }

    // -----------------------------------------------------------------
    // Platzhalter
    // -----------------------------------------------------------------

    /**
     * saveHTML() kodiert geschweifte Klammern in href und src zu
     * %7B%7B...%7D%7D. Danach findet EmailTemplateBuilder::substitute()
     * den Schluessel nicht mehr. Geprueft wird deshalb der serialisierende
     * Weg — im sauberen Fall reicht die Vorlage ohnehin unveraendert durch.
     */
    public function test_platzhalter_ueberstehen_auch_den_serialisierenden_weg(): void
    {
        $html = '<div><script>alert(1)</script>'
            .'<a href="{{CTA_URL}}" style="color:{{TEXT_PRIMARY}};">{{CTA_TEXT}}</a>'
            .'<img src="{{LOGO_SRC}}" alt="{{FIRMENNAME}}" width="240">'
            .'<td bgcolor="{{PAGE_BG}}" style="background:{{SURFACE_BG}};">{{NACHRICHT}}</td>'
            .'</div>';

        $report = $this->sanitizer()->clean($html);

        $this->assertTrue($report->has('element.forbidden'));
        $this->assertStringNotContainsString('%7B', $report->html);

        foreach ([
            '{{CTA_URL}}', '{{CTA_TEXT}}', '{{TEXT_PRIMARY}}', '{{LOGO_SRC}}',
            '{{FIRMENNAME}}', '{{PAGE_BG}}', '{{SURFACE_BG}}', '{{NACHRICHT}}',
        ] as $placeholder) {
            $this->assertStringContainsString($placeholder, $report->html);
        }
    }

    /**
     * Der blanke Platzhalter im Stilbogen steht an Regelstelle, nicht an
     * Deklarationsstelle. Ein Filter, der ihn als kaputte Deklaration
     * liest, entfernt saemtliche Umbruchregeln.
     */
    public function test_platzhalter_im_stilbogen_bleibt_stehen(): void
    {
        $html = "<style>\n    a { color: inherit; }\n    {{RESPONSIVE_CSS}}\n  </style>";

        $report = $this->sanitizer()->clean($html);

        $this->assertSame([], $report->findings);
        $this->assertSame($html, $report->html);
    }

    public function test_url_platzhalter_bilden_den_ganzen_wert_oder_folgen_einem_festen_schema(): void
    {
        $html = '<a href="{{CTA_URL}}">CTA</a>'
            .'<a href="mailto:{{E_MAIL}}">Mail</a>'
            .'<a href="tel:{{DURCHWAHL_TEL}}">Telefon</a>'
            .'<img src="{{LOGO_SRC}}" alt="">'
            .'<img src="cid:{{LOGO_CID}}" alt="">'
            .'<td style="background-image:url(\'{{TRAIN_SRC}}\');color:{{TEXT_PRIMARY}};">Text</td>';

        $report = $this->sanitizer()->clean($html);

        $this->assertSame([], $report->findings, implode(' | ', $report->messages()));
        $this->assertSame($html, $report->html);
    }

    public function test_url_platzhalter_duerfen_schema_host_oder_pfad_nicht_verbergen(): void
    {
        $html = '<a href="javascript:{{CTA_URL}}">A</a>'
            .'<a href="{{SCHEME}}:alert(1)">B</a>'
            .'<img src="https://tracker.example/{{LOGO_SRC}}" alt="">'
            .'<td style="background-image:url(https://tracker.example/{{TRAIN_SRC}});">Text</td>';

        $report = $this->sanitizer()->clean($html);

        $this->assertTrue($report->has('href.placeholder'));
        $this->assertTrue($report->has('src.placeholder'));
        $this->assertTrue($report->has('css.url'));
        $this->assertStringNotContainsString('javascript:', $report->html);
        $this->assertStringNotContainsString('tracker.example', $report->html);
        $this->assertStringNotContainsString('{{SCHEME}}', $report->html);
    }

    public function test_profilplatzhalter_duerfen_nie_zu_links_bildquellen_oder_css_werden(): void
    {
        $html = '<a href="{{POSITION}}">Profil-Link</a>'
            .'<img src="{{VORNAME_NACHNAME}}" alt="Profil-Bild">'
            .'<p style="color:{{POSITION}};border-color:{{VORNAME_NACHNAME}};'
            .'background-image:url({{POSITION}});">Inline</p>'
            .'<style>.profil{color:{{POSITION}};background-image:url({{VORNAME_NACHNAME}});}</style>';

        $report = $this->sanitizer()->clean($html);

        $this->assertTrue($report->has('href.placeholder'));
        $this->assertTrue($report->has('src.placeholder'));
        $this->assertTrue($report->has('css.placeholder'));
        $this->assertTrue($report->has('css.url'));
        $this->assertStringNotContainsString('href="{{POSITION}}"', $report->html);
        $this->assertStringNotContainsString('src="{{VORNAME_NACHNAME}}"', $report->html);
        $this->assertStringNotContainsString('color:{{POSITION}}', $report->html);
        $this->assertStringNotContainsString('url({{VORNAME_NACHNAME}})', $report->html);
    }

    public function test_profilplatzhalter_werden_auch_im_mso_kanal_abgelehnt(): void
    {
        foreach ([
            '<!--[if mso]><img src="{{POSITION}}" alt=""><![endif]-->',
            '<!--[if mso]><a href="{{VORNAME_NACHNAME}}">Profil</a><![endif]-->',
            '<!--[if mso]><table><tr><td style="background-image:url({{POSITION}});">Profil</td></tr></table><![endif]-->',
            '<!--[if mso]><table><tr><td style="color:{{VORNAME_NACHNAME}};">Profil</td></tr></table><![endif]-->',
        ] as $html) {
            $report = $this->sanitizer()->clean($html);

            $this->assertTrue($report->has('comment.conditional'), $html);
            $this->assertSame('', trim($report->html), $html);
        }
    }

    public function test_reine_editorattribute_und_der_transparente_preview_pixel_werden_entfernt(): void
    {
        $pixel = EmailHtmlSanitizer::PREVIEW_TRANSPARENT_PIXEL;
        $html = '<div data-rt-mail-preview-only="signature">Vorschau</div>'
            .'<img data-rt-mail-preview-token="LOGO_SRC" src="'.$pixel.'" alt="">'
            .'<table><tr><td style="background-image:url(\''.$pixel.'\');">Zug</td></tr></table>';

        $report = $this->sanitizer()->clean($html);

        $this->assertTrue($report->has('attribute.editor'));
        $this->assertTrue($report->has('src.preview'));
        $this->assertTrue($report->has('css.url'));
        $this->assertStringNotContainsString('data-rt-mail-', $report->html);
        $this->assertStringNotContainsString($pixel, $report->html);

        $mso = $this->sanitizer()->clean(
            '<!--[if mso]><img src="'.$pixel.'" alt=""><![endif]-->',
        );
        $this->assertTrue($mso->has('comment.conditional'));
        $this->assertSame('', trim($mso->html));
    }

    // -----------------------------------------------------------------
    // Was draussen bleiben muss
    // -----------------------------------------------------------------

    #[DataProvider('verboteneElemente')]
    public function test_verbotene_elemente_werden_entfernt(string $html, string $spur): void
    {
        $report = $this->sanitizer()->clean('<div>Vorher'.$html.'Nachher</div>');

        $this->assertTrue($report->has('element.forbidden'), "nicht beanstandet: {$html}");
        $this->assertStringNotContainsString($spur, $report->html);
        $this->assertStringContainsString('Vorher', $report->html);
        $this->assertStringContainsString('Nachher', $report->html);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function verboteneElemente(): array
    {
        return [
            'script' => ['<script>alert(1)</script>', 'alert(1)'],
            'iframe' => ['<iframe src="https://fremd.test"></iframe>', 'iframe'],
            'form' => ['<form action="https://fremd.test"><input name="a"></form>', 'form'],
            'object' => ['<object data="a.swf"></object>', 'object'],
            'embed' => ['<embed src="a.swf">', 'embed'],
            'link' => ['<link rel="stylesheet" href="https://fremd.test/a.css">', 'stylesheet'],
            'base' => ['<base href="https://fremd.test/">', 'base'],
            'svg' => ['<svg><image href="x"></svg>', '<svg'],
            'textarea' => ['<textarea>x</textarea>', 'textarea'],
            'noscript' => ['<noscript>x</noscript>', 'noscript'],
        ];
    }

    /**
     * libxml2 parst nach HTML 4 und kennt embed/source/track nicht als
     * leere Elemente — alles Nachfolgende wird dort zum Inhalt. Faellt das
     * Element samt Inhalt, reisst ein einzelnes <embed> den Rest der Mail
     * mit.
     */
    public function test_nachfolgender_inhalt_ueberlebt_ein_verbotenes_leeres_element(): void
    {
        foreach (['embed src="a.swf"', 'source src="a.mp4"', 'track src="a.vtt"'] as $tag) {
            $report = $this->sanitizer()->clean("<div>Vorher<{$tag}><p>Nachher</p></div>");

            $this->assertTrue($report->has('element.forbidden'), "nicht beanstandet: {$tag}");
            $this->assertStringNotContainsString('a.', $report->html, "Quelle blieb stehen: {$tag}");
            $this->assertStringContainsString('Vorher', $report->html);
            $this->assertStringContainsString('<p>Nachher</p>', $report->html, "Inhalt verloren bei {$tag}");
        }
    }

    public function test_ereignis_attribute_und_javascript_verweise_fallen_weg(): void
    {
        $report = $this->sanitizer()->clean(
            '<div onclick="alert(1)" onmouseover="x()">'
            .'<a href="javascript:alert(2)">a</a>'
            .'<a href="vbscript:msgbox(1)">b</a>'
            .'<img src="data:text/html;base64,PHNjcmlwdD4=" alt="">'
            .'</div>'
        );

        $this->assertStringNotContainsString('onclick', $report->html);
        $this->assertStringNotContainsString('onmouseover', $report->html);
        $this->assertStringNotContainsString('javascript:', $report->html);
        $this->assertStringNotContainsString('vbscript:', $report->html);
        $this->assertStringNotContainsString('data:text/html', $report->html);

        $this->assertSame(2, $report->countOf('attribute.event'));
        $this->assertSame(2, $report->countOf('href.scheme'));
        $this->assertTrue($report->has('src.data'));
    }

    /**
     * Der klassische Einschleusweg: die "downlevel-revealed"-Form bricht
     * aus dem Kommentar aus. Im Bestand kommt sie nicht vor.
     */
    public function test_aufgedeckte_bedingte_kommentare_und_fremdes_vokabular_fallen_weg(): void
    {
        foreach ([
            '<div><!--[if !mso]><!--><script>alert(1)</script><!--<![endif]--></div>',
            '<div><!--[if mso]><script>alert(1)</script><![endif]--></div>',
            '<div><!--[if mso]><iframe src="https://fremd.test"></iframe><![endif]--></div>',
            '<div><!--[if lt IE 9]><p onclick="x()">y</p><![endif]--></div>',
        ] as $html) {
            $report = $this->sanitizer()->clean($html);

            $this->assertTrue($report->hasViolations(), "nicht beanstandet: {$html}");
            $this->assertStringNotContainsString('[if', $report->html, "Kommentar blieb stehen: {$html}");
        }
    }

    public function test_layouteigenschaften_moderner_seiten_fallen_weg(): void
    {
        $report = $this->sanitizer()->clean(
            '<div style="display:block;float:left;transform:rotate(3deg);'
            .'animation:pulse 2s;transition:all .3s;filter:blur(2px);cursor:pointer;">x</div>'
        );

        foreach (['float', 'transform', 'animation', 'transition', 'filter', 'cursor'] as $property) {
            $this->assertStringNotContainsString($property.':', $report->html, "{$property} kam durch");
        }

        $this->assertStringContainsString('display:block', $report->html);
        $this->assertSame(6, $report->countOf('css.property.forbidden'));
    }

    public function test_flex_und_grid_fallen_weg(): void
    {
        $report = $this->sanitizer()->clean(
            '<div style="display:table;flex-direction:column;grid-template-columns:1fr 1fr;gap:12px;'
            .'justify-content:center;align-items:center;">x</div>'
        );

        foreach (['flex-direction', 'grid-template-columns', 'gap', 'justify-content', 'align-items'] as $property) {
            $this->assertStringNotContainsString($property, $report->html, "{$property} kam durch");
        }

        $this->assertStringContainsString('display:table', $report->html);
    }

    public function test_externe_ressourcen_und_at_regeln_fallen_weg(): void
    {
        $report = $this->sanitizer()->clean(
            '<style>@import url("https://fremd.test/a.css");'
            .'@font-face { font-family: X; src: url(https://fremd.test/x.woff); }'
            .'.rt-pad { padding-left: 32px !important; }</style>'
            .'<div style="background-image:url(https://fremd.test/spion.gif);color:#111;">x</div>'
            .'<img src="https://fremd.test/zaehlpixel.gif" alt="">'
        );

        $this->assertStringNotContainsString('fremd.test', $report->html);
        $this->assertStringNotContainsString('@import', $report->html);
        $this->assertStringNotContainsString('@font-face', $report->html);

        // Was in Ordnung war, steht noch da.
        $this->assertStringContainsString('padding-left: 32px !important', $report->html);
        $this->assertStringContainsString('color:#111', $report->html);

        $this->assertTrue($report->has('css.at-rule'));
        $this->assertTrue($report->has('css.url'));
        $this->assertTrue($report->has('src.external'));
    }

    /**
     * Sammelprobe der Umgehungsversuche. Geprueft wird nicht die Meldung,
     * sondern das Ergebnis: nach dem Durchlauf darf keine der Spuren mehr
     * im Dokument stehen.
     *
     * @param  list<string>  $spuren
     */
    #[DataProvider('umgehungsversuche')]
    public function test_umgehungsversuche_kommen_nicht_durch(string $html, array $spuren): void
    {
        $report = $this->sanitizer()->clean($html);

        $this->assertTrue($report->hasViolations(), 'nicht beanstandet: '.$html);

        $flach = strtolower((string) preg_replace('/[\s\x00-\x1f]/', '', $report->html));

        foreach ($spuren as $spur) {
            $this->assertStringNotContainsString(
                strtolower(str_replace(' ', '', $spur)),
                $flach,
                "\"{$spur}\" kam durch: ".$report->html,
            );
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function umgehungsversuche(): array
    {
        return [
            // CSS-Escapes verstecken gern Schemata: \3a\2f\2f ist "://".
            'css-escape im schema' => [
                '<div style="background:url(\3a \2f \2f fremd.test/a.gif)">x</div>',
                ['fremd.test'],
            ],
            'css expression' => [
                '<div style="width:expression(alert(1));">x</div>',
                ['expression('],
            ],
            'css behavior' => [
                '<div style="behavior:url(#default#time2);">x</div>',
                ['behavior:'],
            ],
            // Zeilenumbruch mitten im Schema — Clients normalisieren ihn weg.
            'umbruch im schema' => [
                "<div style=\"background-image:url('java\nscript:alert(1)')\">x</div>",
                ['javascript:'],
            ],
            'at-regel im stilbogen' => [
                '<style>@import "https://fremd.test/a.css"; .rt-pad { padding: 4px; }</style>',
                ['@import', 'fremd.test'],
            ],
            'externe url im stilbogen' => [
                '<style>.rt-pad { background: url(https://fremd.test/p.gif); }</style>',
                ['fremd.test'],
            ],
            'ausbruch aus dem stilbogen' => [
                '<style>a{color:red}</style ><script>alert(1)</script>',
                ['<script'],
            ],
            // http-equiv faellt weg, content darf nicht als Rest bleiben.
            'meta refresh' => [
                '<meta http-equiv="refresh" content="0;url=https://fremd.test">',
                ['fremd.test', 'refresh'],
            ],
            'formularfelder ohne form' => [
                '<div><input type="text" name="pw"><button>Senden</button></div>',
                ['<input', '<button'],
            ],
            // SVG traegt Skript — als Data-URI genauso wie als Element.
            'data-uri mit svg' => [
                '<img src="data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pg==" alt="">',
                ['svg+xml'],
            ],
            'srcset als zweiter weg' => [
                '<img src="cid:railtime-logo" srcset="https://fremd.test/a.gif 1x" alt="">',
                ['srcset', 'fremd.test'],
            ],
            'background-attribut' => [
                '<td background="https://fremd.test/a.gif">x</td>',
                ['fremd.test'],
            ],
        ];
    }

    /**
     * Begradigt libxml das Markup, entspricht die Ausgabe nicht mehr der
     * Eingabe. Ein Bericht ohne einen einzigen Eintrag waere dann eine
     * falsche Auskunft.
     */
    public function test_begradigtes_markup_wird_gemeldet(): void
    {
        $report = $this->sanitizer()->clean('<div <script>alert(1)</script>>x</div>');

        $this->assertTrue($report->has('markup.normalised'));
        $this->assertStringNotContainsString('<script', $report->html);
        $this->assertNotSame('<div <script>alert(1)</script>>x</div>', $report->html);
    }

    public function test_eigene_hosts_duerfen_bilder_liefern(): void
    {
        $report = $this->sanitizer()->clean(
            '<img src="https://www.rail-time.de/logo.png" alt="">'
            .'<div style="background-image:url(https://rail-time.de/zug.gif);">x</div>'
        );

        $this->assertSame([], $report->findings, implode(' | ', $report->messages()));
    }

    /**
     * Logo, fuenf Kontaktsymbole und der Zug stecken als Base64 im Markup —
     * teils in src, teils in background-image innerhalb eines
     * linear-gradient(). Ein explode(';') ueber die Deklarationen zerlegte
     * die halbe Signatur, weil ein Data-URI selbst Semikolon enthaelt.
     */
    public function test_grosse_data_uris_ueberstehen_die_zerlegung(): void
    {
        $blob = EmailTemplateBuilder::inlineImage('zug-dampf-light.png', 'image/png');
        $this->assertGreaterThan(50000, strlen($blob));

        $html = '<td style="background:#f7f6f3;background-image:linear-gradient(rgba(12,16,23,.72),rgba(12,16,23,.72)),'
            ."url('{$blob}');background-repeat:no-repeat;background-position:center center,left bottom;\">"
            ."<img src=\"{$blob}\" width=\"22\" height=\"22\" alt=\"\"></td>";

        $report = $this->sanitizer()->clean($html);

        $this->assertSame([], $report->findings, implode(' | ', $report->messages()));
        $this->assertSame($html, $report->html);
    }

    public function test_css_url_escapes_und_kommentare_verbergen_keine_schemata(): void
    {
        foreach ([
            '<td style="background-image:URL(\\68 TTP://tracker.example/p.gif);">Inline</td>',
            '<style>.x{background:url(\\6a avascript:alert(1))}</style>',
            '<style>.x{background:url(ht/**/tps://tracker.example/p.gif)}</style>',
            '<td style="background:url(java/**/script:alert(1))">Inline</td>',
        ] as $html) {
            $report = $this->sanitizer()->clean($html);

            $this->assertTrue($report->hasViolations(), "nicht beanstandet: {$html}");
            $this->assertStringNotContainsString('tracker.example', $report->html, $html);
            $this->assertStringNotContainsString('javascript', strtolower($report->html), $html);
            $this->assertStringNotContainsString('avascript', strtolower($report->html), $html);
        }
    }

    public function test_data_uris_muessen_echte_begrenzte_bilder_sein(): void
    {
        $valid = EmailTemplateBuilder::inlineImage('logo-signature-light.png', 'image/png');
        $this->assertSame([], $this->sanitizer()->clean('<img src="'.$valid.'" alt="">')->findings);

        [, $encoded] = explode(',', $valid, 2);
        $binary = base64_decode($encoded, true);
        $this->assertIsString($binary);
        $oversized = substr_replace($binary, pack('N', 50000), 16, 4);

        foreach ([
            str_replace('data:image/png', 'data:image/gif', $valid),
            'data:image/png;base64,!!!!',
            'data:image/png,'.rawurlencode($binary),
            'data:image/png;base64,'.base64_encode($oversized),
        ] as $uri) {
            $report = $this->sanitizer()->clean('<img src="'.$uri.'" alt="">');

            $this->assertTrue($report->has('src.data'));
            $this->assertStringNotContainsString('src=', $report->html);
        }
    }

    /**
     * stripEmptyContactRows() schneidet leere Kontaktzeilen ausschliesslich
     * ueber diese Marker heraus. Die uebliche Regel "alle Kommentare
     * loeschen" laesst leere Durchwahl-, Mobil- und Website-Zeilen stehen.
     */
    public function test_die_kontaktmarker_ueberstehen_auch_die_neuausgabe(): void
    {
        $html = '<table><script>alert(1)</script>'
            .'<!-- RT_PHONE_START --><tr><td>Durchwahl</td></tr><!-- RT_PHONE_END -->'
            .'<!-- RT_MOBILE_START --><tr><td>Mobil</td></tr><!-- RT_MOBILE_END -->'
            .'<!-- RT_WEBSITE_START --><tr><td>Web</td></tr><!-- RT_WEBSITE_END -->'
            .'<!-- RT_COMPANY_PHONE_START --><tr><td>Firma</td></tr><!-- RT_COMPANY_PHONE_END -->'
            .'</table>';

        $report = $this->sanitizer()->clean($html);

        $this->assertTrue($report->has('element.forbidden'));

        foreach (['PHONE', 'MOBILE', 'WEBSITE', 'COMPANY_PHONE'] as $marker) {
            $this->assertStringContainsString("<!-- RT_{$marker}_START -->", $report->html);
            $this->assertStringContainsString("<!-- RT_{$marker}_END -->", $report->html);
        }

        // Und die Marker taugen danach noch fuer den Schnitt.
        $stripped = EmailTemplateBuilder::stripEmptyContactRows($report->html, [
            'DURCHWAHL' => '',
            'MOBIL' => '030 1234',
            'FIRMEN_WEBSITE_HREF' => 'https://www.rail-time.de',
            'FIRMEN_TELEFON' => '030 1234',
        ]);

        $this->assertStringNotContainsString('Durchwahl', $stripped);
        $this->assertStringContainsString('Mobil', $stripped);
        $this->assertStringNotContainsString('RT_MOBILE_START', $stripped);
    }

    public function test_unbekannte_elemente_werden_ausgepackt_der_inhalt_bleibt(): void
    {
        $report = $this->sanitizer()->clean('<div><section><p>Text</p></section></div>');

        $this->assertTrue($report->has('element.unknown'));
        $this->assertStringNotContainsString('<section', $report->html);
        $this->assertStringContainsString('<p>Text</p>', $report->html);
    }

    public function test_align_ist_auf_tabellen_strenger_als_auf_zellen(): void
    {
        // align="left" laesst eine Tabelle floaten, der Folgetext rutscht daneben.
        $report = $this->sanitizer()->clean('<table align="left"><tr><td align="left">x</td></tr></table>');

        $this->assertTrue($report->has('attribute.value'));
        $this->assertStringNotContainsString('<table align', $report->html);
        $this->assertStringContainsString('<td align="left"', $report->html);

        $report = $this->sanitizer()->clean('<table align="center"><tr><td align="right">x</td></tr></table>');

        $this->assertSame([], $report->findings);
    }

    // -----------------------------------------------------------------
    // Betriebsarten
    // -----------------------------------------------------------------

    public function test_strenger_betrieb_wirft_bei_einem_verstoss(): void
    {
        try {
            $this->sanitizer()->assertClean('<div><script>alert(1)</script></div>');
            $this->fail('Der strenge Betrieb haette werfen muessen.');
        } catch (ValidationException $exception) {
            $messages = $exception->errors()['html'] ?? [];

            $this->assertNotEmpty($messages);
            $this->assertStringContainsString('script', implode(' ', $messages));
        }
    }

    public function test_bereinigender_betrieb_wirft_nicht_sondern_protokolliert(): void
    {
        $report = $this->sanitizer()->clean('<div><script>alert(1)</script><p>Rest</p></div>');

        $this->assertFalse($report->isClean());
        $this->assertTrue($report->hasViolations());
        $this->assertCount(1, $report->violations());
        $this->assertStringContainsString('<p>Rest</p>', $report->html);
        $this->assertSame(EmailHtmlReport::SEVERITY_VIOLATION, $report->findings[0]['severity']);
    }

    /**
     * Hinweise haben nichts entfernt. Sie duerfen deshalb die
     * Veroeffentlichung nicht blockieren, muessen aber sichtbar sein.
     */
    public function test_hinweise_blockieren_den_strengen_betrieb_nicht(): void
    {
        $report = $this->sanitizer()->assertClean('<div class="eigene-klasse">x</div>');

        $this->assertFalse($report->isClean());
        $this->assertFalse($report->hasViolations());
        $this->assertTrue($report->has('class.unknown'));
        $this->assertSame(EmailHtmlReport::SEVERITY_NOTICE, $report->notices()[0]['severity']);
        $this->assertStringContainsString('eigene-klasse', $report->html);
    }

    public function test_der_bericht_laesst_sich_in_eine_oberflaeche_reichen(): void
    {
        $report = $this->sanitizer()->clean('<div onclick="x()">y</div>');

        $payload = $report->toArray();

        $this->assertFalse($payload['clean']);
        $this->assertSame($report->html, $payload['html']);
        $this->assertSame(['code', 'severity', 'message', 'context'], array_keys($payload['findings'][0]));
        $this->assertSame($payload, $report->jsonSerialize());
        $this->assertNotEmpty($report->messages()[0]);
    }

    public function test_leere_eingabe_bleibt_leer(): void
    {
        $report = $this->sanitizer()->clean('');

        $this->assertTrue($report->isClean());
        $this->assertSame('', $report->html);
    }

    // -----------------------------------------------------------------
    // Rueckfallschutz fuer zwei Loecher, die eine adversarische Pruefung
    // aufgedeckt hat. Beide kamen vorher unbeanstandet durch.
    // -----------------------------------------------------------------

    /**
     * Protokollrelative Adressen haben KEIN Schema und wurden deshalb wie
     * ein relativer Pfad behandelt. In einer E-Mail gibt es aber kein
     * Seitenschema zum Erben — der Client ergaenzt https und laedt vom
     * fremden Host. Ein Zaehlpixel kam so unbemerkt durch.
     */
    public function test_protokollrelative_adressen_kommen_nicht_durch(): void
    {
        foreach ([
            '<img src="//fremd.example/pixel.png" width="1" height="1">',
            '<img src=" //fremd.example/pixel.png">',
            '<a href="//fremd.example/phish">Klick</a>',
            '<td style="background:url(//fremd.example/bg.png)">x</td>',
            '<style>.a{background-image:url(//fremd.example/bg.png)}</style>',
        ] as $markup) {
            $report = $this->sanitizer()->clean($markup);

            $this->assertFalse($report->isClean(), $markup);
            $this->assertStringNotContainsString('//fremd.example', $report->html, $markup);
        }
    }

    /**
     * @import entkam der At-Regel-Sperre durch eine einzige Ebene
     * Verschachtelung. Das wiegt schwerer als eine externe Ressource: der
     * nachgeladene Stilbogen wird nie geprueft, damit waere die gesamte
     * Eigenschaften-Allowlist optional gewesen.
     */
    public function test_import_entkommt_auch_verschachtelt_nicht(): void
    {
        foreach ([
            '<style>@media screen{@import url(http://fremd.example/x.css);}</style>',
            '<style>@media all{@import "http://fremd.example/x.css";}</style>',
            '<style>@supports (color:red){@media screen{@import url(http://fremd.example/x.css);}}</style>',
        ] as $markup) {
            $report = $this->sanitizer()->clean($markup);

            $this->assertFalse($report->isClean(), $markup);
            $this->assertStringNotContainsString('@import', $report->html, $markup);
            $this->assertStringNotContainsString('fremd.example', $report->html, $markup);
        }
    }

    /**
     * Ein unbeendetes url( lieferte gar keinen Treffer — die Adresse wurde
     * deshalb nie geprueft, obwohl Clients sie bis zum Zeilenende lesen.
     */
    public function test_unbeendetes_url_wird_trotzdem_geprueft(): void
    {
        $report = $this->sanitizer()->clean('<td style="background:url(http://fremd.example/x.png">y</td>');

        $this->assertFalse($report->isClean());
        $this->assertStringNotContainsString('fremd.example', $report->html);
    }

    /**
     * Die Gegenprobe zu allen drei Korrekturen: erlaubte Verschachtelung und
     * erlaubte Adressen duerfen NICHT mit weggeraeumt werden.
     */
    public function test_erlaubte_verschachtelung_und_quellen_bleiben_unangetastet(): void
    {
        $report = $this->sanitizer()->clean(
            '<style>@media only screen and (max-width: 860px){.rt-pad{padding-left:24px !important}}</style>'
            .'<img src="cid:railtime-logo" width="240" alt="">'
            .'<a href="https://rail-time.de">Seite</a>'
            .'<a href="mailto:info@rail-time.de">Mail</a>'
        );

        $this->assertTrue($report->isClean(), implode(' | ', $report->messages()));
        $this->assertStringContainsString('@media only screen', $report->html);
        $this->assertStringContainsString('padding-left:24px', $report->html);
        $this->assertStringContainsString('cid:railtime-logo', $report->html);
        $this->assertStringContainsString('https://rail-time.de', $report->html);
    }
}
