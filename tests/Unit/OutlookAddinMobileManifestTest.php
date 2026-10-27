<?php

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookAddinManifest;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class OutlookAddinMobileManifestTest extends TestCase
{
    public function test_mobile_event_and_cache_revision_share_the_release_version_without_new_permissions(): void
    {
        $id = '10000000-0000-4000-8000-000000000001';
        $manifest = new OutlookAddinManifest([
            'enabled' => true,
            'addin_id' => $id,
            'base_url' => 'https://example.test',
            'entra' => [
                'tenant_id' => $id,
                'client_id' => $id,
                'scope' => 'Signature.Read',
                'scope_uri' => 'api://'.$id.'/Signature.Read',
            ],
        ]);
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($manifest->render(), LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $this->assertSame(OutlookAddinManifest::ADDIN_VERSION, $xpath->evaluate('string(/*/*[local-name()="Version"])'));
        $this->assertSame('ReadWriteItem', $xpath->evaluate('string(/*/*[local-name()="Permissions"])'));
        $this->assertSame($id, $xpath->evaluate('string(/*/*[local-name()="Id"])'));
        $mobile = $xpath->query('//*[local-name()="MobileFormFactor"]//*[local-name()="LaunchEvent"]');
        $this->assertSame(1, $mobile->length);
        $this->assertSame('OnNewMessageCompose', $mobile->item(0)->getAttribute('Type'));
        $this->assertSame('onNewMessageComposeHandler', $mobile->item(0)->getAttribute('FunctionName'));
        $this->assertSame('https://example.test/outlook-addin/runtime?revision='.OutlookAddinManifest::ADDIN_VERSION, $manifest->urls()['runtime']);
        // The Windows JS-only allow-list continues to use its exact stable URL.
        $this->assertSame('https://example.test/outlook-addin/runtime.js', $manifest->urls()['runtime_js']);
        $this->assertSame(['Signature.Read'], $manifest->metadata()['scope']);
        $xpath->registerNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $read = $xpath->query('//*[local-name()="MobileFormFactor"]/*[local-name()="ExtensionPoint" and @xsi:type="MobileMessageReadCommandSurface"]');
        self::assertCount(1, $read);
        self::assertCount(9, $xpath->query('.//*[local-name()="Image"]', $read->item(0)));
        self::assertSame('Settings.Url', $xpath->evaluate('string(.//*[local-name()="SourceLocation"]/@resid)', $read->item(0)));
        foreach ([25, 32, 48] as $size) {
            foreach ([1, 2, 3] as $scale) {
                self::assertCount(1, $xpath->query('.//*[local-name()="Image" and @size="'.$size.'" and @scale="'.$scale.'"]', $read->item(0)));
            }
        }
        self::assertCount(2, $xpath->query('//*[local-name()="DesktopFormFactor"]/*[local-name()="ExtensionPoint" and @xsi:type="MessageReadCommandSurface"]'));
        self::assertCount(2, $xpath->query('//*[local-name()="DesktopFormFactor"]/*[local-name()="ExtensionPoint" and @xsi:type="MessageComposeCommandSurface"]'));
        self::assertCount(2, $xpath->query('//*[local-name()="Url" and @id="Settings.Url" and @DefaultValue="https://example.test/outlook-addin/taskpane?context=settings"]'));
        self::assertSame('1.0.3.0', OutlookAddinManifest::ADDIN_VERSION);
    }
}
