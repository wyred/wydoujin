<div {{ $attributes->merge(['class' => 'grid', 'style' => 'grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);']) }}>
    {{ $slot }}
</div>
