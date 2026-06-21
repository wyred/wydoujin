import * as React from 'react';

export interface CardProps {
  /** Blue hover outline + pointer cursor. @default false */
  interactive?: boolean;
  /** CSS padding value. @default "var(--space-lg)" */
  padding?: string;
  /** CSS border-radius value. @default "var(--radius-lg)" */
  radius?: string;
  onClick?: (e: React.MouseEvent<HTMLDivElement>) => void;
  children?: React.ReactNode;
  style?: React.CSSProperties;
}

export declare function Card(props: CardProps): React.ReactElement;
