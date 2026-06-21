import React from 'react';

/**
 * NavBar — the system's ultra-thin global header. True-black bar in
 * both themes, brand wordmark left, text links center, and a free
 * right slot (theme toggle, account, etc).
 */
export function NavBar({
  brand = 'Brand',
  links = [],
  onBrandClick,
  right,
  style,
  ...rest
}) {
  return (
    <nav
      style={{
        height: '44px',
        background: 'var(--color-black)',
        display: 'flex',
        alignItems: 'center',
        gap: '28px',
        padding: '0 28px',
        ...style,
      }}
      {...rest}
    >
      <button
        type="button"
        onClick={onBrandClick}
        style={{
          background: 'none',
          border: 'none',
          padding: 0,
          cursor: 'pointer',
          font: '600 18px/1 var(--font-display)',
          letterSpacing: '-0.2px',
          color: 'var(--color-on-dark)',
        }}
      >
        {brand}
      </button>

      <div style={{ display: 'flex', alignItems: 'center', gap: '22px', flex: 1 }}>
        {links.map((l, i) => (
          <NavLink key={i} {...l} />
        ))}
      </div>

      {right ? <div style={{ display: 'flex', alignItems: 'center', gap: '16px' }}>{right}</div> : null}
    </nav>
  );
}

function NavLink({ label, onClick, active = false }) {
  const [hover, setHover] = React.useState(false);
  return (
    <button
      type="button"
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        background: 'none',
        border: 'none',
        padding: 0,
        cursor: 'pointer',
        font: '400 12px/1 var(--font-text)',
        color: active || hover ? 'var(--color-on-dark)' : 'var(--color-body-muted)',
        transition: 'color .16s ease',
      }}
    >
      {label}
    </button>
  );
}
