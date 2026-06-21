import React from 'react';

/**
 * Input — pill-shaped single-line text field.
 * 44px tall, hairline border, blue focus ring.
 */
export function Input({
  value,
  onChange,
  placeholder,
  type = 'text',
  disabled = false,
  onFocus,
  onBlur,
  style,
  ...rest
}) {
  const [focused, setFocused] = React.useState(false);

  return (
    <input
      type={type}
      value={value}
      onChange={onChange}
      placeholder={placeholder}
      disabled={disabled}
      onFocus={(e) => { setFocused(true); onFocus && onFocus(e); }}
      onBlur={(e) => { setFocused(false); onBlur && onBlur(e); }}
      style={{
        width: '100%',
        height: '44px',
        boxSizing: 'border-box',
        padding: '0 18px',
        borderRadius: 'var(--radius-pill)',
        border: '1px solid',
        borderColor: focused ? 'var(--color-primary-focus)' : 'var(--color-hairline)',
        background: 'var(--color-canvas)',
        color: 'var(--color-ink)',
        font: 'var(--weight-regular) 15px/1 var(--font-text)',
        letterSpacing: 'var(--tracking-caption)',
        outline: 'none',
        opacity: disabled ? 0.5 : 1,
        boxShadow: focused ? '0 0 0 3px rgba(0,113,227,0.15)' : 'none',
        transition: 'border-color .18s ease, box-shadow .18s ease',
        ...style,
      }}
      {...rest}
    />
  );
}
