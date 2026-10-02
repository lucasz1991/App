<?php

namespace Tests\Feature;

use Tests\TestCase;

final class OutlookAddinRuntimePolicyTest extends TestCase
{
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
