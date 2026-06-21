import React from 'react';

/**
 * Button — the system's action element.
 * Pill-shaped, one accent. Press micro-interaction scales to 0.96.
 * Variants: primary (blue), secondary (pearl + hairline), ghost (text-only).
 */
export function Button({
  variant = 'primary',
  size = 'medium',
  fullWidth = false,
  disabled = false,
  type = 'button',
  onClick,
  children,
  style,
  ...rest
}) {
  const [hover, setHover] = React.useState(false);
  const [active, setActive] = React.useState(false);

  const sizes = {
    small: { padding: '7px 16px', font: 'var(--weight-regular) 14px/1 var(--font-text)' },
    medium: { padding: '11px 22px', font: 'var(--weight-regular) 16px/1 var(--font-text)' },
    large: { padding: '14px 30px', font: 'var(--weight-light) 18px/1 var(--font-text)' },
  };

  const base = {
    primary: { background: 'var(--color-primary)', color: 'var(--color-on-primary)', border: '1px solid transparent' },
    secondary: { background: 'var(--color-pearl)', color: 'var(--color-ink-muted-80)', border: '1px solid var(--color-hairline)' },
    ghost: { background: 'transparent', color: 'var(--text-link)', border: '1px solid transparent' },
  }[variant] || {};

  const hoverTint = {
    primary: { filter: 'brightness(1.06)' },
    secondary: { background: 'var(--color-parchment)' },
    ghost: { background: 'var(--color-parchment)' },
  }[variant] || {};

  return (
    <button
      type={type}
      disabled={disabled}
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => { setHover(false); setActive(false); }}
      onMouseDown={() => setActive(true)}
      onMouseUp={() => setActive(false)}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: '8px',
        width: fullWidth ? '100%' : 'auto',
        borderRadius: 'var(--radius-pill)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        letterSpacing: '-0.01em',
        whiteSpace: 'nowrap',
        opacity: disabled ? 0.4 : 1,
        transition: 'transform .18s cubic-bezier(.4,0,.2,1), background-color .18s ease, filter .18s ease',
        transform: active && !disabled ? 'scale(var(--press-scale))' : 'scale(1)',
        ...sizes[size],
        ...base,
        ...(hover && !disabled ? hoverTint : null),
        ...style,
      }}
      {...rest}
    >
      {children}
    </button>
  );
}
