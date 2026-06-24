<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · wydoujin</title>
    {{-- Apply the saved theme before first paint so error pages honour dark mode too
         (the framework default error page is light-only). / エラー画面もテーマ適用。 --}}
    <script>
        try { if (localStorage.getItem('wyd-theme') === 'dark') document.documentElement.setAttribute('data-dark', 'true'); } catch (e) {}
    </script>
    @vite('resources/css/app.css')
</head>
<body class="min-h-full flex items-center justify-center"
      style="background:var(--surface-page); color:var(--text-body); gap:var(--space-md); padding:var(--space-lg);">
    <span style="font:var(--type-tagline); color:var(--text-heading); letter-spacing:var(--tracking-tagline);">@yield('code')</span>
    <span aria-hidden="true" style="width:1px; height:26px; background:var(--color-hairline);"></span>
    <span style="font:var(--type-body); color:var(--text-muted);">@yield('message')</span>
</body>
</html>
