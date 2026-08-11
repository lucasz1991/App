<?php

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\MailSignature;
use Database\Seeders\MailDocumentSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/**
 * Der Editor der beiden Maildokumente: Berechtigung, optimistische Sperre,
 * Haertung, Veroeffentlichen — und der Einhaengepunkt im Renderpfad.
 *
 * Die Messlatte steht in test_ohne_veroeffentlichte_fassung_bleibt_alles_wie_bisher:
 * ohne veroeffentlichtes Dokument darf sich am Ergebnis nichts aendern.
 */
class MailDocumentEditorTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        // Die Tabelle gehoert nicht zum Minimalschema — hier kommt sie aus
        // der echten Migration, damit Spalten und Test nicht auseinanderlaufen.
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();

        // LogActivity haengt an jeder schreibenden Anfrage.
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'Admin Beispiel']);
    }

    /**
     * Geseedete Dokumente ALS ENTWURF.
     *
     * Der Seeder selbst gibt frei — das ist seine Zusage fuer das
     * Deployment (siehe test_der_seeder_ueberschreibt_und_gibt_sofort_frei).
     * Die Faelle hier pruefen aber den Editor: sein Speichern, seine
     * Freigabe, seine Ablehnungen. Dafuer braucht es einen Ausgangszustand
     * OHNE Freigabe, sonst pruefte man gegen ein bereits fertiges Ergebnis.
     */
    private function seedDocuments(): void
    {
        (new MailDocumentSeeder)->run();

        MailDocument::query()->update([
            'status' => MailDocumentStatus::Draft,
            'published_html' => null,
            'published_css' => null,
            'published_at' => null,
        ]);

        app()->forgetScopedInstances();
    }

    /**
     * Die Zusage des Seeders fuer das Deployment: Er stellt den
     * ausgelieferten Stand her UND gibt ihn frei, damit unmittelbar danach
     * geprueft werden kann — ohne einen weiteren Handgriff im Editor.
     */
    public function test_der_seeder_ueberschreibt_und_gibt_sofort_frei(): void
    {
        (new MailDocumentSeeder)->run();

        foreach (MailDocumentKind::cases() as $kind) {
            $dokument = $this->document($kind);

            $this->assertSame(MailDocumentStatus::Published, $dokument->status, $kind->value);
            $this->assertNotNull($dokument->published_at, $kind->value);
            $this->assertSame(trim((string) $dokument->html), trim((string) $dokument->published_html), $kind->value);
            $this->assertNotNull(EmailTemplateBuilder::publishedDocument($kind), $kind->value);
        }

        // ZWEITER LAUF: Er ueberschreibt ohne Rueckfrage — auch Editor-Arbeit.
        // Genau dafuer ist der Aufruf am Ende eines Deployments gedacht.
        $signatur = $this->document(MailDocumentKind::Signature);
        $signatur->forceFill([
            'html' => '<tr><td>Von Hand geaendert</td></tr>',
            'version' => 7,
        ])->save();

        (new MailDocumentSeeder)->run();

        $frisch = $this->document(MailDocumentKind::Signature);
        $this->assertStringNotContainsString('Von Hand geaendert', (string) $frisch->html);
        $this->assertSame(MailDocumentStatus::Published, $frisch->status);
    }

    /**
     * Mit RT_MAIL_STARTER_KEEP=1 bleibt vorhandene Arbeit stehen — der Weg
     * fuer alle, die den ausgelieferten Stand NICHT wollen.
     */
    public function test_der_seeder_schont_vorhandene_arbeit_auf_ausdrueckliche_anweisung(): void
    {
        (new MailDocumentSeeder)->run();

        $signatur = $this->document(MailDocumentKind::Signature);
        $signatur->forceFill(['html' => '<tr><td>Von Hand geaendert</td></tr>', 'version' => 7])->save();

        putenv('RT_MAIL_STARTER_KEEP=1');

        try {
            (new MailDocumentSeeder)->run();
        } finally {
            putenv('RT_MAIL_STARTER_KEEP');
        }

        $this->assertStringContainsString(
            'Von Hand geaendert',
            (string) $this->document(MailDocumentKind::Signature)->html,
        );
    }

    private function document(MailDocumentKind $kind): MailDocument
    {
        return MailDocument::query()->where('kind', $kind->value)->firstOrFail();
    }

    public function test_ohne_veroeffentlichte_fassung_bleibt_alles_wie_bisher(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        UserProfile::create(['user_id' => $user->id, 'position' => 'Disposition']);

        $builder = new EmailTemplateBuilder($user->fresh());
        $withoutTable = $builder->build('vorlage-html')['content'];

        $this->seedDocuments();

        // Angelegt, aber nicht veroeffentlicht: der Rueckfall bleibt in Kraft.
        $this->assertNull(EmailTemplateBuilder::publishedDocument(MailDocumentKind::Template));
        $this->assertSame($withoutTable, (new EmailTemplateBuilder($user->fresh()))->build('vorlage-html')['content']);
    }

    public function test_veroeffentlichte_vorlage_wird_bevorzugt(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();

        $document = $this->document(MailDocumentKind::Template);
        $document->forceFill([
            'published_html' => str_replace(
                '{{SIGNATURE_BLOCK}}',
                '<tr><td>RT-EDITORFASSUNG {{VORNAME_NACHNAME}}</td></tr>',
                (string) $document->html,
            ),
            'published_css' => '',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();

        $html = (new EmailTemplateBuilder($user->fresh()))->build('vorlage-html')['content'];

        // Die veroeffentlichte Fassung ist Token-HTML: Profilwert UND Palette
        // werden weiterhin erst beim Rendern eingesetzt.
        $this->assertStringContainsString('RT-EDITORFASSUNG Mara Beispiel', $html);
        $this->assertStringContainsString(EmailTemplateBuilder::emailThemeValues('light')['SURFACE_BG'], $html);
        // Die Inhaltsplatzhalter der Vorlage ({{BETREFF}} …) bleiben stehen —
        // sie fuellt der Absender im Mailprogramm.
        $this->assertStringNotContainsString('{{SIGNATURE_BLOCK}}', $html);
        $this->assertStringNotContainsString('{{SURFACE_BG}}', $html);
    }

    public function test_veroeffentlichter_signaturblock_ersetzt_die_blade_quelle(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();

        $signature = $this->document(MailDocumentKind::Signature);
        $publishedHtml = str_replace(
            '{{VORNAME_NACHNAME}}',
            'RT-SIGNATUR {{VORNAME_NACHNAME}}',
            (string) $signature->html,
        );
        $signature->forceFill([
            'published_html' => $publishedHtml,
            'published_css' => '.rt-sign-name{letter-spacing:0;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();

        $builder = new EmailTemplateBuilder($user->fresh());

        $template = $builder->build('vorlage-html')['content'];
        $standalone = $builder->build('signatur-hell')['content'];
        $systemSignature = MailSignature::forCompany()->render();
        $systemMail = view('vendor.mail.html.layout', [
            'slot' => 'Systemmail-Test',
            'subcopy' => '',
            'footer' => '',
        ])->render();

        $this->assertStringContainsString('RT-SIGNATUR Mara Beispiel', $template);
        $this->assertStringContainsString('RT-SIGNATUR Mara Beispiel', $standalone);
        $this->assertStringContainsString('RT-SIGNATUR RT Rail Time GmbH', $systemSignature);

        // CSS steht in den echten Dokumentkoepfen, nicht im <tr>-Fragment.
        $this->assertStringContainsString('data-rt-mail-document-css="signature"', $template);
        $this->assertStringContainsString('.rt-sign-name{letter-spacing:0;}', $standalone);
        $this->assertStringNotContainsString('<style', $systemSignature);
        $this->assertLessThan(stripos($standalone, '</head>'), stripos($standalone, 'data-rt-mail-document-css="signature"'));

        // DIE SYSTEMMAIL BEKOMMT KEIN FREIGEGEBENES CSS. Ihre Fusszeile
        // rendert immer die Blade-Quelle, weil sie den Zug als Bildzeile
        // braucht (Begruendung in MailSignature::render). Das freigegebene
        // CSS ist gegen das freigegebene MARKUP geschrieben — eingebunden
        // laege es auf einem anderen Stand.
        $this->assertStringNotContainsString('.rt-sign-name{letter-spacing:0;}', $systemMail);
        $this->assertStringNotContainsString('RT-SIGNATUR', $systemMail);

        // Nur die bekannten Starterabstaende werden fuer den eigenstaendigen
        // Download auf den kompakten Vertrag abgebildet.
        $this->assertStringContainsString('padding:16px 28px 18px;', $standalone);
        $this->assertStringContainsString('padding:11px 28px;', $standalone);
        $this->assertStringNotContainsString('border-top:5px solid #e4002b;', $standalone);

        $outlookMethod = new \ReflectionMethod($builder, 'buildOutlookSignatureHtml');
        $outlookMethod->setAccessible(true);
        $outlook = $outlookMethod->invoke($builder, 'light', 'RailTime_files');
        $this->assertIsString($outlook);
        $this->assertStringNotContainsString('RT-SIGNATUR', $outlook);
        $this->assertStringContainsString('data-rt-outlook-train', $outlook);
    }

    public function test_systemmail_css_und_signatur_html_teilen_einen_request_lokalen_snapshot(): void
    {
        $this->seedDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $versionA = str_replace(
            '{{VORNAME_NACHNAME}}',
            'SNAPSHOT-A {{VORNAME_NACHNAME}}',
            (string) $signature->html,
        );
        $signature->forceFill([
            'published_html' => $versionA,
            'published_css' => '.snapshot-a{letter-spacing:0;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();

        // Das Layout liest zuerst CSS; der Footer erzeugt danach eine zweite
        // MailSignature-Instanz. Beide muessen trotzdem Version A sehen.
        $cssFromLayout = MailSignature::forCompany()->publishedCss();

        $versionB = str_replace('SNAPSHOT-A', 'SNAPSHOT-B', $versionA);
        $signature->forceFill([
            'published_html' => $versionB,
            'published_css' => '.snapshot-b{letter-spacing:1px;}',
            'published_at' => now()->addSecond(),
        ])->save();

        $htmlFromFooter = MailSignature::forCompany()->render();

        $this->assertStringContainsString('.snapshot-a{letter-spacing:0;}', $cssFromLayout);
        $this->assertStringContainsString('SNAPSHOT-A RT Rail Time GmbH', $htmlFromFooter);
        $this->assertStringNotContainsString('SNAPSHOT-B', $htmlFromFooter);

        // Laravel leert scoped Bindings zwischen HTTP-/Octane-Requests und
        // Queue-Jobs. Der naechste Scope liest deshalb atomar Version B.
        $this->app->forgetScopedInstances();

        $freshCss = MailSignature::forCompany()->publishedCss();
        $freshHtml = MailSignature::forCompany()->render();

        $this->assertStringContainsString('.snapshot-b{letter-spacing:1px;}', $freshCss);
        $this->assertStringContainsString('SNAPSHOT-B RT Rail Time GmbH', $freshHtml);
        $this->assertStringNotContainsString('SNAPSHOT-A', $freshHtml);
    }

    public function test_regulaerer_signaturdownload_respektiert_individuelle_editorabstaende(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $custom = strtr((string) $signature->html, [
            'padding:18px 36px 20px;' => 'padding:21px 41px 29px;',
            'border-top:5px solid #e4002b;' => 'border-top:7px solid #123456;',
            'padding:14px 36px;' => 'padding:19px 41px;',
        ]);
        $signature->forceFill([
            'published_html' => $custom,
            'published_css' => '',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();

        $html = (new EmailTemplateBuilder($user))->build('signatur-hell')['content'];

        $this->assertStringContainsString('padding:21px 41px 29px;', $html);
        $this->assertStringContainsString('border-top:7px solid #123456;', $html);
        $this->assertStringContainsString('padding:19px 41px;', $html);
    }

    public function test_editorseite_ist_administratoren_vorbehalten(): void
    {
        $this->seedDocuments();
        $admin = $this->admin();

        // auth:sanctum greift vor role:admin und schickt Gaeste zur
        // allgemeinen Anmeldung.
        $this->get(route('admin.mail-documents.editor'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('admin.mail-documents.editor'))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($admin)
            ->get(route('admin.mail-documents.editor'))
            ->assertOk()
            ->assertSee('pageBuilderOpen: false', escape: false)
            ->assertSee('data-mail-document-config', escape: false)
            ->assertSee('data-mail-document-root', escape: false)
            // Dokumentwahl, Vorschau und Freigabe teilen sich die feste
            // Studio-Werkzeugleiste oberhalb der scrollfreien Arbeitsflaeche.
            ->assertSee('data-mail-studio-toolbar', escape: false)
            ->assertSee('data-mail-toolbar-region="documents"', escape: false)
            ->assertSee('data-mail-toolbar-region="preview"', escape: false)
            ->assertSee('data-mail-toolbar-region="actions"', escape: false)
            ->assertSee('data-mail-document-status', escape: false)
            ->assertSee('data-mail-document-publish', escape: false)
            // OHNE escape: false — Blade escaped das Trennzeichen & der
            // Query im href zu &amp;. Die rohe URL steht so nie im Markup.
            ->assertSee(route('admin.mail-documents.editor', ['dokument' => 'signature', 'open' => 1]))
            ->assertSee('data-mail-document-back', escape: false)
            ->assertSee('data-mail-theme-button="light"', escape: false)
            ->assertSee('data-mail-theme-button="dark"', escape: false)
            ->assertSee('data-mail-preview-device="desktop"', escape: false)
            ->assertSee('data-mail-preview-device="tablet"', escape: false)
            ->assertSee('data-mail-preview-device="mobile"', escape: false)
            ->assertSee('data-mail-editor-frame', escape: false)
            ->assertSee('data-page-builder-preview-first', escape: false)
            ->assertSee('data-page-builder-assist', escape: false)
            ->assertSee('Mail- &amp; Signatur-Editor', escape: false);

        $this->actingAs($admin)
            ->get(route('admin.mail-documents.editor', ['open' => 1]))
            ->assertOk()
            ->assertSee('pageBuilderOpen: true', escape: false);
    }

    public function test_editorconfig_liefert_echte_vorschau_assets_ohne_die_dokumenttokens_zu_veraendern(): void
    {
        $this->seedDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $signature = $this->document(MailDocumentKind::Signature);
        $originalTemplateBuilderData = $template->builder_data;
        $originalSignatureHtml = (string) $signature->html;

        $response = $this->actingAs($this->admin())
            ->get(route('admin.mail-documents.editor'))
            ->assertOk();

        $this->assertSame(1, preg_match(
            '/<script[^>]*data-mail-document-config[^>]*>(.*?)<\/script>/s',
            (string) $response->getContent(),
            $match,
        ));
        $config = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

        foreach (['light.logo', 'dark.logo'] as $asset) {
            $this->assertStringStartsWith(
                'data:image/png;base64,',
                (string) data_get($config, 'previewAssets.'.$asset),
                $asset,
            );
        }

        foreach (['light.train', 'dark.train'] as $asset) {
            $this->assertStringStartsWith(
                'data:image/gif;base64,',
                (string) data_get($config, 'previewAssets.'.$asset),
                $asset,
            );
        }

        foreach (['location', 'phone', 'mobile', 'email', 'web'] as $icon) {
            $this->assertStringStartsWith(
                'data:image/png;base64,',
                (string) data_get($config, 'previewAssets.icons.'.$icon),
                $icon,
            );
        }

        $this->assertStringContainsString(
            '{{LOGO_SRC}}',
            (string) data_get($config, 'documents.signature.builderData.pages.0.component'),
        );
        $this->assertSame($originalTemplateBuilderData, $template->fresh()->builder_data);
        $this->assertSame($originalSignatureHtml, (string) $signature->fresh()->html);
    }

    public function test_editorseite_erklaert_fehlende_dokumente_statt_abzustuerzen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.mail-documents.editor'))
            ->assertOk()
            ->assertSee('noch nicht eingerichtet', escape: false)
            ->assertDontSee('data-page-builder-shell-toolbar', escape: false)
            ->assertDontSee('data-mail-document-root', escape: false);
    }

    public function test_speichern_verlangt_den_aktuellen_fingerabdruck(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        $this->actingAs($this->admin())
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => ['pages' => []],
                'html' => '<table><tr><td>Neu</td></tr></table>',
                'css' => '',
                'expected_hash' => str_repeat('a', 64),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_hash');

        $this->assertSame(1, $document->fresh()->version);
    }

    public function test_speichern_haertet_das_markup_und_meldet_die_beanstandung(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        $response = $this->actingAs($this->admin())
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => [
                    'pages' => [[
                        'name' => '  Eigene Nachrichtenschale  ',
                        'component' => '<script>ungepruefter Zweitspeicher</script>',
                    ]],
                    'styles' => [['selectors' => ['body'], 'style' => ['behavior' => 'url(x.htc)']]],
                    'railtime' => [
                        'document' => 'signature',
                        'schema' => 1,
                        'unsafe' => '<script>alert(3)</script>',
                    ],
                    'unsafe' => '<iframe src="https://example.test"></iframe>',
                ],
                'html' => '<table><tr><td onclick="alert(1)">Text</td></tr></table><script>alert(2)</script>',
                'css' => 'td { color:#111820; } body { behavior:url(x.htc); }',
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk();

        $saved = $document->fresh();

        $this->assertStringNotContainsString('<script', $saved->html);
        $this->assertStringNotContainsString('onclick', $saved->html);
        $this->assertStringContainsString('Text', $saved->html);
        $this->assertStringNotContainsString('behavior', (string) $saved->css);
        $this->assertStringContainsString('color:#111820', (string) $saved->css);
        $this->assertSame(2, $saved->version);
        $this->assertSame(['pages', 'styles', 'railtime'], array_keys($saved->builder_data));
        $this->assertSame('Eigene Nachrichtenschale', data_get($saved->builder_data, 'pages.0.name'));
        $this->assertSame($saved->html, data_get($saved->builder_data, 'pages.0.component'));
        $this->assertSame([], $saved->builder_data['styles']);
        $this->assertSame([
            'document' => MailDocumentKind::Template->value,
            'schema' => 1,
        ], $saved->builder_data['railtime']);

        // Der gespeicherte Fingerabdruck gehoert zum GEHAERTETEN Inhalt.
        $this->assertSame(
            MailDocument::contentHashFor($saved->builder_data, $saved->html, (string) $saved->css),
            $saved->content_hash,
        );
        $this->assertSame($saved->content_hash, $response->json('document.content_hash'));
        $this->assertSame($saved->builder_data, $response->json('document.builder_data'));
        $this->assertSame($saved->html, $response->json('document.html'));
        $this->assertSame($saved->css, $response->json('document.css'));
        $this->assertFalse($response->json('report.clean'));
        $this->assertNotEmpty($response->json('report.messages'));
    }

    public function test_signatursave_entfernt_editorattribute_und_verlangt_die_kanonische_zugquelle(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $withPreviewAttribute = preg_replace(
            '/<td class="rt-pad rt-sign-cell"/',
            '<td data-rt-mail-preview-train="TRAIN_SRC" class="rt-pad rt-sign-cell"',
            (string) $document->html,
            1,
        );
        $this->assertIsString($withPreviewAttribute);

        $savedResponse = $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $withPreviewAttribute,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk()
            ->assertJsonPath('report.clean', false);

        $saved = $document->fresh();
        $canonicalHtml = (string) $saved->html;
        $this->assertStringNotContainsString('data-rt-mail-', $canonicalHtml);
        $this->assertSame(1, substr_count($canonicalHtml, '{{TRAIN_SRC}}'));
        $this->assertSame($canonicalHtml, data_get($saved->builder_data, 'pages.0.component'));
        $this->assertSame($canonicalHtml, $savedResponse->json('document.html'));

        $attacks = [
            'preview pixel statt token' => str_replace(
                '{{TRAIN_SRC}}',
                EmailHtmlSanitizer::PREVIEW_TRANSPARENT_PIXEL,
                $canonicalHtml,
            ),
            'zweite zugquelle' => str_replace(
                // OHNE Anfuehrungszeichen — so steht die Zugquelle im
                // Markup, seit der Streifen mehrere Hintergrundebenen
                // traegt. Blade escaped Anfuehrungszeichen im
                // style-Attribut sonst zu &#039;.
                'url({{TRAIN_SRC}})',
                'url({{TRAIN_SRC}}),url({{TRAIN_SRC}})',
                $canonicalHtml,
            ),
            'frei erfundene traegerklasse' => str_replace(
                'rt-pad rt-sign-cell',
                'rt-pad custom-sign-cell',
                $canonicalHtml,
            ),
        ];

        foreach ($attacks as $label => $attackHtml) {
            $this->assertNotSame($canonicalHtml, $attackHtml, $label);

            $this->putJson(route('admin.mail-documents.update', $saved), [
                'builder_data' => $saved->builder_data,
                'html' => $attackHtml,
                'css' => (string) $saved->css,
                'expected_hash' => $saved->content_hash,
            ])->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalHtml, (string) $saved->fresh()->html, $label);
        }

        $this->putJson(route('admin.mail-documents.update', $saved), [
            'builder_data' => $saved->builder_data,
            'html' => $canonicalHtml,
            'css' => '[data-rt-mail-preview-train]{color:#111820;}',
            'expected_hash' => $saved->content_hash,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('css');

        $this->assertSame('', trim((string) $saved->fresh()->css));

        $this->postJson(route('admin.mail-documents.publish', $saved), [
            'expected_hash' => $saved->content_hash,
        ])->assertOk()
            ->assertJsonPath('document.status', MailDocumentStatus::Published->value);
    }

    public function test_signaturpublish_lehnt_preview_artefakte_und_mehrdeutige_zugquellen_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $validHtml = (string) $document->html;
        $attacks = [
            'preview attribut' => preg_replace(
                '/<td class="rt-pad rt-sign-cell"/',
                '<td data-rt-mail-preview-train="TRAIN_SRC" class="rt-pad rt-sign-cell"',
                $validHtml,
                1,
            ),
            'preview pixel' => str_replace(
                '{{TRAIN_SRC}}',
                EmailHtmlSanitizer::PREVIEW_TRANSPARENT_PIXEL,
                $validHtml,
            ),
            'mehrere train tokens' => str_replace(
                // OHNE Anfuehrungszeichen — so steht die Zugquelle im
                // Markup, seit der Streifen mehrere Hintergrundebenen
                // traegt. Blade escaped Anfuehrungszeichen im
                // style-Attribut sonst zu &#039;.
                'url({{TRAIN_SRC}})',
                'url({{TRAIN_SRC}}),url({{TRAIN_SRC}})',
                $validHtml,
            ),
        ];

        foreach ($attacks as $label => $attackHtml) {
            $this->assertIsString($attackHtml);
            $this->assertNotSame($validHtml, $attackHtml, $label);
            $document->forceFill([
                'html' => $attackHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $attackHtml,
                    (string) $document->css,
                ),
            ])->save();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertNull($document->fresh()->published_at, $label);
        }

        $previewCss = '[data-rt-mail-preview-train]{color:#111820;}';
        $document->forceFill([
            'html' => $validHtml,
            'css' => $previewCss,
            'content_hash' => MailDocument::contentHashFor(
                $document->builder_data,
                $validHtml,
                $previewCss,
            ),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('css');

        $this->assertNull($document->fresh()->published_at);
    }

    public function test_unveraendertes_speichern_zaehlt_die_version_nicht_hoch(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        $this->actingAs($this->admin())
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $document->html,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk();

        $this->assertSame(1, $document->fresh()->version);
        $this->assertSame($document->content_hash, $document->fresh()->content_hash);
    }

    public function test_veroeffentlichen_schreibt_den_abzug_und_wirkt_sofort(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        $document->forceFill([
            'html' => str_replace('{{SIGNATURE_BLOCK}}', '<tr><td>RT-FREIGABE</td></tr>{{SIGNATURE_BLOCK}}', (string) $document->html),
        ])->save();
        $document->forceFill([
            'content_hash' => MailDocument::contentHashFor($document->builder_data, $document->html, (string) $document->css),
        ])->save();

        $this->actingAs($this->admin())
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk()
            ->assertJsonPath('document.status', MailDocumentStatus::Published->value)
            ->assertJsonPath('document.has_unpublished_changes', false);

        $published = $document->fresh();

        $this->assertNotNull($published->published_at);
        $this->assertStringContainsString('RT-FREIGABE', (string) $published->published_html);
        $this->assertStringContainsString(
            'RT-FREIGABE',
            (new EmailTemplateBuilder($user->fresh()))->build('vorlage-html')['content'],
        );
    }

    public function test_reine_css_aenderung_bleibt_bis_zur_neuen_freigabe_offen_und_wirkt_im_download(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        $this->actingAs($this->admin())
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk()
            ->assertJsonPath('document.has_unpublished_changes', false);

        $document = $document->fresh();
        $css = '.rt-title{letter-spacing:0;}';
        $saved = $this->putJson(route('admin.mail-documents.update', $document), [
            'builder_data' => $document->builder_data,
            'html' => $document->html,
            'css' => $css,
            'expected_hash' => $document->content_hash,
        ])->assertOk()
            ->assertJsonPath('document.has_unpublished_changes', true);

        $this->assertTrue($document->fresh()->hasUnpublishedChanges());
        $this->assertStringNotContainsString($css, (new EmailTemplateBuilder($user))->build('vorlage-html')['content']);

        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $saved->json('document.content_hash'),
        ])->assertOk()
            ->assertJsonPath('document.has_unpublished_changes', false);

        $html = (new EmailTemplateBuilder($user->fresh()))->build('vorlage-html')['content'];
        $this->assertStringContainsString($css, $html);
        $this->assertStringContainsString('data-rt-mail-document-css="template"', $html);
        $this->assertLessThan(stripos($html, '</head>'), stripos($html, 'data-rt-mail-document-css="template"'));
    }

    public function test_veroeffentlichen_verlangt_den_aktuellen_fingerabdruck(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);
        $staleHash = $document->content_hash;
        $changedHtml = str_replace('Sicher abgestimmt.', 'Neu abgestimmt.', (string) $document->html);

        $saved = $this->actingAs($this->admin())
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $changedHtml,
                'css' => (string) $document->css,
                'expected_hash' => $staleHash,
            ])
            ->assertOk();

        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $staleHash,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('expected_hash');

        $this->assertNull($document->fresh()->published_at);

        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $saved->json('document.content_hash'),
        ])->assertOk();
    }

    public function test_veroeffentlichen_erhaelt_die_strukturvertraege_beider_dokumentarten(): void
    {
        $this->seedDocuments();
        $admin = $this->admin();
        $template = $this->document(MailDocumentKind::Template);

        foreach ([
            str_replace('{{SIGNATURE_BLOCK}}', '', (string) $template->html),
            str_replace('{{SIGNATURE_BLOCK}}', '{{SIGNATURE_BLOCK}}{{SIGNATURE_BLOCK}}', (string) $template->html),
        ] as $invalidTemplate) {
            $template->forceFill([
                'html' => $invalidTemplate,
                'content_hash' => MailDocument::contentHashFor($template->builder_data, $invalidTemplate, (string) $template->css),
            ])->save();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $template), [
                    'expected_hash' => $template->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }

        $signature = $this->document(MailDocumentKind::Signature);
        $validSignature = (string) $signature->html;
        $invalidSignature = '<div>Syntaxsauber, aber keine Signatur und ohne Pflichtangaben.</div>';
        $signature->forceFill([
            'html' => $invalidSignature,
            'content_hash' => MailDocument::contentHashFor($signature->builder_data, $invalidSignature, (string) $signature->css),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $signature), [
                'expected_hash' => $signature->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        foreach ([
            '{{LOGO_SRC}}',
            '{{VORNAME_NACHNAME}}',
            '{{POSITION}}',
            '{{E_MAIL}}',
            '{{FIRMENNAME}}',
            '{{FIRMENSTRASSE}}',
            '{{FIRMEN_PLZ_ORT}}',
            '{{FIRMEN_TELEFON}}',
            '{{FIRMEN_EMAIL}}',
            'RT_COMPANY_PHONE_START',
            'RT_COMPANY_PHONE_END',
        ] as $requiredSignatureNeedle) {
            $withoutRequiredCompanyData = str_replace($requiredSignatureNeedle, '', $validSignature);
            $signature->forceFill([
                'html' => $withoutRequiredCompanyData,
                'content_hash' => MailDocument::contentHashFor(
                    $signature->builder_data,
                    $withoutRequiredCompanyData,
                    (string) $signature->css,
                ),
            ])->save();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $signature), [
                    'expected_hash' => $signature->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }

        $this->assertNull($template->fresh()->published_at);
        $this->assertNull($signature->fresh()->published_at);
    }

    public function test_veroeffentlichen_lehnt_verbotene_syntax_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        // Am Editor vorbei eingeschleust: die Freigabe ist die letzte Instanz.
        $document->forceFill(['html' => '<table><tr><td><script>alert(1)</script></td></tr></table>'])->save();

        $this->actingAs($this->admin())
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertNull($document->fresh()->published_at);
        $this->assertNull(EmailTemplateBuilder::publishedDocument(MailDocumentKind::Template));
    }

    public function test_nicht_administratoren_erhalten_403_statt_einer_html_weiterleitung(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => ['pages' => []],
                'html' => '<table><tr><td>Fremd</td></tr></table>',
                'css' => '',
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(403);

        $this->actingAs($user)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(403);
    }

    public function test_downloads_bleiben_fuer_alle_offen(): void
    {
        $this->seedDocuments();
        $user = User::factory()->create(['role' => 'user', 'name' => 'Mara Beispiel']);

        $this->actingAs($user)
            ->get(route('email-templates.download', ['template' => 'signatur-hell']))
            ->assertOk();
    }
}
