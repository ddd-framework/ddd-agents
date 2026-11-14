import { escapePlainTextForText } from "../../../provider/environment";
import { StatusBadge, type AgentsStatusBadgeStatus } from "../../../provider/components";
import { SkeletonPill } from "../../../widgets/_shared/skeleton-primitives";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import {
  ComposedViewTone,
  readComposedViewTone,
} from "../../composed-view-tones";
import { readTextNodeContent, readTextNodeRenderState, useReportTextNodeRenderState } from "./shared/text-node-values";
import styles from "./Badge.module.scss";

/** The status colour of a tone: `muted` has no status of its own and reads as the neutral badge. */
const STATUS_OF_TONE: Readonly<Record<ComposedViewTone, AgentsStatusBadgeStatus>> = {
  neutral: "neutral",
  muted: "neutral",
  success: "success",
  warning: "warning",
  danger: "danger",
  info: "info",
};

/**
 * The `Badge` node (`BadgeComposedViewNode.php`): a short status with a judgement tone, as the design's
 * small status badge (the component library's `StatusBadge variant="small"`, no icon). The status text
 * is the model's, so the badge is told not to translate it and the text is escaped (`model-text.ts`).
 */
const BadgeNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, runtime, testId } = props;
  const content = readTextNodeContent(nodeProps.text);
  const tone = readComposedViewTone(nodeProps.tone);
  useReportTextNodeRenderState(runtime, readTextNodeRenderState(isPending, content), "Badge");
  if (isPending) return <SkeletonPill width={72} height={20} />;
  if (content.kind !== "text") return null;
  return (
    <span className={styles.badge} data-tone={tone} data-testid={testId}>
      <StatusBadge
        status={STATUS_OF_TONE[tone]}
        text={escapePlainTextForText(content.text)}
        translate={false}
        size="small"
        hideIcon
      />
    </span>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Badge", component: BadgeNode };
