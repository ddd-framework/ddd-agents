import {
  COMPOSED_VIEW_TONE_GRAPHIC_COLOR,
  COMPOSED_VIEW_TONE_TEXT_COLOR,
} from "../../composed-view-tones";
import type { DataValueTone } from "./data-value-format";
import styles from "./data-tones.module.scss";

/** The text class of a tone (judgement text in the 900 tone, through light-dark()). */
export const TONE_TEXT_CLASS: Record<DataValueTone, string> = {
  neutral: styles.textNeutral,
  muted: styles.textMuted,
  success: styles.textSuccess,
  warning: styles.textWarning,
  danger: styles.textDanger,
  info: styles.textInfo,
};

/** The graphic colour of a tone — the kit's (`composed-view-tones.ts`). */
export const TONE_GRAPHIC_COLOR: Readonly<Record<DataValueTone, string>> = COMPOSED_VIEW_TONE_GRAPHIC_COLOR;

/** The same judgement text colours as values for the component library's `Text`'s `color` prop — the kit's. */
export const TONE_TEXT_COLOR: Readonly<Record<DataValueTone, string>> = COMPOSED_VIEW_TONE_TEXT_COLOR;
