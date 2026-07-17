<?php

namespace Tests\Feature;

use App\Http\Middleware\MaintenanceMode;
// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_redirects_to_login(): void
    {
        $this->withoutMiddleware(MaintenanceMode::class);

        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
