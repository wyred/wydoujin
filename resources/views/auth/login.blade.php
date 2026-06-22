@extends('layouts.app')

@section('content')
    <div class="flex min-h-screen items-center justify-center" style="padding:var(--space-xl);">
        <form method="POST" action="/login" class="w-full" style="max-width:320px; display:flex; flex-direction:column; gap:var(--space-md);">
            @csrf
            <h1 style="font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">wydoujin</h1>
            <input type="password" name="password" autofocus placeholder="Password"
                   class="w-full"
                   style="background:var(--surface-card); color:var(--text-body); border:1px solid var(--color-hairline); border-radius:var(--radius-md); padding:11px var(--space-md); font:var(--type-body);">
            @error('password')
                <p style="color:#b8453e; font:var(--type-caption);">{{ $message }}</p>
            @enderror
            <x-button type="submit" class="w-full">Enter</x-button>
        </form>
    </div>
@endsection
