import React from 'react';

/**
 * Card — a hairline-bordered surface. No drop shadow (the system's
 * one shadow is reserved for imagery); elevation is a 1px ring.
 * Set interactive for a blue hover outline + pointer.
 */
export function Card({
  interactive = false,
  padding = 'var(--space-lg)',
  radius = 'var(--radius-lg)',
  onClick,
  children,
  style,
  ...rest
}) {
  const [hover, setHover] = React.useState(false);

  return (
    <div
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        background: 'var(--surface-card)',
        border: '1px solid var(--color-hairline)',
        borderRadius: radius,
        padding,
        cursor: interactive ? 'pointer' : 'default',
        outline: interactive && hover ? '2px solid var(--color-primary-focus)' : '2px solid transparent',
        outlineOffset: '-2px',
        transition: 'outline-color .16s ease',
        ...style,
      }}
      {...rest}
    >
      {children}
    </div>
  );
}
