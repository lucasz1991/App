<?php

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\CssSemantic;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTrainCarrier;
use App\Support\MailSignature;
use Database\Seeders\MailDocumentSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\ViewException;
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
        $this->assertSame(1, $frisch->version);
        $this->assertSame(8, data_get($frisch->builder_data, 'railtime.schema'));
    }

    public function test_der_seeder_veroeffentlicht_vorlage_und_signatur_als_idempotenten_release(): void
    {
        (new MailDocumentSeeder)->run();

        $ersterRelease = MailDocument::query()
            ->get()
            ->mapWithKeys(fn (MailDocument $document): array => [$document->kind->value => [
                'version' => $document->version,
                'published_at' => $document->published_at?->toIso8601String(),
                'updated_at' => $document->updated_at?->toIso8601String(),
            ]])
            ->all();

        foreach (MailDocumentKind::cases() as $kind) {
            $dokument = $this->document($kind);
            $this->assertSame(MailDocumentStatus::Published, $dokument->status, $kind->value);
            $this->assertSame(1, $dokument->version, $kind->value);
            $this->assertSame(8, data_get($dokument->builder_data, 'railtime.schema'), $kind->value);
            $this->assertSame(trim((string) $dokument->html), trim((string) $dokument->published_html), $kind->value);
            $this->assertSame((string) data_get($dokument->builder_data, 'pages.0.component'), (string) $dokument->html, $kind->value);
            $this->assertSame(
                MailDocument::contentHashFor($dokument->builder_data ?: [], (string) $dokument->html, (string) $dokument->css),
                $dokument->content_hash,
                $kind->value,
            );
        }

        // Ein identischer Deployment-Lauf bleibt die einzige Version 1.
        (new MailDocumentSeeder)->run();

        foreach (MailDocumentKind::cases() as $kind) {
            $dokument = $this->document($kind);
            $this->assertSame($ersterRelease[$kind->value]['version'], $dokument->version, $kind->value);
            $this->assertSame(1, $dokument->version, $kind->value);
        }
    }

    public function test_der_autoritative_seeder_ueberschreibt_beide_editorstaende_und_setzt_version_eins(): void
    {
        (new MailDocumentSeeder)->run();

        $this->document(MailDocumentKind::Template)->forceFill([
            'html' => '<html><body>Individuelle Vorlage</body></html>',
            'version' => 11,
        ])->save();
        $this->document(MailDocumentKind::Signature)->forceFill([
            'html' => '<tr><td>Individuelle Signatur</td></tr>',
            'version' => 7,
        ])->save();

        (new MailDocumentSeeder)->run();

        $template = $this->document(MailDocumentKind::Template);
        $signatur = $this->document(MailDocumentKind::Signature);

        $this->assertSame(1, $template->version);
        $this->assertSame(1, $signatur->version);

        foreach ([$template, $signatur] as $dokument) {
            $this->assertSame(MailDocumentStatus::Published, $dokument->status);
            $this->assertSame(8, data_get($dokument->builder_data, 'railtime.schema'));
            $this->assertSame(trim((string) $dokument->html), trim((string) $dokument->published_html));
            $this->assertSame((string) data_get($dokument->builder_data, 'pages.0.component'), (string) $dokument->html);
        }

        $this->assertStringNotContainsString('Individuelle Vorlage', (string) $template->html);
        $this->assertStringNotContainsString('Individuelle Signatur', (string) $signatur->html);
    }

    /**
     * Fremde Zwischenzeilen und alte Arbeitsstaende werden verworfen.
     */
    public function test_der_seeder_ignoriert_alte_umgebungsvariablen_und_loescht_fremde_zwischenzeilen(): void
    {
        (new MailDocumentSeeder)->run();

        $this->document(MailDocumentKind::Template)->forceFill([
            'html' => '<html><body>Eigene Vorlage</body></html>',
            'version' => 6,
        ])->save();
        $this->document(MailDocumentKind::Signature)->forceFill([
            'html' => '<tr><td>Eigene Signatur</td></tr>',
            'version' => 7,
        ])->save();

        DB::table('mail_documents')->insert([
            'public_id' => (string) Str::uuid(),
            'kind' => 'temporary-preview',
            'status' => 'draft',
            'builder_data' => '{}',
            'html' => '<div>Zwischenspeicher</div>',
            'css' => '',
            'content_hash' => str_repeat('a', 64),
            'version' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new MailDocumentSeeder)->run();

        foreach (MailDocumentKind::cases() as $kind) {
            $dokument = $this->document($kind);
            $this->assertStringNotContainsString('Eigene', (string) $dokument->html, $kind->value);
            $this->assertSame(1, $dokument->version, $kind->value);
            $this->assertSame(MailDocumentStatus::Published, $dokument->status, $kind->value);
        }

        $this->assertSame(2, MailDocument::query()->count());
        $this->assertFalse(DB::table('mail_documents')->where('kind', 'temporary-preview')->exists());
    }

    public function test_der_seeder_rollt_beide_dokumente_zurueck_wenn_ein_release_scheitert(): void
    {
        (new MailDocumentSeeder)->run();

        foreach (MailDocumentKind::cases() as $kind) {
            $dokument = $this->document($kind);
            $dokument->forceFill([
                'html' => '<div>Arbeitsstand '.$kind->value.'</div>',
                'version' => $kind === MailDocumentKind::Template ? 4 : 9,
            ])->save();
        }

        $vorher = MailDocument::query()
            ->get()
            ->mapWithKeys(fn (MailDocument $document): array => [$document->kind->value => [
                'html' => $document->html,
                'published_html' => $document->published_html,
                'version' => $document->version,
                'content_hash' => $document->content_hash,
            ]])
            ->all();

        MailDocument::updating(function (MailDocument $document): void {
            if ($document->kind === MailDocumentKind::Signature) {
                throw new \RuntimeException('Simulierter Speicherfehler der Signatur.');
            }
        });

        try {
            (new MailDocumentSeeder)->run();
            $this->fail('Der simulierte Speicherfehler wurde nicht ausgeloest.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulierter Speicherfehler der Signatur.', $exception->getMessage());
        }

        foreach (MailDocumentKind::cases() as $kind) {
            $dokument = $this->document($kind);
            $this->assertSame($vorher[$kind->value]['html'], $dokument->html, $kind->value);
            $this->assertSame($vorher[$kind->value]['published_html'], $dokument->published_html, $kind->value);
            $this->assertSame($vorher[$kind->value]['version'], $dokument->version, $kind->value);
            $this->assertSame($vorher[$kind->value]['content_hash'], $dokument->content_hash, $kind->value);
        }
    }

    private function document(MailDocumentKind $kind): MailDocument
    {
        return MailDocument::query()->where('kind', $kind->value)->firstOrFail();
    }

    private function renderSystemMail(string $line = 'Kanonischer Anwendungsinhalt'): string
    {
        $message = (new MailMessage)
            ->greeting('Guten Tag')
            ->line($line)
            ->action('RailTime öffnen', 'https://rail-time.example');

        return (string) app(Markdown::class)
            ->render($message->markdown ?: 'notifications::email', $message->data());
    }

    /** @return list<string> */
    private function trainUrlsFromSystemMail(string $html): array
    {
        preg_match_all(
            '~https?://[^"\'\s\)]+zug-dampf-light\.gif[^"\'\s\)]*~i',
            $html,
            $matches,
        );

        return array_map(
            static fn (string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_HTML5),
            $matches[0] ?? [],
        );
    }

    public function test_systemmail_verwendet_veroeffentlichte_vorlage_und_signatur_genau_einmal(): void
    {
        (new MailDocumentSeeder)->run();
        $template = $this->document(MailDocumentKind::Template);
        $signature = $this->document(MailDocumentKind::Signature);

        $templateHtml = str_replace(
            '<table class="rt-shell"',
            '<!-- RT-RUNTIME-TEMPLATE --><table class="rt-shell"',
            (string) $template->html,
        );
        $signatureHtml = str_replace(
            '{{POSITION}}',
            'RT-RUNTIME-SIGNATURE {{POSITION}}',
            (string) $signature->html,
        );

        foreach ([[$template, $templateHtml], [$signature, $signatureHtml]] as [$document, $html]) {
            $document->forceFill([
                'html' => $html,
                'builder_data' => [
                    'pages' => [['name' => $document->kind->label(), 'component' => $html]],
                    'styles' => [],
                    'railtime' => ['document' => $document->kind->value, 'schema' => 4],
                ],
                'content_hash' => MailDocument::contentHashFor(
                    [
                        'pages' => [['name' => $document->kind->label(), 'component' => $html]],
                        'styles' => [],
                        'railtime' => ['document' => $document->kind->value, 'schema' => 4],
                    ],
                    $html,
                    '',
                ),
            ])->save();

            $this->actingAs($this->admin())
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertOk();
        }

        $this->app->forgetScopedInstances();
        $html = $this->renderSystemMail();

        $this->assertSame(1, substr_count($html, 'Kanonischer Anwendungsinhalt'));
        $this->assertSame(1, substr_count($html, 'RT-RUNTIME-TEMPLATE'));
        $this->assertSame(1, substr_count($html, 'RT-RUNTIME-SIGNATURE'));
        $this->assertSame(1, preg_match_all('/class="[^"]*rt-sign-cell[^"]*"/', $html));
        $this->assertSame(0, substr_count($html, 'data-rt-outlook-train '));
        $this->assertSame(0, substr_count($html, 'data-rt-outlook-train-still'));
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*rt-sign-cell[^"]*"[^>]*\sbackground=/',
            $html,
        );
        $this->assertStringNotContainsString('data-rt-train-main-image', $html);
        $this->assertStringNotContainsString('data-rt-train-main-layer', $html);
        $this->assertSame(0, substr_count($html, 'rt-classic-outlook-train'));
        $this->assertSame(1, substr_count($html, 'zug-dampf-light.gif'));
        $this->assertStringNotContainsString('zug-dampf-light.png', $html);
        $this->assertSame(
            1,
            preg_match_all(
                '/<img\b(?=[^>]*class="[^"]*\brt-sign-train\b[^"]*")(?=[^>]*\bdata-rt-train(?:\s|=|>))[^>]*zug-dampf-light\.gif[^>]*>/i',
                $html,
            ),
        );
        $this->assertSame(1, substr_count($html, 'data-rt-train'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train"'));
        $this->assertStringNotContainsString('zug-dampf-idle-light.gif', $html);
        $this->assertStringNotContainsString('data-rt-train-idle-overlay', $html);
        $this->assertStringNotContainsString('data-rt-train-idle-image', $html);
        $this->assertStringNotContainsString('animation-delay: 13s;', $html);
        $this->assertStringNotContainsString('rt-train-idle-reveal', $html);
        $this->assertStringContainsString('rgba(255,255,255,0)', $html);
        $this->assertStringNotContainsString('rgba(255,255,255,.30)', $html);
        $this->assertSame(
            1,
            preg_match('/<td[^>]*class="[^"]*rt-sign-cell[^"]*"[^>]*>/', $html, $trainCarrier),
        );
        $this->assertStringContainsString('padding:0;', $trainCarrier[0]);
        $this->assertStringNotContainsString('zug-dampf-light.gif', $trainCarrier[0]);
        $this->assertStringContainsString('background-repeat:repeat,no-repeat,no-repeat;', $trainCarrier[0]);
        $this->assertStringContainsString('background-position:left top,right center,center center;', $trainCarrier[0]);
        $this->assertStringContainsString('background-size:64px 64px,auto 100%,100% 100%;', $trainCarrier[0]);
        $this->assertSame(1, substr_count($html, 'class="rt-pad rt-sign-content"'));
        $this->assertStringNotContainsString('data:image', $html);
        $this->assertLessThan(60 * 1024, strlen($html));
    }

    public function test_schema_7_schuetzt_den_paddingfreien_carrier_und_den_inneren_inhaltswrapper(): void
    {
        (new MailDocumentSeeder)->run();
        $canonical = (string) $this->document(MailDocumentKind::Signature)->published_html;

        SignatureDocumentContract::assertValid($canonical);
        SignatureDocumentContract::assertRuntimeValid($canonical);

        $outerPadding = function (string $declarations) use ($canonical): string {
            $html = preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}'.$declarations,
                $canonical,
                1,
                $count,
            );
            $this->assertIsString($html);
            $this->assertSame(1, $count);

            return $html;
        };
        $innerPadding = function (string $declarations) use ($canonical): string {
            $html = preg_replace(
                '/(<td class="rt-pad rt-sign-content" style=")padding:[^;]+;/i',
                '${1}'.$declarations,
                $canonical,
                1,
                $count,
            );
            $this->assertIsString($html);
            $this->assertSame(1, $count);

            return $html;
        };

        // GrapesJS kann dieselbe effektive Box als Kurzform, Longhands oder
        // als Kaskade aus beidem schreiben. Alle Fassungen muessen denselben
        // sicheren Vertrag erfuellen wie der kanonische Starter.
        foreach ([
            'Aussen-Null mit px' => $outerPadding('padding:0px;'),
            'Aussen-Null als Kurzform' => $outerPadding('padding:0 0px 0.0 0.00px;'),
            'Aussen-Null als Longhands' => $outerPadding(
                'padding-top:0px;padding-right:0;padding-bottom:0.0;padding-left:0.00px;'
            ),
            'Aussen-Null nach Kaskade' => $outerPadding(
                'padding:20px;padding-bottom:5px;padding:0 0 0 0;'
            ),
            'Inhalt als Longhands' => $innerPadding(
                'padding-top:18px;padding-right:36px;padding-bottom:0px;padding-left:36px;'
            ),
            'Inhalt nach Kaskade' => $innerPadding(
                'padding:1px;padding:18px 36px 20px;padding-bottom:0;'
            ),
        ] as $name => $equivalent) {
            SignatureDocumentContract::assertValid($equivalent);
            SignatureDocumentContract::assertRuntimeValid($equivalent);
        }

        $cases = [
            'Aussenpadding' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:20px;',
                $canonical,
                1,
                $outerPaddingCount,
            ),
            'Padding-Longhand' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;padding-bottom:20px;',
                $canonical,
                1,
                $longhandCount,
            ),
            'Outlook-MSO-Padding' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;MSO-PADDING-ALT:20px;',
                $canonical,
                1,
                $msoPaddingCount,
            ),
            'Entity-MSO-Padding' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;mso-padding&#45;alt:20px;',
                $canonical,
                1,
                $entityMsoPaddingCount,
            ),
            'doppeltes MSO-Padding' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;mso-padding-alt:0;mso-padding-alt:20px;',
                $canonical,
                1,
                $duplicateMsoPaddingCount,
            ),
            'inneres MSO-Padding' => preg_replace(
                '/(<td class="rt-pad rt-sign-content" style="padding:[^;]+;)/i',
                '${1}mso-padding-alt:20px;',
                $canonical,
                1,
                $innerMsoPaddingCount,
            ),
            'unterer Carrier-Rahmen' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;border-bottom:20px solid transparent;',
                $canonical,
                1,
                $bottomBorderCount,
            ),
            'Outlook-MSO-Rahmen' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;mso-border-alt:solid #000 20px;',
                $canonical,
                1,
                $msoBorderCount,
            ),
            'fehlende Contentklasse' => str_replace('rt-pad rt-sign-content', 'rt-pad', $canonical, $missingClassCount),
            'inneres Nullpadding' => preg_replace(
                '/(<td class="rt-pad rt-sign-content" style=")padding:[^;]+;/i',
                '${1}padding:0;',
                $canonical,
                1,
                $innerPaddingCount,
            ),
            'negatives Inhaltspadding' => $innerPadding('padding:18px 36px -1px;'),
            'fremde Padding-Einheit' => $innerPadding('padding:18px 2rem 0;'),
            'unvollstaendige Padding-Longhands' => $innerPadding(
                'padding-top:18px;padding-right:36px;padding-bottom:0;'
            ),
            'unlesbare Padding-Deklaration' => $innerPadding('padding(18px);'),
            'unlesbares Outlook-Padding' => $innerPadding('padding:18px;mso-padding-alt(20px);'),
            'verschobener Content-Decoy' => str_replace(
                ['rt-pad rt-sign-content', 'rt-sign-identity'],
                ['rt-pad', 'rt-sign-content rt-sign-identity'],
                $canonical,
                $decoyCount,
            ),
        ];

        $this->assertSame(1, $outerPaddingCount);
        $this->assertSame(1, $longhandCount);
        $this->assertSame(1, $msoPaddingCount);
        $this->assertSame(1, $entityMsoPaddingCount);
        $this->assertSame(1, $duplicateMsoPaddingCount);
        $this->assertSame(1, $innerMsoPaddingCount);
        $this->assertSame(1, $bottomBorderCount);
        $this->assertSame(1, $msoBorderCount);
        $this->assertSame(1, $missingClassCount);
        $this->assertSame(1, $innerPaddingCount);
        $this->assertSame(2, $decoyCount);

        foreach ($cases as $name => $invalid) {
            $this->assertIsString($invalid, $name);
            try {
                SignatureDocumentContract::assertValid($invalid);
                $this->fail("Der Schema-7-Vertrag akzeptierte: {$name}");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('padding:0', $exception->getMessage(), $name);
            }
        }
    }

    public function test_runtime_erlaubt_bis_zum_pflichtseeder_nur_die_exakte_schema_6_carrierform(): void
    {
        (new MailDocumentSeeder)->run();
        $canonical = (string) $this->document(MailDocumentKind::Signature)->published_html;
        $legacy = preg_replace(
            '~<table\b[^>]*>\s*<tr>\s*<td class="rt-pad rt-sign-content" style="padding:([^;]+);position:relative;z-index:1;">~i',
            '',
            $canonical,
            1,
            $wrapperOpenCount,
        );
        $this->assertIsString($legacy);
        $this->assertSame(1, $wrapperOpenCount);
        preg_match('/rt-pad rt-sign-content" style="padding:([^;]+);/', $canonical, $paddingMatch);
        $this->assertArrayHasKey(1, $paddingMatch);
        $legacy = preg_replace(
            '~</td>\s*</tr>\s*</table>\s*(?=</td>\s*</tr>\s*<!-- RT_SIGNATURE_MAIN_END -->)~i',
            '',
            $legacy,
            1,
            $wrapperCloseCount,
        );
        $this->assertIsString($legacy);
        $this->assertSame(1, $wrapperCloseCount);
        $legacy = preg_replace_callback(
            '/<td class="rt-sign-cell"([^>]*style=")padding:0;/i',
            static fn (array $match): string => '<td class="rt-pad rt-sign-cell"'.$match[1]
                .'padding:'.$paddingMatch[1].';',
            $legacy,
            1,
            $carrierCount,
        );
        $this->assertIsString($legacy);
        $this->assertSame(1, $carrierCount);

        SignatureDocumentContract::assertRuntimeValid($legacy);

        $this->expectException(\RuntimeException::class);
        SignatureDocumentContract::assertValid($legacy);
    }

    public function test_systemmail_normalisiert_einen_legacy_idle_layer_vor_der_tokenersetzung(): void
    {
        (new MailDocumentSeeder)->run();
        $signature = $this->document(MailDocumentKind::Signature);
        $legacy = str_replace(
            'url({{TRAIN_SRC}});background-repeat:repeat,no-repeat,no-repeat,no-repeat;'
                .'background-position:left top,right center,center center,75% bottom;'
                .'background-size:64px 64px,auto 100%,100% 100%,auto 100%;',
            'url(\'{{TRAIN_IDLE_SRC}}\'),url(&quot;{{TRAIN_SRC}}&quot;);'
                .'background-repeat:repeat,no-repeat,no-repeat,no-repeat,no-repeat;'
                .'background-position:left top,right center,center center,right bottom,right bottom;'
                .'background-size:64px 64px,auto 100%,100% 100%,auto 100%,auto 100%;',
            (string) $signature->published_html,
            $legacyReplacementCount,
        );
        $this->assertSame(1, $legacyReplacementCount);
        $signature->forceFill([
            'html' => $legacy,
            'published_html' => $legacy,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $html = MailSignature::forCompany(playbackNonce: 'legacy-contract')->render();
        $this->assertSame(
            1,
            preg_match('/<td[^>]*class="[^"]*rt-sign-cell[^"]*"[^>]*>/', $html, $carrier),
        );

        // Beide Legacy-Zuglayer werden vor der Ausgabe aus den parallelen
        // Background-Listen geloest. Ausgeliefert wird nur das kombinierte
        // Haupt-GIF als regulaeres Bild.
        $this->assertSame(1, substr_count($html, 'zug-dampf-light.gif'));
        $this->assertStringNotContainsString('zug-dampf-idle-light.gif', $html);
        $this->assertSame(1, substr_count($html, 'data-rt-train'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train"'));
        $this->assertStringNotContainsString('data-rt-train-idle-overlay', $html);
        $this->assertStringNotContainsString('data-rt-train-main-image', $html);
        $this->assertStringNotContainsString('data-rt-train-main-layer', $html);
        $this->assertStringNotContainsString('data-rt-train-idle-image', $html);
        $this->assertStringNotContainsString('zug-dampf-idle-light.gif', $carrier[0]);
        $this->assertStringNotContainsString('zug-dampf-light.gif', $carrier[0]);
        $this->assertStringContainsString(
            'background-repeat:repeat,no-repeat,no-repeat;',
            $carrier[0],
        );
        $this->assertStringContainsString(
            'background-position:left top,right center,center center;',
            $carrier[0],
        );
        $this->assertStringContainsString(
            'background-size:64px 64px,auto 100%,100% 100%;',
            $carrier[0],
        );
        $this->assertStringContainsString(
            'linear-gradient(rgba(255,255,255,0),rgba(255,255,255,0))',
            $carrier[0],
        );
        $this->assertStringNotContainsString('right bottom', $carrier[0]);
        $this->assertStringNotContainsString(
            '&amp;p='.substr(hash('sha256', 'legacy-contract'), 0, 32),
            $carrier[0],
        );
        $this->assertStringContainsString(
            '&amp;p='.substr(hash('sha256', 'legacy-contract'), 0, 32),
            $html,
        );

        $report = (new EmailHtmlSanitizer(['127.0.0.1', 'rail-time.de']))->clean($html);
        $this->assertSame([], $report->findings, implode(' | ', $report->messages()));
        $this->assertSame($html, $report->html);
    }

    public function test_aktuelle_zugstruktur_wird_bytegleich_idempotent_normalisiert(): void
    {
        (new MailDocumentSeeder)->run();
        $published = (string) $this->document(MailDocumentKind::Signature)->published_html;
        $signature = MailSignature::forCompany(playbackNonce: 'idempotent-contract');
        $normalizer = new \ReflectionMethod($signature, 'normalizePublishedTrainCarrier');
        $normalizer->setAccessible(true);

        $once = $normalizer->invoke($signature, $published);
        $twice = $normalizer->invoke($signature, $once);

        $this->assertSame($published, $once);
        $this->assertSame($once, $twice);
    }

    public function test_zugnormalisierung_aendert_nur_das_echte_style_attribut_des_carriers(): void
    {
        (new MailDocumentSeeder)->run();
        $published = (string) $this->document(MailDocumentKind::Signature)->published_html;
        $legacy = str_replace(
            'url({{TRAIN_SRC}});background-repeat:repeat,no-repeat,no-repeat,no-repeat;'
                .'background-position:left top,right center,center center,75% bottom;'
                .'background-size:64px 64px,auto 100%,100% 100%,auto 100%;',
            'url({{TRAIN_IDLE_SRC}}),url({{TRAIN_SRC}});'
                .'background-repeat:repeat,no-repeat,no-repeat,no-repeat,no-repeat;'
                .'background-position:left top,right center,center center,right bottom,right bottom;'
                .'background-size:64px 64px,auto 100%,100% 100%,auto 100%,auto 100%;',
            $published,
            $legacyCount,
        );
        $decoy = <<<'HTML'
data-decoy='<td class="rt-sign-cell" style="background:none">'
HTML;
        $withDecoy = str_replace(
            '<td class="rt-sign-cell"',
            '<td '.$decoy.' class="rt-sign-cell"',
            $legacy,
            $carrierCount,
        );

        $this->assertSame(1, $legacyCount);
        $this->assertSame(1, $carrierCount);
        $normalized = SignatureTrainCarrier::normalize($withDecoy);

        $this->assertStringContainsString($decoy, $normalized);
        $this->assertStringNotContainsString('{{TRAIN_IDLE_SRC}}', $normalized);
        $this->assertSame(1, substr_count($normalized, '{{TRAIN_SRC}}'));
    }

    public function test_mehrdeutige_oder_malformed_background_listen_brechen_fail_closed_ab(): void
    {
        (new MailDocumentSeeder)->run();
        $published = (string) $this->document(MailDocumentKind::Signature)->published_html;
        $signature = MailSignature::forCompany(playbackNonce: 'malformed-contract');
        $normalizer = new \ReflectionMethod($signature, 'normalizePublishedTrainCarrier');
        $normalizer->setAccessible(true);
        $withTrailingBackgroundShorthand = preg_replace(
            '/(<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*style="[^"]*)(?=")/i',
            '${1}background:none;',
            $published,
            1,
            $shorthandReplacementCount,
        );
        $this->assertSame(1, $shorthandReplacementCount);
        $this->assertIsString($withTrailingBackgroundShorthand);
        $withDuplicateRealStyle = str_replace(
            '<td class="rt-sign-cell"',
            '<td style="background:none" class="rt-sign-cell"',
            $published,
            $duplicateRealStyleCount,
        );
        $this->assertSame(1, $duplicateRealStyleCount);

        $cases = [
            'missing main' => str_replace('{{TRAIN_SRC}}', '{{BROKEN_TRAIN_SRC}}', $published),
            'duplicate main' => str_replace(
                'url({{TRAIN_SRC}})',
                'url({{TRAIN_SRC}}),url({{TRAIN_SRC}})',
                $published,
            ),
            'duplicate idle' => str_replace(
                'url({{TRAIN_SRC}})',
                'url({{TRAIN_IDLE_SRC}}),url({{TRAIN_IDLE_SRC}}),url({{TRAIN_SRC}})',
                $published,
            ),
            'non parallel lists' => str_replace(
                'background-repeat:repeat,no-repeat,no-repeat,no-repeat;',
                'background-repeat:repeat,no-repeat,no-repeat;',
                $published,
            ),
            'unbalanced function' => str_replace('linear-gradient(', 'linear-gradient((', $published),
            'duplicate declaration' => str_replace(
                'background-size:',
                'background-size:auto;background-size:',
                $published,
            ),
            'missing background size' => preg_replace(
                '/background-size:[^;]*;/',
                '',
                $published,
                1,
            ),
            'duplicate background image declaration' => str_replace(
                'background-image:',
                'background-image:none;background-image:',
                $published,
            ),
            'commented duplicate background image declaration' => str_replace(
                'background-image:',
                'background-image:none;background-image/**/:',
                $published,
            ),
            'background shorthand after longhands' => $withTrailingBackgroundShorthand,
            'commented background shorthand after longhands' => str_replace(
                'background:none;',
                'background/**/:none;',
                $withTrailingBackgroundShorthand,
            ),
            'entity encoded comment shorthand after longhands' => str_replace(
                'background:none;',
                'background&#47;*;*&#47;:none;',
                $withTrailingBackgroundShorthand,
            ),
            'entity encoded escaped semicolon before background image' => str_replace(
                'background-image:',
                'x&#92;;background-image:',
                $published,
            ),
            'duplicate actual carrier style attribute' => $withDuplicateRealStyle,
            'raw text fake carrier' => '<script type="text/plain"><td class="rt-sign-cell" style="background:none"></script>'.$published,
            'bogus declaration fake carrier' => '<!fake <td class="rt-sign-cell" style="background:none">>'.$published,
        ];

        foreach ($cases as $label => $malformed) {
            try {
                $normalizer->invoke($signature, $malformed);
                $this->fail("{$label}: Die mehrdeutige Signatur wurde nicht abgelehnt.");
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->assertInstanceOf(\RuntimeException::class, $exception, $label);
            }
        }
    }

    public function test_outlook_bildprojektion_umgeht_den_signaturvertrag_nicht(): void
    {
        (new MailDocumentSeeder)->run();
        $signature = $this->document(MailDocumentKind::Signature);
        $malformedForBackgroundRuntime = str_replace(
            'background-repeat:repeat,no-repeat,no-repeat,no-repeat;',
            'background-repeat:repeat,no-repeat,no-repeat;',
            (string) $signature->published_html,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $signature->forceFill([
            'published_html' => $malformedForBackgroundRuntime,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $builder = new EmailTemplateBuilder(User::factory()->create(['name' => 'Outlook Test']));
        $method = new \ReflectionMethod($builder, 'buildOutlookSignatureHtml');
        $method->setAccessible(true);
        $this->expectException(\RuntimeException::class);
        $method->invoke($builder, 'light', 'RailTime_files');
    }

    public function test_systemmail_replay_urls_sind_innerhalb_einer_mail_identisch_und_pro_mail_eindeutig(): void
    {
        (new MailDocumentSeeder)->run();

        $first = MailSignature::forCompany();
        $firstValues = $first->values();
        $this->assertSame($firstValues['TRAIN_SRC'], $first->values()['TRAIN_SRC']);
        $this->assertSame($firstValues['TRAIN_IDLE_SRC'], $first->values()['TRAIN_IDLE_SRC']);
        $this->assertSame('', $firstValues['TRAIN_IDLE_SRC']);

        parse_str((string) parse_url($firstValues['TRAIN_SRC'], PHP_URL_QUERY), $firstTrainQuery);
        $this->assertArrayHasKey('v', $firstTrainQuery);
        $this->assertArrayHasKey('p', $firstTrainQuery);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $firstTrainQuery['p']);

        $secondValues = MailSignature::forCompany()->values();
        parse_str((string) parse_url($secondValues['TRAIN_SRC'], PHP_URL_QUERY), $secondQuery);
        $this->assertNotSame($firstTrainQuery['p'], $secondQuery['p']);

        $explicitA = MailSignature::forCompany(playbackNonce: 'mail/42')->values();
        $explicitB = MailSignature::forCompany(playbackNonce: 'mail/42')->values();
        $this->assertSame($explicitA['TRAIN_SRC'], $explicitB['TRAIN_SRC']);
        $this->assertStringContainsString(
            '&p='.substr(hash('sha256', 'mail/42'), 0, 32),
            $explicitA['TRAIN_SRC'],
        );
        $this->assertStringNotContainsString('mail%2F42', $explicitA['TRAIN_SRC']);

        $withoutAnimation = MailSignature::forCompany(animated: false)->values();
        parse_str((string) parse_url($withoutAnimation['TRAIN_SRC'], PHP_URL_QUERY), $staticQuery);
        $this->assertArrayNotHasKey('p', $staticQuery);

        $mailA = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Replay A</p>'));
        $mailB = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Replay B</p>'));
        $urlsA = $this->trainUrlsFromSystemMail($mailA);
        $urlsB = $this->trainUrlsFromSystemMail($mailB);

        $this->assertCount(1, $urlsA);
        $this->assertCount(1, $urlsB);
        $this->assertCount(1, array_unique($urlsA));
        $this->assertCount(1, array_unique($urlsB));
        $this->assertNotSame($urlsA[0], $urlsB[0]);
    }

    public function test_systemmail_zeigt_identische_firmen_und_notfallnummer_genau_einmal(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), [
            'phone' => '04171 546803',
            'emergency_phone' => '+49 (0) 4171 546803',
        ]));
        (new MailDocumentSeeder)->run();

        $html = $this->renderSystemMail();

        $this->assertSame(1, substr_count($html, 'href="tel:+494171546803"'));
        $this->assertSame(1, preg_match_all('/>04171 546803<\/a>/', $html));
        $this->assertStringNotContainsString('>+49 (0) 4171 546803</a>', $html);
        $this->assertSame(1, substr_count($html, 'href="mailto:info@rail-time.de"'));
        $this->assertSame(1, preg_match_all('/>info@rail-time\.de<\/a>/', $html));
    }

    public function test_systemmail_schlaegt_bei_fehlender_freigabe_in_migrierter_installation_fehl(): void
    {
        (new MailDocumentSeeder)->run();
        $this->document(MailDocumentKind::Template)->forceFill([
            'status' => MailDocumentStatus::Draft,
            'published_html' => null,
            'published_css' => null,
            'published_at' => null,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('veröffentlichte Maildokument');

        $this->renderSystemMail();
    }

    public function test_systemmail_hat_vor_der_maildocument_migration_einen_bootstrap_fallback(): void
    {
        Schema::drop('mail_documents');
        $this->app->forgetScopedInstances();

        $html = $this->renderSystemMail('Bootstrap-Inhalt');

        $this->assertSame(1, substr_count($html, 'Bootstrap-Inhalt'));
        $this->assertSame(1, preg_match_all('/class="[^"]*rt-sign-cell[^"]*"/', $html));
        $this->assertStringContainsString('mail-assets/', $html);
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
        // Simuliert eine bereits vor dem Fix veroeffentlichte Datenbank-
        // version. Der Runtime-Pfad muss das kachelnde Word-Attribut auch
        // ohne vorherigen Seeder-Lauf sicher entfernen.
        $publishedHtml = preg_replace(
            '/<td class="rt-sign-cell"/',
            '<td class="rt-sign-cell" background="{{TRAIN_STILL_SRC}}"',
            $publishedHtml,
            1,
            $legacyBackgroundCount,
        );
        $this->assertIsString($publishedHtml);
        $this->assertSame(1, $legacyBackgroundCount);
        $signature->forceFill([
            'published_html' => $publishedHtml,
            'published_css' => '.rt-sign-name{letter-spacing:0;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $templateDocument = $this->document(MailDocumentKind::Template);
        $templateDocument->forceFill([
            'published_html' => (string) $templateDocument->html,
            'published_css' => '',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

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
        // OHNE Firmenname: Ohne sendende Person bleibt VORNAME_NACHNAME leer.
        // Der frueher hier greifende Rueckfall auf den Firmennamen ist
        // entfallen — die Marke steht bereits als Wortmarke in der rechten
        // Spalte, der Name darunter war eine Doppelung.
        $this->assertStringContainsString('RT-SIGNATUR', $systemSignature);
        $this->assertStringNotContainsString('RT-SIGNATUR RT Rail Time GmbH', $systemSignature);

        // CSS steht in den echten Dokumentkoepfen, nicht im <tr>-Fragment.
        $this->assertStringContainsString('data-rt-mail-document-css="signature"', $template);
        $this->assertStringContainsString('.rt-sign-name{letter-spacing:0;}', $standalone);
        $this->assertStringNotContainsString('<style', $systemSignature);
        $this->assertLessThan(stripos($standalone, '</head>'), stripos($standalone, 'data-rt-mail-document-css="signature"'));

        // Vorlage, eigenstaendige Signatur und Systemmail verwenden denselben
        // einfachen Bildvertrag: ein regulaeres kombiniertes Zug-GIF, keine
        // zweite Idle-Quelle und kein ausgelieferter Zug-Background.
        foreach ([
            'Vorlage' => $template,
            'Signatur' => $standalone,
            'Systemmail' => $systemMail,
        ] as $channel => $rendered) {
            $this->assertSame(1, substr_count($rendered, 'data-rt-train'), $channel);
            $this->assertSame(1, substr_count($rendered, 'class="rt-sign-train"'), $channel);
            $this->assertStringNotContainsString('data-rt-train-idle', $rendered, $channel);
            $this->assertStringNotContainsString('zug-dampf-idle-', $rendered, $channel);
            $this->assertSame(
                1,
                preg_match(
                    '/<img\b(?=[^>]*class="[^"]*\brt-sign-train\b[^"]*")(?=[^>]*\bdata-rt-train(?:\s|=|>))[^>]*src="([^"]+)"[^>]*>/i',
                    $rendered,
                    $trainImage,
                ),
                $channel,
            );
            $this->assertSame(
                1,
                preg_match('/<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*>/', $rendered, $carrier),
                $channel,
            );
            $this->assertStringNotContainsString($trainImage[1], $carrier[0], $channel);
        }

        $this->assertStringContainsString('.rt-sign-name{letter-spacing:0;}', $systemMail);
        $this->assertStringContainsString('RT-SIGNATUR', $systemMail);
        $this->assertStringNotContainsString('data-rt-outlook-train', $systemMail);
        $this->assertStringNotContainsString('data-rt-train-idle-overlay', $systemMail);
        $this->assertStringNotContainsString('data-rt-train-main-image', $systemMail);
        $this->assertStringNotContainsString('data-rt-train-main-layer', $systemMail);
        $this->assertStringNotContainsString('data-rt-train-idle-image', $systemMail);
        $this->assertSame(1, substr_count($systemMail, 'zug-dampf-light.gif'));
        $this->assertSame(1, preg_match_all('/<img\b[^>]*zug-dampf-light\.gif[^>]*>/i', $systemMail));
        $this->assertSame(0, substr_count($systemMail, 'rt-classic-outlook-train'));
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*rt-sign-cell[^"]*"[^>]*\sbackground=/',
            $systemMail,
        );
        $this->assertSame(
            1,
            preg_match('/<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*>/', $systemMail, $systemTrainCarrier),
        );
        $this->assertStringContainsString('padding:0;', $systemTrainCarrier[0]);
        $this->assertStringNotContainsString('zug-dampf-light.gif', $systemTrainCarrier[0]);
        $this->assertStringContainsString('background-repeat:repeat,no-repeat,no-repeat;', $systemTrainCarrier[0]);
        $this->assertStringContainsString('background-position:left top,right center,center center;', $systemTrainCarrier[0]);
        $this->assertSame(1, substr_count($systemMail, 'class="rt-pad rt-sign-content"'));
        $this->assertStringNotContainsString('zug-dampf-light.png', $systemMail);

        // Nur die bekannten Starterabstaende werden fuer den eigenstaendigen
        // Download auf den kompakten Vertrag abgebildet.
        $this->assertStringContainsString('padding:16px 28px 0;', $standalone);
        $this->assertStringContainsString('padding:11px 28px;', $standalone);
        $this->assertStringNotContainsString('border-top:5px solid #e4002b;', $standalone);

        $outlookMethod = new \ReflectionMethod($builder, 'buildOutlookSignatureHtml');
        $outlookMethod->setAccessible(true);
        $outlook = $outlookMethod->invoke($builder, 'light', 'RailTime_files');
        $this->assertIsString($outlook);
        $this->assertStringContainsString('RT-SIGNATUR Mara Beispiel', $outlook);
        $this->assertStringNotContainsString('data-rt-outlook-train', $outlook);
        $this->assertSame(1, substr_count($outlook, 'data-rt-train'));
        $this->assertSame(1, substr_count($outlook, 'class="rt-sign-train"'));
        $this->assertSame(
            1,
            preg_match('/<img\b(?=[^>]*class="[^"]*\brt-sign-train\b[^"]*")(?=[^>]*\bdata-rt-train(?:\s|=|>))[^>]*>/', $outlook, $outlookAnimatedTrain),
        );
        $this->assertStringNotContainsString('opacity:', $outlookAnimatedTrain[0]);
        $this->assertStringContainsString('width="100%"', $outlookAnimatedTrain[0]);
        $this->assertStringContainsString('style="display:block;width:100%;max-width:1815px;height:auto;', $outlookAnimatedTrain[0]);
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
        $this->assertStringContainsString('SNAPSHOT-A', $htmlFromFooter);
        $this->assertStringNotContainsString('SNAPSHOT-B', $htmlFromFooter);

        // Laravel leert scoped Bindings zwischen HTTP-/Octane-Requests und
        // Queue-Jobs. Der naechste Scope liest deshalb atomar Version B.
        $this->app->forgetScopedInstances();

        $freshCss = MailSignature::forCompany()->publishedCss();
        $freshHtml = MailSignature::forCompany()->render();

        $this->assertStringContainsString('.snapshot-b{letter-spacing:1px;}', $freshCss);
        $this->assertStringContainsString('SNAPSHOT-B', $freshHtml);
        $this->assertStringNotContainsString('SNAPSHOT-A', $freshHtml);
    }

    public function test_regulaerer_signaturdownload_respektiert_individuelle_editorabstaende(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $custom = strtr((string) $signature->html, [
            'padding:18px 36px 0;' => 'padding:21px 41px 29px;',
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
            ->assertSee('data-mail-document-save', escape: false)
            ->assertSee('data-mail-document-publish', escape: false)
            ->assertSee('Mail-Notifications sowie Systemmails veröffentlichen', escape: false)
            // OHNE escape: false — Blade escaped das Trennzeichen & der
            // Query im href zu &amp;. Die rohe URL steht so nie im Markup.
            ->assertSee(route('admin.mail-documents.editor', ['dokument' => 'signature', 'open' => 1]))
            ->assertSee('data-mail-document-back', escape: false)
            ->assertSee('data-mail-theme-button="light"', escape: false)
            ->assertSee('data-mail-theme-button="dark"', escape: false)
            ->assertSee('data-mail-preview-device="desktop"', escape: false)
            ->assertSee('data-mail-preview-device="tablet"', escape: false)
            ->assertSee('data-mail-preview-device="mobile"', escape: false)
            ->assertSee('data-mail-preview-replay', escape: false)
            ->assertSee('restartAllGifs', escape: false)
            ->assertSee('data-mail-editor-frame', escape: false)
            ->assertSee('data-page-builder-preview-first', escape: false)
            ->assertSee('data-page-builder-preview-replay', escape: false)
            ->assertSee('animate=1', escape: false)
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
        $originalSignatureBuilderData = $signature->builder_data;
        $originalSignatureHtml = (string) $signature->html;

        $response = $this->actingAs($this->admin())
            ->get(route('admin.mail-documents.editor'))
            ->assertOk();

        // [^<]* STATT (.*?): Der Lazy-Quantor lief ueber diesen Inhalt ins
        // Backtrack-Limit von PCRE und lieferte dann false — nicht "nicht
        // gefunden", sondern einen Abbruch. Die Vorschau-Assets stecken als
        // Base64 im Konfigurationsblock, und seit die Zugeinfahrt in
        // 1640 x 412 vorliegt, sind das mehrere hundert Kilobyte.
        // JSON in einem <script> darf kein rohes < enthalten (Blade escaped
        // es), deshalb ist [^<]* hier sicher UND laeuft linear.
        $this->assertSame(1, preg_match(
            '/<script[^>]*data-mail-document-config[^>]*>([^<]*)<\/script>/',
            (string) $response->getContent(),
            $match,
        ));
        $config = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

        // Wortmarke und Zeichen sind bewegt, siehe render-marken-animation.
        foreach (['light.logo', 'dark.logo', 'light.mark', 'dark.mark'] as $asset) {
            $this->assertStringStartsWith(
                'data:image/gif;base64,',
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
        // Der Body wird im Builder editiert; die vollstaendige HTML-Fassung
        // bleibt als serverautoritative Baseline fuer Head, Markenfragment
        // und Dokumenthuelle im Payload. CSS und Builderprojekt muessen
        // daneben unverkuerzt ankommen.
        $this->assertSame((string) $template->html, data_get($config, 'documents.template.html'));
        $this->assertSame((string) $signature->html, data_get($config, 'documents.signature.html'));
        $this->assertSame((string) $template->css, data_get($config, 'documents.template.css'));
        $this->assertSame((string) $signature->css, data_get($config, 'documents.signature.css'));
        $this->assertSame($originalTemplateBuilderData, data_get($config, 'documents.template.builderData'));
        $this->assertSame($originalSignatureBuilderData, data_get($config, 'documents.signature.builderData'));
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
        $unsafeHtml = str_replace(
            '{{APPLICATION_CONTENT}}',
            '<tr><td onclick="alert(1)">Text</td></tr>{{APPLICATION_CONTENT}}<script>alert(2)</script>',
            (string) $document->html,
        );

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
                'html' => $unsafeHtml,
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
            '/<td class="rt-sign-cell"/',
            '<td data-rt-mail-preview-train="TRAIN_SRC" class="rt-sign-cell"',
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
                'rt-sign-cell',
                'custom-sign-cell',
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
                '/<td class="rt-sign-cell"/',
                '<td data-rt-mail-preview-train="TRAIN_SRC" class="rt-sign-cell"',
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

    public function test_signatur_save_und_publish_lehnen_background_kurzform_am_zugcarrier_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $shorthandHtml = str_replace(
            'background-image:',
            'background:',
            $canonicalHtml,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $shorthandHtml,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertSame($canonicalHtml, (string) $document->fresh()->html);

        $document->forceFill([
            'html' => $shorthandHtml,
            'content_hash' => MailDocument::contentHashFor(
                $document->builder_data,
                $shorthandHtml,
                (string) $document->css,
            ),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertNull($document->fresh()->published_at);
    }

    public function test_signatur_lehnt_den_css_kommentarzustand_bypass_beim_save_publish_und_rendern_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $attackHtml = preg_replace(
            '/(<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*style="[^"]*)(?=")/i',
            '${1}background/*;*/:none;',
            $canonicalHtml,
            1,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $this->assertIsString($attackHtml);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $attackHtml,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertSame($canonicalHtml, (string) $document->fresh()->html);

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

        $this->assertNull($document->fresh()->published_at);

        $document->forceFill([
            'published_html' => $attackHtml,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        try {
            MailSignature::forCompany(playbackNonce: 'comment-state-bypass')->render();
            $this->fail('Der CSS-Kommentarzustand-BYPASS wurde beim Rendern nicht abgelehnt.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('CSS-Kommentare', $exception->getMessage());
        }
    }

    public function test_signatur_lehnt_den_css_escape_zustand_bypass_beim_save_publish_und_rendern_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $attackHtml = str_replace(
            'background-image:',
            'x\\;background-image:',
            $canonicalHtml,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $attackHtml,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertSame($canonicalHtml, (string) $document->fresh()->html);

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

        $this->assertNull($document->fresh()->published_at);

        $document->forceFill([
            'published_html' => $attackHtml,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        try {
            MailSignature::forCompany(playbackNonce: 'escape-state-bypass')->render();
            $this->fail('Der CSS-Escape-Zustand-BYPASS wurde beim Rendern nicht abgelehnt.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('CSS-Escape-Zeichen', $exception->getMessage());
        }
    }

    public function test_signatur_lehnt_html_entity_delimiter_beim_save_publish_und_rendern_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $attackHtml = preg_replace(
            '/(<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*style="[^"]*)(?=")/i',
            '${1}&#59;background:none;',
            $canonicalHtml,
            1,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $this->assertIsString($attackHtml);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $attackHtml,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertSame($canonicalHtml, (string) $document->fresh()->html);

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

        $this->assertNull($document->fresh()->published_at);

        $document->forceFill([
            'published_html' => $attackHtml,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        try {
            MailSignature::forCompany(playbackNonce: 'entity-delimiter-bypass')->render();
            $this->fail('Der HTML-Entity-Delimiter wurde beim Rendern nicht abgelehnt.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('background-Kurzform', $exception->getMessage());
        }
    }

    public function test_signatur_lehnt_ungueltige_css_envelope_zustaende_beim_save_publish_und_rendern_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $canonicalBuilderData = $document->builder_data;
        $canonicalCss = (string) $document->css;
        $canonicalHash = MailDocument::contentHashFor(
            $canonicalBuilderData,
            $canonicalHtml,
            $canonicalCss,
        );
        $canonicalPublishedHtml = $document->published_html;
        $canonicalPublishedCss = $document->published_css;
        $canonicalPublishedAt = $document->published_at;
        $canonicalStatus = $document->status;

        $attacks = [
            'raw vertical tab' => [
                str_replace('background-image:', "\vbackground-image:", $canonicalHtml),
                'CSS-Steuerzeichen',
            ],
            'entity vertical tab' => [
                str_replace('background-image:', '&#11;background-image:', $canonicalHtml),
                'CSS-Steuerzeichen',
            ],
            'unmatched opening brace' => [
                str_replace('background-image:', 'x{;background-image:', $canonicalHtml),
                'CSS-Klammern',
            ],
            'unmatched opening bracket' => [
                str_replace('background-image:', 'x[;background-image:', $canonicalHtml),
                'CSS-Klammern',
            ],
            'stray closing brace' => [
                str_replace('background-image:', 'x};background-image:', $canonicalHtml),
                'CSS-Klammern',
            ],
            'stray closing bracket' => [
                str_replace('background-image:', 'x];background-image:', $canonicalHtml),
                'CSS-Klammern',
            ],
            'raw carriage return in url string' => [
                str_replace(
                    'url({{GRUND_RASTER_SRC}})',
                    "url('{{GRUND_RASTER_SRC}}\r')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'raw line feed in url string' => [
                str_replace(
                    'url({{GRUND_RASTER_SRC}})',
                    "url('{{GRUND_RASTER_SRC}}\n')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'raw form feed in url string' => [
                str_replace(
                    'url({{GRUND_RASTER_SRC}})',
                    "url('{{GRUND_RASTER_SRC}}\f')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'entity carriage return in url string' => [
                str_replace(
                    'url({{GRUND_RASTER_SRC}})',
                    "url('{{GRUND_RASTER_SRC}}&#13;')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'entity line feed in url string' => [
                str_replace(
                    'url({{GRUND_RASTER_SRC}})',
                    "url('{{GRUND_RASTER_SRC}}&#10;')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'entity form feed in url string' => [
                str_replace(
                    'url({{GRUND_RASTER_SRC}})',
                    "url('{{GRUND_RASTER_SRC}}&#12;')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
        ];

        foreach ($attacks as $label => [$attackHtml, $runtimeMessage]) {
            $this->assertNotSame($canonicalHtml, $attackHtml, $label);
            $document->forceFill([
                'html' => $canonicalHtml,
                'content_hash' => $canonicalHash,
                'published_html' => $canonicalPublishedHtml,
                'published_css' => $canonicalPublishedCss,
                'published_at' => $canonicalPublishedAt,
                'status' => $canonicalStatus,
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $canonicalBuilderData,
                    'html' => $attackHtml,
                    'css' => $canonicalCss,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalHtml, (string) $document->fresh()->html, $label);

            $document->forceFill([
                'html' => $attackHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $canonicalBuilderData,
                    $attackHtml,
                    $canonicalCss,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalPublishedAt, $document->fresh()->published_at, $label);

            $document->forceFill([
                'published_html' => $attackHtml,
                'published_at' => now(),
                'status' => MailDocumentStatus::Published,
            ])->save();
            $this->app->forgetScopedInstances();

            try {
                MailSignature::forCompany(playbackNonce: 'css-envelope-'.$label)->render();
                $this->fail("{$label}: Der ungueltige CSS-Envelope wurde beim Rendern nicht abgelehnt.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString($runtimeMessage, $exception->getMessage(), $label);
            }
        }
    }

    public function test_signatur_erlaubt_nur_den_gekoppelten_kanonischen_layervertrag_beim_save_publish_und_rendern(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $canonicalBuilderData = $document->builder_data;
        $canonicalCss = (string) $document->css;
        $canonicalHash = MailDocument::contentHashFor(
            $canonicalBuilderData,
            $canonicalHtml,
            $canonicalCss,
        );
        $canonicalPublishedHtml = $document->published_html;
        $canonicalPublishedCss = $document->published_css;
        $canonicalPublishedAt = $document->published_at;
        $canonicalStatus = $document->status;

        $extraLayer = function (string $image) use ($canonicalHtml): string {
            $html = str_replace(
                'url({{TRAIN_SRC}})',
                $image.',url({{TRAIN_SRC}})',
                $canonicalHtml,
                $imageCount,
            );
            $html = str_replace(
                'background-repeat:repeat,no-repeat,no-repeat,no-repeat;',
                'background-repeat:repeat,no-repeat,no-repeat,no-repeat,no-repeat;',
                $html,
                $repeatCount,
            );
            $html = str_replace(
                'background-position:left top,right center,center center,75% bottom;',
                'background-position:left top,right center,center center,right bottom,75% bottom;',
                $html,
                $positionCount,
            );
            $html = str_replace(
                'background-size:64px 64px,auto 100%,100% 100%,auto 100%;',
                'background-size:64px 64px,auto 100%,100% 100%,auto 100%,auto 100%;',
                $html,
                $sizeCount,
            );

            $this->assertSame([1, 1, 1, 1], [$imageCount, $repeatCount, $positionCount, $sizeCount]);

            return $html;
        };
        $importantLonghand = function (string $property) use ($canonicalHtml): string {
            $html = preg_replace(
                '/('.preg_quote($property, '/').':[^;]*);/',
                '$1!important;',
                $canonicalHtml,
                1,
                $replacementCount,
            );
            $this->assertSame(1, $replacementCount);
            $this->assertIsString($html);

            return $html;
        };

        $attacks = [
            'unknown image function' => [
                str_replace('url({{GRUND_RASTER_SRC}})', 'foo()', $canonicalHtml),
                'Basis-Layer',
            ],
            'global repeat value' => [
                str_replace(
                    'background-repeat:repeat,no-repeat,no-repeat,no-repeat;',
                    'background-repeat:inherit,no-repeat,no-repeat,no-repeat;',
                    $canonicalHtml,
                ),
                'Wiederholungswerte',
            ],
            'bogus position value' => [
                str_replace(
                    'background-position:left top,right center,center center,75% bottom;',
                    'background-position:bogus,right center,center center,75% bottom;',
                    $canonicalHtml,
                ),
                'Basispositionen',
            ],
            'bogus size value' => [
                str_replace(
                    'background-size:64px 64px,auto 100%,100% 100%,auto 100%;',
                    'background-size:bogus,auto 100%,100% 100%,auto 100%;',
                    $canonicalHtml,
                ),
                'Basisgroessen',
            ],
            'foreign url layer' => [
                $extraLayer('url(https://rail-time.de/foreign-train.gif)'),
                'Legacy-Idle-Layer',
            ],
            'foreign data uri layer' => [
                $extraLayer('url(data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==)'),
                'Legacy-Idle-Layer',
            ],
            'important background image' => [$importantLonghand('background-image'), '!important'],
            'important background repeat' => [$importantLonghand('background-repeat'), '!important'],
            'important background position' => [$importantLonghand('background-position'), '!important'],
            'important background size' => [$importantLonghand('background-size'), '!important'],
        ];

        foreach ($attacks as $label => [$attackHtml, $runtimeMessage]) {
            $this->assertNotSame($canonicalHtml, $attackHtml, $label);
            $document->forceFill([
                'html' => $canonicalHtml,
                'content_hash' => $canonicalHash,
                'published_html' => $canonicalPublishedHtml,
                'published_css' => $canonicalPublishedCss,
                'published_at' => $canonicalPublishedAt,
                'status' => $canonicalStatus,
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $canonicalBuilderData,
                    'html' => $attackHtml,
                    'css' => $canonicalCss,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalHtml, (string) $document->fresh()->html, $label);

            $document->forceFill([
                'html' => $attackHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $canonicalBuilderData,
                    $attackHtml,
                    $canonicalCss,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertEquals($canonicalPublishedAt, $document->fresh()->published_at, $label);

            $document->forceFill([
                'published_html' => $attackHtml,
                'published_at' => now(),
                'status' => MailDocumentStatus::Published,
            ])->save();
            $this->app->forgetScopedInstances();

            try {
                MailSignature::forCompany(playbackNonce: 'layer-contract-'.$label)->render();
                $this->fail("{$label}: Der ungueltige Layervertrag wurde beim Rendern nicht abgelehnt.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString($runtimeMessage, $exception->getMessage(), $label);
            }
        }
    }

    public function test_signatur_save_und_publish_lehnen_unvollstaendige_oder_mehrdeutige_longhand_vertraege_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $canonicalBuilderData = $document->builder_data;
        $canonicalCss = (string) $document->css;

        $missingSizeHtml = preg_replace(
            '/background-size:[^;]*;/',
            '',
            $canonicalHtml,
            1,
            $missingSizeCount,
        );
        $duplicateImageHtml = str_replace(
            'background-image:',
            'background-image:none;background-image:',
            $canonicalHtml,
            $duplicateImageCount,
        );
        $nonParallelHtml = str_replace(
            'background-repeat:repeat,no-repeat,no-repeat,no-repeat;',
            'background-repeat:repeat,no-repeat,no-repeat;',
            $canonicalHtml,
            $nonParallelCount,
        );

        $this->assertSame(1, $missingSizeCount);
        $this->assertIsString($missingSizeHtml);
        $this->assertSame(1, $duplicateImageCount);
        $this->assertSame(1, $nonParallelCount);

        $attacks = [
            'fehlendes background-size' => $missingSizeHtml,
            'doppeltes background-image' => $duplicateImageHtml,
            'nicht parallele background-Listen' => $nonParallelHtml,
        ];

        foreach ($attacks as $label => $attackHtml) {
            $document->forceFill([
                'html' => $canonicalHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $canonicalBuilderData,
                    $canonicalHtml,
                    $canonicalCss,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $canonicalBuilderData,
                    'html' => $attackHtml,
                    'css' => $canonicalCss,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalHtml, (string) $document->fresh()->html, $label);

            $document->forceFill([
                'html' => $attackHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $canonicalBuilderData,
                    $attackHtml,
                    $canonicalCss,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertNull($document->fresh()->published_at, $label);
        }
    }

    public function test_separate_mail_css_spalten_sperren_important_und_reservierte_zugquellen(): void
    {
        $this->seedDocuments();
        $admin = $this->admin();
        $attacks = [
            '.rt-sign-cell{background:none!important;}',
            '*{opacity:1&#33;important;}',
            '.rt-train-idle-overlay{visibility:visible!\\69mportant;}',
            '.x{content:"unterminated;}',
            ".x{content:\"line\nbreak\";}",
            ".x{content:\"line\rbreak\";}",
            ".x{content:\"line\fbreak\";}",
            sprintf('.x{content:"unfinished%c', 92),
            '@keyframes injected{from{opacity:0}to{opacity:1}}',
            '.x{animation:injected 1s linear;}',
            '.x{animation-delay:1s;}',
            '.x{anim\\61 tion:injected 1s;}',
            'td{mso-padding-alt:20px;}',
            'td{MSO-PADDING-ALT:20px;}',
            'td{mso-padding&#45;alt:20px;}',
            'td{mso-padd\\69 ng-alt:20px;}',
            'td{mso-padding/**/-alt:20px;}',
            '.rt-sign-\\63 ell::before{content:"x";}',
            '.rt-train-main-image{opacity:0;}',
            '.rt-train-main-layer{height:auto;}',
            '.rt-sign-train{display:none;}',
            '.rt-train-idle-overlay::before{content:"x";}',
            '.rt-train-idle-surface::after{content:"x";}',
            '.rt-train-idle-image{opacity:0;}',
            '.rt-classic-outlook-train::before{content:"x";}',
            '[data-rt-train-main-image]{visibility:hidden;}',
            '[data-rt-train-main-layer]{height:auto;}',
            '[data-rt-train]{visibility:hidden;}',
            '[data-rt-train-idle-overlay]{opacity:1;}',
            '[data-rt-train-idle-image="1"]{visibility:hidden;}',
            '[class]{color:red;}',
            '[class="foo rt-sign-cell"]::before{content:"x";}',
            '[cl\\61 ss~="rt-sign-\\63 ell"]::before{content:"x";}',
            '[class*="train-idle"]::after{content:"x";}',
            '[class~="ordinary-card"]{color:inherit;}',
            'td[class$=" rt-sign-cell"]{background:none;}',
            'td[class^="rt-pad rt-sign-\\63 ell"]{background:none;}',
            "[cl\\61\r\nss~='ordinary-card']{color:inherit;}",
            ".rt-sign-\\63\r\nell::before{content:\"x\";}",
            ".rt-sign-\\63\rell::before{content:\"x\";}",
            ".rt-sign-\\63\fell::before{content:\"x\";}",
            '.rt-sign-\\63&#13;&#10;ell::before{content:"x";}',
            "*{opacity:1!imp\\6f\r\nrtant;}",
            "*{opacity:1!imp\\6f\frtant;}",
            '.rt-sign-logo{background-image:url({{TRAIN_SRC}});}',
            '.rt-sign-logo{background-image:url({{TRAIN_IDLE_SRC}});}',
            '.rt-sign-logo{background-image:url({{TRAIN_STILL_SRC}});}',
            '.x{content:"{{RESPONSIVE_CSS}}";}',
            '.x{content:"{{SIGNATURE_BLOCK}}";}',
            '.x{content:"{{APPLICATION_CONTENT}}";}',
        ];

        foreach (MailDocumentKind::cases() as $kind) {
            $document = $this->document($kind);
            $canonicalCss = (string) $document->css;

            foreach ($attacks as $css) {
                $document->forceFill([
                    'css' => $canonicalCss,
                    'content_hash' => MailDocument::contentHashFor(
                        $document->builder_data,
                        (string) $document->html,
                        $canonicalCss,
                    ),
                ])->save();
                $document->refresh();

                $this->actingAs($admin)
                    ->putJson(route('admin.mail-documents.update', $document), [
                        'builder_data' => $document->builder_data,
                        'html' => (string) $document->html,
                        'css' => $css,
                        'expected_hash' => $document->content_hash,
                    ])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('css');

                $document->forceFill([
                    'css' => $css,
                    'content_hash' => MailDocument::contentHashFor(
                        $document->builder_data,
                        (string) $document->html,
                        $css,
                    ),
                ])->save();
                $document->refresh();

                $this->actingAs($admin)
                    ->postJson(route('admin.mail-documents.publish', $document), [
                        'expected_hash' => $document->content_hash,
                    ])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('css');
            }
        }
    }

    public function test_template_style_und_signatur_slot_bleiben_kanonisch_und_strukturell_echt(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);
        $admin = $this->admin();
        $canonical = (string) $document->html;
        $withoutSignature = str_replace('{{SIGNATURE_BLOCK}}', '', $canonical);
        $attacks = [
            'extra style' => str_replace('</head>', '<style>*{opacity:1!important}</style></head>', $canonical),
            'commented canonical style' => str_replace(
                ['<style>', '</style>'],
                ['<!-- <style>', '</style> -->'],
                $canonical,
            ),
            'changed canonical style' => str_replace(
                'a { color: inherit; }',
                'a { color: inherit; } * { opacity: 1 !important; }',
                $canonical,
            ),
            'missing Outlook mark still' => str_replace('{{ICON_RT_STILL_SRC}}', '', $canonical),
            'duplicate Outlook mark still' => str_replace(
                '<!-- RT_TEMPLATE_MARK_END -->',
                '<img src="{{ICON_RT_STILL_SRC}}" alt=""><!-- RT_TEMPLATE_MARK_END -->',
                $canonical,
            ),
            'animated mark without mso hide' => str_replace(';mso-hide:all;', ';', $canonical),
            'mark still outside conditional' => str_replace(
                ['<!--[if mso]><img class="rt-mark"', '<![endif]-->'],
                ['<img class="rt-mark"', ''],
                $canonical,
            ),
            'comment signature slot' => str_replace(
                '{{SIGNATURE_BLOCK}}',
                '<!-- {{SIGNATURE_BLOCK}} -->',
                $canonical,
            ),
            'head signature slot' => str_replace(
                '</head>',
                '{{SIGNATURE_BLOCK}}</head>',
                $withoutSignature,
            ),
            'early train source' => str_replace(
                '</body>',
                '<img src="{{TRAIN_SRC}}" alt=""></body>',
                $canonical,
            ),
            'text application marker' => str_replace(
                '<!-- RT_APPLICATION_CONTENT_START -->',
                'RT_APPLICATION_CONTENT_START',
                $canonical,
            ),
            'attribute application marker' => str_replace(
                '<!-- RT_APPLICATION_CONTENT_START -->',
                '<span data-marker="<!-- RT_APPLICATION_CONTENT_START -->"></span>',
                $canonical,
            ),
        ];

        foreach ($attacks as $html) {
            $document->forceFill([
                'html' => $canonical,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $canonical,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $document->builder_data,
                    'html' => $html,
                    'css' => (string) $document->css,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $document->forceFill([
                'html' => $html,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $html,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }
    }

    public function test_signatur_html_sperrt_eigene_stylebloecke_und_standzugquellen(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonical = (string) $document->html;

        foreach ([
            $canonical.'<style>.rt-sign-cell{background:none!important}</style>',
            str_replace(
                'RT_SIGNATURE_MAIN_END',
                'RT_SIGNATURE_MAIN_END<img src="{{TRAIN_STILL_SRC}}" alt="">',
                $canonical,
            ),
        ] as $html) {
            $document->forceFill([
                'html' => $canonical,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $canonical,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $document->builder_data,
                    'html' => $html,
                    'css' => (string) $document->css,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }
    }

    public function test_runtime_erlaubt_train_still_nicht_als_bild_oder_zweiten_zug(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $invalid = str_replace(
            'RT_SIGNATURE_MAIN_END',
            'RT_SIGNATURE_MAIN_END<img src="{{TRAIN_STILL_SRC}}" alt="">',
            (string) $document->published_html,
        );
        $document->forceFill([
            'published_html' => $invalid,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        MailSignature::forCompany(playbackNonce: 'invalid-still-source')->render();
    }

    public function test_signaturmarker_muessen_exakte_geordnete_kommentarpaare_bleiben(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonical = (string) $document->html;
        $attacks = [
            str_replace(
                '<!-- RT_SIGNATURE_MAIN_END -->',
                '<!-- RT_SIGNATURE_MAIN_END_EXTRA -->',
                $canonical,
            ),
            str_replace(
                '<!-- RT_SIGNATURE_MAIN_END -->',
                '<!-- RT_SIGNATURE_MAIN_END --><!-- RT_SIGNATURE_MAIN_END -->',
                $canonical,
            ),
            str_replace(
                '<!-- RT_PHONE_START -->',
                '<!-- RT_PHONE_START_EXTRA -->',
                $canonical,
            ),
            strtr($canonical, [
                '<!-- RT_PHONE_START -->' => '<!-- RT_PHONE_END -->',
                '<!-- RT_PHONE_END -->' => '<!-- RT_PHONE_START -->',
            ]),
            str_replace('<!-- RT_WEBSITE_START -->', '', $canonical),
            str_replace(
                '<!-- RT_COMPANY_PHONE_END -->',
                '<!-- RT_COMPANY_PHONE_END --><!-- RT_COMPANY_PHONE_END -->',
                $canonical,
            ),
            str_replace(
                '<!-- RT_SIGNATURE_MAIN_END -->',
                '<span><!-- RT_SIGNATURE_MAIN_END --></span>',
                $canonical,
            ),
            str_replace(
                '<!-- RT_PHONE_END -->',
                '<tr><td>unerlaubte Zusatzzeile</td></tr><!-- RT_PHONE_END -->',
                $canonical,
            ),
            str_replace(
                '<table class="rt-contact"',
                '<table class="rt-contact rt-company-contact"',
                $canonical,
            ),
            str_replace(
                'class="rt-contact rt-company-contact"',
                'class="rt-contact"',
                $canonical,
            ),
            str_replace('{{DURCHWAHL}}', '{{MOBIL}}', $canonical),
            str_replace('{{FIRMEN_WEBSITE_LABEL}}', '{{FIRMEN_TELEFON}}', $canonical),
        ];

        foreach ($attacks as $html) {
            $document->forceFill([
                'html' => $canonical,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $canonical,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $document->builder_data,
                    'html' => $html,
                    'css' => (string) $document->css,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $document->forceFill([
                'html' => $html,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $html,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }
    }

    public function test_runtime_bettet_editierbares_css_vor_trusted_regeln_ein_und_liefert_ein_zugbild(): void
    {
        $this->seedDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $signature = $this->document(MailDocumentKind::Signature);

        $template->forceFill([
            'published_css' => '.rt-title{letter-spacing:0;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $signature->forceFill([
            'published_css' => '.rt-sign-name{letter-spacing:0;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $html = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>CSS-Vertrag</p>'));
        $templateCss = strpos($html, 'data-rt-mail-document-css="template"');
        $signatureCss = strpos($html, 'data-rt-mail-document-css="signature"');
        $trusted = strpos($html, '/* RT_SERVER_SIGNATURE_RUNTIME_START');

        $this->assertIsInt($templateCss);
        $this->assertIsInt($signatureCss);
        $this->assertIsInt($trusted);
        $this->assertLessThan($trusted, $templateCss);
        $this->assertLessThan($trusted, $signatureCss);
        $this->assertSame(1, substr_count($html, 'data-rt-train'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train"'));
        $this->assertMatchesRegularExpression(
            '/<img\b(?=[^>]*class="[^"]*\brt-sign-train\b[^"]*")(?=[^>]*\bdata-rt-train(?:\s|=|>))[^>]*>/',
            $html,
        );
        $this->assertStringNotContainsString('data-rt-train-idle', $html);
        $this->assertStringNotContainsString('zug-dampf-idle-', $html);
        $this->assertStringNotContainsString('@keyframes rt-train-idle-reveal', $html);
    }

    public function test_legacy_published_css_mit_important_bricht_runtime_fail_closed_ab(): void
    {
        $this->seedDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $template->forceFill([
            'published_css' => '*{opacity:1!important;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_template_css_mit_keyframes_bricht_runtime_fail_closed_ab(): void
    {
        $this->seedDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $template->forceFill([
            'published_css' => '@keyframes injected{from{opacity:0}to{opacity:1}}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectExceptionMessage(CssSemantic::PROTECTED_EDITABLE_CSS_MESSAGE);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_signature_css_mit_escape_geschuetztem_selector_bricht_runtime_ab(): void
    {
        $this->seedDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $signature->forceFill([
            'published_css' => '.rt-sign-\\63 ell::before{content:"zweiter Zug";}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectExceptionMessage(CssSemantic::PROTECTED_EDITABLE_CSS_MESSAGE);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_signature_css_mit_crlf_hexescape_bricht_runtime_ab(): void
    {
        $this->seedDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $signature->forceFill([
            'published_css' => ".rt-sign-\\63\r\nell::before{content:\"zweiter Zug\";}",
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectExceptionMessage(CssSemantic::PROTECTED_EDITABLE_CSS_MESSAGE);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_template_css_mit_crlf_important_bricht_runtime_ab(): void
    {
        $this->seedDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $template->forceFill([
            'published_css' => "*{opacity:1!imp\\6f\r\nrtant;}",
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_template_css_mit_responsive_runtime_token_bricht_fail_closed_ab(): void
    {
        $this->seedDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $template->forceFill([
            'published_css' => '.x{content:"{{RESPONSIVE_CSS}}";}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_signature_css_mit_html_runtime_token_bricht_fail_closed_ab(): void
    {
        $this->seedDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $signature->forceFill([
            'published_css' => '.x{content:"{{SIGNATURE_BLOCK}} {{APPLICATION_CONTENT}}";}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_published_signature_css_mit_zugtoken_bricht_runtime_fail_closed_ab(): void
    {
        $this->seedDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $signature->forceFill([
            'published_css' => '.rt-sign-logo{background-image:url({{TRAIN_SRC}});}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
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
        $css = '.rt-title{letter-spacing:0;}'
            .'.animation-card{content:"@keyframes rt-sign-cell";}'
            .'[data-label="rt-sign-cell"]{letter-spacing:0;}'
            .'.ordinary-card{content:"[class=rt-sign-cell]";}';
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
            'RT_COMPANY_EMAIL_START',
            'RT_COMPANY_EMAIL_END',
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
