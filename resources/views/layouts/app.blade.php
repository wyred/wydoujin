<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'wydoujin' }}</title>
    {{-- Apply saved theme before first paint (no flash). / 描画前に保存テーマを適用。 --}}
    <script>
        try { if (localStorage.getItem('wyd-theme') === 'dark') document.documentElement.setAttribute('data-dark', 'true'); } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full" style="background: var(--surface-page); color: var(--text-body);">
    @yield('content')
</body>
</html>
