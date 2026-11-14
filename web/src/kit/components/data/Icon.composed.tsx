import { Icon, isAgentsIconName, type AgentsIconName } from "../../../provider/components";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readDataValueTone, readText } from "./data-value-format";
import { TONE_GRAPHIC_COLOR } from "./data-tones";
import { useReportDataNodeStatus } from "./use-report-data-node-status";
import styles from "./Icon.module.scss";

const ICON_SIZE_PX: Record<string, number> = { small: 16, medium: 20, large: 24 };

/** The icon a wire name stands for — the node's names are package icon names (`IconComposedViewNode::ICON_NAMES`). */
const readIconName = (value: unknown): AgentsIconName | undefined => (isAgentsIconName(value) ? value : undefined);

/**
 * The `Icon` node (the check and cross of a majority column): one component-library icon in
 * its tone's graphic colour. A `label` makes it an image with a name for screen readers and a tooltip; without one it
 * is decoration. A bound name that is not an icon renders nothing and reports `failed`.
 */
const IconNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, runtime, isPending } = props;
  const iconName = readIconName(nodeProps.name);
  const label = readText(nodeProps.label);
  const tone = readDataValueTone(nodeProps.tone);
  const sizePx = ICON_SIZE_PX[typeof nodeProps.size === "string" ? nodeProps.size : "small"] ?? ICON_SIZE_PX.small;
  const hasUnknownName = !isPending && iconName === undefined;
  useReportDataNodeStatus(
    runtime,
    isPending ? "pending" : hasUnknownName ? "failed" : "mounted",
    hasUnknownName ? "INVALID_PROP" : undefined,
    hasUnknownName ? `the Icon name "${String(nodeProps.name)}" is not an icon` : undefined,
  );
  if (iconName === undefined) return null;
  return (
    <span
      className={styles.icon}
      role={label ? "img" : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
      title={label}
    >
      <Icon pixelSize={sizePx} color={TONE_GRAPHIC_COLOR[tone]} name={iconName} />
    </span>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Icon", component: IconNode };
