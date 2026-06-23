<?php

use Illuminate\Support\Facades\Blade;

test('login renders on themed shell', function (): void {
    $this->withoutVite()
        ->get('/login')
        ->assertOk()
        ->assertSee('wydoujin', false);
});

test('button renders link or button by variant', function (): void {
    $link = Blade::render('<x-button variant="primary" href="/x">Go</x-button>');
    $this->assertStringContainsString('<a', $link);
    $this->assertStringContainsString('href="/x"', $link);
    $this->assertStringContainsString('var(--color-primary)', $link);
    $this->assertStringContainsString('var(--radius-pill)', $link);

    $btn = Blade::render('<x-button>Save</x-button>');
    $this->assertStringContainsString('<button', $btn);
});

test('nav has brand links and theme toggle', function (): void {
    $nav = Blade::render('<x-nav active="home" />');
    $this->assertStringContainsString('wydoujin', $nav);
    $this->assertStringContainsString('href="/"', $nav);
    $this->assertStringContainsString('href="/mangaka"', $nav);
    $this->assertStringContainsString('var(--color-black)', $nav);   // true-black bar
    $this->assertStringContainsString("localStorage.setItem('wyd-theme'", $nav); // toggle
});
