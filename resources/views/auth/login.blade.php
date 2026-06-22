@extends('layouts.app')

@section('content')
    <div class="flex min-h-screen items-center justify-center p-8">
        <form method="POST" action="/login" class="w-full max-w-sm space-y-4">
            @csrf
            <h1 class="text-xl font-semibold">wydoujin</h1>
            <input type="password" name="password" autofocus
                   class="w-full rounded bg-neutral-800 px-3 py-2"
                   placeholder="Password">
            @error('password')
                <p class="text-sm text-red-400">{{ $message }}</p>
            @enderror
            <button type="submit" class="w-full rounded bg-indigo-600 px-3 py-2">Enter</button>
        </form>
    </div>
@endsection
