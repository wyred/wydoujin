import React from 'react';

/**
 * Badge — a small soft-tinted pill for status, counts, or taxonomy.
 * Tone sets the hue; the fill is a low-alpha wash of it so badges
 * sit quietly on white or charcoal alike.
 */
const TONES = {
  neutral: '#7a7a7a',
  blue: '#0066cc',
  green: '#2e7d4f',
  purple: '#8447b0',
  amber: '#a36a1f',
  red: '#b8453e',
};

export function Badge({
  tone = 'neutral',
  solid = false,
  children,
  style,
  ...rest
}) {
  const hue = TONES[tone] || TONES.neutral;

  const skin = solid
    ? { background: hue, color: '#ffffff' }
    : { background: `color-mix(in srgb, ${hue} 14%, transparent)`, color: hue };

  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: '5px',
        height: '22px',
        padding: '0 10px',
        borderRadius: 'var(--radius-pill)',
        font: 'var(--weight-semibold) 12px/1 var(--font-text)',
        letterSpacing: '0.1px',
        whiteSpace: 'nowrap',
        ...skin,
        ...style,
      }}
      {...rest}
    >
      {children}
    </span>
  );
}
