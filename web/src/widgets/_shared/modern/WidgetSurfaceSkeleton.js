import { Text } from "../../../provider/components";
import { SkeletonCircle, SkeletonLine } from "../skeleton-primitives";
import { WidgetSurface } from "./WidgetSurface";
import styles from "./WidgetSurfaceSkeleton.module.scss";
/** The loading state shaped like the surface it stands for: the same surface, the title, then row-shaped blocks. */
export const WidgetSurfaceSkeleton = (props) => {
    const { title, rowCount = 3, level = "elevated", testId } = props;
    return (<WidgetSurface level={level} testId={testId}>
      {/* a plain text title gets the title paragraph; an element (a widget's own headline) already renders its text
            block — wrapping it would nest a paragraph in a paragraph (invalid HTML, a hydration error) */}
      {typeof title === "string" || typeof title === "number" ? (<Text role="intro" weight="bold" translate={false}>
          {title}
        </Text>) : ((title ?? null))}
      <div className={styles.rows} aria-busy="true">
        {Array.from({ length: rowCount }, (unused, rowIndex) => (<div key={rowIndex} className={styles.row}>
            <SkeletonCircle size={32}/>
            <span className={styles.rowText}>
              <SkeletonLine width="60%" height={12}/>
              <SkeletonLine width="35%" height={10}/>
            </span>
          </div>))}
      </div>
    </WidgetSurface>);
};
