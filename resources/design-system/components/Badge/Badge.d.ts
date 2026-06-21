import * as React from 'react';

export interface BadgeProps {
  /** Hue. @default "neutral" */
  tone?: 'neutral' | 'blue' | 'green' | 'purple' | 'amber' | 'red';
  /** Solid fill instead of soft wash. @default false */
  solid?: boolean;
  children?: React.ReactNode;
  style?: React.CSSProperties;
}

export declare function Badge(props: BadgeProps): React.ReactElement;
