import type { LayoutGap } from "./layout-props";

/** The CSS-module class of a gap step (`_layout-gaps.scss`): `medium` → `gapMedium`. */
export const layoutGapClassName = (styles: Record<string, string>, gap: LayoutGap): string | undefined =>
  styles[`gap${gap.charAt(0).toUpperCase()}${gap.slice(1)}`];
