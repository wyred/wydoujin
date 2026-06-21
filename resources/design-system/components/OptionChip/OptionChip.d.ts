import * as React from 'react';

export interface OptionChipProps {
  /** Chip text (or pass children). */
  label?: React.ReactNode;
  /** @default false */
  selected?: boolean;
  /** @default false */
  disabled?: boolean;
  onClick?: (e: React.MouseEvent<HTMLButtonElement>) => void;
  children?: React.ReactNode;
  style?: React.CSSProperties;
}

export declare function OptionChip(props: OptionChipProps): React.ReactElement;
