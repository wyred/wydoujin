<?php

// The framework's default error page is light-only; ours extend errors.layout, which
// applies the saved theme so 404/500/etc. honour dark mode. Lock that in.

test('a 404 renders the custom theme-aware error view', function (): void {
    $this->withoutVite();

    $this->get('/no-such-route-exists')
        ->assertNotFound()
        ->assertSee('wyd-theme', false) // the inline theme script — unique to errors.layout
        ->assertSee('Not found');
});
