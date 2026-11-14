import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readTextNodeContent, readTextNodeRenderState, useReportTextNodeRenderState } from "./shared/text-node-values";
import styles from "./Label.module.scss";

/**
 * The `Label` node (`LabelComposedViewNode.php`): the short name above a value, an input or a group — the
 * design's section label (muted, medium, uppercase, letter-spaced; the look of `widgets/_shared/WidgetLabel`, whose copy
 * is a translation key, while a label here is the model's words and stays untranslated).
 */
const LabelNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, runtime, testId } = props;
  const content = readTextNodeContent(nodeProps.text);
  useReportTextNodeRenderState(runtime, readTextNodeRenderState(isPending, content), "Label");
  if (isPending) return <SkeletonLine width={120} height={10} radius={4} />;
  if (content.kind !== "text") return null;
  return (
    <Text
      role="help"
      weight="medium"
      translate={false}
      color="--agents-fg-light"
      className={styles.label}
      testId={testId}
    >
      {escapePlainTextForText(content.text)}
    </Text>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Label", component: LabelNode };
