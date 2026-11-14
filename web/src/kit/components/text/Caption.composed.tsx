import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readTextNodeContent, readTextNodeRenderState, useReportTextNodeRenderState } from "./shared/text-node-values";
import styles from "./Caption.module.scss";

/**
 * The `Caption` node (`CaptionComposedViewNode.php`): muted secondary text — the sub-line under a title, a
 * unit, a date, a method note (`textCaption` in `--agents-fg-light`). A block of its own, or inline in a compact slot.
 */
const CaptionNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const content = readTextNodeContent(nodeProps.text);
  useReportTextNodeRenderState(runtime, readTextNodeRenderState(isPending, content), "Caption");
  if (isPending) return <SkeletonLine width="30%" height={10} radius={4} />;
  if (content.kind !== "text") return null;
  return (
    <Text
      role="caption"
      translate={false}
      color="--agents-fg-light"
      display={sizeClass === "compact" ? "inline" : "block"}
      className={styles.caption}
      testId={testId}
    >
      {escapePlainTextForText(content.text)}
    </Text>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Caption", component: CaptionNode };
