import React from 'react';
import { OptionChip } from '../OptionChip/OptionChip.jsx';

/**
 * Segmented — managed single-select row of OptionChips.
 * Pass options as strings or { label, value }; value is the
 * selected value; onChange(value) fires on pick.
 */
export function Segmented({
  options = [],
  value,
  onChange,
  disabled = false,
  style,
  ...rest
}) {
  const norm = options.map((o) =>
    typeof o === 'string' ? { label: o, value: o } : o
  );

  return (
    <div
      role="group"
      style={{ display: 'inline-flex', gap: '6px', flexWrap: 'wrap', ...style }}
      {...rest}
    >
      {norm.map((o) => (
        <OptionChip
          key={String(o.value)}
          label={o.label}
          selected={value === o.value}
          disabled={disabled}
          onClick={() => onChange && onChange(o.value)}
        />
      ))}
    </div>
  );
}
