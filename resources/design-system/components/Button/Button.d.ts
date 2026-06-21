import * as React from 'react';

export interface ButtonProps {
  /** Visual weight. @default "primary" */
  variant?: 'primary' | 'secondary' | 'ghost';
  /** @default "medium" */
  size?: 'small' | 'medium' | 'large';
  /** Stretch to the container width. @default false */
  fullWidth?: boolean;
  /** @default false */
  disabled?: boolean;
  /** @default "button" */
  type?: 'button' | 'submit' | 'reset';
  onClick?: (e: React.MouseEvent<HTMLButtonElement>) => void;
  children?: React.ReactNode;
  style?: React.CSSProperties;
}

export declare function Button(props: ButtonProps): React.ReactElement;
