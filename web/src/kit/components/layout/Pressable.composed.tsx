import { InteractiveSurface } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { useEffect, useMemo } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readTextProp } from "./layout-props";
import { LayoutNestingContext, useLayoutNesting } from "./layout-nesting-context";
import styles from "./Pressable.module.scss";

/**
 * `Pressable` (`PressableComposedViewNode.php`): its content as one clickable area. A click runs the
 * node's `onClick` — `{"set": <state>}` writes `value`, `{"action": <name>}` runs the tree's action — through the
 * renderer, so a frozen tree (the e-mail) writes nothing. `isSelected` draws the one selection ring of the design
 * (inset ring + 8 % tint) and sets `aria-pressed`. In the e-mail capture, and inside another pressable, the content
 * shows without a control. Nothing inside → `empty`.
 */
const Pressable = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, slots, runtime, testId } = props;
  const nesting = useLayoutNesting();
  const contentNodes = slots.children ?? [];
  const isSelected = typeof nodeProps.isSelected === "boolean" ? nodeProps.isSelected : undefined;
  const accessibleLabel = readTextProp(nodeProps.label);
  const hasContent = contentNodes.length > 0;
  const { reportNodeStatus } = runtime;
  const innerNesting = useMemo(() => ({ ...nesting, isInsidePressable: true }), [nesting]);

  useEffect(() => {
    if (hasContent) reportNodeStatus("mounted");
    else reportNodeStatus("empty", undefined, "the Pressable has no content to click");
  }, [hasContent, reportNodeStatus]);

  if (!hasContent) return null;
  if (runtime.isEmailRender || nesting.isInsidePressable) {
    return (
      <div className={classNames(styles.pressable, styles.content, isSelected && styles.selected)} data-testid={testId}>
        {contentNodes}
      </div>
    );
  }
  return (
    <InteractiveSurface hoverable className={classNames(styles.pressable, isSelected && styles.selected)}>
      <button
        type="button"
        className={styles.button}
        data-testid={testId}
        aria-pressed={isSelected}
        aria-label={accessibleLabel}
        onClick={() => runtime.runEvent(node.props.onClick, nodeProps.value)}
      >
        <LayoutNestingContext.Provider value={innerNesting}>{contentNodes}</LayoutNestingContext.Provider>
      </button>
    </InteractiveSurface>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Pressable", component: Pressable };
