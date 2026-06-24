<h1 {{ $attributes->merge(['style' => 'font:var(--type-tagline); color:var(--text-heading); letter-spacing:var(--tracking-tagline); margin:0 0 var(--space-md);']) }}>
    {{ $slot }}
</h1>
