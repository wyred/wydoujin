import React from 'react';

/**
 * Textarea — multi-line text field.
 * 12px radius, hairline border, blue focus ring, vertical resize.
 */
export function Textarea({
  value,
  onChange,
  placeholder,
  rows = 3,
  disabled = false,
  onFocus,
  onBlur,
  style,
  ...rest
}) {
  const [focused, setFocused] = React.useState(false);

  return (
    <textarea
      value={value}
      onChange={onChange}
      placeholder={placeholder}
      rows={rows}
      disabled={disabled}
      onFocus={(e) => { setFocused(true); onFocus && onFocus(e); }}
      onBlur={(e) => { setFocused(false); onBlur && onBlur(e); }}
      style={{
        width: '100%',
        boxSizing: 'border-box',
        minHeight: '56px',
        padding: '12px 16px',
        borderRadius: '12px',
        border: '1px solid',
        borderColor: focused ? 'var(--color-primary-focus)' : 'var(--color-hairline)',
        background: 'var(--color-canvas)',
        color: 'var(--color-ink)',
        font: 'var(--weight-regular) 14px/1.5 var(--font-text)',
        outline: 'none',
        resize: 'vertical',
        opacity: disabled ? 0.5 : 1,
        boxShadow: focused ? '0 0 0 3px rgba(0,113,227,0.15)' : 'none',
        transition: 'border-color .18s ease, box-shadow .18s ease',
        ...style,
      }}
      {...rest}
    />
  );
}
