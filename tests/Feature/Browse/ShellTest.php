<?php

namespace Tests\Feature\Browse;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ShellTest extends TestCase
{
    public function test_login_renders_on_themed_shell(): void
    {
        $this->withoutVite()
            ->get('/login')
            ->assertOk()
            ->assertSee('wydoujin', false);
    }

    public function test_button_renders_link_or_button_by_variant(): void
    {
        $link = Blade::render('<x-button variant="primary" href="/x">Go</x-button>');
        $this->assertStringContainsString('<a', $link);
        $this->assertStringContainsString('href="/x"', $link);
        $this->assertStringContainsString('var(--color-primary)', $link);
        $this->assertStringContainsString('var(--radius-pill)', $link);

        $btn = Blade::render('<x-button>Save</x-button>');
        $this->assertStringContainsString('<button', $btn);
    }

    public function test_nav_has_brand_links_and_theme_toggle(): void
    {
        $nav = Blade::render('<x-nav active="home" />');
        $this->assertStringContainsString('wydoujin', $nav);
        $this->assertStringContainsString('href="/"', $nav);
        $this->assertStringContainsString('href="/mangaka"', $nav);
        $this->assertStringContainsString('var(--color-black)', $nav);   // true-black bar
        $this->assertStringContainsString("localStorage.setItem('wyd-theme'", $nav); // toggle
    }
}
