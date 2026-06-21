import * as React from 'react';

export interface SegmentedOption {
  label: React.ReactNode;
  value: string;
}

export interface SegmentedProps {
  /** Strings or { label, value } objects. */
  options?: Array<string | SegmentedOption>;
  /** Currently selected value. */
  value?: string;
  onChange?: (value: string) => void;
  /** @default false */
  disabled?: boolean;
  style?: React.CSSProperties;
}

export declare function Segmented(props: SegmentedProps): React.ReactElement;
