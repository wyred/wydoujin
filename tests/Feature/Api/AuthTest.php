<?php

beforeEach(function (): void {
    config(['app.api_token' => 'secret']);
});

test('disabled when token unset returns 503', function (): void {
    config(['app.api_token' => null]);
    $this->getJson('/api/v1/works')->assertStatus(503);
});

test('missing token returns 401', function (): void {
    $this->getJson('/api/v1/works')->assertStatus(401);
});

test('wrong token returns 401', function (): void {
    $this->getJson('/api/v1/works', ['Authorization' => 'Bearer nope'])->assertStatus(401);
});

test('bearer token authenticates', function (): void {
    $this->getJson('/api/v1/works', ['Authorization' => 'Bearer secret'])->assertOk();
});

test('x-api-token header authenticates', function (): void {
    $this->getJson('/api/v1/works', ['X-Api-Token' => 'secret'])->assertOk();
});

test('web routes stay session gated (api gate is separate)', function (): void {
    config(['app.password' => 'pw']);
    $this->get('/')->assertRedirect('/login');
});
