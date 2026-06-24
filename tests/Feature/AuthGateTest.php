<?php

beforeEach(function (): void {
    $this->withoutVite();
});

test('routes open when no password set', function (): void {
    config(['app.password' => null]);
    $this->get('/')->assertOk();
});

test('protected route redirects when password set', function (): void {
    config(['app.password' => 'secret']);
    $this->get('/')->assertRedirect('/login');
});

test('correct password grants access', function (): void {
    config(['app.password' => 'secret']);
    $this->post('/login', ['password' => 'secret'])->assertRedirect('/');
    $this->get('/')->assertOk();
});

test('wrong password is rejected', function (): void {
    config(['app.password' => 'secret']);
    $this->from('/login')
        ->post('/login', ['password' => 'nope'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('password');
});

test('health is always reachable', function (): void {
    config(['app.password' => 'secret']);
    $this->getJson('/health')->assertOk();
});

test('logout clears the session and re-closes the gate', function (): void {
    config(['app.password' => 'secret']);
    $this->post('/login', ['password' => 'secret'])->assertRedirect('/');
    $this->get('/')->assertOk();

    $this->post('/logout')->assertRedirect('/login');

    $this->get('/')->assertRedirect('/login');
});

test('zero is treated as a real password', function (): void {
    config(['app.password' => '0']);
    $this->get('/')->assertRedirect('/login');
});
