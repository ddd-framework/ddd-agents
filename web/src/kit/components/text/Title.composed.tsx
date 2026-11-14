import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import {
  readAllowedValue,
  readTextNodeContent,
  readTextNodeRenderState,
  useReportTextNodeRenderState,
} from "./shared/text-node-values";
import styles from "./Title.module.scss";

const TITLE_LEVELS = ["widget", "section"] as const;

/**
 * The `Title` node (`TitleComposedViewNode.php`): the heading of the view (`widget`, the card title —
 * `textIntro` bold) or of one part of it (`section`, `text` bold). The model's words, never
 * translated; a skeleton while a bound text waits for its data.
 */
const TitleNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, runtime, testId } = props;
  const content = readTextNodeContent(nodeProps.text);
  const level = readAllowedValue(nodeProps.level, TITLE_LEVELS, "widget");
  useReportTextNodeRenderState(runtime, readTextNodeRenderState(isPending, content), "Title");
  if (isPending) return <SkeletonLine width="40%" height={level === "widget" ? 20 : 16} radius={4} />;
  if (content.kind !== "text") return null;
  return (
    <Text
      role={level === "widget" ? "intro" : "body"}
      weight="bold"
      translate={false}
      className={styles.title}
      testId={testId}
      headingLevel={level === "widget" ? 3 : 4}
    >
      {escapePlainTextForText(content.text)}
    </Text>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Title", component: TitleNode };
