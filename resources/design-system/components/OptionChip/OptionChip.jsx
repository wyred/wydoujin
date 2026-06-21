import React from 'react';

/**
 * OptionChip — a single selectable pill (single- or multi-select cell).
 * Selected = filled accent; idle = hairline outline. Compose freely,
 * or use <Segmented> for a managed single-select row.
 */
export function OptionChip({
  label,
  selected = false,
  disabled = false,
  onClick,
  children,
  style,
  ...rest
}) {
  const [hover, setHover] = React.useState(false);

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        height: '38px',
        padding: '0 16px',
        borderRadius: 'var(--radius-pill)',
        border: '1px solid',
        borderColor: selected ? 'var(--color-primary)' : 'var(--color-hairline)',
        background: selected
          ? 'var(--color-primary)'
          : (hover ? 'var(--color-parchment)' : 'transparent'),
        color: selected ? 'var(--color-on-primary)' : 'var(--color-ink)',
        font: 'var(--weight-regular) 14px/1 var(--font-text)',
        letterSpacing: 'var(--tracking-caption)',
        whiteSpace: 'nowrap',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.4 : 1,
        transition: 'background-color .16s ease, border-color .16s ease, color .16s ease',
        ...style,
      }}
      {...rest}
    >
      {label ?? children}
    </button>
  );
}
