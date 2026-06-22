<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_routes_open_when_no_password_set(): void
    {
        config(['app.password' => null]);
        $this->get('/')->assertOk();
    }

    public function test_protected_route_redirects_when_password_set(): void
    {
        config(['app.password' => 'secret']);
        $this->get('/')->assertRedirect('/login');
    }

    public function test_correct_password_grants_access(): void
    {
        config(['app.password' => 'secret']);
        $this->post('/login', ['password' => 'secret'])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_wrong_password_is_rejected(): void
    {
        config(['app.password' => 'secret']);
        $this->from('/login')
            ->post('/login', ['password' => 'nope'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('password');
    }

    public function test_health_is_always_reachable(): void
    {
        config(['app.password' => 'secret']);
        $this->getJson('/health')->assertOk();
    }

    public function test_zero_is_treated_as_a_real_password(): void
    {
        config(['app.password' => '0']);
        $this->get('/')->assertRedirect('/login');
    }
}
