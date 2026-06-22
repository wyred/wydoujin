@props(['variant' => 'primary', 'href' => null, 'type' => 'button'])

@php
    $skin = $variant === 'secondary'
        ? 'background: var(--color-pearl); color: var(--color-ink-muted-80); border: 1px solid var(--color-hairline);'
        : 'background: var(--color-primary); color: var(--color-on-primary); border: 1px solid transparent;';
    $base = 'display:inline-flex;align-items:center;justify-content:center;gap:var(--space-xs);'
        .'padding:11px 22px;border-radius:var(--radius-pill);font:var(--weight-regular) 16px/1 var(--font-text);'
        .'letter-spacing:-0.01em;white-space:nowrap;cursor:pointer;text-decoration:none;'
        .'transition:transform .18s cubic-bezier(.4,0,.2,1), filter .18s ease;';
@endphp

@if ($href)
    <a href="{{ $href }}"
       {{ $attributes->merge(['style' => $base.$skin, 'class' => 'active:[transform:scale(var(--press-scale))] hover:[filter:brightness(1.06)]']) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}"
        {{ $attributes->merge(['style' => $base.$skin, 'class' => 'active:[transform:scale(var(--press-scale))] hover:[filter:brightness(1.06)]']) }}>
        {{ $slot }}
    </button>
@endif
