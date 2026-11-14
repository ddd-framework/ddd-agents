import { Text } from "../../../provider/components";
import type { ReactNode } from "react";
import { SkeletonCircle, SkeletonLine } from "../skeleton-primitives";
import { WidgetSurface, type WidgetSurfaceLevel } from "./WidgetSurface";
import styles from "./WidgetSurfaceSkeleton.module.scss";

export interface WidgetSurfaceSkeletonProps {
  /** The widget's title — known before the data, so it is real text, never a skeleton. */
  title?: ReactNode;
  rowCount?: number;
  level?: WidgetSurfaceLevel;
  testId?: string;
}

/** The loading state shaped like the surface it stands for: the same surface, the title, then row-shaped blocks. */
export const WidgetSurfaceSkeleton = (props: WidgetSurfaceSkeletonProps) => {
  const { title, rowCount = 3, level = "elevated", testId } = props;
  return (
    <WidgetSurface level={level} testId={testId}>
      {title ? (
        <Text role="intro" weight="bold" translate={false}>
          {title}
        </Text>
      ) : null}
      <div className={styles.rows} aria-busy="true">
        {Array.from({ length: rowCount }, (unused, rowIndex) => (
          <div key={rowIndex} className={styles.row}>
            <SkeletonCircle size={32} />
            <span className={styles.rowText}>
              <SkeletonLine width="60%" height={12} />
              <SkeletonLine width="35%" height={10} />
            </span>
          </div>
        ))}
      </div>
    </WidgetSurface>
  );
};
