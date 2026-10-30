<?php

namespace Tests\Feature;

use Tests\TestCase;

final class OutlookAddinRuntimePolicyTest extends TestCase
{
    public function test_unversioned_classic_runtime_is_not_cached_but_matching_fingerprints_still_are(): void
    {
        $this->withoutMiddleware();
        $hash = substr(hash_file('sha256', public_path('outlook-addin/runtime.js')), 0, 16);

        foreach (['', '?v=outdated', '?v[unknown]=value'] as $query) {
            $response = $this->get('/outlook-addin/runtime.js'.$query);
            $response->assertOk();
            $response->assertHeader('Content-Type', 'text/javascript; charset=UTF-8');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $cache = $response->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cache);
            $this->assertStringContainsString('no-cache', $cache);
            $this->assertStringContainsString('max-age=0', $cache);
            $this->assertSame(file_get_contents(public_path('outlook-addin/runtime.js')), $response->getContent());
        }

        $versioned = $this->get('/outlook-addin/runtime.js?v='.$hash);
        $versioned->assertOk();
        $this->assertStringContainsString('max-age=3600', $versioned->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-store', $versioned->headers->get('Cache-Control'));

        $taskpane = $this->get('/outlook-addin/taskpane.js');
        $taskpane->assertOk();
        $this->assertStringContainsString('max-age=3600', $taskpane->headers->get('Cache-Control'));
    }

    public function test_mobile_runtime_allows_only_the_exact_microsoft_ajax_fallback(): void
    {
        // This contract targets the public runtime response, not DB-backed
        // maintenance/user middleware. No development database is needed.
        $this->withoutMiddleware();
        config()->set('outlook_addin.base_url', 'https://example.test');
        $response = $this->get('/outlook-addin/runtime');
        $response->assertOk();
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertIsString($policy);
        $this->assertStringContainsString('https://ajax.aspnetcdn.com/ajax/3.5/MicrosoftAjax.js', $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
        $this->assertStringNotContainsString('https://ajax.aspnetcdn.com;', $policy);
        $this->assertStringContainsString("default-src 'none'", $policy);
        $this->assertStringContainsString("connect-src 'self' https://login.microsoftonline.com;", $policy);
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
