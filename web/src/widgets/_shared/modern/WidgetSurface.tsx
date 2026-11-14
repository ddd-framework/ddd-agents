import { classNames } from "../../../provider/class-names";
import type { ReactNode } from "react";
import styles from "./WidgetSurface.module.scss";

export type WidgetSurfaceLevel = "elevated" | "flat";

export interface WidgetSurfaceProps {
  children: ReactNode;
  /** `elevated` on the chat; `flat` for a tile inside a surface or anything inside a canvas pane. */
  level?: WidgetSurfaceLevel;
  /** The compact radius and padding pair, for small nested tiles. */
  compact?: boolean;
  className?: string;
  testId?: string;
}

/** The surface every modern widget and kit component sits on — one radius, one padding, one shadow per level. */
export const WidgetSurface = (props: WidgetSurfaceProps) => {
  const { children, level = "elevated", compact = false, className, testId } = props;
  return (
    <div
      data-surface-level={level}
      data-testid={testId}
      className={classNames(styles.surface, styles[level], compact && styles.compact, className)}
    >
      {children}
    </div>
  );
};
