import { CSSProperties, RefObject } from "react";
import { translateText } from "../../provider/environment";
import { useCanvasResize } from "./use-canvas-resize";
import styles from "./CanvasResizeHandle.module.scss";

export interface CanvasResizeHandleProps {
  rootRef: RefObject<HTMLElement | null>;
  inset: number;
  width: number | null;
  onWidthChange: (width: number | null) => void;
  onClose: () => void;
}

/** The pull tab on the canvas's left edge: drag to resize, pull past the narrowest and hold to close. */
export const CanvasResizeHandle = (props: CanvasResizeHandleProps) => {
  const { rootRef, inset, width, onWidthChange, onClose } = props;
  const { tab, tabX, handleProps } = useCanvasResize({ rootRef, inset, width, onWidthChange, onClose });

  return (
    <div
      {...handleProps}
      className={styles.handle}
      data-tab={tab}
      aria-label={translateText("Resize canvas")}
    >
      <span className={styles.tab} style={{ "--agents-resize-tab-x": `${tabX}px` } as CSSProperties} aria-hidden="true">
        <span className={styles.tabCircle} />
        <span className={styles.tabTop} />
        <span className={styles.tabBottom} />
      </span>
    </div>
  );
};
