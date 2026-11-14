import { classNames } from "../../../provider/class-names";
import { useEffect } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readEnumProp, readGapProp } from "./layout-props";
import { layoutGapClassName } from "./layout-gap-class";
import styles from "./Flow.module.scss";

const ALIGNMENTS = ["start", "center", "end"] as const;

const ALIGNMENT_CLASS_NAMES: Record<(typeof ALIGNMENTS)[number], string> = {
  start: styles.alignStart,
  center: styles.alignCenter,
  end: styles.alignEnd,
};

/**
 * `Flow` (`FlowComposedViewNode.php`): badges, labels, chips and small controls side by side,
 * wrapping onto as many lines as they need. Without items it shows nothing and reports `empty`.
 */
const Flow = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, slots, runtime } = props;
  const items = slots.children ?? [];
  const alignment = readEnumProp(nodeProps.align, ALIGNMENTS, "center");
  const hasItems = items.length > 0;
  const { reportNodeStatus } = runtime;

  useEffect(() => {
    if (hasItems) reportNodeStatus("mounted");
    else reportNodeStatus("empty", undefined, "the Flow has no items to show");
  }, [hasItems, reportNodeStatus]);

  if (!hasItems) return null;
  return (
    <div
      className={classNames(
        styles.flow,
        ALIGNMENT_CLASS_NAMES[alignment],
        layoutGapClassName(styles, readGapProp(nodeProps.gap, "small")),
      )}
    >
      {items}
    </div>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Flow", component: Flow };
