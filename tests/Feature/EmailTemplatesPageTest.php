<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\PageHelpCatalog;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;
use ZipArchive;

class EmailTemplatesPageTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_guest_is_redirected_from_email_templates_page(): void
    {
        $this->get(route('email-templates.index'))
            ->assertRedirect(route('login'));
    }

    public function test_verified_user_sees_two_modals_and_two_initially_closed_download_accordions(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('email-templates.index'));
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee(__('app.email_templates'))
            ->assertSee(route('email-templates.download', ['template' => 'vorlage-eml']), escape: false)
            ->assertSee(route('email-templates.download', ['template' => 'vorlage-html']), escape: false)
            ->assertSee(route('email-templates.download', ['template' => 'vorlage-dunkel-eml']), escape: false)
            ->assertSee(route('email-templates.download', ['template' => 'vorlage-dunkel-html']), escape: false)
            ->assertSee('previewUrls:', escape: false)
            ->assertSee('previewDownloadUrls:', escape: false)
            ->assertSee('profileModalOpen: false', escape: false)
            ->assertSee('previewModalOpen: false', escape: false)
            ->assertSee('openAccordionSection: null', escape: false)
            ->assertSee("mailTheme: 'light'", escape: false)
            ->assertSee("signatureTheme: 'light'", escape: false)
            ->assertSee('data-email-template-modal-trigger="profile"', escape: false)
            ->assertSee('data-email-template-modal-trigger="preview"', escape: false)
            ->assertSee('data-email-template-modal="profile"', escape: false)
            ->assertSee('data-email-template-modal="preview"', escape: false)
            ->assertSee('aria-haspopup="dialog"', escape: false)
            ->assertSee('aria-controls="email-template-profile-modal"', escape: false)
            ->assertSee('aria-controls="email-template-preview-modal"', escape: false)
            ->assertSee('data-email-template-accordion="mail"', escape: false)
            ->assertSee('data-email-template-accordion="signature"', escape: false)
            ->assertSee('data-email-template-theme-toggle="mail"', escape: false)
            ->assertSee('data-email-template-theme-toggle="signature"', escape: false)
            ->assertSee(__('app.email_templates_outlook_install'))
            ->assertSee('data-template-format="zip"', escape: false)
            ->assertSee('<template x-if="previewModalOpen">', escape: false)
            ->assertSee('x-bind:src="previewFrameUrl()"', escape: false)
            ->assertSee('data-email-template-preview-replay', escape: false)
            ->assertSee("window.matchMedia('(prefers-reduced-motion: reduce)')", escape: false)
            ->assertSee('previewPlaybackId: 0', escape: false)
            ->assertDontSee('data-email-template-motif-toggle', escape: false)
            ->assertSee('data-email-template-preview-frame', escape: false)
            ->assertDontSee('data-email-template-accordion="profile"', escape: false)
            ->assertDontSee('data-email-template-accordion="preview"', escape: false)
            ->assertSee('rounded-xl px-4 py-4 shadow-lg', escape: false)
            ->assertSee('grid-cols-[minmax(0,1fr)_4.75rem] items-stretch', escape: false)
            ->assertSee('flex flex-col justify-end border-l pl-3 text-right', escape: false)
            ->assertSee('data-menu-active="true"', escape: false);

        preg_match_all('/data-template-key="([^"]+)"/', $content, $matches);

        $this->assertCount(9, $matches[1]);
        $this->assertCount(9, array_unique($matches[1]));
        $this->assertEqualsCanonicalizing([
            'vorlage-eml',
            'vorlage-html',
            'vorlage-dunkel-eml',
            'vorlage-dunkel-html',
            'signatur-hell',
            'signatur-dunkel',
            'signatur-outlook-hell',
            'signatur-outlook-dunkel',
            'signatur-text',
        ], $matches[1]);
        $this->assertSame(2, substr_count($content, 'data-email-template-modal="'));
        $this->assertSame(2, substr_count($content, 'data-email-template-modal-trigger="'));
        $this->assertSame(2, substr_count($content, 'data-email-template-accordion="'));
        $this->assertSame(2, substr_count($content, 'data-email-template-theme-toggle="'));
        $this->assertSame(4, substr_count($content, 'data-email-template-theme-option="'));
        $this->assertSame(1, substr_count($content, '<iframe'));
        $this->assertDoesNotMatchRegularExpression('/<iframe\b[^>]*\ssrc=/i', $content);
        $this->assertSame(1, substr_count($content, 'data-testid="message-viewer-host"'));

        $modalSource = file_get_contents(resource_path('views/components/ui/state-modal.blade.php'));
        $this->assertStringContainsString('id="{{ $id }}"', $modalSource);
        $this->assertStringContainsString('document.getElementById(@js($titleId))?.focus()', $modalSource);
        $this->assertStringContainsString('x-show.important=', $modalSource);
        $this->assertStringContainsString('x-trap.inert.noscroll=', $modalSource);
        $this->assertStringContainsString('max-h-[calc(100dvh-1rem)]', $modalSource);
        $this->assertStringContainsString('overscroll-contain', $modalSource);
        $this->assertStringNotContainsString('email-template-preview-dialog', file_get_contents(resource_path('css/app.css')));
    }

    public function test_profile_no_longer_contains_an_email_templates_tab(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertDontSee('id="tab-templates"', escape: false)
            ->assertDontSee('id="panel-templates"', escape: false);
    }

    public function test_admin_sees_email_templates_as_active_own_sidebar_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('email-templates.index'))
            ->assertOk()
            ->assertSee(route('email-templates.index'), escape: false)
            ->assertSee(route('admin.mail-documents.editor', ['open' => 1]), escape: false)
            ->assertSee(route('admin.mail-documents.editor', ['dokument' => 'template', 'open' => 1]), escape: false)
            ->assertSee(route('admin.mail-documents.editor', ['dokument' => 'signature', 'open' => 1]), escape: false)
            ->assertSee('data-email-template-editor-link', escape: false)
            ->assertSee('Vorlagen &amp; Signaturen bearbeiten', escape: false)
            ->assertSee('data-menu-active="true"', escape: false);
    }

    public function test_non_admin_does_not_see_the_mail_document_editor_action(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('email-templates.index'))
            ->assertOk()
            ->assertDontSee(route('admin.mail-documents.editor'), escape: false)
            ->assertDontSee('data-email-template-editor-link', escape: false);
    }

    public function test_personalized_template_can_be_downloaded_and_unknown_key_returns_404(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);

        $response = $this->actingAs($user)
            ->get(route('email-templates.download', ['template' => 'signatur-text']));

        $response->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertSee('Mara Beispiel');

        $this->assertStringContainsString(
            'attachment; filename="RailTime-Signatur-mara-beispiel.txt"',
            (string) $response->headers->get('content-disposition')
        );

        $this->actingAs($user)
            ->get(route('email-templates.download', ['template' => 'unbekannt']))
            ->assertNotFound();
    }

    public function test_light_and_dark_mail_variants_have_explicit_download_contracts(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $expectations = [
            'vorlage-eml' => ['message/rfc822', 'RailTime-E-Mailvorlage-hell-mara-beispiel.eml'],
            'vorlage-html' => ['text/html; charset=UTF-8', 'RailTime-E-Mailvorlage-hell-mara-beispiel.html'],
            'vorlage-dunkel-eml' => ['message/rfc822', 'RailTime-E-Mailvorlage-dunkel-mara-beispiel.eml'],
            'vorlage-dunkel-html' => ['text/html; charset=UTF-8', 'RailTime-E-Mailvorlage-dunkel-mara-beispiel.html'],
        ];

        foreach ($expectations as $key => [$mime, $filename]) {
            $response = $this->actingAs($user)
                ->get(route('email-templates.download', ['template' => $key]));

            $response->assertOk()->assertHeader('content-type', $mime);
            $this->assertStringContainsString(
                'attachment; filename="'.$filename.'"',
                (string) $response->headers->get('content-disposition')
            );
        }
    }

    /**
     * Der Dampflok-Gueterzug steht still im Hintergrund des
     * Signaturbereichs — in JEDER herunterladbaren HTML-Fassung, mit der
     * zum Thema passenden Toenung. Die Textsignatur bleibt reiner Text.
     */
    public function test_every_downloadable_html_variant_carries_the_themed_steam_train(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $builder = new EmailTemplateBuilder($user);

        $asset = fn (string $file, string $mime) => 'data:'.$mime.';base64,'.base64_encode(
            file_get_contents(resource_path('mail-templates/assets/'.$file))
        );

        $lightTrain = $asset('zug-dampf-light.png', 'image/png');
        $darkTrain = $asset('zug-dampf-dark.png', 'image/png');

        // Die eigenstaendigen Signaturen zeigen die einmalige Einfahrt,
        // die Vorlagen das Standbild.
        $expected = [
            'vorlage-html' => $lightTrain,
            'vorlage-dunkel-html' => $darkTrain,
            'signatur-hell' => $asset('zug-dampf-light.gif', 'image/gif'),
            'signatur-dunkel' => $asset('zug-dampf-dark.gif', 'image/gif'),
        ];

        foreach ($expected as $template => $train) {
            $html = $builder->build($template)['content'];

            $this->assertStringNotContainsString('{{TRAIN_SRC}}', $html, $template);
            $this->assertStringContainsString("url('".$train, $html, $template);
            $this->assertStringContainsString('background-repeat:no-repeat', $html, $template);
            $this->assertStringContainsString('background-position:center center,left bottom', $html, $template);
            $this->assertStringContainsString('background-size:100% 100%,86% auto', $html, $template);

            // Die Kurzform background: setzt background-image zurueck — sie
            // muss deshalb VOR der Bildangabe stehen, sonst verschwindet der
            // Zug lautlos.
            $backgroundShorthand = strpos($html, ';background:#');
            $backgroundImage = strpos($html, 'background-image:linear-gradient(');
            if ($backgroundShorthand !== false) {
                $this->assertLessThan($backgroundImage, $backgroundShorthand, $template);
            }
        }

        // Auch die .eml-Fassungen tragen ihn (dort immer eingebettet, weil
        // ein cid: im CSS-Hintergrund client-abhaengig waere).
        foreach (['vorlage-eml' => $lightTrain, 'vorlage-dunkel-eml' => $darkTrain] as $template => $train) {
            $this->assertStringContainsString(
                "url('data:image/png;base64,",
                $this->decodeEmlHtmlPart($builder->build($template)['content']),
                $template
            );
        }

        // Reine Textsignatur bleibt unberuehrt.
        $this->assertStringNotContainsString(
            'base64',
            $builder->build('signatur-text')['content']
        );
    }

    public function test_the_animated_steam_train_plays_once_with_exact_timing(): void
    {
        foreach ([
            'zug-dampf-light.gif', 'zug-dampf-dark.gif',
        ] as $file) {
            $binary = file_get_contents(resource_path('mail-templates/assets/'.$file));

            $this->assertStringStartsWith('GIF89a', $binary, $file);
            $this->assertGreaterThan(45, substr_count($binary, "\x21\xf9\x04"), $file);
            $this->assertStringNotContainsString('NETSCAPE2.0', $binary, $file);

            $durations = [];
            $offset = 0;
            while (($offset = strpos($binary, "\x21\xf9\x04", $offset)) !== false) {
                $durations[] = ord($binary[$offset + 4]) | (ord($binary[$offset + 5]) << 8);
                $offset += 8;
            }

            $this->assertSame(30, $durations[0], "{$file}: Startverzoegerung muss 300 ms betragen.");
            $this->assertSame(740, array_sum($durations), "{$file}: 300 + 5500 + 1600 ms erwartet.");
            $this->assertLessThanOrEqual(200 * 1024, strlen($binary), $file);
        }

        foreach (['zug-dampf-idle-light.gif', 'zug-dampf-idle-dark.gif'] as $file) {
            $binary = file_get_contents(resource_path('mail-templates/assets/'.$file));
            $marker = strpos($binary, 'NETSCAPE2.0');
            $this->assertNotFalse($marker, "{$file}: Standrauch muss loopen.");
            $loops = ord($binary[$marker + 14]) | (ord($binary[$marker + 15]) << 8);
            $this->assertSame(0, $loops, "{$file}: Standrauch muss endlos loopen.");

            $durations = [];
            $offset = 0;
            while (($offset = strpos($binary, "\x21\xf9\x04", $offset)) !== false) {
                $durations[] = ord($binary[$offset + 4]) | (ord($binary[$offset + 5]) << 8);
                $offset += 8;
            }

            $this->assertSame(370, array_sum($durations), "{$file}: Idle-Loop muss 3,7 s dauern.");
            $this->assertSame(0, 740 % array_sum($durations), "{$file}: Einfahrt muss exakt auf der Idle-Loop-Naht enden.");
            $this->assertLessThanOrEqual(200 * 1024, strlen($binary), $file);
        }
    }

    public function test_outlook_export_contains_installable_signature_and_one_regular_gif(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);

        foreach (['hell' => 'light', 'dunkel' => 'dark'] as $variant => $theme) {
            $key = "signatur-outlook-{$variant}";
            $response = $this->actingAs($user)
                ->get(route('email-templates.download', ['template' => $key]));

            $response->assertOk()->assertHeader('content-type', 'application/zip');
            $this->assertStringContainsString(
                "RailTime-Outlook-Signatur-{$variant}-mara-beispiel.zip",
                (string) $response->headers->get('content-disposition'),
            );

            $tempPath = tempnam(sys_get_temp_dir(), 'railtime-outlook-test-');
            $this->assertNotFalse($tempPath);
            file_put_contents($tempPath, $response->getContent());

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($tempPath));
            $signatureName = "RailTime-Signatur-{$variant}-mara-beispiel";
            $assetFolder = "{$signatureName}_files";

            foreach ([
                "{$signatureName}.htm",
                "{$signatureName}.rtf",
                "{$signatureName}.txt",
                "{$assetFolder}/zug-dampf.gif",
                "{$assetFolder}/logo.png",
                "{$assetFolder}/contact-location.png",
                "{$assetFolder}/contact-phone.png",
                "{$assetFolder}/contact-mobile.png",
                "{$assetFolder}/contact-email.png",
                "{$assetFolder}/contact-web.png",
                'Outlook-klassisch-installieren.cmd',
                'RailTime-Outlook-Installer.ps1',
                'README-Outlook.html',
            ] as $path) {
                $this->assertNotFalse($zip->locateName($path), $path);
            }

            $html = $zip->getFromName("{$signatureName}.htm");
            $this->assertIsString($html);
            $this->assertStringContainsString('<img data-rt-outlook-train', $html);
            $this->assertStringContainsString("src=\"{$assetFolder}/zug-dampf.gif\"", $html);
            $this->assertStringContainsString('class="rt-pad rt-sign-cell"', $html);
            $this->assertStringContainsString('style="padding:0;background:', $html);
            $this->assertStringContainsString('<td style="padding:18px 30px 0;">', $html);
            $this->assertStringContainsString('<td align="left" style="padding:12px 0 20px;text-align:left;', $html);
            $this->assertStringContainsString('height:auto;margin:0;border:0;outline:none;', $html);
            $this->assertStringNotContainsString('data:image/gif;base64,', $html);
            $this->assertStringNotContainsString('background-image:linear-gradient(', $html);
            $this->assertSame(1, substr_count($html, '/zug-dampf.gif'));

            $gif = $zip->getFromName("{$assetFolder}/zug-dampf.gif");
            $this->assertSame(
                file_get_contents(resource_path("mail-templates/assets/zug-dampf-outlook-{$theme}.gif")),
                $gif,
            );
            $this->assertStringStartsWith('GIF89a', $gif);
            $this->assertStringNotContainsString('NETSCAPE2.0', $gif);
            $this->assertLessThanOrEqual(200 * 1024, strlen($gif));

            $durations = [];
            $offset = 0;
            while (($offset = strpos($gif, "\x21\xf9\x04", $offset)) !== false) {
                $durations[] = ord($gif[$offset + 4]) | (ord($gif[$offset + 5]) << 8);
                $offset += 8;
            }
            $this->assertSame(1110, array_sum($durations), 'Outlook-GIF muss 11,1 Sekunden dauern.');

            $installer = $zip->getFromName('Outlook-klassisch-installieren.cmd');
            $this->assertStringContainsString("set \"SIGNATURE_NAME={$signatureName}\"", $installer);
            $this->assertStringContainsString('RailTime-Outlook-Installer.ps1', $installer);
            $this->assertStringContainsString('-NoLogo -NoProfile -STA -ExecutionPolicy Bypass', $installer);
            $this->assertStringContainsString('RAILTIME_INSTALLER_TEST_MODE', $installer);
            $this->assertStringNotContainsString('\\{'.$signatureName.'}', $installer);
            $this->assertStringContainsString('Das ZIP wurde nicht vollstaendig entpackt', $installer);
            $this->assertStringContainsString('[FEHLER]', $installer);
            $this->assertSame(0, preg_match('/(?<!\r)\n/', $installer), 'CMD muss reine CRLF-Zeilenenden verwenden.');

            $installerScript = $zip->getFromName('RailTime-Outlook-Installer.ps1');
            $this->assertIsString($installerScript);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $installerScript, 'Windows PowerShell 5.1 benötigt für Umlaute eine UTF-8-BOM.');
            $this->assertStringNotContainsString('__RAILTIME_SIGNATURE_NAME__', $installerScript);
            $this->assertStringContainsString("[string] \$SignatureName = '{$signatureName}'", $installerScript);
            $this->assertStringContainsString('System.Windows.Forms', $installerScript);
            $this->assertStringContainsString('AutoScaleMode]::Dpi', $installerScript);
            $this->assertStringContainsString('Outlook schließen und installieren', $installerScript);
            $this->assertStringContainsString('$installButton.Size = New-Object System.Drawing.Size(278, 46)', $installerScript);
            $this->assertStringContainsString('$logButton.Size = New-Object System.Drawing.Size(148, 46)', $installerScript);
            $this->assertStringContainsString('$closeButton.Size = New-Object System.Drawing.Size(174, 46)', $installerScript);
            $this->assertStringContainsString("@('OUTLOOK', 'olk')", $installerScript);
            $this->assertStringContainsString('CloseMainWindow()', $installerScript);
            $this->assertStringContainsString('Stop-Process -Id $process.Id -Force', $installerScript);
            $this->assertStringContainsString('MessageBoxDefaultButton]::Button2', $installerScript);
            $this->assertStringContainsString("'^[^@\\s]+@rail-time\\.de$'", $installerScript);
            $this->assertStringContainsString("'New Signature'", $installerScript);
            $this->assertStringContainsString("'Reply-Forward Signature'", $installerScript);
            $this->assertStringContainsString("'DisableRoamingSignatures'", $installerScript);
            $this->assertStringContainsString('RailTime-Outlook-Signatur-Installation.log', $installerScript);
            $this->assertStringContainsString('Testmodus: Outlook-Prozesse werden nicht berührt.', $installerScript);
            $this->assertStringNotContainsString('Common\\MailSettings', $installerScript);

            if (PHP_OS_FAMILY === 'Windows') {
                $installerTestRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'railtime-outlook-installer-'.bin2hex(random_bytes(6));
                $packageDirectory = $installerTestRoot.DIRECTORY_SEPARATOR.'package';
                $fakeWindowsProfile = $installerTestRoot.DIRECTORY_SEPARATOR.'windows-profile';

                try {
                    File::ensureDirectoryExists($packageDirectory);
                    $this->assertTrue($zip->extractTo($packageDirectory));

                    $accountFixture = [
                        'DefaultProfile' => 'Outlook',
                        'Profiles' => [[
                            'Name' => 'Outlook',
                            'Accounts' => [
                                ['Key' => '00000001', 'Email' => 'person@example.org'],
                                ['Key' => '00000002', 'Email' => 'wrong@rail-time.de.example'],
                                ['Key' => '00000003', 'Email' => 'first@rail-time.de'],
                                ['Key' => '00000004', 'Email' => 'second@rail-time.de'],
                            ],
                        ]],
                    ];

                    $installation = $this->runOutlookInstaller($packageDirectory, $fakeWindowsProfile, $accountFixture);
                    $this->assertSame(0, $installation['exitCode'], $installation['output']);
                    $this->assertStringContainsString('[ERFOLG]', $installation['output']);
                    $this->assertStringNotContainsString('ECHO ', $installation['output']);
                    $this->assertTrue($installation['result']['Success']);
                    $this->assertSame('first@rail-time.de', $installation['result']['AccountEmail']);
                    $this->assertSame('00000003', $installation['result']['AccountKey']);
                    $this->assertSame($signatureName, $installation['result']['NewSignature']);
                    $this->assertSame($signatureName, $installation['result']['ReplyForwardSignature']);
                    $this->assertTrue($installation['result']['LocalSignatureMode']);

                    $reinstallation = $this->runOutlookInstaller($packageDirectory, $fakeWindowsProfile, $accountFixture);
                    $this->assertSame(0, $reinstallation['exitCode'], $reinstallation['output']);
                    $this->assertStringContainsString('[ERFOLG]', $reinstallation['output']);

                    $installedRoot = $fakeWindowsProfile.DIRECTORY_SEPARATOR.'AppData'.DIRECTORY_SEPARATOR.'Roaming'
                        .DIRECTORY_SEPARATOR.'Microsoft'.DIRECTORY_SEPARATOR.'Signatures';
                    foreach ([
                        "{$signatureName}.htm",
                        "{$signatureName}.rtf",
                        "{$signatureName}.txt",
                        "{$assetFolder}/zug-dampf.gif",
                        "{$assetFolder}/logo.png",
                        "{$assetFolder}/contact-location.png",
                        "{$assetFolder}/contact-phone.png",
                        "{$assetFolder}/contact-mobile.png",
                        "{$assetFolder}/contact-email.png",
                        "{$assetFolder}/contact-web.png",
                    ] as $installedFile) {
                        $this->assertFileExists($installedRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $installedFile));
                    }
                    $this->assertFileDoesNotExist($installedRoot.DIRECTORY_SEPARATOR."{{$signatureName}}.htm");

                    $logPath = $fakeWindowsProfile.DIRECTORY_SEPARATOR.'Temp'
                        .DIRECTORY_SEPARATOR.'RailTime-Outlook-Signatur-Installation.log';
                    $this->assertFileExists($logPath);
                    $this->assertStringContainsString('[ERFOLG]', File::get($logPath));

                    if ($variant === 'hell') {
                        $incompleteDirectory = $installerTestRoot.DIRECTORY_SEPARATOR.'incomplete-package';
                        $incompleteProfile = $installerTestRoot.DIRECTORY_SEPARATOR.'incomplete-profile';
                        File::ensureDirectoryExists($incompleteDirectory);
                        File::put(
                            $incompleteDirectory.DIRECTORY_SEPARATOR.'Outlook-klassisch-installieren.cmd',
                            $installer,
                        );

                        $incompleteInstallation = $this->runOutlookInstaller($incompleteDirectory, $incompleteProfile, $accountFixture);
                        $this->assertSame(11, $incompleteInstallation['exitCode'], $incompleteInstallation['output']);
                        $this->assertStringContainsString('[FEHLER]', $incompleteInstallation['output']);
                        $this->assertStringContainsString('vollstaendig entpackt', $incompleteInstallation['output']);

                        $missingAccountProfile = $installerTestRoot.DIRECTORY_SEPARATOR.'missing-account-profile';
                        $missingAccountFixture = [
                            'DefaultProfile' => 'Outlook',
                            'Profiles' => [[
                                'Name' => 'Outlook',
                                'Accounts' => [
                                    ['Key' => '00000001', 'Email' => 'person@example.org'],
                                    ['Key' => '00000002', 'Email' => 'wrong@rail-time.de.example'],
                                ],
                            ]],
                        ];
                        $missingAccount = $this->runOutlookInstaller($packageDirectory, $missingAccountProfile, $missingAccountFixture);
                        $this->assertSame(12, $missingAccount['exitCode'], $missingAccount['output']);
                        $this->assertStringContainsString('kein Konto mit der Domain @rail-time.de', $missingAccount['output']);
                        $this->assertFalse($missingAccount['result']['Success']);
                        $this->assertSame(12, $missingAccount['result']['ExitCode']);
                        $this->assertDirectoryDoesNotExist(
                            $missingAccountProfile.DIRECTORY_SEPARATOR.'AppData'.DIRECTORY_SEPARATOR.'Roaming'
                            .DIRECTORY_SEPARATOR.'Microsoft'.DIRECTORY_SEPARATOR.'Signatures',
                        );
                    }
                } finally {
                    File::deleteDirectory($installerTestRoot);
                }
            }

            $readme = $zip->getFromName('README-Outlook.html');
            $this->assertStringContainsString('Einstellungen → Konten → Signaturen', $readme);
            $this->assertStringContainsString('ZIP zuerst vollständig entpacken', $readme);
            $this->assertStringContainsString('Rechtsklick → Alle extrahieren', $readme);
            $this->assertStringContainsString('grafische Windows-Einrichtung', $readme);
            $this->assertStringContainsString('schließt Classic Outlook und das neue Outlook anschließend automatisch', $readme);
            $this->assertStringContainsString('ersten Konto mit einer Adresse, die exakt auf @rail-time.de endet', $readme);
            $this->assertStringContainsString('Classic-Signaturmodus', $readme);
            $this->assertStringContainsString('Erfolg oder Fehler erscheinen direkt in der Oberfläche', $readme);
            $this->assertStringContainsString('lokale Windows-Installationsroutine kann diese Signatur daher nicht direkt im neuen Outlook registrieren', $readme);

            $zip->close();
            unlink($tempPath);
        }
    }

    public function test_only_the_steam_train_is_public_and_the_old_motif_query_is_ignored(): void
    {
        $vector = resource_path('mail-templates/assets/zug-dampf.svg');
        $this->assertFileExists($vector);
        $this->assertSame(
            file_get_contents(resource_path('mail-templates/source/rt-dampflok.svg')),
            file_get_contents($vector),
            'Das Website-SVG muss dieselben Lokproportionen und Waggons wie die Animationsquelle verwenden.',
        );
        $vectorSvg = file_get_contents($vector);
        $this->assertStringContainsString('id="steam-engine"', $vectorSvg);
        $this->assertStringContainsString('id="steam-plume"', $vectorSvg);
        $this->assertStringContainsString('id="running-gear"', $vectorSvg);
        $this->assertStringContainsString('id="coupling-rod"', $vectorSvg);
        $this->assertStringContainsString('id="main-rod"', $vectorSvg);
        $this->assertStringContainsString('id="additional-container-wagon"', $vectorSvg);
        $this->assertSame(5, substr_count($vectorSvg, 'data-drive-wheel='));

        $smokeFreeVector = resource_path('mail-templates/assets/zug-dampf-ohne-rauch.svg');
        $this->assertFileExists($smokeFreeVector);
        $smokeFreeSvg = file_get_contents($smokeFreeVector);
        $this->assertStringContainsString('id="steam-engine"', $smokeFreeSvg);
        $this->assertStringContainsString('id="running-gear"', $smokeFreeSvg);
        $this->assertStringContainsString('id="coupling-rod"', $smokeFreeSvg);
        $this->assertStringContainsString('id="main-rod"', $smokeFreeSvg);
        $this->assertStringContainsString('id="additional-container-wagon"', $smokeFreeSvg);
        $this->assertStringNotContainsString('id="steam-plume"', $smokeFreeSvg);
        $this->assertStringNotContainsString('<path class="smoke"', $smokeFreeSvg);
        $this->assertSame(5, substr_count($smokeFreeSvg, 'data-drive-wheel='));

        foreach (['light', 'dark'] as $theme) {
            foreach (['png', 'gif'] as $format) {
                $this->assertFileExists(resource_path("mail-templates/assets/zug-dampf-{$theme}.{$format}"));
                $this->assertFileDoesNotExist(resource_path("mail-templates/assets/zug-{$theme}.{$format}"));
            }
            $this->assertFileExists(resource_path("mail-templates/assets/zug-dampf-idle-{$theme}.gif"));
        }

        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $steam = (new EmailTemplateBuilder($user))->build('signatur-hell')['content'];
        $this->assertStringContainsString(
            'data:image/gif;base64,'.base64_encode(
                file_get_contents(resource_path('mail-templates/assets/zug-dampf-light.gif'))
            ),
            $steam,
        );

        $plain = $this->actingAs($user)->get(route('email-templates.download', ['template' => 'signatur-hell']));
        $legacyQuery = $this->actingAs($user)->get(route('email-templates.download', [
            'template' => 'signatur-hell',
            'motiv' => 'gueterzug',
        ]));
        $expectedGif = 'data:image/gif;base64,'.base64_encode(
            file_get_contents(resource_path('mail-templates/assets/zug-dampf-light.gif'))
        );
        $this->assertStringContainsString($expectedGif, $plain->getContent());
        $this->assertStringContainsString($expectedGif, $legacyQuery->getContent());
    }

    /**
     * Der Streifen ist auf den Desktop ausgelegt (flach); mobil holt eine
     * Media-Query die groessere Darstellung zurueck.
     */
    public function test_the_train_strip_is_desktop_sized_and_grows_on_mobile(): void
    {
        [$width, $height] = getimagesize(resource_path('mail-templates/assets/zug-dampf-light.png'));

        // Doppelte Aufloesung von 720 x 75 CSS-Pixeln.
        $this->assertSame(1440, $width);
        $this->assertSame(150, $height);

        // Die Umbruchregeln stehen in EINER Quelle. Vorher lagen sie
        // viermal im Projekt und waren bereits auseinandergelaufen: die
        // Vorlage brach bei 680 px um, die Signatur bei 620 px.
        $regeln = file_get_contents(resource_path('views/emails/parts/responsive-css.blade.php'));

        $this->assertStringContainsString(
            '.rt-sign-cell { background-size: 100% 100%, 125% auto, 125% auto !important; }',
            $regeln,
        );
        $this->assertStringContainsString('padding: 0 0 12px !important;', $regeln);
        $this->assertStringContainsString('.rt-sign-identity { padding: 18px 0 0 !important; }', $regeln);
        $this->assertStringContainsString('tr.rt-stack > td.rt-sign-identity { padding: 18px 0 0 !important; }', $regeln);

        // Und jede Ausgabestelle zieht daraus, statt eine eigene Kopie zu halten.
        foreach ([
            'mail-templates/signature-light-master.html',
            'mail-templates/signature-dark-master.html',
            'mail-templates/email-master.html',
        ] as $file) {
            $source = file_get_contents(resource_path($file));
            $this->assertStringContainsString('{{RESPONSIVE_CSS}}', $source, $file);
            $this->assertStringNotContainsString('@media only screen', $source, $file);
        }

        $layout = file_get_contents(resource_path('views/vendor/mail/html/layout.blade.php'));
        $this->assertStringContainsString("@include('emails.parts.responsive-css'", $layout);
        $this->assertStringNotContainsString('@media only screen', $layout);
    }

    /**
     * Drei Stufen statt einer: ohne die Tablet-Stufe stand die Signatur auf
     * einem 700-px-Schirm weiter im vollen Breitlayout und war gequetscht.
     */
    public function test_mail_layout_breaks_in_three_stages_from_one_source(): void
    {
        $regeln = file_get_contents(resource_path('views/emails/parts/responsive-css.blade.php'));

        preg_match_all('/@media only screen and \(max-width: (\d+)px\)/', $regeln, $treffer);

        $this->assertSame(['1000', '860', '480'], $treffer[1]);

        // Gestapelt wird ab der mittleren Stufe — nicht erst auf dem Telefon.
        $stapelStufe = strpos($regeln, 'max-width: 860px');
        $telefonStufe = strpos($regeln, 'max-width: 480px');
        $stapelRegel = strpos($regeln, 'tr.rt-stack > td { box-sizing');

        $this->assertIsInt($stapelRegel);
        $this->assertGreaterThan($stapelStufe, $stapelRegel);
        $this->assertLessThan($telefonStufe, $stapelRegel);

        // Jede erzeugte Datei traegt alle drei Stufen.
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        UserProfile::create(['user_id' => $user->id, 'position' => 'Disposition']);
        $builder = new EmailTemplateBuilder($user->fresh());

        foreach (['vorlage-html', 'signatur-hell', 'signatur-dunkel'] as $key) {
            $html = $builder->build($key)['content'];

            foreach (['1000px', '860px', '480px'] as $stufe) {
                $this->assertStringContainsString('max-width: '.$stufe, $html, "{$key} / {$stufe}");
            }

            $this->assertStringNotContainsString('{{RESPONSIVE_CSS}}', $html, $key);
        }
    }

    /** Holt den HTML-Teil aus einer erzeugten .eml-Datei. */
    private function decodeEmlHtmlPart(string $eml): string
    {
        preg_match(
            '/Content-Type: text\/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--=_rt_rel_/s',
            $eml,
            $matches
        );

        return (string) base64_decode((string) preg_replace('/\s+/', '', $matches[1] ?? ''), true);
    }

    public function test_light_and_dark_html_are_distinct_static_palettes_with_embedded_contact_icons(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $builder = new EmailTemplateBuilder($user);

        $light = $builder->build('vorlage-html')['content'];
        $dark = $builder->build('vorlage-dunkel-html')['content'];

        $this->assertStringContainsString('data-rt-theme="light"', $light);
        $this->assertStringContainsString('background:#f4f2ed', $light);
        $this->assertStringContainsString(
            '<td class="rt-pad rt-sign-cell" bgcolor="#f7f6f3" style="padding:20px 38px 28px;background:#f7f6f3;',
            $light
        );
        $this->assertStringContainsString('color:#111820;font-size:25px;', $light);
        $this->assertStringContainsString(
            'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('mail-templates/assets/logo-signature-light.png'))),
            $light
        );
        $this->assertStringNotContainsString('bgcolor="#080b10"', $light);
        $this->assertStringContainsString('data-rt-theme="dark"', $dark);
        $this->assertStringContainsString('background:#111820', $dark);
        $this->assertStringContainsString(
            '<td class="rt-pad rt-sign-cell" bgcolor="#0c1017" style="padding:20px 38px 28px;background:#0c1017;',
            $dark
        );
        $this->assertStringContainsString('color:#ffffff;font-size:25px;', $dark);
        $this->assertStringContainsString(
            'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('mail-templates/assets/logo-mail-dark.png'))),
            $dark
        );
        $this->assertNotSame($light, $dark);

        foreach ([$light, $dark] as $html) {
            $this->assertStringNotContainsString('hero-railtime', $html);
            $this->assertStringNotContainsString('{{HERO_SRC}}', $html);
            $this->assertStringContainsString('class="rt-logo"', $html);
            // Der mobile Umbruch darf nur direkte Zellen treffen. Ein
            // Nachfahren-Selektor zerlegte zuvor die verschachtelte
            // Kontakttabelle und stellte Symbol ueber statt neben den Text.
            $this->assertStringContainsString(
                'tr.rt-stack > td { box-sizing: border-box !important;',
                $html
            );
            $this->assertStringNotContainsString('.rt-stack td {', $html);
            $this->assertStringContainsString(
                '.rt-contact td.rt-contact-icon, .rt-contact td.rt-contact-text { display: table-cell !important;',
                $html
            );
            $this->assertStringContainsString('data:image/png;base64,', $html);
            $this->assertStringContainsString('background-image:linear-gradient(rgba(', $html);
            $this->assertStringContainsString('background-size:100% 100%,86% auto;', $html);
            $this->assertStringContainsString(
                'class="rt-sign-logo" width="37%" valign="bottom"',
                $html,
            );
            $this->assertStringContainsString('text-align:right;vertical-align:bottom;', $html);
            $this->assertStringContainsString(
                'class="rt-sign-identity" width="63%" valign="top"',
                $html,
            );
            $this->assertStringContainsString('padding:6px 28px 0 0;position:relative;z-index:1;', $html);
            $this->assertStringContainsString('text-align:left;vertical-align:top;', $html);
            $this->assertStringNotContainsString('{{THEME', $html);
            $this->assertStringNotContainsString('{{ICON_', $html);
        }
    }

    public function test_eml_variants_embed_the_selected_html_palette_logo_and_contact_icons(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), [
            'name' => 'RailTime Prüfgesellschaft mbH',
        ]));

        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $builder = new EmailTemplateBuilder($user);

        foreach ([
            'vorlage-eml' => 'light',
            'vorlage-dunkel-eml' => 'dark',
        ] as $key => $theme) {
            $eml = $builder->build($key)['content'];

            $this->assertStringContainsString("X-RailTime-Theme: {$theme}", $eml);
            $logoFilename = $theme === 'light'
                ? 'logo-signature-light.png'
                : 'logo-mail-dark.png';
            $this->assertStringContainsString("Content-Disposition: inline; filename=\"{$logoFilename}\"", $eml);
            $this->assertStringContainsString(
                'Subject: =?UTF-8?B?'.base64_encode('{{BETREFF}} | RailTime Prüfgesellschaft mbH').'?=',
                $eml
            );
            $this->assertStringContainsString('Content-ID: <railtime-logo>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-icon-location>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-icon-phone>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-icon-mobile>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-icon-email>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-icon-web>', $eml);
            $this->assertStringNotContainsString('railtime-hero', $eml);

            preg_match(
                '/Content-Type: text\/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--=_rt_rel_/s',
                $eml,
                $matches
            );

            $this->assertArrayHasKey(1, $matches);
            $html = base64_decode((string) preg_replace('/\s+/', '', $matches[1]), true);
            $this->assertIsString($html);
            $this->assertStringContainsString('data-rt-theme="'.$theme.'"', $html);
            $this->assertStringContainsString('src="cid:railtime-logo"', $html);
            $this->assertStringContainsString('src="cid:railtime-icon-email"', $html);
        }
    }

    public function test_signature_places_linked_icon_contacts_below_identity_and_logo_on_the_right(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), [
            'website' => 'https://rail-time.example/leistungen',
            'phone' => '04171 6089890',
        ]));

        $user = User::factory()->create([
            'name' => 'Mara Beispiel',
            'email' => 'mara@example.test',
        ]);
        UserProfile::create([
            'user_id' => $user->id,
            'position' => 'Disposition',
            'phone' => '+49 (0) 4171 12345',
            'mobile' => '0176 12345678',
        ]);

        $html = (new EmailTemplateBuilder($user->fresh()))->build('signatur-hell')['content'];

        $this->assertStringContainsString(
            '<td class="rt-pad rt-sign-cell" bgcolor="#f7f6f3" style="padding:18px 30px 24px;',
            $html,
        );
        $this->assertStringContainsString('Mara Beispiel', $html);
        $this->assertStringContainsString('Disposition', $html);
        $this->assertStringContainsString('href="tel:+49417112345"', $html);
        $this->assertStringContainsString('href="tel:+4917612345678"', $html);
        $this->assertStringContainsString('href="mailto:mara@example.test"', $html);
        $this->assertStringContainsString('href="https://rail-time.example/leistungen"', $html);
        $this->assertStringContainsString('>rail-time.example/leistungen<', $html);
        // Logo, vier Personen- und zweimal drei Firmenicons als PNG.
        $this->assertSame(11, substr_count($html, 'data:image/png;base64,'));
        $this->assertSame(2, substr_count($html, 'data:image/gif;base64,'));
        $this->assertStringContainsString('class="rt-sign-logo"', $html);
        $this->assertStringNotContainsString('RT_PHONE_START', $html);
        $this->assertStringNotContainsString('{{TRAIN_SRC}}', $html);
        $this->assertStringContainsString('background-image:linear-gradient(', $html);
        $this->assertStringContainsString('background-position:center center,left bottom', $html);

        // Reverse Stack: die Markenspalte steht in der Quelle zuerst (und
        // landet damit beim Stapeln oben), dir="rtl" haelt sie auf breiten
        // Schirmen trotzdem rechts.
        $this->assertStringContainsString('<table role="presentation" dir="rtl"', $html);
        $this->assertStringContainsString('<td dir="ltr" class="rt-sign-logo"', $html);
        $this->assertStringContainsString('<td dir="ltr" class="rt-sign-identity"', $html);

        $logoPosition = strpos($html, 'class="rt-sign-logo"');
        $namePosition = strpos($html, 'Mara Beispiel');
        $phonePosition = strpos($html, 'href="tel:+49417112345"');

        $this->assertIsInt($logoPosition);
        $this->assertIsInt($namePosition);
        $this->assertIsInt($phonePosition);
        $this->assertLessThan($namePosition, $logoPosition);
        $this->assertLessThan($phonePosition, $namePosition);
    }

    public function test_all_html_variants_center_contact_icons_in_mail_client_safe_cells(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), [
            'phone' => '04171 6089890',
        ]));

        $user = User::factory()->create([
            'name' => 'Mara Beispiel',
            'email' => 'mara@example.test',
        ]);
        UserProfile::create([
            'user_id' => $user->id,
            'position' => 'Disposition',
            'phone' => '+49 (0) 4171 12345',
            'mobile' => '0176 12345678',
        ]);

        $builder = new EmailTemplateBuilder($user->fresh());

        foreach (['vorlage-html', 'vorlage-dunkel-html', 'signatur-hell', 'signatur-dunkel'] as $key) {
            $html = $builder->build($key)['content'];

            $this->assertSame(8, substr_count($html, '<td width="22" align="center" valign="middle"'));
            $this->assertSame(10, substr_count($html, 'mso-line-height-rule:exactly;text-align:center;'));
            $this->assertSame(10, substr_count($html, 'style="display:block;width:22px;height:22px;margin:0 auto;"'));
            $this->assertSame(3, substr_count($html, 'padding:0 0 8px 9px;'));
            $this->assertSame(3, substr_count($html, 'padding:0 0 0 9px;'));
            $this->assertStringNotContainsString('width="30"', $html);
        }
    }

    public function test_signature_shows_company_address_under_the_logo_with_the_landline_instead_of_the_mobile(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), [
            'name' => 'RT Rail Time GmbH',
            'street' => 'Borsteler Weg 29–31',
            'postal_code' => '21423',
            'city' => 'Winsen (Luhe)',
            'phone' => '04171 6089890',
            'emergency_phone' => '0160 1881848',
            'email' => 'info@rail-time.de',
        ]));

        $user = User::factory()->create([
            'name' => 'Mara Beispiel',
            'email' => 'mara@example.test',
        ]);

        $builder = new EmailTemplateBuilder($user->fresh());

        foreach (['vorlage-html', 'vorlage-dunkel-html', 'signatur-hell', 'signatur-dunkel'] as $key) {
            $html = $builder->build($key)['content'];

            // Festnetzanschluss statt Notfall-Mobilnummer.
            $this->assertStringContainsString('href="tel:+4941716089890"', $html);
            $this->assertStringContainsString('04171 6089890', $html);
            $this->assertStringNotContainsString('0160 1881848', $html);
            $this->assertStringNotContainsString('24/7', $html);

            // Die Anschrift existiert in zwei Fassungen: breit in der
            // Markenspalte (spart Hoehe), schmal am Ende des Personenblocks
            // (bessere Lesefolge auf dem Telefon). Immer genau eine ist sichtbar.
            $this->assertSame(2, substr_count($html, 'Borsteler Weg 29–31'), $key);
            $this->assertSame(1, substr_count($html, 'class="rt-only-wide"'), $key);
            $this->assertSame(1, substr_count($html, 'class="rt-only-narrow"'), $key);
            $this->assertStringContainsString('.rt-only-wide { display: none !important;', $html);
            $this->assertStringContainsString('.rt-only-narrow { display: block !important;', $html);

            $logoPosition = strpos($html, 'alt="RT Rail Time GmbH"');
            $widePosition = strpos($html, 'class="rt-only-wide"');
            $narrowPosition = strpos($html, 'class="rt-only-narrow"');

            $this->assertIsInt($logoPosition, $key);
            $this->assertIsInt($widePosition, $key);
            $this->assertIsInt($narrowPosition, $key);
            $this->assertLessThan($widePosition, $logoPosition, $key);
            $this->assertLessThan($narrowPosition, $widePosition, $key);
        }
    }

    public function test_signature_omits_the_company_phone_line_when_no_landline_is_stored(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), ['phone' => '   ']));

        $user = User::factory()->create(['email' => 'mara@example.test']);
        $html = (new EmailTemplateBuilder($user))->build('signatur-dunkel')['content'];

        $this->assertStringNotContainsString('RT_COMPANY_PHONE', $html);
        $this->assertStringNotContainsString('>T <', $html);
        $this->assertStringContainsString('Borsteler Weg', $html);
    }

    public function test_contact_block_stays_flush_left_even_if_a_client_keeps_the_cell_right_to_left(): void
    {
        $user = User::factory()->create(['email' => 'mara@example.test']);
        $builder = new EmailTemplateBuilder($user);

        foreach (['vorlage-html', 'vorlage-dunkel-html', 'signatur-hell', 'signatur-dunkel'] as $key) {
            $html = $builder->build($key)['content'];

            // Der Reverse Stack setzt die Wrapper-Tabelle auf rtl. Clients, die
            // das dir-Attribut der Zelle verwerfen, wuerden die schrumpfende
            // Kontakttabelle sonst an die rechte Kante schieben.
            $this->assertStringContainsString('<table role="presentation" dir="rtl"', $html, $key);
            $this->assertSame(2, substr_count($html, 'style="direction:ltr;width:'), $key);
            $this->assertStringContainsString(
                '<table class="rt-contact" role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:0;margin-right:auto;',
                $html,
                $key
            );
        }
    }

    public function test_contact_icons_are_brand_red_glyphs_without_a_background_tile(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), [
            'phone' => '04171 6089890',
        ]));

        $user = User::factory()->create(['email' => 'mara@example.test']);
        UserProfile::create([
            'user_id' => $user->id,
            'phone' => '04171 12345',
            'mobile' => '0176 12345678',
        ]);

        $html = (new EmailTemplateBuilder($user->fresh()))->build('signatur-hell')['content'];

        preg_match_all('/<img src="data:image\/png;base64,([^"]+)" width="22"/', $html, $matches);
        // Vier Personenicons plus je drei Firmenicons fuer Desktop und Mobil.
        $this->assertCount(10, $matches[1]);

        foreach ($matches[1] as $index => $base64) {
            $binary = base64_decode($base64, true);
            $this->assertIsString($binary);

            $image = imagecreatefromstring($binary);
            $this->assertNotFalse($image, "Icon {$index} ist kein gueltiges Bild.");

            // Doppelte Anzeigegroesse fuer scharfe Darstellung auf Retina.
            $this->assertSame(44, imagesx($image), "Icon {$index} hat die falsche Breite.");
            $this->assertSame(44, imagesy($image), "Icon {$index} hat die falsche Hoehe.");

            // Alle vier Ecken muessen voll transparent sein — eine farbige
            // Hintergrundkachel wuerde hier sofort auffallen.
            foreach ([[0, 0], [43, 0], [0, 43], [43, 43]] as [$x, $y]) {
                $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;
                $this->assertSame(127, $alpha, "Icon {$index} hat an ({$x},{$y}) keinen transparenten Grund.");
            }

            // Deckende Bildpunkte tragen das Markenrot.
            $reds = [];
            for ($x = 0; $x < 44; $x++) {
                for ($y = 0; $y < 44; $y++) {
                    $color = imagecolorat($image, $x, $y);
                    if (((($color >> 24) & 0x7F) < 20)) {
                        $reds[] = [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
                    }
                }
            }

            $this->assertNotEmpty($reds, "Icon {$index} hat keine deckenden Bildpunkte.");
            $average = array_map(
                fn (int $channel) => (int) round(array_sum(array_column($reds, $channel)) / count($reds)),
                [0, 1, 2]
            );

            $this->assertGreaterThan(200, $average[0], "Icon {$index} ist nicht rot genug.");
            $this->assertLessThan(60, $average[1], "Icon {$index} hat zu viel Gruenanteil.");
            $this->assertLessThan(90, $average[2], "Icon {$index} hat zu viel Blauanteil.");

            imagedestroy($image);
        }
    }

    public function test_missing_personal_phone_and_mobile_remove_only_the_personal_icon_rows(): void
    {
        $user = User::factory()->create(['email' => 'mara@example.test']);
        UserProfile::create([
            'user_id' => $user->id,
            'phone' => '   ',
            'mobile' => "\t",
        ]);

        $html = (new EmailTemplateBuilder($user))->build('signatur-dunkel')['content'];

        $this->assertStringNotContainsString('RT_PHONE_', $html);
        $this->assertStringNotContainsString('RT_MOBILE_', $html);
        // Der Firmenkontakt wird absichtlich je einmal fuer die breite und die
        // gestapelte mobile Signatur ausgegeben.
        $this->assertSame(2, substr_count($html, 'href="tel:+494171546803"'));
        $this->assertStringContainsString('href="mailto:mara@example.test"', $html);
    }

    public function test_only_both_mail_html_variants_are_available_as_protected_previews(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);

        foreach ([
            'vorlage-html' => 'light',
            'vorlage-dunkel-html' => 'dark',
        ] as $key => $theme) {
            $response = $this->actingAs($user)
                ->get(route('email-templates.preview', ['template' => $key]));

            $response->assertOk()
                ->assertHeader('content-disposition', 'inline')
                ->assertHeader('cache-control', 'max-age=0, no-store, private')
                ->assertSee('data-rt-theme="'.$theme.'"', escape: false)
                ->assertDontSee('hero-railtime', escape: false)
                ->assertSee('class="rt-logo"', escape: false);

            $animatedA = $this->actingAs($user)->get(route('email-templates.preview', [
                'template' => $key,
                'animate' => 1,
                'play' => 1,
            ]));
            $animatedB = $this->actingAs($user)->get(route('email-templates.preview', [
                'template' => $key,
                'animate' => 1,
                'play' => 2,
            ]));
            $animatedA->assertSee('data:image/gif;base64,', escape: false);
            $this->assertNotSame($animatedA->getContent(), $animatedB->getContent());
            $response->assertSee('data:image/png;base64,', escape: false);

            $this->assertStringContainsString(
                "default-src 'none'",
                (string) $response->headers->get('content-security-policy')
            );
        }

        foreach (['vorlage-eml', 'vorlage-dunkel-eml', 'signatur-hell', 'signatur-dunkel', 'unbekannt'] as $key) {
            $this->actingAs($user)
                ->get(route('email-templates.preview', ['template' => $key]))
                ->assertNotFound();
        }
    }

    public function test_contextual_info_explains_setup_in_german_and_english(): void
    {
        $catalog = app(PageHelpCatalog::class);

        App::setLocale('de');
        $german = $catalog->forRoute('email-templates.index');
        $this->assertStringContainsString('Vorlagen & Signaturen', $german['title']);
        $this->assertStringContainsString('Einstellungen → Konten → Signaturen', implode(' ', $german['points']));
        $this->assertStringContainsString('vollständig entpacken', implode(' ', $german['points']));
        $this->assertStringContainsString('Thunderbird', implode(' ', $german['points']));
        $this->assertStringContainsString('Testmail', implode(' ', $german['points']));

        App::setLocale('en');
        $english = $catalog->forRoute('email-templates.index');
        $this->assertStringContainsString('templates & signatures', $english['title']);
        $this->assertStringContainsString('Settings → Accounts → Signatures', implode(' ', $english['points']));
        $this->assertStringContainsString('fully extract', implode(' ', $english['points']));
        $this->assertStringContainsString('Thunderbird', implode(' ', $english['points']));
        $this->assertStringContainsString('test email', implode(' ', $english['points']));
    }

    /**
     * @param  array<string, mixed>  $accountFixture
     * @return array{exitCode: int, output: string, result: array<string, mixed>}
     */
    private function runOutlookInstaller(string $packageDirectory, string $fakeWindowsProfile, array $accountFixture): array
    {
        $fakeAppData = $fakeWindowsProfile.DIRECTORY_SEPARATOR.'AppData'.DIRECTORY_SEPARATOR.'Roaming';
        $fakeTemp = $fakeWindowsProfile.DIRECTORY_SEPARATOR.'Temp';
        File::ensureDirectoryExists($fakeAppData);
        File::ensureDirectoryExists($fakeTemp);

        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['APPDATA'] = $fakeAppData;
        $environment['TEMP'] = $fakeTemp;
        $environment['TMP'] = $fakeTemp;
        $environment['RAILTIME_INSTALLER_TEST_MODE'] = '1';
        $commandInterpreter = $environment['COMSPEC'] ?? 'C:\\Windows\\System32\\cmd.exe';
        $installerPath = $packageDirectory.DIRECTORY_SEPARATOR.'Outlook-klassisch-installieren.cmd';
        $fixturePath = $fakeWindowsProfile.DIRECTORY_SEPARATOR.'outlook-accounts.json';
        $resultPath = $fakeWindowsProfile.DIRECTORY_SEPARATOR.'installer-result.json';
        $targetDirectory = $fakeAppData.DIRECTORY_SEPARATOR.'Microsoft'.DIRECTORY_SEPARATOR.'Signatures';
        File::put($fixturePath, json_encode($accountFixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $process = proc_open(
            [
                $commandInterpreter,
                '/d',
                '/c',
                $installerPath,
                '-TestMode',
                '-NoGui',
                '-SourceDirectory',
                $packageDirectory,
                '-TargetDirectory',
                $targetDirectory,
                '-AccountFixturePath',
                $fixturePath,
                '-ResultPath',
                $resultPath,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $packageDirectory,
            $environment,
            ['bypass_shell' => true],
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'output' => trim((string) $stdout."\n".(string) $stderr),
            'result' => File::exists($resultPath)
                ? json_decode(File::get($resultPath), true, 512, JSON_THROW_ON_ERROR)
                : [],
        ];
    }
}
