<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Http\Middleware\LogActivity;
use App\Models\EmployeeIdentityAccount;
use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SignatureArtifactVersion;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTrainCarrier;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\MailSignature;
use App\Support\OutlookAddin\EntraAccessTokenValidator;
use App\Support\OutlookAddin\OutlookAddinConfiguration;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use App\Support\OutlookAddin\OutlookTemplateLibrary;
use App\Support\OutlookAddin\VerifiedEntraIdentity;
use App\Support\PageHelpCatalog;
use Firebase\JWT\JWT;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Mockery;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;
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

    public function test_v22_v23_signature_background_is_embedded_once_and_reuses_the_img_content_id(): void
    {
        Http::preventStrayRequests();
        $source = URL::asset('mail-assets/zug-dampf-v19-light.gif');
        foreach (['v22', 'v23'] as $version) {
            $html = SystemMailInlineImageEmbedder::mark('<html><body><!-- RT_TEMPLATE_MARK_START -->'
                .'<table><tr data-rt-artifact-version="'.$version.'"><td class="rt-sign-cell" data-rt-signature-background="1" '
                .'style="background-color:#ffffff;background-image:url(&quot;'.htmlspecialchars($source.'?v='.$version.'&theme=light', ENT_QUOTES | ENT_HTML5, 'UTF-8').'&quot;);background-size:125% auto;background-position:65% bottom;">'
                .'<p>Kontaktdaten bleiben normaler Text.</p><img src="'.$source.'" alt=""></td></tr></table>'
                .'<!-- RT_TEMPLATE_MARK_END --></body></html>');
            $email = (new Email)->from('sender@rail-time.test')->to('recipient@rail-time.test')->subject($version.' MIME')->html($html);
            $embedder = app(SystemMailInlineImageEmbedder::class);

            $this->assertSame(1, $embedder->embed($email));
            $attachments = $email->getAttachments();
            $this->assertCount(1, $attachments);
            $this->assertSame('zug-dampf-v19-light.gif', $attachments[0]->getFilename());
            $this->assertSame('inline', $attachments[0]->getDisposition());
            $cid = 'cid:'.$attachments[0]->getContentId();
            $delivered = html_entity_decode((string) $email->getHtmlBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringContainsString("background-image:url('".$cid."')", $delivered);
            $this->assertStringContainsString('<img src="'.$cid.'"', $delivered);
            $this->assertStringContainsString('background-size:125% auto;background-position:65% bottom;', $delivered);
            $this->assertStringContainsString('<p>Kontaktdaten bleiben normaler Text.</p>', $delivered);
            $this->assertStringNotContainsString($source, $delivered);
            $this->assertStringContainsString('multipart/related', $email->toString());
            $this->assertSame(0, $embedder->embed($email));
            $this->assertCount(1, $email->getAttachments());
        }
        Http::assertNothingSent();
    }

    public function test_css_only_signature_train_is_related_not_a_second_inline_attachment(): void
    {
        Http::preventStrayRequests();
        $source = URL::asset('mail-assets/zug-dampf-v19-light.gif');
        $embedder = app(SystemMailInlineImageEmbedder::class);

        foreach (['v22', 'v23'] as $version) {
            foreach ([false, true] as $withAttachments) {
                $html = SystemMailInlineImageEmbedder::mark('<html><body><!-- RT_TEMPLATE_MARK_START -->'
                    .'<table><tr data-rt-artifact-version="'.$version.'"><td class="rt-sign-cell" data-rt-signature-background="1" '
                    .'style="background-image:url(\''.$source.'\');background-size:175% auto;background-position:65% bottom;background-repeat:no-repeat;">'
                    .'<p>Kontaktdaten</p></td></tr></table><!-- RT_TEMPLATE_MARK_END --></body></html>');
                $email = (new Email)->from('sender@rail-time.test')->to('recipient@rail-time.test')
                    ->subject('CSS-only '.$version)->text('Kontaktdaten')->html($html)
                    ->date(new \DateTimeImmutable('2026-09-06T00:00:00Z'));
                $email->getHeaders()->addIdHeader('Message-ID', 'css-only@rail-time.test');
                if ($withAttachments) {
                    $email->html(str_replace('</body>', '<img src="cid:existing-logo@rail-time.test" alt="Logo"></body>', $html))
                        ->addPart((new DataPart('existing logo bytes', 'logo.png', 'image/png'))->asInline()->setContentId('existing-logo@rail-time.test'))
                        ->attach('%PDF-1.7 original document', 'document.pdf', 'application/pdf')
                        ->addPart((new DataPart('unrelated image bytes', 'photo.png', 'image/png'))->asInline()->setContentId('unrelated@rail-time.test'));
                }

                $this->assertSame(1, $embedder->embed($email));
                $deliveredHtml = (string) $email->getHtmlBody();
                $this->assertSame(1, substr_count($deliveredHtml, 'background-image:'));
                $this->assertStringNotContainsString('<img src="cid:railtime-', $deliveredHtml);
                $this->assertStringNotContainsString(' background=', $deliveredHtml);
                $this->assertStringContainsString('background-size:175% auto;background-position:65% bottom;background-repeat:no-repeat;', $deliveredHtml);

                $body = $email->getBody();
                if ($withAttachments) {
                    $this->assertInstanceOf(MixedPart::class, $body);
                    $outerParts = $body->getParts();
                    $this->assertCount(3, $outerParts);
                    $this->assertSame('document.pdf', $outerParts[1]->getFilename());
                    $this->assertSame('attachment', $outerParts[1]->getDisposition());
                    $this->assertSame('%PDF-1.7 original document', $outerParts[1]->getBody());
                    $this->assertSame('photo.png', $outerParts[2]->getFilename());
                    $this->assertSame('unrelated image bytes', $outerParts[2]->getBody());
                    $body = $outerParts[0];
                }
                $this->assertInstanceOf(RelatedPart::class, $body);
                $relatedParts = $body->getParts();
                $this->assertCount($withAttachments ? 3 : 2, $relatedParts);
                $this->assertInstanceOf(AlternativePart::class, $relatedParts[0]);
                $alternatives = $relatedParts[0]->getParts();
                $this->assertCount(2, $alternatives);
                $this->assertSame('Kontaktdaten', $alternatives[0]->getBody());
                $this->assertInstanceOf(TextPart::class, $alternatives[1]);
                $this->assertSame('html', $alternatives[1]->getMediaSubtype());
                $train = $relatedParts[array_key_last($relatedParts)];
                $this->assertSame('zug-dampf-v19-light.gif', $train->getFilename());
                $this->assertSame('inline', $train->getDisposition());
                $this->assertStringContainsString('cid:'.$train->getContentId(), $alternatives[1]->getBody());
                $serialized = $email->toString();
                $this->assertSame(1, substr_count($serialized, 'Content-ID: <'.$train->getContentId().'>'));
                $this->assertSame(0, $embedder->embed($email));
                $this->assertSame($serialized, $email->toString());
                $this->assertSame($serialized, implode('', iterator_to_array($email->toIterable(), false)));
                $this->assertSame($serialized, (clone $email)->toString());
            }
        }
        Http::assertNothingSent();
    }

    public function test_v22_signature_background_embedding_accepts_only_scoped_local_assets(): void
    {
        Http::preventStrayRequests();
        Storage::fake('public');
        $binary = (string) file_get_contents(public_path('mail-assets/contact-email.png'));
        $filename = hash('sha256', $binary).'.png';
        Storage::disk('public')->put('mail-imports/'.$filename, $binary);
        $importSource = URL::to(Storage::disk('public')->url('mail-imports/'.$filename));
        $localSource = URL::asset('mail-assets/contact-email.png');
        $invalidSource = str_replace('contact-email.png', '../contact-email.png', $localSource);
        $encodedSource = str_replace('contact-email.png', '%63ontact-email.png', $localSource);
        $foreignSource = 'https://foreign.example/mail-assets/contact-email.png';
        $carrier = static fn (string $style, string $version = 'v22', string $attributes = 'class="rt-sign-cell" data-rt-signature-background="1"'): string => '<tr data-rt-artifact-version="'.$version.'"><td '.$attributes.' style="'.htmlspecialchars($style, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"><p>Kontakt</p></td></tr>';
        $untouched = [
            $carrier('background-image:url('.$foreignSource.');'),
            $carrier('background-image:url('.$invalidSource.');'),
            $carrier('background-image:url('.$encodedSource.');'),
            $carrier('background-image:url('.$localSource.');', 'v21'),
            $carrier('background-image:url('.$localSource.');', 'v24'),
            $carrier('background-image:url('.$foreignSource.');', 'v23'),
            $carrier('background-image:url('.$localSource.');', 'v22', 'class="rt-sign-cell"'),
            $carrier('background-image:url('.$localSource.');', 'v22', 'class="other" data-rt-signature-background="1"'),
            $carrier('background-image:url('.$localSource.');', 'v22', 'class="rt-sign-cell" data-rt-signature-background="1" data-rt-signature-background="0"'),
            $carrier('background-image:url('.$localSource.');background-image:url('.$foreignSource.');'),
            $carrier('background-image:url('.$localSource.'),url('.$foreignSource.');'),
            $carrier('background:url('.$localSource.');'),
            '<tr data-rt-artifact-version="v22"><td><table>'.$carrier('background-image:url('.$localSource.');', 'v20').'</table></td></tr>',
            '<style>.custom{background-image:url('.$localSource.');}</style>',
        ];
        $html = SystemMailInlineImageEmbedder::mark('<html><body><!-- RT_TEMPLATE_MARK_START -->'
            .'<table>'.$carrier('background-image: url('.$importSource.') !important;background-size:150% auto;').implode('', $untouched).'</table>'
            .'<!-- RT_TEMPLATE_MARK_END --></body></html>');
        $email = (new Email)->html($html);
        $embedder = app(SystemMailInlineImageEmbedder::class);

        $this->assertSame(1, $embedder->embed($email));
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame($filename, $email->getAttachments()[0]->getFilename());
        $delivered = (string) $email->getHtmlBody();
        $this->assertStringNotContainsString($importSource, $delivered);
        foreach ($untouched as $fragment) {
            $this->assertStringContainsString($fragment, $delivered);
        }
        $this->assertStringContainsString(' !important;background-size:150% auto;', $delivered);
        $this->assertSame(0, $embedder->embed($email));
        $unmarked = (new Email)->html(str_replace(SystemMailInlineImageEmbedder::RUNTIME_ATTRIBUTE, '', $html));
        $this->assertSame(0, $embedder->embed($unmarked));
        $this->assertSame([], $unmarked->getAttachments());
        Http::assertNothingSent();
    }

    public function test_outlook_addin_public_config_is_fail_closed_and_contains_no_secret(): void
    {
        config([
            'outlook_addin.enabled' => false,
            'outlook_addin.deployed' => false,
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.entra.tenant_id' => '',
            'outlook_addin.entra.client_id' => '',
            'outlook_addin.entra.audience' => '',
            'outlook_addin.entra.scope_uri' => '',
        ]);

        $this->get(route('outlook-addin.config'))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertJsonPath('ready', false)
            ->assertJsonMissingPath('auth.clientSecret')
            ->assertJsonMissingPath('token');

        foreach (['taskpane', 'runtime'] as $bundle) {
            $hash = hash_file('sha256', public_path("outlook-addin/{$bundle}.js"));
            $this->assertIsString($hash);

            $response = $this->get(route("outlook-addin.{$bundle}"));
            $response->assertOk()
                ->assertSee(
                    "https://app.rail-time.de/outlook-addin/{$bundle}.js?v=".substr($hash, 0, 16),
                    escape: false,
                );
            if ($bundle === 'taskpane') {
                $response->assertSee('RailTime öffnen')
                    ->assertSee('RailTime als App installieren')
                    ->assertSee(route('home'), escape: false)
                    ->assertSee(route('help'), escape: false)
                    ->assertDontSee('https://outlook.office.com/mail/', escape: false);
            }

            $cacheControl = $response->headers->get('cache-control');
            $this->assertIsString($cacheControl);
            $this->assertStringContainsString('max-age=0', $cacheControl);
            $this->assertStringContainsString('no-store', $cacheControl);
            $this->assertStringContainsString('public', $cacheControl);
        }

        $this->getJson(route('api.outlook-addin.bootstrap'))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'outlook_addin_unauthorized');
    }

    public function test_outlook_addin_validates_a_real_entra_rs256_access_token(): void
    {
        $tenantId = '11111111-1111-4111-8111-111111111111';
        $clientId = '22222222-2222-4222-8222-222222222222';
        $objectId = '33333333-3333-4333-8333-333333333333';
        config([
            'outlook_addin.enabled' => true,
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.entra.tenant_id' => $tenantId,
            'outlook_addin.entra.client_id' => $clientId,
            'outlook_addin.entra.audience' => $clientId,
            'outlook_addin.entra.scope' => 'Signature.Read',
            'outlook_addin.entra.scope_uri' => "api://{$clientId}/Signature.Read",
        ]);

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        Http::fake([
            "https://login.microsoftonline.com/{$tenantId}/discovery/v2.0/keys" => Http::response([
                'keys' => [[
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'kid' => 'railtime-test-key',
                    'alg' => 'RS256',
                    'n' => $this->base64Url((string) $details['rsa']['n']),
                    'e' => $this->base64Url((string) $details['rsa']['e']),
                ]],
            ]),
        ]);
        Cache::flush();

        $token = JWT::encode([
            'aud' => $clientId,
            'iss' => "https://login.microsoftonline.com/{$tenantId}/v2.0",
            'iat' => time() - 5,
            'nbf' => time() - 5,
            'exp' => time() + 300,
            'tid' => $tenantId,
            'oid' => $objectId,
            'scp' => 'openid profile Signature.Read',
            'azp' => $clientId,
            'preferred_username' => 'mara@example.com',
            'name' => 'Mara Beispiel',
        ], $privateKey, 'RS256', 'railtime-test-key');

        $identity = app(EntraAccessTokenValidator::class)->validate($token);

        $this->assertSame($tenantId, $identity->tenantId);
        $this->assertSame($objectId, $identity->objectId);
        $this->assertSame('mara@example.com', $identity->principal);
        $this->assertSame('Mara Beispiel', $identity->displayName);
        Http::assertSentCount(1);
    }

    public function test_outlook_addin_requires_a_preprovisioned_identity_and_matching_mailbox(): void
    {
        $this->createOutlookIdentityAccountsTable();
        $user = User::factory()->create([
            'email' => 'mara@example.com',
            'email_verified_at' => now(),
        ]);
        $identity = new VerifiedEntraIdentity(
            tenantId: '11111111-1111-4111-8111-111111111111',
            objectId: '33333333-3333-4333-8333-333333333333',
            principal: 'mara@example.com',
            displayName: 'Mara Beispiel',
        );
        $resolver = app(OutlookAddinIdentityResolver::class);

        try {
            $resolver->resolve($identity, 'mara@example.com');
            $this->fail('Eine nicht provisionierte E-Mail-Adresse darf kein Microsoft-Konto verknüpfen.');
        } catch (OutlookAddinException $exception) {
            $this->assertSame('outlook_addin_identity_not_linked', $exception->errorCode);
        }

        EmployeeIdentityAccount::query()->create([
            'user_id' => $user->id,
            'provider' => AccountProvider::Microsoft365,
            'external_id' => $identity->objectId,
            'principal' => $identity->principal,
            'email' => $user->email,
            'lifecycle_status' => 'active',
            'provisioning_status' => 'active',
        ]);

        $this->assertTrue($resolver->resolve($identity, 'MARA@example.com')->is($user));

        try {
            $resolver->resolve($identity, 'fremdes-postfach@example.com');
            $this->fail('Ein fremdes Outlook-Postfach darf nicht auf die Signatur zugreifen.');
        } catch (OutlookAddinException $exception) {
            $this->assertSame('outlook_addin_mailbox_mismatch', $exception->errorCode);
        }

        $this->assertDatabaseCount('employee_identity_accounts', 1);
        $this->assertDatabaseHas('employee_identity_accounts', [
            'external_id' => $identity->objectId,
            'principal' => $identity->principal,
        ]);
    }

    public function test_outlook_addin_payload_uses_published_documents_and_inline_cid_media(): void
    {
        config([
            'app.url' => 'https://app.rail-time.de',
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.marker' => 'RT-SIGNATURE-MANAGED-V1',
        ]);
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $user = User::factory()->create(['name' => 'Mara Beispiel']);

        $payloads = app(OutlookAddinPayloadService::class);
        $payload = $payloads->forUser($user);

        $this->assertSame('RT-SIGNATURE-MANAGED-V1', $payload['marker']);
        $this->assertStringContainsString('Mara Beispiel', $payload['signature']['html']);
        $this->assertStringContainsString('<!-- RT-SIGNATURE-MANAGED-V1 -->', $payload['signature']['html']);
        $this->assertStringContainsString('src="cid:', $payload['signature']['html']);
        $this->assertStringNotContainsString('src="https://', $payload['signature']['html']);
        $this->assertStringNotContainsString('data:image/', $payload['signature']['html']);
        $this->assertNotEmpty($payload['signature']['media']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $payload['version']['signature']);
        $this->assertStringContainsString(
            'RT-SIGNATURE-VERSION:'.$payload['version']['signature'],
            $payload['signature']['html'],
        );
        $this->assertSame(
            2,
            substr_count($payload['signature']['html'], 'RT-SIGNATURE-VERSION:'.$payload['version']['signature']),
        );
        $this->assertCount(1, $payload['templates']);
        $this->assertTrue($payload['templates'][0]['active']);
        $this->assertSame($payload['template'], [
            'html' => $payload['templates'][0]['html'],
            'media' => $payload['templates'][0]['media'],
        ]);
        $this->assertSame($payload['version']['template'], $payload['templates'][0]['version']);
        $this->assertStringContainsString(
            'RT-SIGNATURE-VERSION:'.$payload['version']['signature'],
            $payload['templates'][0]['html'],
        );

        foreach ([$payload['signature'], $payload['template']] as $document) {
            foreach ($document['media'] as $medium) {
                $this->assertSame($medium['name'], $medium['contentId']);
                $this->assertNotSame('', base64_decode($medium['base64'], true));
                $this->assertStringContainsString('cid:'.$medium['contentId'], $document['html']);
            }
        }

        $previousSignatureVersion = $payload['version']['signature'];
        $user->forceFill(['name' => 'Mara Aktualisiert'])->save();
        $updatedPayload = $payloads->forUser($user->fresh());
        $this->assertNotSame($previousSignatureVersion, $updatedPayload['version']['signature']);
        $this->assertStringContainsString(
            'RT-SIGNATURE-VERSION:'.$updatedPayload['version']['signature'],
            $updatedPayload['signature']['html'],
        );
        $this->assertStringNotContainsString(
            'RT-SIGNATURE-VERSION:'.$previousSignatureVersion,
            $updatedPayload['signature']['html'],
        );
        $this->assertSame(
            $updatedPayload['version']['signature'],
            $payloads->forUser($user->fresh())['version']['signature'],
        );

        $signatureDocument = MailDocument::query()
            ->where('kind', MailDocumentKind::Signature->value)
            ->where('is_active', true)
            ->firstOrFail();
        $v20Html = preg_replace(
            '/<tr>/',
            '<tr '.SignatureArtifactVersion::ATTRIBUTE.'="'.SignatureArtifactVersion::V20.'">',
            (string) $signatureDocument->published_html,
            1,
            $v20MarkerCount,
        );
        $this->assertSame(1, $v20MarkerCount);
        $this->assertIsString($v20Html);
        $v20Html = SignatureTrainCarrier::normalize($v20Html);
        $v20Html = str_replace(
            'class="rt-sign-name"',
            'class="rt-sign-name custom-copy"',
            $v20Html,
            $customClassCount,
        );
        $this->assertSame(1, $customClassCount);
        $signatureDocument->forceFill([
            'published_html' => $v20Html,
            'published_css' => implode('', [
                '.custom-copy{color:#123456}',
                '.rt-sign-name{letter-spacing:0}',
                ':scope/**/>/**/b\\6f dy .custom-copy{text-decoration:none}',
                ':is(html,:root,:scope,body) .custom-copy{font-weight:700}',
                ':where(html/**/>body,:sc\\6f pe body) .custom-copy{font-style:normal}',
                ':is(html,.custom-copy) .custom-copy{text-align:left}',
                '.ExternalClass .custom-copy{line-height:100%}',
                '[data-ogsc] .custom-copy{font-size:14px}',
                '[data-outlook-cycle] .custom-copy{white-space:normal}',
                'body[data-outlook-cycle] .custom-copy{overflow-wrap:normal}',
                'u + #body .custom-copy{word-spacing:0}',
                '@media only screen and (max-width:480px){[data-ogsc] :where(html,body) .custom-copy{font-size:13px}}',
                '.ExternalClass .missing-outlook-copy{color:#654321}',
            ]),
        ])->save();
        app(PublishedMailDocumentSnapshotStore::class)
            ->forget(MailDocumentKind::Signature);

        $customPayload = $payloads->forUser($user->fresh());
        $customSignature = $customPayload['signature']['html'];
        $publishedStylePosition = strpos($customSignature, 'data-rt-mail-document-css="signature"');
        $runtimeStylePosition = strpos($customSignature, 'data-rt-outlook-signature-css="1"');
        $signatureTablePosition = strpos($customSignature, 'class="rt-outlook-signature rts');

        $this->assertIsInt($publishedStylePosition);
        $this->assertIsInt($runtimeStylePosition);
        $this->assertIsInt($signatureTablePosition);
        $this->assertLessThan($runtimeStylePosition, $publishedStylePosition);
        $this->assertLessThan($signatureTablePosition, $runtimeStylePosition);
        $this->assertStringContainsString('.custom-copy{color:#123456}', $customSignature);
        $this->assertMatchesRegularExpression(
            '/class="rt-outlook-signature (rts[0-9a-f]{10})"/',
            $customSignature,
        );
        preg_match('/class="rt-outlook-signature (rts[0-9a-f]{10})"/', $customSignature, $scopeMatch);
        $scopeSelector = '.'.$scopeMatch[1];
        $this->assertStringContainsString(
            $scopeSelector.' .custom-copy{text-decoration:none}',
            $customSignature,
        );
        $this->assertStringContainsString(
            $scopeSelector.' .custom-copy{font-weight:700}',
            $customSignature,
        );
        $this->assertStringContainsString(
            $scopeSelector.' .custom-copy{font-style:normal}',
            $customSignature,
        );
        $this->assertStringContainsString(
            $scopeSelector.' :is(html,.custom-copy) .custom-copy{text-align:left}',
            $customSignature,
        );
        $this->assertStringContainsString(
            '.ExternalClass '.$scopeSelector.' .custom-copy{line-height:100%}',
            $customSignature,
        );
        $this->assertStringContainsString(
            '[data-ogsc] '.$scopeSelector.' .custom-copy{font-size:14px}',
            $customSignature,
        );
        $this->assertStringContainsString(
            '[data-outlook-cycle] '.$scopeSelector.' .custom-copy{white-space:normal}',
            $customSignature,
        );
        $this->assertStringContainsString(
            'body[data-outlook-cycle] '.$scopeSelector.' .custom-copy{overflow-wrap:normal}',
            $customSignature,
        );
        $this->assertStringContainsString(
            'u + #body '.$scopeSelector.' .custom-copy{word-spacing:0}',
            $customSignature,
        );
        $this->assertStringContainsString(
            '@media only screen and (max-width:480px){[data-ogsc] '.$scopeSelector.' .custom-copy{font-size:13px}}',
            $customSignature,
        );
        $this->assertStringNotContainsString('missing-outlook-copy', $customSignature);
    }

    public function test_outlook_compose_template_scopes_and_compacts_styles_without_losing_content(): void
    {
        config(['app.url' => 'https://app.rail-time.de', 'outlook_addin.base_url' => 'https://app.rail-time.de']);
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $template = MailDocument::query()->where('kind', MailDocumentKind::Template->value)->firstOrFail();
        $template->forceFill([
            'published_html' => str_replace('</body>', '<p class="compose-copy">Vorlageninhalt bleibt &amp; erhalten.</p><pre>  Erste Zeile\n  Zweite Zeile</pre></body>', $template->published_html),
            'published_css' => 'body .compose-copy{color:#123456;font-weight:700}.unused-template-class{color:#654321}@media only screen and (max-width:480px){.compose-copy{font-size:17px}}',
        ])->save();
        app(PublishedMailDocumentSnapshotStore::class)->forget(MailDocumentKind::Template);
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $payload = app(OutlookAddinPayloadService::class)->forUser($user);
        $html = $payload['template']['html'];
        preg_match('/class="rt-outlook-template (rtt[0-9a-f]{12})"/', $html, $scope);
        $this->assertNotEmpty($scope);
        preg_match('/<style data-rt-outlook-template-css="1">(.*?)<\/style>/s', $html, $styles);
        $this->assertNotEmpty($styles);
        $css = $styles[1];
        $this->assertLessThan(24577, strlen($css));
        $this->assertLessThan(99001, strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')) / 2);
        $this->assertStringContainsString('.'.$scope[1].' .compose-copy{color:#123456;font-weight:700}', $css);
        $this->assertStringContainsString('.'.$scope[1].' .compose-copy{font-size:17px}', $css);
        $this->assertStringContainsString('.'.$scope[1].' .rt-card', $css);
        $this->assertStringContainsString('max-width: 480px', $css);
        $body = preg_replace('/<style\b[^>]*>.*?<\/style>/s', '', $html);
        $this->assertIsString($body);
        $wrapper = substr($body, strpos($body, '<div class="rt-outlook-template '));
        $this->assertStringNotContainsString('!important', $wrapper, 'Desktop inline fallbacks must remain overridable by responsive rules.');
        $this->assertStringContainsString('font-family: Arial,Helvetica,sans-serif;', $wrapper);
        $this->assertStringNotContainsString('.unused-template-class', $css);
        $this->assertStringNotContainsString('data-rt-artifact-version="v14"', $css);
        $this->assertStringNotContainsString('@keyframes', $css);
        $this->assertStringNotContainsString('@supports', $css);
        $this->assertDoesNotMatchRegularExpression('/(?:^|[{},])\s*(?:html|body|table|td|img|a)\s*(?:[,>{]|\s+\.)/i', $css);
        $this->assertSame(1, substr_count($html, 'data-rt-outlook-template-css="1"'));
        $this->assertStringContainsString('Mara Beispiel', $html);
        $this->assertStringContainsString('Vorlageninhalt bleibt &amp; erhalten.', $html);
        $this->assertStringContainsString('<pre>  Erste Zeile\n  Zweite Zeile</pre>', $html);
        $this->assertMatchesRegularExpression('/<p class="compose-copy" style="[^"]*color: #123456[^\"]*">/', $html);
        $this->assertStringContainsString('<!--[if mso]>', $html);
        $this->assertStringContainsString('RT-SIGNATURE-VERSION:', $html);
        $this->assertStringNotContainsString('data:image/', $html);
        foreach ($payload['template']['media'] as $medium) {
            $this->assertStringContainsString('cid:'.$medium['contentId'], $html);
            $this->assertNotFalse(base64_decode($medium['base64'], true));
        }
        $this->assertSame($html, $payload['templates'][0]['html']);
        $this->assertSame($html, app(OutlookAddinPayloadService::class)->forUser($user)['template']['html']);
    }

    public function test_outlook_compose_template_enforces_transport_budget_before_payload_delivery(): void
    {
        config(['app.url' => 'https://app.rail-time.de', 'outlook_addin.base_url' => 'https://app.rail-time.de']);
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $template = MailDocument::query()->where('kind', MailDocumentKind::Template->value)->firstOrFail();
        $template->forceFill([
            'published_html' => str_replace('</body>', '<p>'.str_repeat('💼', 50000).'</p></body>', $template->published_html),
        ])->save();
        app(PublishedMailDocumentSnapshotStore::class)->forget(MailDocumentKind::Template);

        try {
            app(OutlookAddinPayloadService::class)->forUser(User::factory()->create());
            $this->fail('Oversized compose HTML must not be delivered to Office.');
        } catch (OutlookAddinException $exception) {
            $this->assertStringContainsString('99.000 Zeichen', $exception->getPrevious()?->getMessage() ?? '');
        }

        $template->forceFill([
            'published_html' => $this->canonicalMailDocumentHtml(MailDocumentKind::Template),
            'published_css' => str_repeat('.rt-title{color:#123456}', 1200),
        ])->save();
        app(PublishedMailDocumentSnapshotStore::class)->forget(MailDocumentKind::Template);
        try {
            app(OutlookAddinPayloadService::class)->forUser(User::factory()->create());
            $this->fail('Oversized scoped CSS must not be delivered to Office.');
        } catch (OutlookAddinException $exception) {
            $this->assertStringContainsString('24 KiB', $exception->getPrevious()?->getMessage() ?? '');
        }
    }

    public function test_outlook_addin_published_root_context_css_cannot_escape_signature_scope(): void
    {
        try {
            TrustedOutlookSignatureCss::publishedStyle(
                '<table class="custom-copy"></table>',
                ':is(html,body) + *{color:red}',
                'rts0123456789',
            );
            $this->fail('Ein Root-Sibling-Selektor darf den dynamischen Signatur-Scope nicht verlassen.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Das veroeffentlichte Signatur-CSS darf keine Elemente ausserhalb der Signatur adressieren.',
                $exception->getMessage(),
            );
        }
    }

    public function test_outlook_addin_payload_exposes_published_template_snapshots_in_stable_order(): void
    {
        config([
            'app.url' => 'https://app.rail-time.de',
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.marker' => 'RT-SIGNATURE-MANAGED-V1',
        ]);
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $active = MailDocument::query()
            ->where('kind', MailDocumentKind::Template->value)
            ->where('is_active', true)
            ->firstOrFail();
        $canonicalHtml = trim((string) $active->published_html);
        $alphaPublished = str_replace('Sicher abgestimmt.', 'Alpha freigegeben.', $canonicalHtml, $alphaCount);
        $alphaDraft = str_replace('Sicher abgestimmt.', 'Alpha nur im Entwurf.', $canonicalHtml, $alphaDraftCount);
        $zuluPublished = str_replace('Sicher abgestimmt.', 'Zulu freigegeben.', $canonicalHtml, $zuluCount);
        $this->assertSame([1, 1, 1], [$alphaCount, $alphaDraftCount, $zuluCount]);

        $zulu = $this->createTemplateSlot('Zulu Vorlage', $zuluPublished, $zuluPublished);
        $alpha = $this->createTemplateSlot('  Alpha   Vorlage  ', $alphaDraft, $alphaPublished);
        $draftOnly = $this->createTemplateSlot('Nicht freigegeben', $canonicalHtml, null);
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $payloads = app(OutlookAddinPayloadService::class);

        $payload = $payloads->forUser($user);

        $this->assertSame(
            [$active->public_id, $alpha->public_id, $zulu->public_id],
            array_column($payload['templates'], 'id'),
        );
        $this->assertSame(
            ['Standardvorlage', 'Alpha Vorlage', 'Zulu Vorlage'],
            array_column($payload['templates'], 'name'),
        );
        $this->assertSame([true, false, false], array_column($payload['templates'], 'active'));
        $this->assertNotContains($draftOnly->public_id, array_column($payload['templates'], 'id'));

        foreach ($payload['templates'] as $template) {
            $this->assertSame($template['id'], $template['key']);
            $this->assertSame($template['name'], $template['label']);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $template['version']);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $template['hash']);
            $this->assertStringStartsWith($template['version'], $template['hash']);
            $this->assertStringContainsString('Mara Beispiel', $template['html']);
            foreach ($template['media'] as $medium) {
                $this->assertStringContainsString('cid:'.$medium['contentId'], $template['html']);
            }
        }

        $this->assertStringContainsString('Alpha freigegeben.', $payload['templates'][1]['html']);
        $this->assertStringNotContainsString('Alpha nur im Entwurf.', $payload['templates'][1]['html']);
        $this->assertSame(
            hash('sha256', $alphaPublished."\0"),
            $payload['templates'][1]['hash'],
        );
        $this->assertSame($payload['template'], [
            'html' => $payload['templates'][0]['html'],
            'media' => $payload['templates'][0]['media'],
        ]);
        $this->assertSame($payload['version']['template'], $payload['templates'][0]['version']);

        $fingerprint = $payloads->sourceFingerprint($user);
        $draftOnly->forceFill(['html' => $draftOnly->html.'<!-- geaenderter Entwurf -->'])->save();
        $alpha->forceFill(['html' => $alpha->html.'<!-- ebenfalls nur Entwurf -->'])->save();
        $this->assertSame($fingerprint, $payloads->sourceFingerprint($user));

        $alpha->forceFill([
            'published_html' => str_replace('Alpha freigegeben.', 'Alpha neu freigegeben.', $alphaPublished),
        ])->save();
        $this->assertNotSame($fingerprint, $payloads->sourceFingerprint($user));
    }

    public function test_outlook_library_payload_default_is_explicit_and_drafts_do_not_change_employee_snapshots(): void
    {
        config(['app.url' => 'https://app.rail-time.de', 'outlook_addin.base_url' => 'https://app.rail-time.de', 'outlook_addin.snapshots.auto_refresh' => false]);
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_22_000200_create_mail_document_versions_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        (include database_path('migrations/2026_09_06_010000_add_outlook_library_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'staff']);
        $payloads = app(OutlookAddinPayloadService::class);
        $library = app(OutlookTemplateLibrary::class);
        $before = $payloads->sourceFingerprint($user);
        $this->assertNull($payloads->forUser($user)['automaticTemplateId']);
        $draft = $library->createDraft($admin, 'Outlook Angebot');
        $this->assertSame($before, $payloads->sourceFingerprint($user));
        $draft = $library->publish($admin, $draft, $draft->content_hash);
        $released = $payloads->forUser($user);
        $this->assertNull($released['automaticTemplateId']);
        $this->assertCount(2, $released['templates']);
        $releasedFingerprint = $payloads->sourceFingerprint($user);

        $draft = $library->setDefault($admin, $draft, $draft->content_hash);
        $defaultPayload = $payloads->forUser($user);
        $this->assertSame($draft->public_id, $defaultPayload['automaticTemplateId']);
        $this->assertTrue(collect($defaultPayload['templates'])->firstWhere('id', $draft->public_id)['isDefault']);
        $this->assertSame($released['template'], $defaultPayload['template']);
        $this->assertNotSame($releasedFingerprint, $payloads->sourceFingerprint($user));

        $library->withdraw($admin, $draft, $draft->content_hash);
        $withdrawn = $payloads->forUser($user);
        $this->assertNull($withdrawn['automaticTemplateId']);
        $this->assertCount(1, $withdrawn['templates']);
        $this->assertSame($released['template'], $withdrawn['template']);
        $this->assertSame($before, $payloads->sourceFingerprint($user));
    }

    public function test_outlook_addin_rebuilds_a_legacy_personal_snapshot_without_template_collection(): void
    {
        config([
            'app.url' => 'https://app.rail-time.de',
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.snapshots.disk' => 'private',
        ]);
        Storage::fake('private');
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $user = User::factory()->create([
            'name' => 'Mara Beispiel',
            'role' => 'staff',
            'status' => true,
            'email_verified_at' => now(),
        ]);
        $snapshots = app(OutlookAddinUserSnapshotStore::class);
        $payload = $snapshots->currentForUser($user);
        $path = $snapshots->pathForUser($user);
        $encrypted = Storage::disk('private')->get($path);
        $compressed = base64_decode(Crypt::decryptString($encrypted), true);
        $this->assertIsString($compressed);
        $json = gzdecode($compressed);
        $this->assertIsString($json);
        $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        unset($envelope['payload']['templates']);
        $envelope['payload_hash'] = hash('sha256', json_encode(
            $envelope['payload'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $legacyEnvelope = gzencode(json_encode(
            $envelope,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ), 6);
        $this->assertIsString($legacyEnvelope);
        Storage::disk('private')->put(
            $path,
            Crypt::encryptString(base64_encode($legacyEnvelope)),
        );

        $rebuilt = $snapshots->currentForUser($user);

        $this->assertSame($payload['templates'], $rebuilt['templates']);
        $this->assertStringContainsString(
            'RT-SIGNATURE-VERSION:'.$rebuilt['version']['signature'],
            $rebuilt['signature']['html'],
        );
    }

    public function test_outlook_addin_current_check_uses_fresh_user_data_without_rewriting_the_snapshot(): void
    {
        config([
            'app.url' => 'https://app.rail-time.de',
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.snapshots.disk' => 'private',
            'outlook_addin.snapshots.auto_refresh' => false,
        ]);
        Storage::fake('private');
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $user = User::factory()->create([
            'name' => 'Mara Beispiel',
            'role' => 'staff',
            'status' => true,
            'email_verified_at' => now(),
        ]);
        UserProfile::withoutEvents(static function () use ($user): void {
            UserProfile::query()->create([
                'user_id' => $user->id,
                'first_name' => 'Mara',
                'last_name' => 'Beispiel',
                'phone' => '04171 111111',
                'position' => 'Disposition',
            ]);
        });
        $user->load('profile');

        $snapshots = app(OutlookAddinUserSnapshotStore::class);
        $snapshots->currentForUser($user);
        $path = $snapshots->pathForUser($user);
        $storedBytes = Storage::disk('private')->get($path);

        $this->assertTrue($snapshots->isCurrentForUser($user->id));
        $this->assertSame($storedBytes, Storage::disk('private')->get($path));

        UserProfile::withoutEvents(static function () use ($user): void {
            UserProfile::query()
                ->where('user_id', $user->id)
                ->firstOrFail()
                ->forceFill(['phone' => '04171 222222'])
                ->save();
        });

        $this->assertSame('04171 111111', $user->profile?->phone);
        $this->assertFalse($snapshots->isCurrentForUser($user));
        $this->assertSame($storedBytes, Storage::disk('private')->get($path));

        UserProfile::withoutEvents(static function () use ($user): void {
            UserProfile::query()
                ->where('user_id', $user->id)
                ->firstOrFail()
                ->forceFill(['phone' => '04171 111111'])
                ->save();
        });
        $this->assertTrue($snapshots->isCurrentForUser($user->id));
        User::withoutEvents(static function () use ($user): void {
            User::query()
                ->findOrFail($user->id)
                ->forceFill(['role' => 'user'])
                ->save();
        });

        $this->assertSame('staff', $user->role);
        $this->assertFalse($snapshots->isCurrentForUser($user));
        $this->assertSame($storedBytes, Storage::disk('private')->get($path));
    }

    public function test_outlook_addin_current_check_preserves_an_unreadable_snapshot_without_logging(): void
    {
        config([
            'app.url' => 'https://app.rail-time.de',
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.snapshots.disk' => 'private',
            'outlook_addin.snapshots.auto_refresh' => false,
        ]);
        Storage::fake('private');
        $user = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'email_verified_at' => now(),
        ]);
        $snapshots = app(OutlookAddinUserSnapshotStore::class);
        $path = $snapshots->pathForUser($user);
        $unreadableBytes = 'kein-gueltiger-verschluesselter-outlook-abzug';
        Storage::disk('private')->put($path, $unreadableBytes);
        Log::spy();

        $this->assertFalse($snapshots->isCurrentForUser($user));
        $this->assertTrue(Storage::disk('private')->exists($path));
        $this->assertSame($unreadableBytes, Storage::disk('private')->get($path));
        Log::shouldNotHaveReceived('notice');
    }

    public function test_outlook_addin_runtime_css_fingerprint_stales_and_refreshes_a_personal_snapshot(): void
    {
        config([
            'app.url' => 'https://app.rail-time.de',
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.snapshots.disk' => 'private',
            'outlook_addin.snapshots.auto_refresh' => false,
        ]);
        Storage::fake('private');
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $user = User::factory()->create([
            'name' => 'Mara Beispiel',
            'role' => 'staff',
            'status' => true,
            'email_verified_at' => now(),
        ]);

        $realViewFactory = $this->app->make(ViewFactory::class);
        $baseRuntimeCss = $realViewFactory
            ->make('emails.parts.responsive-css', ['border' => '#dfe3e6'])
            ->render();
        $runtimeCss = $baseRuntimeCss."\n.rt-outlook-source-revision{display:block;}";
        $runtimeView = Mockery::mock(ViewContract::class);
        $runtimeView->shouldReceive('render')->andReturnUsing(
            static function () use (&$runtimeCss): string {
                return $runtimeCss;
            },
        );
        $viewFactory = Mockery::mock($realViewFactory)->makePartial();
        $viewFactory->shouldReceive('make')
            ->withArgs(static fn (mixed $view): bool => $view === 'emails.parts.responsive-css')
            ->andReturn($runtimeView);
        $this->app->instance(ViewFactory::class, $viewFactory);
        View::swap($viewFactory);

        try {
            $payloads = app(OutlookAddinPayloadService::class);
            $fingerprintA = $payloads->sourceFingerprint($user);
            $this->assertSame($fingerprintA, $payloads->sourceFingerprint($user));

            $snapshots = app(OutlookAddinUserSnapshotStore::class);
            $initial = $snapshots->currentForUser($user);
            $path = $snapshots->pathForUser($user);
            $storedBytesA = Storage::disk('private')->get($path);
            $this->assertTrue($snapshots->isCurrentForUser($user));

            $runtimeCss = $baseRuntimeCss."\n.rt-outlook-source-revision{display:none;}";
            $fingerprintB = $payloads->sourceFingerprint($user);
            $this->assertNotSame($fingerprintA, $fingerprintB);
            $this->assertSame($fingerprintB, $payloads->sourceFingerprint($user));

            $this->assertFalse($snapshots->isCurrentForUser($user));
            $this->assertSame($storedBytesA, Storage::disk('private')->get($path));

            $refreshed = $snapshots->currentForUser($user);
            $storedBytesB = Storage::disk('private')->get($path);
            $this->assertNotSame($storedBytesA, $storedBytesB);
            $this->assertTrue($snapshots->isCurrentForUser($user));
            $this->assertNotSame(
                $initial['version']['signature'],
                $refreshed['version']['signature'],
            );
            $this->assertSame(
                substr($fingerprintB, 0, 16),
                $refreshed['version']['personal'],
            );
            // The source still invalidates snapshots, but unused runtime
            // selectors are no longer shipped into the compose document.
            $this->assertStringNotContainsString(
                '.rt-outlook-source-revision',
                $refreshed['template']['html'],
            );
            $this->assertStringContainsString('data-rt-outlook-template-css="1"', $refreshed['template']['html']);
        } finally {
            $this->app->instance(ViewFactory::class, $realViewFactory);
            View::swap($realViewFactory);
        }
    }

    public function test_outlook_addin_fails_closed_when_active_template_status_is_not_published(): void
    {
        config([
            'app.url' => 'https://app.rail-time.de',
            'outlook_addin.base_url' => 'https://app.rail-time.de',
        ]);
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        MailDocument::query()
            ->where('kind', MailDocumentKind::Template->value)
            ->where('is_active', true)
            ->firstOrFail()
            ->forceFill(['status' => MailDocumentStatus::Draft])
            ->save();
        $this->assertFalse(MailDocument::query()
            ->published()
            ->where('kind', MailDocumentKind::Template->value)
            ->exists());
        $payloads = app(OutlookAddinPayloadService::class);
        $user = User::factory()->create(['name' => 'Mara Beispiel']);

        try {
            $payloads->forUser($user);
            $this->fail('Ein aktiver Entwurf darf nicht als Outlook-Vorlage ausgeliefert werden.');
        } catch (OutlookAddinException $exception) {
            $this->assertSame('outlook_addin_publication_invalid', $exception->errorCode);
        }

        try {
            $payloads->sourceFingerprint($user);
            $this->fail('Ein aktiver Entwurf darf keinen Outlook-Quellfingerabdruck erzeugen.');
        } catch (OutlookAddinException $exception) {
            $this->assertSame('outlook_addin_snapshot_source_invalid', $exception->errorCode);
        }
    }

    public function test_outlook_addin_manifest_and_employee_surface_use_the_cross_platform_compose_event(): void
    {
        $this->withoutMiddleware(LogActivity::class);
        $this->configureReadyOutlookAddin();
        config(['outlook_addin.snapshots.disk' => 'private']);
        Storage::fake('private');
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.outlook-addin.manifest'));
        $manifest = $response->getContent();

        $response->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('DefaultMinVersion="1.10"', $manifest);
        $this->assertSame(2, substr_count($manifest, 'Type="OnNewMessageCompose"'));
        $this->assertSame(2, substr_count($manifest, 'FunctionName="onNewMessageComposeHandler"'));
        $this->assertStringNotContainsString('Type="OnMessageCompose"', $manifest);
        $this->assertStringContainsString('/outlook-addin/runtime.js', $manifest);
        $this->assertStringNotContainsString('client_secret', strtolower($manifest));

        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($manifest, LIBXML_NONET));

        $this->createOutlookIdentityAccountsTable();
        $employee = User::factory()->create([
            'email' => 'employee@example.com',
            'role' => 'staff',
            'status' => true,
            'email_verified_at' => now(),
        ]);
        EmployeeIdentityAccount::query()->create([
            'user_id' => $employee->id,
            'provider' => AccountProvider::Microsoft365,
            'external_id' => '44444444-4444-4444-8444-444444444444',
            'principal' => $employee->email,
            'email' => $employee->email,
            'lifecycle_status' => 'active',
            'provisioning_status' => 'active',
        ]);
        $snapshots = app(OutlookAddinUserSnapshotStore::class);
        $snapshotPath = $snapshots->pathForUser($employee);
        $snapshots->forgetForUser($employee);
        $this->assertTrue(app(OutlookAddinConfiguration::class)->availableTo($employee));
        $this->assertFalse($snapshots->isCurrentForUser($employee));

        $this->actingAs($employee)
            ->get(route('email-templates.index'))
            ->assertOk()
            ->assertSee('data-outlook-addin-pending', escape: false)
            ->assertSee('Microsoft verbunden')
            ->assertSee('data-mail-outlook-access', escape: false)
            ->assertSee('data-email-template-primary-downloads', escape: false)
            ->assertSee('data-email-template-employee-action="signature-copy"', escape: false);
        $this->assertFalse(Storage::disk('private')->exists($snapshotPath));

        $snapshots->currentForUser($employee);
        $this->assertTrue(Storage::disk('private')->exists($snapshotPath));
        $snapshotBytes = Storage::disk('private')->get($snapshotPath);

        $managedResponse = $this->actingAs($employee)
            ->get(route('email-templates.index'));
        $managedResponse
            ->assertOk()
            ->assertSee('data-outlook-addin-managed', escape: false)
            ->assertSee('Aktueller Stand')
            ->assertSee('Die aktuelle Signatur und die bereitgestellten Vorlagen liegen für das Add-in vor.')
            ->assertDontSee('data-email-template-primary-downloads', escape: false)
            ->assertDontSee('data-email-template-employee-action=', escape: false)
            ->assertDontSee(route('email-templates.download', ['template' => 'vorlage-html']), escape: false)
            ->assertDontSee(route('email-templates.download', ['template' => 'signatur-outlook-hell']), escape: false);
        $this->assertSame($snapshotBytes, Storage::disk('private')->get($snapshotPath));

        User::query()->whereKey($employee->id)->update(['name' => 'Mara Neuer Stand']);
        $this->assertFalse($snapshots->isCurrentForUser($employee));
        $this->assertTrue(Storage::disk('private')->exists($snapshotPath));
        $this->assertSame($snapshotBytes, Storage::disk('private')->get($snapshotPath));

        $unlinkedEmployee = User::factory()->create(['email' => 'unlinked@example.com']);
        $this->actingAs($unlinkedEmployee)
            ->get(route('email-templates.index'))
            ->assertOk()
            ->assertDontSee('data-outlook-addin-managed', escape: false)
            ->assertSee('Für Ihr Konto muss die IT die Microsoft-Zuordnung und die Add-in-Zuweisung prüfen.')
            ->assertSee('data-email-template-primary-downloads', escape: false)
            ->assertSee('data-email-template-employee-action="signature-copy"', escape: false)
            ->assertSee('data-email-template-employee-action="template-download"', escape: false);

        $unauthorizedEmployee = User::factory()->create(['role' => 'user']);
        $this->actingAs($unauthorizedEmployee)
            ->get(route('admin.outlook-addin.manifest'))
            ->assertForbidden();
    }

    public function test_outlook_deployment_package_is_complete_and_inert_by_default(): void
    {
        $this->withoutMiddleware(LogActivity::class);
        $this->configureReadyOutlookAddin();
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.outlook-addin.package'));
        $response->assertOk()
            ->assertHeader('content-type', 'application/zip')
            ->assertHeader('x-content-type-options', 'nosniff');

        $path = tempnam(sys_get_temp_dir(), 'railtime-outlook-test-');
        $this->assertIsString($path);

        try {
            $this->assertNotFalse(file_put_contents($path, $response->getContent()));
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $files = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $files[] = $zip->getNameIndex($index);
            }
            sort($files);
            $this->assertSame([
                'ExchangeFallback.ps1',
                'README.txt',
                'manifest.xml',
                'meta.json',
                'signature.html',
            ], $files);
            $script = (string) $zip->getFromName('ExchangeFallback.ps1');
            $signature = (string) $zip->getFromName('signature.html');
            $meta = json_decode((string) $zip->getFromName('meta.json'), true, flags: JSON_THROW_ON_ERROR);
            $zip->close();

            $this->assertStringContainsString('$ApplyChanges = $false', $script);
            $this->assertStringContainsString('$EnableRule = $false', $script);
            $this->assertFalse($meta['tenant_mutated']);
            $this->assertFalse($meta['apply_changes_default']);
            $this->assertFalse($meta['enable_rule_default']);
            $this->assertStringContainsString('%%DisplayName%%', $signature);
            $this->assertStringContainsString('%%Title%%', $signature);
            $this->assertStringContainsString('%%Phone%%', $signature);
            $this->assertStringContainsString('%%MobilePhone%%', $signature);
            $this->assertStringContainsString('%%WindowsEmailAddress%%', $signature);
            $this->assertStringNotContainsString('RTEXCHANGE', $signature);
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_verified_employee_gets_four_minimal_actions_and_lazy_previews(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('email-templates.index'));
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee(__('app.email_templates'))
            ->assertSee(route('email-templates.download', ['template' => 'vorlage-html']), escape: false)
            ->assertSee(route('email-templates.download', ['template' => 'signatur-outlook-hell']), escape: false)
            ->assertDontSee(route('email-templates.download', ['template' => 'vorlage-eml']), escape: false)
            ->assertDontSee(route('email-templates.download', ['template' => 'vorlage-dunkel-eml']), escape: false)
            ->assertDontSee(route('email-templates.download', ['template' => 'vorlage-dunkel-html']), escape: false)
            ->assertDontSee(route('email-templates.download', ['template' => 'signatur-text']), escape: false)
            ->assertSee('previewUrls:', escape: false)
            ->assertDontSee('previewDownloadUrls:', escape: false)
            ->assertSee('previewModalOpen: false', escape: false)
            ->assertSee('signatureModalOpen: false', escape: false)
            ->assertSee('signatureFrameReady: false', escape: false)
            ->assertSee('signatureLoadFailed: false', escape: false)
            ->assertSee('signatureCopyUrl:', escape: false)
            ->assertSee("mailTheme: 'light'", escape: false)
            ->assertSee('data-email-template-modal-trigger="preview"', escape: false)
            ->assertSee('data-email-template-modal-trigger="signature"', escape: false)
            ->assertSee('data-email-template-modal="preview"', escape: false)
            ->assertSee('data-email-template-modal="signature"', escape: false)
            ->assertSee('data-email-template-signature-copy-action', escape: false)
            ->assertSee('data-email-template-signature-copy-frame', escape: false)
            ->assertSee('data-email-template-signature-copy-confirm', escape: false)
            ->assertSee('aria-haspopup="dialog"', escape: false)
            ->assertSee('aria-controls="email-template-preview-modal"', escape: false)
            ->assertSee('aria-controls="email-template-signature-modal"', escape: false)
            ->assertSee('data-email-template-primary-downloads', escape: false)
            ->assertSee('Klassisches Outlook für Windows')
            ->assertSee('Neues Outlook / Web')
            ->assertSee('Direkt öffnen und kopieren')
            ->assertSee('Profildaten ergänzen')
            ->assertSee('data-email-template-secondary-action', escape: false)
            ->assertDontSee('data-email-template-quick-actions', escape: false)
            ->assertDontSee('data-email-template-modal="profile"', escape: false)
            ->assertDontSee(__('app.email_templates_flow.steps_label'))
            ->assertDontSee(__('app.email_templates_flow.signature_safety'))
            ->assertDontSee(__('app.email_templates_flow.profile_included'))
            ->assertDontSee(__('app.email_templates_flow.approved_design'))
            ->assertDontSee(__('app.email_templates_legal_hint'))
            ->assertDontSee(__('app.email_templates_flow.employee_preview'))
            ->assertSee('data-template-format="zip"', escape: false)
            ->assertSee('data-template-format="html"', escape: false)
            ->assertSee('<template x-if="previewModalOpen">', escape: false)
            ->assertSee('<template x-if="signatureModalOpen && signatureCopyHtml">', escape: false)
            ->assertSee('x-bind:src="previewFrameUrl()"', escape: false)
            ->assertSee('x-bind:srcdoc="signatureCopyHtml"', escape: false)
            ->assertSee("headers: { Accept: 'application/json' }", escape: false)
            ->assertSee("querySelector('body > table[role=presentation]')", escape: false)
            ->assertSee('watchSignatureFrame()', escape: false)
            ->assertSee('new window.ResizeObserver', escape: false)
            ->assertSee('x-ref="signatureCopyButton"', escape: false)
            ->assertSee('signatureLoadFailed ? loadSignatureCopy() : copySignature()', escape: false)
            ->assertSee('Erneut versuchen')
            ->assertDontSee('previewFrameLoaded', escape: false)
            ->assertDontSee('previewFrameReady', escape: false)
            ->assertDontSee('data-email-template-preview-loading', escape: false)
            ->assertDontSee('data-email-template-preview-replay', escape: false)
            ->assertDontSee('data-email-template-preview-theme-toggle', escape: false)
            ->assertSee("window.matchMedia('(prefers-reduced-motion: reduce)')", escape: false)
            ->assertSee('previewPlaybackId: 0', escape: false)
            ->assertSee("preview.searchParams.set('play', String(this.previewPlaybackId))", escape: false)
            ->assertSee("preview.searchParams.set('static', '1')", escape: false)
            ->assertDontSee('data-email-template-motif-toggle', escape: false)
            ->assertSee('data-ui-preview-frame', escape: false)
            ->assertSee('data-email-template-preview-frame', escape: false)
            ->assertDontSee('data-email-template-accordion=', escape: false)
            ->assertSee('data-menu-active="true"', escape: false);

        preg_match_all('/data-template-key="([^"]+)"/', $content, $matches);

        $this->assertCount(2, $matches[1]);
        $this->assertCount(2, array_unique($matches[1]));
        $this->assertEqualsCanonicalizing([
            'vorlage-html',
            'signatur-outlook-hell',
        ], $matches[1]);
        $this->assertSame(2, substr_count($content, 'data-email-template-modal="'));
        $this->assertSame(2, substr_count($content, 'data-email-template-modal-trigger="'));
        $this->assertSame(4, substr_count($content, 'data-email-template-employee-action="'));
        $this->assertSame(2, substr_count($content, 'data-email-template-primary-download="'));
        $this->assertSame(2, substr_count($content, 'data-email-template-primary-action'));
        $this->assertSame(2, substr_count($content, '<iframe'));
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

    public function test_signature_copy_surface_is_private_script_free_and_personalized(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);

        $response = $this->actingAs($user)
            ->get(route('email-templates.signature-copy'));

        $response->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertJsonStructure(['html']);

        $cacheControl = (string) $response->headers->get('cache-control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);

        $html = (string) $response->json('html');
        $this->assertStringContainsString('Mara Beispiel', $html);
        $this->assertStringNotContainsString('<script', $html);

        preg_match_all('/<img\b[^>]*\bsrc="([^"]+)"/i', $html, $images);
        $this->assertNotEmpty($images[1]);
        foreach ($images[1] as $source) {
            $this->assertStringStartsWith('https://', html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }

    public function test_guest_is_redirected_from_signature_copy_surface(): void
    {
        $this->get(route('email-templates.signature-copy'))
            ->assertRedirect(route('login'));
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

        // Die Vorschaukarten mit den Editor-Links haengen an ECHTEN Zeilen in
        // mail_documents (die Seite prueft Schema::hasTable und die Sammlung).
        // Die Tabelle gehoert nicht zum Minimalschema — hier kommt sie aus der
        // echten Migration, damit Spalten und Test nicht auseinanderlaufen.
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $template = MailDocument::query()->where('kind', MailDocumentKind::Template->value)->firstOrFail();
        $signature = MailDocument::query()->where('kind', MailDocumentKind::Signature->value)->firstOrFail();

        $this->actingAs($admin)
            ->get(route('email-templates.index'))
            ->assertOk()
            ->assertSee(route('email-templates.index'), escape: false)
            ->assertSee(route('admin.mail-documents.editor'), escape: false)
            // OHNE escape: false — Blade escaped das Trennzeichen & der
            // Query im href zu &amp;. Die rohe URL steht so nie im Markup.
            ->assertSee(route('admin.mail-documents.editor', ['dokument' => 'template', 'slot' => $template->public_id, 'open' => 1]))
            ->assertSee(route('admin.mail-documents.editor', ['dokument' => 'signature', 'slot' => $signature->public_id, 'open' => 1]))
            ->assertSee('data-email-template-editor-link', escape: false)
            ->assertSee('Vorlagen verwalten', escape: false)
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
     * Der Dampflok-Gueterzug steht in jeder fertigen HTML-Fassung genau
     * einmal als statisches IMG im absolut unten verankerten Layer. Nach
     * 13 Sekunden uebernimmt ein zweites, nullhoch ueberlagertes Idle-IMG;
     * Classic Outlook erhaelt davor genau ein PNG-Standbild im normalen Flow.
     */
    public function test_every_downloadable_html_variant_carries_the_themed_steam_train(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $builder = new EmailTemplateBuilder($user);

        $asset = fn (string $file, string $mime) => 'data:'.$mime.';base64,'.base64_encode(
            file_get_contents(resource_path('mail-templates/assets/'.$file))
        );

        $lightTrain = $asset('zug-dampf-light.gif', 'image/gif');
        $darkTrain = $asset('zug-dampf-dark.gif', 'image/gif');
        $lightTrainStill = $asset('zug-dampf-light.png', 'image/png');
        $darkTrainStill = $asset('zug-dampf-dark.png', 'image/png');
        $lightTrainIdle = $asset('zug-dampf-idle-light.gif', 'image/gif');
        $darkTrainIdle = $asset('zug-dampf-idle-dark.gif', 'image/gif');

        $expected = [
            'vorlage-html' => [$lightTrain, $lightTrainStill, $lightTrainIdle],
            'vorlage-dunkel-html' => [$darkTrain, $darkTrainStill, $darkTrainIdle],
            'signatur-hell' => [$lightTrain, $lightTrainStill, $lightTrainIdle],
            'signatur-dunkel' => [$darkTrain, $darkTrainStill, $darkTrainIdle],
        ];

        foreach ($expected as $template => [$train, $trainStill, $trainIdle]) {
            $html = $builder->build($template)['content'];

            $this->assertStringNotContainsString('{{TRAIN_SRC}}', $html, $template);
            $carrier = $this->assertRuntimeTrainImages($html, $train, $trainStill, $template);
            $this->assertStringContainsString($train, $carrier, $template);
            $this->assertSame(1, substr_count($html, $trainStill), $template);
            $this->assertSame(1, substr_count($html, $trainIdle), $template);
            $this->assertSame(1, substr_count($html, 'data-rt-train-idle-overlay'), $template);
            $this->assertSame(1, substr_count($html, 'data-rt-train-idle-image'), $template);
            $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $html, $template);
            // Die Runtime-Pruefung oben korreliert Quelle und IMG bereits
            // positionssicher. Hier reicht eine begrenzte Stringpruefung; das
            // komplette Base64-GIF als Regex uebersteigt sonst PCREs Groessenlimit.
            $this->assertSame(1, substr_count($carrier, 'src="'.$train.'"'), $template);
            $this->assertStringContainsString('background-position:center center', $carrier, $template);
            $this->assertStringContainsString('background-size:100% 100%', $carrier, $template);
            $this->assertStringNotContainsString('signatur-raster-', $html, $template);
            $this->assertStringNotContainsString('signatur-marke-', $html, $template);

            // Frueher stand hier die Kurzform background:, und die SETZT
            // background-image zurueck — stand sie danach, verschwand der Zug
            // lautlos. Seit die Zelle background-color: verwendet, kann das
            // gar nicht mehr passieren: die Langform ruehrt die Bildebenen
            // nicht an. Geprueft wird deshalb die Ursache statt der Folge.
            $this->assertStringContainsString('background-color:', $html, $template);
            $this->assertDoesNotMatchRegularExpression(
                '/rt-sign-cell[^>]*[";]background:/',
                $html,
                $template.': die Kurzform background: wuerde die Bildebenen zuruecksetzen.',
            );
        }

        // Auch die .eml-Fassungen tragen alle drei Zugmedien als CID-IMG.
        foreach (['vorlage-eml', 'vorlage-dunkel-eml'] as $template) {
            $eml = $builder->build($template)['content'];
            $this->assertStringContainsString('Content-ID: <railtime-train>', $eml, $template);
            $this->assertStringContainsString('Content-ID: <railtime-train-still>', $eml, $template);
            $this->assertStringContainsString('Content-ID: <railtime-train-idle>', $eml, $template);
            $html = $this->decodeEmlHtmlPart($eml);
            $this->assertRuntimeTrainImages(
                $html,
                'cid:railtime-train',
                'cid:railtime-train-still',
                $template,
            );
            $this->assertStringContainsString('data-rt-train-idle-overlay', $html, $template);
            $this->assertStringContainsString('src="cid:railtime-train-idle"', $html, $template);
            $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $html, $template);
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
            $this->assertGreaterThan(40, substr_count($binary, "\x21\xf9\x04"), $file);
            $this->assertStringNotContainsString('NETSCAPE2.0', $binary, $file);

            $durations = [];
            $offset = 0;
            while (($offset = strpos($binary, "\x21\xf9\x04", $offset)) !== false) {
                $durations[] = ord($binary[$offset + 4]) | (ord($binary[$offset + 5]) << 8);
                $offset += 8;
            }

            $this->assertSame(30, $durations[0], "{$file}: Startverzoegerung muss 300 ms betragen.");
            // 13 Sekunden Gesamtlaufzeit: 0,35 s Vorlauf und 7 s Einfahrt bis
            // zur exakten Ankunft bei 7,35 s. Erst danach folgen zwei ruhige
            // Idle-Rauchzyklen und ein kurzer End-Hold. Alles bleibt in genau
            // einem nicht-loopenden GIF, damit kein Rauch rechts vorauseilt.
            $this->assertSame(1300, array_sum($durations), "{$file}: 13 s Gesamtlaufzeit erwartet.");
            // Das 1,5x-Retina-Asset (2160 x 159) bleibt selbst am maximalen
            // 1815-px-Carrier scharf und ist gegenueber der vorherigen
            // 2x-Fassung etwa ein Drittel kleiner. Vertretbar, weil die
            // Datei in versendeten Mails VERLINKT ist — Gmails 102-kB-Schnitt
            // gilt fuer die Nachricht, nicht fuer das Bild.
            $this->assertLessThanOrEqual(
                str_contains($file, 'light') ? 620 * 1024 : 445 * 1024,
                strlen($binary),
                $file,
            );
        }

        // Das Haupt-GIF traegt Einfahrt und Schlusszustand als absolut
        // positioniertes IMG. Nach 13 Sekunden uebernimmt das nullhohe Idle-IMG.
        $signatur = (new EmailTemplateBuilder(User::factory()->create()))->build('signatur-hell')['content'];
        $train = 'data:image/gif;base64,'.base64_encode(file_get_contents(
            resource_path('mail-templates/assets/zug-dampf-light.gif')
        ));
        $trainStill = 'data:image/png;base64,'.base64_encode(file_get_contents(
            resource_path('mail-templates/assets/zug-dampf-light.png')
        ));
        $carrier = $this->assertRuntimeTrainImages($signatur, $train, $trainStill);
        $this->assertSame(1, substr_count($signatur, $train));
        $this->assertSame(1, substr_count($signatur, $trainStill));
        $this->assertStringContainsString($train, $carrier);
        $this->assertStringContainsString('data-rt-train-idle-overlay', $signatur);
        $this->assertStringContainsString('data-rt-train-idle-image', $signatur);
        $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $signatur);
        $this->assertStringContainsString('animation-delay: 13s;', $signatur);
        $this->assertStringNotContainsString('data-rt-outlook-train', $signatur);
    }

    public function test_outlook_export_contains_installable_signature_with_height_neutral_gif(): void
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
                "{$assetFolder}/zug-dampf.png",
                "{$assetFolder}/logo.gif",
                "{$assetFolder}/contact-location.png",
                "{$assetFolder}/contact-phone.png",
                "{$assetFolder}/contact-mobile.png",
                "{$assetFolder}/contact-email.png",
                "{$assetFolder}/contact-web.png",
                'Outlook-klassisch-installieren.cmd',
                'RailTime-Outlook-Installer.ps1',
                'RailTime-Paketmanifest.json',
                'README-Outlook.html',
            ] as $path) {
                $this->assertNotFalse($zip->locateName($path), $path);
            }

            $html = $zip->getFromName("{$signatureName}.htm");
            $this->assertIsString($html);
            $carrier = $this->assertRuntimeTrainImages(
                $html,
                "{$assetFolder}/zug-dampf.gif",
                "{$assetFolder}/zug-dampf.png",
            );
            $this->assertStringContainsString("{$assetFolder}/zug-dampf.gif", $carrier);
            $this->assertStringNotContainsString('data-rt-outlook-train', $html);
            $this->assertStringNotContainsString('data-rt-outlook-train-still', $html);
            $this->assertStringNotContainsString('width:70%', $html);
            $this->assertStringNotContainsString('max-width:620px', $html);
            // OHNE rt-pad an der aeusseren Zelle: die traegt padding:0, damit
            // der unten verankerte Zug-Layer bis an die Kante reicht. Waere die Klasse dort,
            // verkleinerten die Umbruchregeln die Null und der Innenabstand
            // der inneren Zelle blieb zusaetzlich stehen — der Block war auf
            // schmalen Schirmen doppelt eingerueckt (24+36 statt 24 px).
            $this->assertStringContainsString('class="rt-sign-cell"', $html);
            $this->assertStringNotContainsString('rt-sign-train-background', $html);
            $this->assertStringNotContainsString('class="rt-pad rt-sign-cell"', $html);
            $this->assertStringContainsString('style="padding:0;overflow:hidden;background-color:', $html);
            $this->assertStringContainsString('<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">', $html);
            $this->assertStringContainsString('<td class="rt-pad rt-sign-content" valign="bottom" style="padding:0 28px 15px;position:relative;z-index:1;vertical-align:bottom;">', $html);
            $this->assertStringContainsString('height:auto;margin:0 0 0 -12.5%;border:0;outline:none;text-decoration:none;', $html);
            $this->assertStringNotContainsString('data:image/gif;base64,', $html);
            $this->assertStringContainsString('background-image:linear-gradient(', $html);
            $this->assertStringContainsString('background-repeat:no-repeat;', $html);
            $this->assertStringContainsString('background-position:center center;', $html);
            $this->assertStringContainsString('background-size:100% 100%;', $html);
            $this->assertStringNotContainsString('signatur-raster-', $html);
            $this->assertStringNotContainsString('signatur-marke-', $html);
            $this->assertSame(1, substr_count($html, '/zug-dampf.gif'));
            $this->assertSame(1, substr_count($html, '/zug-dampf.png'));
            $this->assertStringNotContainsString('?p=', $html);
            $this->assertStringNotContainsString('&p=', $html);
            $this->assertStringNotContainsString('mail-assets/zug-dampf-', $html);

            $gif = $zip->getFromName("{$assetFolder}/zug-dampf.gif");
            $this->assertSame(
                file_get_contents(resource_path("mail-templates/assets/zug-dampf-{$theme}.gif")),
                $gif,
            );
            $this->assertStringStartsWith('GIF89a', $gif);
            $this->assertStringNotContainsString('NETSCAPE2.0', $gif);
            $this->assertLessThanOrEqual(1536 * 1024, strlen($gif));

            $png = $zip->getFromName("{$assetFolder}/zug-dampf.png");
            $this->assertSame(
                file_get_contents(resource_path("mail-templates/assets/zug-dampf-{$theme}.png")),
                $png,
            );
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

            $durations = [];
            $offset = 0;
            while (($offset = strpos($gif, "\x21\xf9\x04", $offset)) !== false) {
                $durations[] = ord($gif[$offset + 4]) | (ord($gif[$offset + 5]) << 8);
                $offset += 8;
            }
            $this->assertSame(1300, array_sum($durations), 'Outlook-GIF muss dieselbe 13-s-Sequenz wie die Systemsignatur tragen.');

            $installer = $zip->getFromName('Outlook-klassisch-installieren.cmd');
            $this->assertStringContainsString("set \"SIGNATURE_NAME={$signatureName}\"", $installer);
            $this->assertStringContainsString('RailTime-Outlook-Installer.ps1', $installer);
            $this->assertStringContainsString('-NoLogo -NoProfile -STA -ExecutionPolicy Bypass', $installer);
            $this->assertStringContainsString('RAILTIME_INSTALLER_TEST_MODE', $installer);
            $this->assertStringNotContainsString('\\{'.$signatureName.'}', $installer);
            $this->assertStringContainsString('Das ZIP wurde nicht vollstaendig entpackt', $installer);
            $this->assertStringContainsString('[FEHLER]', $installer);
            $this->assertStringContainsString('[PRUEFUNG]', $installer);
            $this->assertStringContainsString('[NAECHSTER SCHRITT]', $installer);
            $this->assertStringContainsString('[NEUES OUTLOOK]', $installer);
            $this->assertStringContainsString('README-Outlook.html oeffnen, "Signatur kopieren"', $installer);
            $this->assertStringContainsString('Ziel: %APPDATA%\Microsoft\Signatures', $installer);
            $this->assertStringNotContainsString('%%APPDATA%%', $installer);
            $this->assertStringContainsString('RailTime-Paketmanifest.json', $installer);
            $this->assertStringContainsString('if not defined RAILTIME_INSTALLER_TEST_MODE pause', $installer);
            $this->assertSame(0, preg_match('/(?<!\r)\n/', $installer), 'CMD muss reine CRLF-Zeilenenden verwenden.');

            $manifest = json_decode($zip->getFromName('RailTime-Paketmanifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $manifest['schema']);
            $this->assertSame($signatureName, $manifest['signatureName']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['packageFingerprint']);
            $this->assertCount(14, $manifest['files']);
            $this->assertSame(
                $manifest['packageFingerprint'],
                hash('sha256', collect($manifest['files'])->map(
                    static fn (array $file): string => "{$file['path']}:{$file['sha256']}",
                )->implode("\n")),
            );
            $manifestFiles = collect($manifest['files'])->keyBy('path');
            foreach ([
                "{$signatureName}.htm",
                "{$signatureName}.rtf",
                "{$signatureName}.txt",
                "{$assetFolder}/zug-dampf.gif",
                "{$assetFolder}/zug-dampf.png",
                "{$assetFolder}/logo.gif",
                "{$assetFolder}/contact-location.png",
                "{$assetFolder}/contact-phone.png",
                "{$assetFolder}/contact-mobile.png",
                "{$assetFolder}/contact-email.png",
                "{$assetFolder}/contact-web.png",
                'README-Outlook.html',
                'Outlook-klassisch-installieren.cmd',
                'RailTime-Outlook-Installer.ps1',
            ] as $manifestPath) {
                $this->assertTrue($manifestFiles->has($manifestPath), $manifestPath);
                $manifestEntry = $manifestFiles->get($manifestPath);
                $this->assertSame(strlen($zip->getFromName($manifestPath)), $manifestEntry['bytes']);
                $this->assertSame(hash('sha256', $zip->getFromName($manifestPath)), $manifestEntry['sha256']);
            }

            $installerScript = $zip->getFromName('RailTime-Outlook-Installer.ps1');
            $this->assertIsString($installerScript);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $installerScript, 'Windows PowerShell 5.1 benötigt für Umlaute eine UTF-8-BOM.');
            $this->assertStringNotContainsString('__RAILTIME_SIGNATURE_NAME__', $installerScript);
            $this->assertStringContainsString("[string] \$SignatureName = '{$signatureName}'", $installerScript);
            $this->assertStringContainsString('System.Windows.Forms', $installerScript);
            $this->assertStringContainsString('AutoScaleMode]::Dpi', $installerScript);
            $this->assertStringContainsString('$form.AutoScroll = $true', $installerScript);
            $this->assertStringContainsString('PrimaryScreen.WorkingArea.Height', $installerScript);
            $this->assertStringContainsString('[Math]::Min(750, $workingHeight - 80)', $installerScript);
            $this->assertStringContainsString('Outlook schließen und installieren', $installerScript);
            $this->assertStringContainsString('$installButton.Size = New-Object System.Drawing.Size(278, 46)', $installerScript);
            $this->assertStringContainsString('$logButton.Size = New-Object System.Drawing.Size(148, 46)', $installerScript);
            $this->assertStringContainsString('$closeButton.Size = New-Object System.Drawing.Size(174, 46)', $installerScript);
            $this->assertStringContainsString("Get-Process -Name 'OUTLOOK'", $installerScript);
            $this->assertStringContainsString("Get-Process -Name 'olk'", $installerScript);
            $this->assertStringContainsString('es bleibt geöffnet und wird nicht verändert', $installerScript);
            $this->assertStringContainsString('CloseMainWindow()', $installerScript);
            $this->assertStringContainsString('Stop-Process -Id $process.Id -Force', $installerScript);
            $this->assertStringContainsString('MessageBoxDefaultButton]::Button2', $installerScript);
            $this->assertStringContainsString("'^[^@\\s]+@rail-time\\.de$'", $installerScript);
            $this->assertStringContainsString("'New Signature'", $installerScript);
            $this->assertStringContainsString("'Reply-Forward Signature'", $installerScript);
            $this->assertStringContainsString("'DisableRoamingSignatures'", $installerScript);
            $this->assertStringContainsString('RailTime-Outlook-Signatur-Installation.log', $installerScript);
            $this->assertStringContainsString('Testmodus: Outlook-Prozesse werden nicht berührt.', $installerScript);
            $this->assertStringContainsString('Paketmanifest geprüft', $installerScript);
            $this->assertStringContainsString('PackageFingerprint', $installerScript);
            $this->assertStringContainsString('FileHashes', $installerScript);
            $this->assertStringContainsString('Erkannte RailTime-Konten', $installerScript);
            $this->assertStringContainsString('SHA-256-Dateiprüfung:', $installerScript);
            $this->assertStringContainsString('[DATEI {0}/{1}]', $installerScript);
            $this->assertStringContainsString('Kopiervorlage für neues Outlook öffnen', $installerScript);
            $this->assertStringContainsString(
                "\$templatePath = Join-Path \$SourceDirectory 'README-Outlook.html'",
                $installerScript,
            );
            $this->assertStringNotContainsString(
                "\$templatePath = Join-Path \$SourceDirectory (\$SignatureName + '.htm')",
                $installerScript,
            );
            $this->assertStringContainsString('param([string] $Message, [int] $Percent, [string] $Detail)', $installerScript);
            $this->assertStringContainsString('$statusText.Text = $Detail', $installerScript);
            $this->assertStringContainsString('Die Einrichtung wurde ohne Installation geschlossen.', $installerScript);
            $this->assertStringContainsString('$newOutlookButton.Enabled = $false', $installerScript);
            $this->assertStringNotContainsString('Hide-InstallerConsole', $installerScript);
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
                    $this->assertSame(['first@rail-time.de', 'second@rail-time.de'], $installation['result']['DetectedAccounts']);
                    $this->assertSame('00000003', $installation['result']['AccountKey']);
                    $this->assertSame($signatureName, $installation['result']['NewSignature']);
                    $this->assertSame($signatureName, $installation['result']['ReplyForwardSignature']);
                    $this->assertTrue($installation['result']['LocalSignatureMode']);
                    $this->assertSame(11, $installation['result']['InstalledFiles']);
                    $this->assertCount(11, $installation['result']['FileHashes']);
                    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $installation['result']['PackageFingerprint']);
                    $this->assertStringContainsString('[INFO] Erkannte RailTime-Konten: 2', $installation['output']);
                    $this->assertStringContainsString('[INFO] Verifizierte Dateien: 11', $installation['output']);
                    $this->assertStringContainsString('[INFO] Neues Outlook: keine lokale Änderung', $installation['output']);

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
                        "{$assetFolder}/zug-dampf.png",
                        "{$assetFolder}/logo.gif",
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
                    $this->assertStringContainsString('RailTime-Konten erkannt: 2', File::get($logPath));
                    $this->assertSame(11, substr_count(File::get($logPath), ' | SHA-256 '));

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

                        $tamperedDirectory = $installerTestRoot.DIRECTORY_SEPARATOR.'tampered-package';
                        $tamperedProfile = $installerTestRoot.DIRECTORY_SEPARATOR.'tampered-profile';
                        File::copyDirectory($packageDirectory, $tamperedDirectory);
                        File::append($tamperedDirectory.DIRECTORY_SEPARATOR.$assetFolder.DIRECTORY_SEPARATOR.'logo.gif', 'tampered');
                        $tamperedInstallation = $this->runOutlookInstaller($tamperedDirectory, $tamperedProfile, $accountFixture);
                        $this->assertSame(11, $tamperedInstallation['exitCode'], $tamperedInstallation['output']);
                        $this->assertStringContainsString('Paketprüfung ist fehlgeschlagen', $tamperedInstallation['output']);
                        $this->assertFalse($tamperedInstallation['result']['Success']);
                        $this->assertSame(11, $tamperedInstallation['result']['ExitCode']);
                        $this->assertDirectoryDoesNotExist(
                            $tamperedProfile.DIRECTORY_SEPARATOR.'AppData'.DIRECTORY_SEPARATOR.'Roaming'
                            .DIRECTORY_SEPARATOR.'Microsoft'.DIRECTORY_SEPARATOR.'Signatures',
                        );

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
            $this->assertIsString($readme);
            $this->assertStringContainsString('id="railtime-signature-copy-frame"', $readme);
            $this->assertStringContainsString('sandbox="allow-same-origin"', $readme);
            $this->assertStringContainsString('id="railtime-copy-signature"', $readme);
            $this->assertStringContainsString('id="railtime-select-signature"', $readme);
            $this->assertStringContainsString("execCommand('copy')", $readme);
            $this->assertStringContainsString('body > table[role="presentation"]', $readme);
            $this->assertStringContainsString('Der rote Button übernimmt ausschließlich die Signatur', $readme);
            $this->assertMatchesRegularExpression('/\bsrcdoc="([^"]*)"/s', $readme);
            preg_match('/\bsrcdoc="([^"]*)"/s', $readme, $srcdocMatch);
            $copyHtml = html_entity_decode($srcdocMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $copyCarrier = $this->assertRuntimeTrainImages($copyHtml);
            $this->assertStringContainsString('border-top:5px solid #e4002b;', $copyHtml);
            $this->assertStringNotContainsString('border-left:5px solid #e4002b;', $copyHtml);
            $this->assertStringContainsString('border-top:5px solid #e4002b;', $copyCarrier);
            $this->assertStringContainsString('zug-dampf-'.$theme.'.gif', $copyCarrier);
            $this->assertMatchesRegularExpression(
                '/<img\b[^>]*class="rt-sign-train"[^>]*src="https:\/\/[^">]+zug-dampf-'.$theme.'\.gif[^">]*"/',
                $copyCarrier,
            );
            $this->assertSame(1, substr_count($copyHtml, 'class="rt-sign-train-mso"'));
            $this->assertSame(1, substr_count($copyHtml, 'data-rt-train-mso="1"'));
            $this->assertStringContainsString('zug-dampf-'.$theme.'.png', $copyHtml);
            $this->assertDoesNotMatchRegularExpression('/<v:(?:rect|fill)\b/i', $copyHtml);
            $this->assertStringNotContainsString("{$assetFolder}/", $copyHtml);
            $this->assertStringNotContainsString('data:image/', $copyHtml);
            preg_match_all('/<img\b[^>]*\bsrc="([^"]+)"/i', $copyHtml, $copyImageMatches);
            $this->assertNotEmpty($copyImageMatches[1]);
            foreach ($copyImageMatches[1] as $copyImageSource) {
                $this->assertStringStartsWith('https://', html_entity_decode($copyImageSource, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $this->assertStringContainsString('.rt-copy-preview iframe { display:block; width:min(720px,100%); height:1px;', $readme);
            $this->assertStringNotContainsString('min-height:320px', $readme);
            $this->assertStringContainsString('Einstellungen → Konten → Signaturen', $readme);
            $this->assertStringContainsString('ZIP zuerst vollständig entpacken', $readme);
            $this->assertStringContainsString('Rechtsklick → Alle extrahieren', $readme);
            $this->assertStringContainsString('geprüfte Windows-Einrichtung', $readme);
            $this->assertStringContainsString('schließt anschließend ausschließlich Classic Outlook', $readme);
            $this->assertStringContainsString('Die Windows-Einrichtung lässt das neue Outlook deshalb geöffnet', $readme);
            $this->assertStringContainsString('ersten Konto mit einer Adresse, die exakt auf @rail-time.de endet', $readme);
            $this->assertStringContainsString('Classic-Signaturmodus', $readme);
            $this->assertStringContainsString('Erfolg oder Fehler erscheinen direkt in der Oberfläche', $readme);
            $this->assertStringContainsString('lokale Windows-Installationsroutine kann diese Signatur daher nicht direkt im neuen Outlook registrieren', $readme);
            $this->assertStringContainsString('Einstellungen → E-Mail → Vorlagen → Hinzufügen → OFT hinzufügen', $readme);
            $this->assertStringContainsString('Dieses Signaturpaket enthält bewusst keine OFT-Datei', $readme);
            $this->assertStringContainsString('qualifizierenden Microsoft-365-Abonnement', $readme);
            $this->assertStringContainsString('Zum Anschauen und Kopieren dient ausschließlich diese README-Kopieransicht', $readme);

            $zip->close();
            unlink($tempPath);
        }
    }

    public function test_published_v15_signature_drives_eml_and_outlook_exports_with_optimized_fail_open_assets(): void
    {
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        $this->createCanonicalMailDocuments();

        $signature = MailDocument::query()
            ->where('kind', MailDocumentKind::Signature->value)
            ->firstOrFail();
        $markedHtml = preg_replace(
            '/^<tr>/',
            '<tr '.SignatureArtifactVersion::ATTRIBUTE.'="'.SignatureArtifactVersion::V15.'">',
            (string) $signature->published_html,
            1,
            $markerCount,
        );
        $this->assertIsString($markedHtml);
        $this->assertSame(1, $markerCount);

        $v15Html = SignatureTrainCarrier::normalize($markedHtml);
        SignatureDocumentContract::assertValid($v15Html);
        $builderData = $signature->builder_data ?: [];
        data_set($builderData, 'pages.0.component', $v15Html);
        data_set($builderData, 'railtime.schema', SignatureDocumentContract::SCHEMA);
        $signature->forceFill([
            'builder_data' => $builderData,
            'html' => $v15Html,
            'published_html' => $v15Html,
            'content_hash' => MailDocument::contentHashFor($builderData, $v15Html, ''),
            'version' => 15,
        ])->save();
        $this->app->forgetScopedInstances();

        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $builder = new EmailTemplateBuilder($user);
        $themes = [
            'light' => [
                'eml' => 'vorlage-eml',
                'outlook' => 'signatur-outlook-hell',
                'variant' => 'hell',
                'logo' => 'wortmarke-signature-v15-light',
            ],
            'dark' => [
                'eml' => 'vorlage-dunkel-eml',
                'outlook' => 'signatur-outlook-dunkel',
                'variant' => 'dunkel',
                'logo' => 'wortmarke-mail-v15-dark',
            ],
        ];

        foreach ($themes as $theme => $assets) {
            $train = "zug-dampf-v15-{$theme}";
            $eml = $builder->build($assets['eml'])['content'];

            foreach ([
                'railtime-logo' => $assets['logo'].'.gif',
                'railtime-logo-still' => $assets['logo'].'.png',
                'railtime-train' => $train.'.gif',
                'railtime-train-still' => $train.'.png',
            ] as $contentId => $filename) {
                $this->assertStringContainsString("Content-ID: <{$contentId}>", $eml);
                $this->assertStringContainsString(
                    "Content-Disposition: inline; filename=\"{$filename}\"",
                    $eml,
                );
                $this->assertSame(
                    file_get_contents(resource_path('mail-templates/assets/'.$filename)),
                    $this->decodeEmlInlineAttachment($eml, $contentId),
                    $filename,
                );
            }
            $this->assertStringNotContainsString('Content-ID: <railtime-train-idle>', $eml);
            $this->assertStringNotContainsString('zug-dampf-idle-', $eml);

            $emlHtml = $this->decodeEmlHtmlPart($eml);
            SignatureTrainCarrier::assertRuntimeImages(
                $emlHtml,
                'cid:railtime-train',
                expectedIdleSource: '',
                expectedMsoSource: 'cid:railtime-train-still',
            );
            $this->assertStringContainsString(
                'data-rt-artifact-version="v15"',
                $emlHtml,
            );
            $this->assertStringContainsString(
                'class="rt-sign-stage" style="position:relative;height:auto;min-height:200px;overflow:visible;"',
                $emlHtml,
            );
            $this->assertStringContainsString('width="720" height="61" alt=""', $emlHtml);
            $this->assertStringContainsString(
                'class="rt-sign-content-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="position:relative;z-index:1;',
                $emlHtml,
            );
            $this->assertStringNotContainsString('data-rt-train-idle', $emlHtml);

            $zipPath = tempnam(sys_get_temp_dir(), 'railtime-v15-outlook-');
            $this->assertNotFalse($zipPath);
            file_put_contents($zipPath, $builder->build($assets['outlook'])['content']);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($zipPath));

            try {
                $signatureName = "RailTime-Signatur-{$assets['variant']}-mara-beispiel";
                $assetFolder = "{$signatureName}_files";
                $this->assertSame(
                    file_get_contents(resource_path('mail-templates/assets/'.$train.'.gif')),
                    $zip->getFromName("{$assetFolder}/zug-dampf.gif"),
                );
                $this->assertSame(
                    file_get_contents(resource_path('mail-templates/assets/'.$train.'.png')),
                    $zip->getFromName("{$assetFolder}/zug-dampf.png"),
                );
                $this->assertSame(
                    file_get_contents(resource_path('mail-templates/assets/'.$assets['logo'].'.gif')),
                    $zip->getFromName("{$assetFolder}/logo.gif"),
                );

                $outlookHtml = $zip->getFromName("{$signatureName}.htm");
                $this->assertIsString($outlookHtml);
                SignatureTrainCarrier::assertRuntimeImages(
                    $outlookHtml,
                    "{$assetFolder}/zug-dampf.gif",
                    expectedIdleSource: '',
                    expectedMsoSource: "{$assetFolder}/zug-dampf.png",
                );
                $this->assertStringContainsString('data-rt-artifact-version="v15"', $outlookHtml);
                $this->assertStringContainsString(
                    'class="rt-sign-stage" style="position:relative;height:auto;min-height:200px;overflow:visible;"',
                    $outlookHtml,
                );
                $this->assertStringContainsString('width="720" height="61" alt=""', $outlookHtml);
                $this->assertStringNotContainsString('data-rt-train-idle', $outlookHtml);

                $readme = $zip->getFromName('README-Outlook.html');
                $this->assertIsString($readme);
                preg_match('/\bsrcdoc="([^"]*)"/s', $readme, $srcdocMatch);
                $this->assertArrayHasKey(1, $srcdocMatch);
                $copyHtml = html_entity_decode($srcdocMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                SignatureTrainCarrier::assertRuntimeImages(
                    $copyHtml,
                    expectedIdleSource: '',
                );
                $this->assertStringContainsString('data-rt-artifact-version="v15"', $copyHtml);
                $this->assertStringContainsString('/mail-assets/'.$train.'.gif', $copyHtml);
                $this->assertStringContainsString('/mail-assets/'.$train.'.png', $copyHtml);
                $this->assertStringContainsString('/mail-assets/'.$assets['logo'].'.gif', $copyHtml);
                $this->assertStringContainsString(
                    'class="rt-sign-stage" style="position:relative;height:auto;min-height:200px;overflow:visible;"',
                    $copyHtml,
                );
                $this->assertStringNotContainsString('data-rt-train-idle', $copyHtml);
            } finally {
                $zip->close();
                unlink($zipPath);
            }
        }
    }

    public function test_published_v20_signature_uses_v18_geometry_and_optimized_eml_media(): void
    {
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        $this->createCanonicalMailDocuments();

        $signature = MailDocument::query()
            ->where('kind', MailDocumentKind::Signature->value)
            ->firstOrFail();
        $markedHtml = preg_replace(
            '/^<tr>/',
            '<tr '.SignatureArtifactVersion::ATTRIBUTE.'="'.SignatureArtifactVersion::V20.'">',
            (string) $signature->published_html,
            1,
            $markerCount,
        );
        $this->assertIsString($markedHtml);
        $this->assertSame(1, $markerCount);

        $v20Html = SignatureTrainCarrier::normalize($markedHtml);
        SignatureDocumentContract::assertValid($v20Html);
        $builderData = $signature->builder_data ?: [];
        data_set($builderData, 'pages.0.component', $v20Html);
        data_set($builderData, 'railtime.schema', SignatureDocumentContract::SCHEMA);
        $signature->forceFill([
            'builder_data' => $builderData,
            'html' => $v20Html,
            'published_html' => $v20Html,
            'content_hash' => MailDocument::contentHashFor($builderData, $v20Html, ''),
            'version' => 20,
        ])->save();
        $this->app->forgetScopedInstances();

        $builder = new EmailTemplateBuilder(User::factory()->create(['name' => 'Mara Beispiel']));
        foreach ([
            'vorlage-eml' => [
                'theme' => 'light',
                'mark' => 'icon-rt-v19-light',
                'logo' => 'wortmarke-signature-v19-light',
                'train' => 'zug-dampf-v19-light',
            ],
            'vorlage-dunkel-eml' => [
                'theme' => 'dark',
                'mark' => 'icon-rt-v19-dark',
                'logo' => 'wortmarke-mail-v19-dark',
                'train' => 'zug-dampf-v19-dark',
            ],
        ] as $template => $assets) {
            $eml = $builder->build($template)['content'];

            foreach ([
                'railtime-mark' => $assets['mark'].'.gif',
                'railtime-mark-still' => $assets['mark'].'.png',
                'railtime-logo' => $assets['logo'].'.gif',
                'railtime-logo-still' => $assets['logo'].'.png',
                'railtime-train' => $assets['train'].'.gif',
                'railtime-train-still' => $assets['train'].'.png',
            ] as $contentId => $filename) {
                $this->assertStringContainsString("Content-ID: <{$contentId}>", $eml);
                $this->assertStringContainsString(
                    "Content-Disposition: inline; filename=\"{$filename}\"",
                    $eml,
                );
                $this->assertSame(
                    file_get_contents(resource_path('mail-templates/assets/'.$filename)),
                    $this->decodeEmlInlineAttachment($eml, $contentId),
                    $filename,
                );
            }

            $this->assertStringNotContainsString('Content-ID: <railtime-train-idle>', $eml);
            $emlHtml = $this->decodeEmlHtmlPart($eml);
            $this->assertStringContainsString('data-rt-artifact-version="v20"', $emlHtml);
            $this->assertStringContainsString('src="cid:railtime-mark"', $emlHtml);
            $this->assertStringContainsString('src="cid:railtime-mark-still"', $emlHtml);
            $this->assertStringContainsString(
                'style="position:relative;z-index:0;display:block;width:100%;height:200px;max-height:200px;',
                $emlHtml,
            );
            $this->assertStringContainsString('margin-bottom:-200px', $emlHtml);
            $this->assertStringNotContainsString('position:absolute;z-index:0', $emlHtml);
            SignatureTrainCarrier::assertRuntimeImages(
                $emlHtml,
                'cid:railtime-train',
                expectedIdleSource: '',
                expectedMsoSource: 'cid:railtime-train-still',
            );
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

    /** Das kompakte Retina-Asset bleibt hochaufloesend und der Idle-Layer ist zentral. */
    public function test_the_train_asset_is_high_resolution_with_central_idle_overlay_css(): void
    {
        [$width, $height] = getimagesize(resource_path('mail-templates/assets/zug-dampf-light.png'));

        // Das Bild ist BREIT und FLACH (2160 x 159). Der Himmel darueber
        // wurde bewusst knapp gehalten: die Zelle zeigt es mit auto 100%,
        // also an ihrer Hoehe ausgerichtet. Je mehr leerer Himmel im Bild
        // steckt, desto kleiner geriete der Zug darin — bei reichlich
        // Kopfraum lag er als flaches Band unter den Daten statt dahinter.
        $this->assertSame(2160, $width);
        $this->assertSame(159, $height);

        // Die Umbruchregeln stehen in EINER Quelle. Vorher lagen sie
        // viermal im Projekt und waren bereits auseinandergelaufen: die
        // Vorlage brach bei 680 px um, die Signatur bei 620 px.
        $regeln = file_get_contents(resource_path('views/emails/parts/responsive-css.blade.php'));

        // Diese Regeln erhalten die geschuetzte Editoransicht des kanonischen
        // Carriers. Ausgelieferte Signaturen projizieren den Zug als IMG.
        $this->assertStringContainsString('background-size: 100% 100% !important;', $regeln);
        $this->assertStringContainsString('background-position: center center !important;', $regeln);
        $this->assertStringNotContainsString('64px 64px, auto 52%', $regeln);
        $this->assertStringContainsString('RT_SERVER_SIGNATURE_RUNTIME_START', $regeln);
        $this->assertStringContainsString('.rt-train-idle-overlay', $regeln);
        $this->assertStringContainsString('.rt-sign-train', $regeln);
        $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $regeln);
        // Tablet hoch und kleiner stapelt Logo, Person und Firmendaten ohne
        // eine zweite mobile Logo- oder Kontaktkopie.
        $this->assertStringContainsString('tr.rt-stack > td { box-sizing: border-box !important; display: block !important; width: 100% !important; }', $regeln);
        $this->assertStringContainsString('.rt-sign-identity { padding: 14px 0 0 !important; }', $regeln);
        $this->assertStringContainsString('border-left: 0 !important;', $regeln);
        $this->assertStringContainsString('border-bottom: 1px solid {{ $border }} !important;', $regeln);
        $this->assertStringContainsString('.rt-sign-company {', $regeln);
        $this->assertStringContainsString('tr.rt-stack > td + td { padding-top: 12px !important; }', $regeln);
        $this->assertMatchesRegularExpression(
            '/tr\[data-rt-artifact-version="v21"\]\s+\.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0\s*!important;/s',
            $regeln,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|\})\s*\.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0\s*!important;/s',
            $regeln,
        );

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
        $this->assertStringContainsString('EmailTemplateBuilder::buildSystemMailHtml', $layout);
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

        // Versionsgebundene Regeln duerfen dieselbe Stufe ergaenzen,
        // aber keinen vierten Umbruchpunkt in den gemeinsamen Vertrag bringen.
        $this->assertSame(['1000', '860', '480'], array_values(array_unique($treffer[1])));

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

    private function decodeEmlInlineAttachment(string $eml, string $contentId): string
    {
        preg_match(
            '/Content-ID: <'.preg_quote($contentId, '/').'>\r\n'
                .'Content-Disposition: inline; filename="[^"]+"\r\n\r\n'
                .'(.*?)\r\n--=_rt_rel_/s',
            $eml,
            $matches,
        );

        $this->assertArrayHasKey(1, $matches, "CID {$contentId} besitzt keinen decodierbaren MIME-Inhalt.");
        $binary = base64_decode((string) preg_replace('/\s+/', '', $matches[1]), true);
        $this->assertIsString($binary, "CID {$contentId} ist nicht gueltig base64-kodiert.");

        return $binary;
    }

    public function test_light_and_dark_html_are_distinct_static_palettes_with_embedded_contact_icons(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $builder = new EmailTemplateBuilder($user);

        $light = $builder->build('vorlage-html')['content'];
        $dark = $builder->build('vorlage-dunkel-html')['content'];

        $this->assertStringContainsString('data-rt-theme="light"', $light);
        // Kein Beige mehr: die helle Fassung steht auf Weiss.
        $this->assertStringContainsString('background:#ffffff', $light);
        $this->assertStringContainsString(
            '<td class="rt-sign-cell" bgcolor="#ffffff" style="padding:0;overflow:hidden;background-color:#ffffff;',
            $light
        );
        $this->assertStringContainsString('<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">', $light);
        $this->assertStringContainsString(
            '<td class="rt-pad rt-sign-content" valign="bottom" style="padding:0 36px 15px;position:relative;z-index:1;vertical-align:bottom;">',
            $light
        );
        $this->assertStringContainsString('color:#111820;font-size:23px;', $light);
        $this->assertStringContainsString(
            'data:image/gif;base64,'.base64_encode(file_get_contents(resource_path('mail-templates/assets/wortmarke-signature-light.gif'))),
            $light
        );
        $this->assertStringNotContainsString('bgcolor="#080b10"', $light);
        $this->assertStringContainsString('data-rt-theme="dark"', $dark);
        $this->assertStringContainsString('background:#111820', $dark);
        $this->assertStringContainsString(
            '<td class="rt-sign-cell" bgcolor="#0c1017" style="padding:0;overflow:hidden;background-color:#0c1017;',
            $dark
        );
        $this->assertStringContainsString('<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">', $dark);
        $this->assertStringContainsString(
            '<td class="rt-pad rt-sign-content" valign="bottom" style="padding:0 36px 15px;position:relative;z-index:1;vertical-align:bottom;">',
            $dark
        );
        $this->assertStringContainsString('color:#ffffff;font-size:23px;', $dark);
        $this->assertStringContainsString(
            'data:image/gif;base64,'.base64_encode(file_get_contents(resource_path('mail-templates/assets/wortmarke-mail-dark.gif'))),
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
            // Nur der transparente, bildfreie Grundschleier bleibt bestehen.
            // Raster und grosses RT-Wasserzeichen duerfen nicht mehr laden.
            $this->assertMatchesRegularExpression('/rt-sign-cell[^>]*linear-gradient\(rgba\(/', $html);
            $carrier = $this->assertRuntimeTrainImages($html);
            $this->assertStringContainsString('background-size:100% 100%', $carrier);
            $this->assertStringNotContainsString('signatur-raster-', $html);
            $this->assertStringNotContainsString('signatur-marke-', $html);
            $this->assertStringContainsString('data-rt-train-idle-overlay', $html);
            $this->assertStringContainsString('data-rt-train-idle-image', $html);
            $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $html);
            $this->assertStringContainsString(
                'class="rt-sign-logo" colspan="2" width="100%" valign="top"',
                $html,
            );
            $this->assertStringContainsString('text-align:right;vertical-align:top;', $html);
            $this->assertStringContainsString(
                'class="rt-sign-identity" dir="ltr" width="50%" valign="top"',
                $html,
            );
            $this->assertStringContainsString('class="rt-sign-company" dir="ltr" width="50%"', $html);
            $this->assertStringContainsString('padding:8px 24px 0 0;position:relative;z-index:1;', $html);
            $this->assertStringContainsString('text-align:left;vertical-align:top;', $html);
            $this->assertStringNotContainsString('rowspan=', $html);
            $this->assertStringNotContainsString('class="rt-sign-layout" role="presentation" dir="rtl"', $html);
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
                ? 'wortmarke-signature-light.gif'
                : 'wortmarke-mail-dark.gif';
            $this->assertStringContainsString("Content-Disposition: inline; filename=\"{$logoFilename}\"", $eml);
            $this->assertStringContainsString(
                'Subject: =?UTF-8?B?'.base64_encode('{{BETREFF}} | RailTime Prüfgesellschaft mbH').'?=',
                $eml
            );
            $this->assertStringContainsString('Content-ID: <railtime-logo>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-logo-still>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-mark>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-mark-still>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-train>', $eml);
            $this->assertStringNotContainsString('Content-ID: <railtime-signature-grid>', $eml);
            $this->assertStringNotContainsString('Content-ID: <railtime-signature-watermark>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-train-still>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-train-idle>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-icon-location>', $eml);
            $this->assertStringContainsString('Content-ID: <railtime-icon-phone>', $eml);
            // Ohne Mobilnummer gibt es keine Mobilzeile und keinen losen Bildanhang.
            $this->assertSame(0, substr_count($eml, 'Content-ID: <railtime-icon-mobile>'));
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
            $this->assertStringContainsString('src="cid:railtime-logo-still"', $html);
            $this->assertStringContainsString('src="cid:railtime-mark"', $html);
            $this->assertStringContainsString('src="cid:railtime-mark-still"', $html);
            $this->assertRuntimeTrainImages(
                $html,
                'cid:railtime-train',
                'cid:railtime-train-still',
            );
            $this->assertStringContainsString('data-rt-train-idle-overlay', $html);
            $this->assertStringContainsString('src="cid:railtime-train-idle"', $html);
            $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $html);
            $this->assertStringContainsString('src="cid:railtime-icon-email"', $html);
            $this->assertStringNotContainsString('src="cid:railtime-icon-mobile"', $html);
            preg_match_all('/cid:(railtime-[a-z0-9-]+)/', $html, $references);
            preg_match_all('/Content-ID: <(railtime-[a-z0-9-]+)>/', $eml, $included);
            $this->assertEqualsCanonicalizing(array_unique($references[1]), $included[1]);
            $this->assertStringNotContainsString('data:image/', $html);
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
            '<td class="rt-sign-cell" bgcolor="#ffffff" style="padding:0;overflow:hidden;',
            $html,
        );
        $this->assertStringContainsString('<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">', $html);
        $this->assertStringContainsString(
            '<td class="rt-pad rt-sign-content" valign="bottom" style="padding:0 28px 15px;position:relative;z-index:1;vertical-align:bottom;">',
            $html,
        );
        $this->assertStringContainsString('Mara Beispiel', $html);
        $this->assertStringContainsString('Disposition', $html);
        $this->assertStringContainsString('href="tel:+49417112345"', $html);
        $this->assertStringContainsString('href="tel:+4917612345678"', $html);
        $this->assertStringContainsString('href="mailto:mara@example.test"', $html);
        $this->assertStringContainsString('href="https://rail-time.example/leistungen"', $html);
        $this->assertStringContainsString('>rail-time.example/leistungen<', $html);
        // PNG: drei Personenicons, vier einmalige Firmenicons sowie die
        // Outlook-Standbilder fuer Wortmarke und Zug. Raster und Wasserzeichen
        // entfallen.
        $this->assertSame(9, substr_count($html, 'data:image/png;base64,'));
        // GIF: Hauptzug, eine transparente Idle-Rauchschleife und Wortmarke.
        $this->assertSame(3, substr_count($html, 'data:image/gif;base64,'));
        $this->assertRuntimeTrainImages($html);
        $this->assertStringContainsString('data-rt-train-idle-overlay', $html);
        $this->assertStringContainsString('data-rt-train-idle-image', $html);
        $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $html);
        $this->assertStringNotContainsString('background=""', $html);
        $this->assertStringContainsString('class="rt-sign-logo"', $html);
        $this->assertStringNotContainsString('RT_PHONE_START', $html);
        $this->assertStringNotContainsString('{{TRAIN_SRC}}', $html);
        // Der transparente Schleier ist die einzige bildfreie Grundebene.
        $this->assertMatchesRegularExpression('/rt-sign-cell[^>]*linear-gradient\(/', $html);
        $this->assertStringContainsString('background-position:center center', $html);
        $this->assertStringNotContainsString('signatur-raster-', $html);
        $this->assertStringNotContainsString('signatur-marke-', $html);

        // EIN Quellreihenfolge-sicherer Wrapper: Logo, Person und Firma
        // existieren genau einmal. Auch wenn ein weiterleitender Client das
        // responsive Head-CSS entfernt, bleibt diese Reihenfolge lesbar.
        $this->assertSame(1, substr_count($html, '<table class="rt-sign-layout" role="presentation" width="100%"'));
        $this->assertStringContainsString('<td class="rt-sign-logo" colspan="2" width="100%"', $html);
        $this->assertStringContainsString('<td class="rt-sign-identity" dir="ltr" width="50%"', $html);
        $this->assertStringContainsString('<td class="rt-sign-company" dir="ltr" width="50%"', $html);
        $this->assertStringNotContainsString('rowspan=', $html);
        $this->assertStringNotContainsString('class="rt-sign-layout" role="presentation" dir="rtl"', $html);

        $namePosition = strpos($html, 'Mara Beispiel');
        $phonePosition = strpos($html, 'href="tel:+49417112345"');
        $logoPosition = strpos($html, 'class="rt-sign-logo"');
        $companyPosition = strpos($html, 'class="rt-sign-company"');

        $this->assertIsInt($logoPosition);
        $this->assertIsInt($namePosition);
        $this->assertIsInt($phonePosition);
        $this->assertIsInt($companyPosition);
        $this->assertLessThan($namePosition, $logoPosition);
        $this->assertLessThan($phonePosition, $namePosition);
        $this->assertLessThan($companyPosition, $phonePosition);
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

            // Sieben einmalige Symbolzellen: drei Personen- und vier
            // Firmenkontakte. Es gibt keine versteckte Mobilkopie mehr.
            $this->assertSame(7, substr_count($html, '<td width="22" align="center" valign="middle"'));
            $this->assertSame(7, substr_count($html, 'mso-line-height-rule:exactly;text-align:center;'));
            $this->assertSame(7, substr_count($html, 'style="display:block;width:22px;height:22px;margin:0 auto;"'));
            // Symbol LINKS vom Text: zwei Personen- und drei Firmenzeilen
            // besitzen Abstand; die beiden letzten Zeilen schliessen ab.
            $this->assertSame(5, substr_count($html, 'padding:0 0 6px 9px;'));
            $this->assertSame(2, substr_count($html, 'padding:0 0 0 9px;'));
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
            // OHNE die Bilddaten pruefen: Das Base64-Alphabet enthaelt
            // Ziffern und den Schraegstrich, deshalb steht "24/7" frueher
            // oder spaeter zufaellig in irgendeinem eingebetteten Bild —
            // gemeint ist aber der Text der Nachricht.
            $ohneBilder = preg_replace('/data:[^;]+;base64,[A-Za-z0-9+\/=]+/', '', $html);
            $this->assertStringNotContainsString('24/7', $ohneBilder);

            // Die Anschrift steht seit dem symmetrischen Umbau GENAU EINMAL
            // im Markup — in der Firmenspalte, Strasse und Ort in einer
            // Zeile. Vorher lag sie doppelt vor (breit in der Marken-,
            // schmal in der Personenspalte) und kostete in jedem Client die
            // doppelte Menge Markup fuer denselben Inhalt.
            $this->assertSame(1, substr_count($html, 'Borsteler Weg 29–31'), $key);
            $this->assertStringContainsString('Borsteler Weg 29–31 · 21423 Winsen (Luhe)', $html, $key);
            $this->assertStringNotContainsString('class="rt-only-wide"', $html, $key);
            $this->assertStringNotContainsString('class="rt-only-narrow"', $html, $key);

            // Die Wortmarke fuehrt die Firmenspalte an, die Anschrift folgt.
            $logoPosition = strpos($html, 'alt="RT Rail Time GmbH"');
            $adressPosition = strpos($html, 'Borsteler Weg 29–31');

            $this->assertIsInt($logoPosition, $key);
            $this->assertIsInt($adressPosition, $key);
            $this->assertLessThan($adressPosition, $logoPosition, $key);
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

            // Der Layout-Wrapper bleibt selbst links-nach-rechts. Beide
            // Kontaktbereiche schreiben ihre Leserichtung zusaetzlich fest,
            // damit geerbtes RTL die Symbole nicht auf die falsche Seite legt.
            $this->assertSame(1, substr_count($html, '<table class="rt-sign-layout" role="presentation" width="100%"'), $key);
            $this->assertStringContainsString('class="rt-sign-logo" colspan="2" width="100%"', $html, $key);
            $this->assertStringContainsString('class="rt-sign-identity" dir="ltr"', $html, $key);
            $this->assertStringContainsString('class="rt-sign-company" dir="ltr"', $html, $key);
            $this->assertStringNotContainsString('rowspan=', $html, $key);
            $this->assertStringNotContainsString('class="rt-sign-layout" role="presentation" dir="rtl"', $html, $key);
            $this->assertStringContainsString(
                '<table class="rt-contact" role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:0;margin-right:auto;',
                $html,
                $key
            );
            // Die Firmenliste existiert absichtlich genau einmal. Symbole und
            // Text behalten in jeder Breite dieselbe Quellreihenfolge; nur der
            // gemeinsame Block wechselt mobil von rechts nach links.
            $this->assertStringContainsString(
                'class="rt-contact rt-company-contact" role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:auto;margin-right:0;',
                $html,
                $key
            );
            $this->assertSame(1, substr_count($html, 'class="rt-contact rt-company-contact"'), $key);
            $this->assertStringNotContainsString('rt-firma-schmal', $html, $key);
            $this->assertStringNotContainsString('rt-marke-mobil', $html, $key);
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
        // Drei Personenicons plus vier einmalige Firmenicons.
        $this->assertCount(7, $matches[1]);

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
        // Ein gemeinsamer Firmenkontaktblock bleibt fuer alle Breiten im DOM.
        $this->assertSame(1, substr_count($html, 'href="tel:+494171546803"'));
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
            foreach ([$animatedA, $animatedB] as $animated) {
                $animated->assertSee('data:image/gif;base64,', escape: false);
                $animatedContent = (string) $animated->getContent();
                $animatedCarrier = $this->assertRuntimeTrainImages($animatedContent);
                $this->assertStringContainsString('data:image/gif;base64,', $animatedCarrier);
                $this->assertStringContainsString('data-rt-train-idle-overlay', $animatedContent);
                $this->assertStringContainsString('data-rt-train-idle-image', $animatedContent);
                $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $animatedContent);
            }
            $this->assertNotSame($animatedA->getContent(), $animatedB->getContent());
            $response->assertSee('data:image/png;base64,', escape: false);
            $staticContent = (string) $response->getContent();
            $staticCarrier = $this->assertRuntimeTrainImages($staticContent);
            $this->assertStringContainsString('data:image/png;base64,', $staticCarrier);
            $this->assertStringNotContainsString('data-rt-train-idle-', $staticContent);
            $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $staticContent);

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
        $germanPoints = implode(' ', $german['points']);
        $this->assertStringContainsString('Einstellungen → Konten → Signaturen', $germanPoints);
        $this->assertStringContainsString('vollständig', $germanPoints);
        $this->assertStringContainsString('Thunderbird', $germanPoints);
        $this->assertStringContainsString('Testmail', $germanPoints);
        $this->assertStringContainsString('Signatur und Mailvorlage', $germanPoints);
        $this->assertStringNotContainsString('EML', $germanPoints);

        App::setLocale('en');
        $english = $catalog->forRoute('email-templates.index');
        $this->assertStringContainsString('templates & signatures', $english['title']);
        $englishPoints = implode(' ', $english['points']);
        $this->assertStringContainsString('Settings → Accounts → Signatures', $englishPoints);
        $this->assertStringContainsString('fully extract', $englishPoints);
        $this->assertStringContainsString('Thunderbird', $englishPoints);
        $this->assertStringContainsString('test email', $englishPoints);
        $this->assertStringContainsString('signature and mail template', $englishPoints);
        $this->assertStringNotContainsString('EML', $englishPoints);

        $englishUser = User::factory()->create(['locale' => 'en']);
        $this->actingAs($englishUser)
            ->get(route('email-templates.index'))
            ->assertOk()
            ->assertDontSee('Quick setup')
            ->assertSee('New Outlook / Web')
            ->assertSee('Copy signature')
            ->assertSee('Try again')
            ->assertDontSee('Schnelle Einrichtung');
    }

    private function assertRuntimeTrainImages(
        string $html,
        ?string $expectedTrainSource = null,
        ?string $expectedStillSource = null,
        string $message = '',
    ): string {
        SignatureTrainCarrier::assertRuntimeImages(
            $html,
            $expectedTrainSource,
            expectedMsoSource: $expectedStillSource,
        );

        $this->assertSame(1, substr_count($html, 'class="rt-sign-stage"'), $message);
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train"'), $message);
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train-mso"'), $message);
        $this->assertSame(1, substr_count($html, 'data-rt-train-mso="1"'), $message);
        $this->assertMatchesRegularExpression('/<img\b[^>]*class="rt-sign-train"[^>]*\bdata-rt-train(?:\s|=|>)[^>]*>/i', $html, $message);
        $this->assertMatchesRegularExpression('/<img\b[^>]*class="rt-sign-train-mso"[^>]*\bdata-rt-train-mso="1"[^>]*>/i', $html, $message);
        $this->assertMatchesRegularExpression('/<div\b[^>]*class="[^"]*\brt-sign-train-layer\b[^"]*"[^>]*style="display:block;[^">]*height:200px;[^">]*max-height:200px;[^">]*margin-bottom:-200px;[^">]*overflow:hidden;/s', $html, $message);
        $this->assertMatchesRegularExpression('/<table\b[^>]*class="rt-sign-train-frame"[^>]*height="200"[^>]*>/i', $html, $message);
        $this->assertMatchesRegularExpression('/<td\b[^>]*class="rt-sign-train-slot"[^>]*height="200"[^>]*valign="bottom"[^>]*>/i', $html, $message);
        $this->assertMatchesRegularExpression('/<table\b[^>]*class="rt-sign-content-frame"[^>]*height="200"[^>]*>/i', $html, $message);
        $this->assertMatchesRegularExpression('/<img\b[^>]*class="rt-sign-train"[^>]*style="position:static;[^">]*bottom:auto;[^">]*display:inline-block;[^">]*vertical-align:bottom;/s', $html, $message);
        $this->assertDoesNotMatchRegularExpression('/<v:(?:rect|fill)\b/i', $html, $message);
        $this->assertStringNotContainsString('<!--[if mso]><tr><td class="rt-sign-train-mso"', $html, $message);

        $this->assertSame(
            1,
            preg_match('/<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*>/', $html, $carrier),
            $message,
        );
        $this->assertStringNotContainsString('rt-sign-train-background', $carrier[0], $message);
        $this->assertStringNotContainsString('data-rt-train-background', $carrier[0], $message);
        $this->assertStringContainsString(
            'background-repeat:no-repeat;',
            $carrier[0],
            $message,
        );
        $this->assertStringNotContainsString('signatur-raster-', $html, $message);
        $this->assertStringNotContainsString('signatur-marke-', $html, $message);
        $this->assertDoesNotMatchRegularExpression('/background-image:[^;]*(?:data:image\/gif|\.gif)/i', $carrier[0], $message);

        return $html;
    }

    private function createCanonicalMailDocuments(): void
    {
        foreach (MailDocumentKind::cases() as $kind) {
            $html = $this->canonicalMailDocumentHtml($kind);
            $builderData = [
                'pages' => [[
                    'name' => $kind->label(),
                    'component' => $html,
                ]],
                'styles' => [],
                'railtime' => [
                    'document' => $kind->value,
                    'schema' => SignatureDocumentContract::SCHEMA,
                ],
            ];

            $attributes = [
                'kind' => $kind,
                'status' => MailDocumentStatus::Published,
                'builder_data' => $builderData,
                'html' => $html,
                'css' => '',
                'published_html' => $html,
                'published_css' => '',
                'published_at' => now(),
                'content_hash' => MailDocument::contentHashFor($builderData, $html, ''),
                'version' => 1,
            ];
            if (Schema::hasColumn('mail_documents', 'name')) {
                $attributes['name'] = $kind === MailDocumentKind::Signature ? 'Standardsignatur' : 'Standardvorlage';
            }
            if (Schema::hasColumn('mail_documents', 'is_active')) {
                $attributes['is_active'] = true;
            }

            MailDocument::query()->create($attributes);
        }
    }

    private function createTemplateSlot(string $name, string $draftHtml, ?string $publishedHtml): MailDocument
    {
        $builderData = [
            'pages' => [[
                'name' => $name,
                'component' => $draftHtml,
            ]],
            'styles' => [],
            'railtime' => [
                'document' => MailDocumentKind::Template->value,
                'schema' => SignatureDocumentContract::SCHEMA,
            ],
        ];

        return MailDocument::query()->create([
            'kind' => MailDocumentKind::Template,
            'name' => $name,
            'status' => MailDocumentStatus::Draft,
            'is_active' => null,
            'builder_data' => $builderData,
            'html' => $draftHtml,
            'css' => '',
            'published_html' => $publishedHtml,
            'published_css' => $publishedHtml === null ? null : '',
            'published_at' => $publishedHtml === null ? null : now(),
            'content_hash' => MailDocument::contentHashFor($builderData, $draftHtml, ''),
            'version' => 2,
        ]);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function configureReadyOutlookAddin(): void
    {
        $tenantId = '11111111-1111-4111-8111-111111111111';
        $clientId = '22222222-2222-4222-8222-222222222222';

        config([
            'outlook_addin.enabled' => true,
            'outlook_addin.deployed' => true,
            'outlook_addin.base_url' => 'https://app.rail-time.de',
            'outlook_addin.entra.tenant_id' => $tenantId,
            'outlook_addin.entra.client_id' => $clientId,
            'outlook_addin.entra.authority' => "https://login.microsoftonline.com/{$tenantId}",
            'outlook_addin.entra.audience' => $clientId,
            'outlook_addin.entra.scope' => 'Signature.Read',
            'outlook_addin.entra.scope_uri' => "api://{$clientId}/Signature.Read",
        ]);
    }

    private function createOutlookIdentityAccountsTable(): void
    {
        if (Schema::hasTable('employee_identity_accounts')) {
            return;
        }

        Schema::create('employee_identity_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider', 64);
            $table->string('external_id', 191)->nullable();
            $table->string('principal', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('lifecycle_status', 32)->default('active');
            $table->string('provisioning_status', 32)->nullable();
            $table->string('license_status', 32)->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    private function canonicalMailDocumentHtml(MailDocumentKind $kind): string
    {
        if ($kind === MailDocumentKind::Template) {
            $html = (string) file_get_contents(EmailTemplateBuilder::masterPath('email-master.html'));
        } else {
            $tokens = [];
            foreach (array_keys(MailSignature::forCompany()->values([], CompanyData::defaults())) as $key) {
                $tokens[$key] = '{{'.$key.'}}';
            }

            $html = view('emails.parts.signature', ['values' => $tokens])->render();
        }

        return trim(app(EmailHtmlSanitizer::class)->assertClean(trim($html))->html);
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
