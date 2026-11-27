/** The CSS-module class of a gap step (`_layout-gaps.scss`): `medium` → `gapMedium`. */
export const layoutGapClassName = (styles, gap) => styles[`gap${gap.charAt(0).toUpperCase()}${gap.slice(1)}`];
