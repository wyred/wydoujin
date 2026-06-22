{{-- Soft blue-tinted taxonomy pill (parody/event/flags). One accent only. --}}
<span class="inline-flex items-center" {{ $attributes->merge(['style' => 'gap:5px; height:22px; padding:0 10px; border-radius:var(--radius-pill); font:var(--weight-semibold) 12px/1 var(--font-text); letter-spacing:0.1px; white-space:nowrap; color:var(--color-primary); background:color-mix(in srgb, var(--color-primary) 12%, transparent);']) }}>
    {{ $slot }}
</span>
