<?php

namespace Tests\Feature;

use App\Support\OutlookAddin\OutlookAddinBuild;
use App\Support\OutlookAddin\OutlookAddinManifest;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

final class OutlookAddinBuildRevisionTest extends TestCase
{
    private string $temporaryPublic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->temporaryPublic = sys_get_temp_dir().'/railtime-outlook-build-test-'.bin2hex(random_bytes(12));
        mkdir($this->temporaryPublic.'/outlook-addin', 0700, true);
        $this->app->usePublicPath($this->temporaryPublic);
        file_put_contents(public_path('outlook-addin/runtime.js'), "/* synthetic runtime */\n");
        file_put_contents(public_path('outlook-addin/taskpane.js'), "/* synthetic taskpane */\n");
        config()->set('outlook_addin.base_url', 'https://example.test');
    }

    protected function tearDown(): void
    {
        // Only these two test-created files are removed; real public bundles
        // are never replaced or recursively traversed.
        foreach (['runtime', 'taskpane'] as $bundle) {
            $file = $this->temporaryPublic.'/outlook-addin/'.$bundle.'.js';
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->temporaryPublic.'/outlook-addin');
        rmdir($this->temporaryPublic);
        parent::tearDown();
    }

    public function test_metadata_is_bound_to_both_final_bundle_bytes_and_the_source_contract(): void
    {
        $build = new OutlookAddinBuild(public_path('outlook-addin'));
        $metadata = $build->metadata();
        $runtime = hash_file('sha256', public_path('outlook-addin/runtime.js'));
        $taskpane = hash_file('sha256', public_path('outlook-addin/taskpane.js'));
        $contract = OutlookAddinBuild::contracts()['sourceContract'];
        $this->assertSame([
            'schema' => 1,
            'revision' => hash('sha256', "railtime-outlook-build-v1\0".$runtime."\0".$taskpane."\0".$contract),
            'sourceContract' => $contract,
            'sourceTemplate' => OutlookAddinBuild::contracts()['sourceTemplate'],
            'bundles' => ['runtime' => $runtime, 'taskpane' => $taskpane],
        ], $metadata);
        $this->assertContains($contract, OutlookAddinBuild::contracts()['readerContracts']);

        touch(public_path('outlook-addin/runtime.js'), time() - 86400);
        $this->assertSame($metadata, $build->metadata(), 'File timestamps cannot define a content revision.');
        file_put_contents(public_path('outlook-addin/taskpane.js'), "/* changed synthetic taskpane */\n");
        $next = $build->metadata();
        $this->assertNotSame($metadata['revision'], $next['revision']);
        $this->assertSame($runtime, $next['bundles']['runtime']);
        $this->assertNotSame($taskpane, $next['bundles']['taskpane']);
        $this->assertSame($contract, $next['sourceContract']);
    }

    public function test_a_partial_build_has_no_revision_and_never_invents_a_fingerprint(): void
    {
        $build = new OutlookAddinBuild(public_path('outlook-addin'));
        unlink(public_path('outlook-addin/taskpane.js'));
        $this->assertNull($build->metadata());
        $this->assertSame('https://example.test/outlook-addin/runtime.js', $build->bundleUrl('https://example.test/', 'runtime'));
        $this->get('/outlook-addin/taskpane.js')->assertStatus(503);
    }

    public function test_only_the_exact_full_content_hash_is_cacheable_for_each_bundle(): void
    {
        foreach (['runtime', 'taskpane'] as $bundle) {
            $body = file_get_contents(public_path('outlook-addin/'.$bundle.'.js'));
            $hash = hash('sha256', $body);
            $response = $this->get('/outlook-addin/'.$bundle.'.js?v='.$hash);
            $response->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=UTF-8')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertSame($body, $response->getContent());
            $this->assertStringContainsString('max-age=3600', $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('no-store', $response->headers->get('Cache-Control'));

            foreach (['', '?v=', '?v=outdated', '?v='.substr($hash, 0, 16), '?v='.strtoupper($hash),
                '?v='.$hash.'%0A', '?v='.$hash.'%0D%0A', '?v%5B%5D='.$hash,
                '?v%5Bnested%5D%5Bvalue%5D='.$hash, '?v='.str_repeat('0', 64)] as $query) {
                $fallback = $this->get('/outlook-addin/'.$bundle.'.js'.$query);
                $fallback->assertOk();
                $this->assertSame($body, $fallback->getContent());
                $this->assertStringContainsString('no-store', $fallback->headers->get('Cache-Control'), $bundle.$query);
                $this->assertStringContainsString('max-age=0', $fallback->headers->get('Cache-Control'));
            }
        }
    }

    public function test_a_stale_manifest_url_receives_current_bytes_without_cache_permission(): void
    {
        $build = app(OutlookAddinBuild::class);
        $old = $build->metadata();
        $oldUrl = $build->bundleUrl('https://example.test', 'runtime');
        $currentBody = "/* newer synthetic runtime */\n";
        file_put_contents(public_path('outlook-addin/runtime.js'), $currentBody);

        $response = $this->get($oldUrl);
        $response->assertOk();
        $this->assertSame($currentBody, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNotSame($old['revision'], $build->metadata()['revision']);
        $this->assertNotSame($oldUrl, $build->bundleUrl('https://example.test', 'runtime'));
    }

    public function test_manifest_loader_and_windows_allowlist_share_the_actual_bundle_revision(): void
    {
        $metadata = app(OutlookAddinBuild::class)->metadata();
        $id = '10000000-0000-4000-8000-000000000001';
        $manifest = new OutlookAddinManifest([
            'enabled' => true, 'addin_id' => $id, 'base_url' => 'https://example.test',
            'entra' => ['tenant_id' => $id, 'client_id' => $id, 'scope' => 'Signature.Read',
                'scope_uri' => 'api://'.$id.'/Signature.Read'],
        ]);
        $urls = $manifest->urls();
        $this->assertSame('https://example.test/outlook-addin/runtime?revision='.$metadata['revision'], $urls['runtime']);
        $this->assertSame('https://example.test/outlook-addin/taskpane?revision='.$metadata['revision'], $urls['taskpane']);
        $this->assertSame('https://example.test/outlook-addin/taskpane?context=settings&revision='.$metadata['revision'], $urls['settings']);
        $this->assertSame('https://example.test/outlook-addin/runtime.js?v='.$metadata['bundles']['runtime'], $urls['runtime_js']);

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($manifest->render(), LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $this->assertSame('ReadWriteItem', $xpath->evaluate('string(/*/*[local-name()="Permissions"])'));
        $this->assertSame(2, $xpath->query('//*[local-name()="LaunchEvent" and @Type="OnNewMessageCompose"]')->length);
        $this->assertSame($urls['runtime_js'], $xpath->evaluate('string(//*[local-name()="Url" and @id="JSRuntime.Url"]/@DefaultValue)'));

        $this->get('/.well-known/microsoft-officeaddins-allowed.json')->assertOk()->assertExactJson([
            'allowed' => [$urls['runtime_js'], 'https://example.test/outlook-addin/runtime.js'],
        ])->assertHeader('X-Content-Type-Options', 'nosniff');
        foreach (['runtime', 'taskpane'] as $bundle) {
            $response = $this->get('/outlook-addin/'.$bundle.'?revision=stale');
            $response->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('src="https://example.test/outlook-addin/'.$bundle.'.js?v='.$metadata['bundles'][$bundle].'"', $response->getContent());
        }
    }

    public function test_a_missing_build_keeps_the_allowlist_legacy_alias_unique(): void
    {
        unlink(public_path('outlook-addin/taskpane.js'));
        $this->get('/.well-known/microsoft-officeaddins-allowed.json')->assertOk()->assertExactJson([
            'allowed' => ['https://example.test/outlook-addin/runtime.js'],
        ]);
    }

    public function test_unknown_bundle_names_are_not_publicly_readable(): void
    {
        $this->get('/outlook-addin/other.js')->assertNotFound();
    }
}
