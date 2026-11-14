import { type AgentsIconName } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { ConversationWidgetButton } from "../../../widgets/_shared/ConversationWidgetButton";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { isComposedInputUsable, readComposedText } from "./shared/composed-input-values";
import styles from "./ComposedInputs.module.scss";

/** The closed glyph list of the node's `icon` prop → the component-library icon. */
const BUTTON_ICON_NAMES: Record<string, AgentsIconName> = {
  copy: "duplicate",
  reset: "refresh",
  link: "link",
  add: "add",
  check: "check",
  next: "arrowRight",
  calendar: "calendar",
  info: "info",
};

/** How long a copy button says "Copied" after the click (the confirmation ChatGPT's "Copy result" gives). */
export const COPIED_CONFIRMATION_MS = 2000;

/** The name of the tree action a click runs (`onClick: {"action": "<name>"}`), or undefined for a `set` click. */
const readClickActionName = (onClick: unknown): string | undefined => {
  if (typeof onClick !== "object" || onClick === null || Array.isArray(onClick)) return undefined;
  const actionName = (onClick as Record<string, unknown>).action;
  return typeof actionName === "string" ? actionName : undefined;
};

/**
 * `Button` — runs one of the tree's own actions or writes a value to its state (`ButtonComposedViewNode.php`; ChatGPT's "Copy result" / "Reset" of the simulators): `onClick: {"action": "<name>"}`
 * runs `copy`, `resetState`, `openUrl` or `openEntity`; `onClick: {"set": "<name>"}` writes `value`. It never sends a
 * chat message. `primary` is the one main action (a square button, for real-effect
 * actions), `secondary` sits beside it. It is an affordance only, so a replayed message and the e-mail capture show no
 * button at all. The component library renders a `<button>` without a type — a submit button inside a `Form` would become the Enter
 * target and submit the form; this one is set to `type="button"`. A button that runs a `copy` action confirms the copy
 * for {@link COPIED_CONFIRMATION_MS}: its label turns into "Copied" with a check glyph. A label longer than the chat column
 * wraps onto a second line (copy wraps, never cut) instead of pushing the button out of the widget.
 */
const ComposedButton = (props: ComposedViewComponentProps) => {
  const { node, props: nodeProps, runtime, testId } = props;
  const wrapperRef = useRef<HTMLDivElement>(null);
  const label = readComposedText(nodeProps.label);
  const isUsable = isComposedInputUsable(runtime);
  const [isCopyConfirmed, setIsCopyConfirmed] = useState(false);
  const clickActionName = readClickActionName(node.props.onClick);
  const isCopyButton = clickActionName !== undefined && runtime.findAction?.(clickActionName)?.type === "copy";
  useEffect(() => {
    if (!isCopyConfirmed) return;
    const confirmationTimer = setTimeout(() => setIsCopyConfirmed(false), COPIED_CONFIRMATION_MS);
    return () => clearTimeout(confirmationTimer);
  }, [isCopyConfirmed]);
  useLayoutEffect(() => {
    wrapperRef.current?.querySelectorAll("button").forEach((buttonElement) => {
      buttonElement.type = "button";
    });
  });
  if (!label || !isUsable) return null;
  const nodeIcon = typeof nodeProps.icon === "string" ? BUTTON_ICON_NAMES[nodeProps.icon] : undefined;
  const icon = isCopyConfirmed ? "check" : nodeIcon;
  const isFullWidth = nodeProps.fullWidth === true;
  const buttonType = nodeProps.variant === "secondary" ? "muted" : "primary";
  const click = () => {
    runtime.runEvent(node.props.onClick, nodeProps.value);
    if (isCopyButton) setIsCopyConfirmed(true);
  };
  return (
    <div ref={wrapperRef} className={isFullWidth ? styles.buttonFullWidth : styles.button}>
      <ConversationWidgetButton
        variant={buttonType}
        size="medium"
        icon={icon}
        iconPosition="left"
        wrapsLabel
        translate={isCopyConfirmed}
        fullWidth={isFullWidth}
        onClick={click}
        testId={testId}
      >
        {isCopyConfirmed ? "Copied" : escapePlainTextForText(label)}
      </ConversationWidgetButton>
    </div>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Button", component: ComposedButton };
