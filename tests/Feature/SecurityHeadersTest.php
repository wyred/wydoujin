<?php

test('adds defense-in-depth response headers', function (): void {
    config(['app.password' => null]);

    $res = $this->get('/health');

    $res->assertHeader('X-Content-Type-Options', 'nosniff');
    $res->assertHeader('X-Frame-Options', 'DENY');
    $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $res->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
});

test('sets a CSP outside local env', function (): void {
    $this->app['env'] = 'testing';

    $csp = $this->get('/health')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'none'")->toContain("default-src 'self'");
});

test('skips CSP in local env so Vite HMR works', function (): void {
    $this->app['env'] = 'local';

    expect($this->get('/health')->headers->has('Content-Security-Policy'))->toBeFalse();
});
