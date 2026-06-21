import * as React from 'react';

export interface NavBarLink {
  label: React.ReactNode;
  onClick?: () => void;
  /** Render as the current section (white). @default false */
  active?: boolean;
}

export interface NavBarProps {
  /** Wordmark text. @default "Brand" */
  brand?: React.ReactNode;
  links?: NavBarLink[];
  onBrandClick?: () => void;
  /** Free slot on the right (theme toggle, account, etc). */
  right?: React.ReactNode;
  style?: React.CSSProperties;
}

export declare function NavBar(props: NavBarProps): React.ReactElement;
