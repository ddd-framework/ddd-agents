import { classNames } from "../../../provider/class-names";
import { useEffect } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readBooleanProp, readEnumProp, readGapProp } from "./layout-props";
import { layoutGapClassName } from "./layout-gap-class";
import styles from "./Stack.module.scss";

const DIRECTIONS = ["row", "column"] as const;
const ALIGNMENTS = ["start", "center", "end", "stretch", "baseline"] as const;
const JUSTIFICATIONS = ["start", "center", "end", "between"] as const;

const ALIGNMENT_CLASS_NAMES: Record<(typeof ALIGNMENTS)[number], string> = {
  start: styles.alignStart,
  center: styles.alignCenter,
  end: styles.alignEnd,
  stretch: styles.alignStretch,
  baseline: styles.alignBaseline,
};

const JUSTIFICATION_CLASS_NAMES: Record<(typeof JUSTIFICATIONS)[number], string> = {
  start: styles.justifyStart,
  center: styles.justifyCenter,
  end: styles.justifyEnd,
  between: styles.justifyBetween,
};

/**
 * `Stack` (`StackComposedViewNode.php`): the children in a row or a column with one gap step between
 * them. A row centres its items and wraps by default, so a title with a control at its end (`justify: "between"`)
 * stays readable on a phone. A stack without children shows nothing and reports `empty`.
 */
const Stack = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, slots, runtime } = props;
  const children = slots.children ?? [];
  const direction = readEnumProp(nodeProps.direction, DIRECTIONS, "column");
  const alignment = readEnumProp(nodeProps.align, ALIGNMENTS, direction === "row" ? "center" : "stretch");
  const justification = readEnumProp(nodeProps.justify, JUSTIFICATIONS, "start");
  const isWrapping = direction === "row" && readBooleanProp(nodeProps.wrap, true);
  const hasChildren = children.length > 0;
  const { reportNodeStatus } = runtime;

  useEffect(() => {
    if (hasChildren) reportNodeStatus("mounted");
    else reportNodeStatus("empty", undefined, "the Stack has no children to show");
  }, [hasChildren, reportNodeStatus]);

  if (!hasChildren) return null;
  return (
    <div
      className={classNames(
        styles.stack,
        styles[direction],
        isWrapping && styles.wrap,
        ALIGNMENT_CLASS_NAMES[alignment],
        JUSTIFICATION_CLASS_NAMES[justification],
        layoutGapClassName(styles, readGapProp(nodeProps.gap)),
      )}
    >
      {children}
    </div>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Stack", component: Stack };
