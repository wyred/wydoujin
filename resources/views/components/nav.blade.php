@props(['active' => null])

<nav class="flex items-center" style="height:44px; background:var(--color-black); gap:var(--space-xl); padding:0 var(--space-xl);">
    <a href="/" class="no-underline" style="font:var(--weight-semibold) 18px/1 var(--font-display); letter-spacing:-0.2px; color:var(--color-on-dark);">wydoujin</a>

    <div class="flex items-center" style="gap:var(--space-lg); flex:1;">
        <a href="/" class="no-underline {{ $active === 'home' ? '[color:var(--color-on-dark)]' : '[color:var(--color-body-muted)]' }} hover:[color:var(--color-on-dark)]" style="font:var(--type-nav);">Home</a>
        <a href="/mangaka" class="no-underline {{ $active === 'mangaka' ? '[color:var(--color-on-dark)]' : '[color:var(--color-body-muted)]' }} hover:[color:var(--color-on-dark)]" style="font:var(--type-nav);">Mangaka</a>
    </div>

    <button type="button"
        x-data="{ dark: document.documentElement.getAttribute('data-dark') === 'true' }"
        @click="dark = !dark; dark ? document.documentElement.setAttribute('data-dark','true') : document.documentElement.removeAttribute('data-dark'); localStorage.setItem('wyd-theme', dark ? 'dark' : 'light')"
        :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
        style="background:none; border:none; cursor:pointer; color:var(--color-on-dark); font-size:16px; line-height:1;">
        <span x-text="dark ? '☀' : '☾'">☾</span>
    </button>
</nav>
