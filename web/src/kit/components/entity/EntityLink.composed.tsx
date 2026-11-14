import { Icon, InteractiveSurface, Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import { useEffect } from "react";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import type {
  ComposedViewComponentDefinition,
  ComposedViewComponentProps,
} from "../../composed-view-component-types";
import {
  readAllowedValue,
  readTextNodeContent,
} from "../text/shared/text-node-values";
import { readEntityLinkTarget, type EntityLinkTargetState } from "./entity-link-target";
import styles from "./EntityLink.module.scss";

const VARIANTS = ["link", "title", "chip"] as const;

/**
 * The `EntityLink` node (`EntityLinkComposedViewNode.php`; ChatGPT's `Entity` — the dotted-underlined
 * business name that opens a detail panel): the name of an entity a tool returned. A
 * click runs the tree's `openEntity` action named by `action`; the widget opens the `entityDetail` canvas for the
 * entity the backend resolved the platform-minted reference to. `link` (default) sits in a sentence or a table cell,
 * `title` is the bold headline of a Surface (a business name), `chip` a small pill with the detail
 * glyph.
 *
 * Without a usable action — no `action`, an action that is not `openEntity`, a reference the backend did not resolve —
 * the name shows as plain text and the node reports `failed UNRESOLVED_REF`, so the model learns the panel does not
 * open. In the e-mail capture the name shows without a control. A link never sends a chat message.
 */
const EntityLinkNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, runtime, testId } = props;
  const { reportNodeStatus } = runtime;
  const content = readTextNodeContent(nodeProps.text);
  const variant = readAllowedValue(nodeProps.variant, VARIANTS, "link");
  const target = readEntityLinkTarget(nodeProps.action, runtime);
  const targetState: EntityLinkTargetState = target.state;
  const contentKind = content.kind;

  useEffect(() => {
    if (isPending) reportNodeStatus("pending");
    else if (contentKind === "missing") reportNodeStatus("empty", "NO_DATA", "the EntityLink has no name to show");
    else if (contentKind === "invalid")
      reportNodeStatus("failed", "INVALID_PROP", "the EntityLink got a name that is not text");
    else if (targetState === "missing") {
      reportNodeStatus(
        "failed",
        "UNRESOLVED_REF",
        "the EntityLink names no openEntity action — its name shows without a link",
      );
    } else if (targetState === "unresolved") {
      reportNodeStatus(
        "failed",
        "UNRESOLVED_REF",
        "the EntityLink's openEntity action has no resolved entity reference — its name shows without a link",
      );
    } else reportNodeStatus("mounted");
  }, [isPending, contentKind, targetState, reportNodeStatus]);

  if (isPending) return <SkeletonLine width="7em" height={12} radius={4} />;
  if (content.kind !== "text") return null;
  const name = escapePlainTextForText(content.text);
  const isClickable = target.state !== "missing" && target.state !== "unresolved" && !runtime.isEmailRender;

  const isTitle = variant === "title";
  const textType = isTitle ? "intro" : "body";
  const fontWeight = isTitle ? "bold" : undefined;
  if (!isClickable) {
    return (
      <Text
        role={textType}
        weight={fontWeight}
        translate={false}
        display="inline"
        className={classNames(styles.plainName, variant === "chip" && styles.plainChip)}
        testId={`${testId}-name`}
      >
        {name}
      </Text>
    );
  }
  const openDetail = () => runtime.runEvent({ action: target.actionName });
  if (variant === "chip") {
    return (
      <InteractiveSurface hoverable className={styles.chip}>
        <button type="button" className={styles.chipButton} onClick={openDetail} data-testid="composed-entity-link">
          <Text
            role="help"
            translate={false}
            display="inline"
            className={styles.chipName}
            testId={`${testId}-name`}
          >
            {name}
          </Text>
          <Icon color="var(--agents-fg-light)" className={styles.chipIcon} pixelSize={14} name="details" />
        </button>
      </InteractiveSurface>
    );
  }
  return (
    <button type="button" className={styles.link} onClick={openDetail} data-testid="composed-entity-link">
      <Text
        role={textType}
        weight={fontWeight}
        translate={false}
        display="inline"
        className={styles.linkName}
        testId={`${testId}-name`}
      >
        {name}
      </Text>
    </button>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "EntityLink",
  component: EntityLinkNode,
};
