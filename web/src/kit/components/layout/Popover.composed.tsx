import { Icon, InteractiveSurface, Text, type AgentsIconName } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import { useEffect, useId, useRef, useState } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readEnumProp, readTextProp } from "./layout-props";
import { useLayoutNesting } from "./layout-nesting-context";
import styles from "./Popover.module.scss";

const ICONS = ["info", "help", "none"] as const;
const PLACEMENTS = ["start", "end"] as const;

const ICON_NAMES: Record<Exclude<(typeof ICONS)[number], "none">, AgentsIconName> = {
  info: "info",
  help: "help",
};

/**
 * `Popover` (`PopoverComposedViewNode.php`; ChatGPT's "ⓘ Methodik"): a small text button that opens a
 * floating explanation — `text`, or the nodes of `content`. A click opens it, a second click, Escape or a click
 * elsewhere closes it; the button carries `aria-expanded` and names the panel. The component library's `Popover` is a hover tooltip,
 * which a phone cannot open, so the kit uses a disclosure button instead. Inside a `Pressable` (already one button) and
 * in the e-mail capture it shows its label only. A popover with nothing to explain → `empty`.
 */
const Popover = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, slots, runtime, testId } = props;
  const { isInsidePressable } = useLayoutNesting();
  const [isOpen, setIsOpen] = useState(false);
  const popoverRef = useRef<HTMLSpanElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const panelId = useId();
  const label = readTextProp(nodeProps.label);
  const text = readTextProp(nodeProps.text);
  const contentNodes = slots.content ?? [];
  const icon = readEnumProp(nodeProps.icon, ICONS, "info");
  const placement = readEnumProp(nodeProps.placement, PLACEMENTS, "end");
  const hasExplanation = text !== undefined || contentNodes.length > 0;
  const { reportNodeStatus } = runtime;

  useEffect(() => {
    if (label !== undefined && hasExplanation) reportNodeStatus("mounted");
    else reportNodeStatus("empty", undefined, "the Popover has no label or nothing to explain");
  }, [label, hasExplanation, reportNodeStatus]);

  // While open: a press outside the popover or Escape closes it (Escape returns the focus to the button).
  useEffect(() => {
    if (!isOpen) return undefined;
    const closeOnOutsidePress = (pointerEvent: PointerEvent) => {
      if (popoverRef.current && !popoverRef.current.contains(pointerEvent.target as Node)) setIsOpen(false);
    };
    const closeOnEscape = (keyboardEvent: KeyboardEvent) => {
      if (keyboardEvent.key !== "Escape") return;
      setIsOpen(false);
      triggerRef.current?.focus();
    };
    document.addEventListener("pointerdown", closeOnOutsidePress);
    document.addEventListener("keydown", closeOnEscape);
    return () => {
      document.removeEventListener("pointerdown", closeOnOutsidePress);
      document.removeEventListener("keydown", closeOnEscape);
    };
  }, [isOpen]);

  if (label === undefined || !hasExplanation) return null;
  const labelContent = (
    <>
      {icon !== "none" ? <Icon className={styles.icon} name={ICON_NAMES[icon]} /> : null}
      <Text role="help" color="var(--agents-fg-light)" translate={false}>
        {escapePlainTextForText(label)}
      </Text>
    </>
  );
  if (isInsidePressable || runtime.isEmailRender)
    return (
      <span className={styles.staticLabel} data-testid={testId}>
        {labelContent}
      </span>
    );

  return (
    <span ref={popoverRef} className={styles.popover}>
      <InteractiveSurface hoverable className={styles.triggerWrapper}>
        <button
          ref={triggerRef}
          type="button"
          className={styles.trigger}
          data-testid={testId}
          aria-expanded={isOpen}
          aria-controls={isOpen ? panelId : undefined}
          onClick={() => setIsOpen((wasOpen) => !wasOpen)}
        >
          {labelContent}
        </button>
      </InteractiveSurface>
      {isOpen ? (
        <span
          id={panelId}
          role="note"
          data-testid={`${testId}-panel`}
          className={classNames(styles.panel, placement === "start" ? styles.placementStart : styles.placementEnd)}
        >
          {text !== undefined ? (
            <Text role="help" translate={false}>
              {escapePlainTextForText(text)}
            </Text>
          ) : null}
          {contentNodes}
        </span>
      ) : null}
    </span>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Popover", component: Popover };
