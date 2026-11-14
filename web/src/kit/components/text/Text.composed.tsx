import { Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import { useEffect, useRef, useState } from "react";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { readComposedViewTone } from "../../composed-view-tones";
import {
  readAllowedValue,
  readTextNodeContent,
  TextNodeRenderState,
  useReportTextNodeRenderState,
} from "./shared/text-node-values";
import styles from "./Text.module.scss";

const TEXT_WEIGHTS = ["regular", "medium", "bold"] as const;
const FONT_WEIGHT_BY_TEXT_WEIGHT = {
  regular: "regular",
  medium: "medium",
  bold: "bold",
} as const;
const MAX_CLAMPED_LINES = 10;

/** `maxLines` within 1–10, else no clamp. */
const readMaxLines = (value: unknown): number | undefined =>
  typeof value === "number" && Number.isFinite(value) && value >= 1
    ? Math.min(Math.floor(value), MAX_CLAMPED_LINES)
    : undefined;

/**
 * Whether the clamped run cuts its text off — measured on the element, again whenever its width changes (a narrower pane
 * clamps a text that fit before).
 */
const useIsTextCutOff = (isClamped: boolean, clampedText: string | null) => {
  const clampedRunRef = useRef<HTMLSpanElement>(null);
  const [isCutOff, setIsCutOff] = useState(false);
  useEffect(() => {
    const clampedRun = clampedRunRef.current;
    if (!isClamped || clampedText === null || !clampedRun) {
      setIsCutOff(false);
      return undefined;
    }
    const measureCutOff = () => setIsCutOff(clampedRun.scrollHeight > clampedRun.clientHeight + 1);
    measureCutOff();
    if (typeof ResizeObserver === "undefined") return undefined;
    const resizeObserver = new ResizeObserver(measureCutOff);
    resizeObserver.observe(clampedRun);
    return () => resizeObserver.disconnect();
  }, [isClamped, clampedText]);
  return { clampedRunRef, isCutOff };
};

/**
 * The `Text` node (`TextComposedViewNode.php`): plain text — a paragraph, or an inline run when it sits in
 * a compact slot (inside another `Text`, a table cell). Its children are inline nodes that follow the text on the same
 * line (a `Badge`, a `Link`, a `SourceRef` chip). `tone` picks the muted or a judgement colour, `maxLines` clamps a
 * long text, keeps the whole of it on hover and — when the clamp cut something off — offers "Show more" / "Show less"
 * below the paragraph, so a touch screen reaches the whole text too (the e-mail capture shows the clamp alone). The model's words are never translated and never parsed as markup.
 */
const TextNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, slots, sizeClass, runtime, testId } = props;
  const content = readTextNodeContent(nodeProps.text);
  const childNodes = slots.children ?? [];
  const weight = readAllowedValue(nodeProps.weight, TEXT_WEIGHTS, "regular");
  const tone = readComposedViewTone(nodeProps.tone);
  const maxLines = readMaxLines(nodeProps.maxLines);
  const isInline = sizeClass === "compact";
  const [isExpanded, setIsExpanded] = useState(false);
  const isClamped = maxLines !== undefined && !isExpanded;
  const { clampedRunRef, isCutOff } = useIsTextCutOff(
    isClamped,
    content.kind === "text" && !isPending ? content.text : null,
  );
  const isShowingClampToggle =
    !isInline && !runtime.isEmailRender && maxLines !== undefined && (isCutOff || isExpanded);
  let renderState: TextNodeRenderState = "shown";
  if (isPending) renderState = "pending";
  else if (content.kind === "invalid") renderState = "invalid";
  else if (content.kind === "missing" && childNodes.length === 0) renderState = "empty";
  useReportTextNodeRenderState(runtime, renderState, "Text");

  if (isPending) {
    return isInline ? (
      <span className={styles.inlineSkeleton}>
        <SkeletonLine width="6em" height={12} radius={4} />
      </span>
    ) : (
      <SkeletonLine width="80%" height={14} radius={4} />
    );
  }
  if (renderState !== "shown") return null;
  const Wrapper = isInline ? "span" : "div";
  return (
    <Wrapper
      className={classNames(styles.text, isInline ? styles.inline : styles.paragraph, styles[tone])}
      data-testid={testId}
    >
      {content.kind === "text" ? (
        <span
          ref={clampedRunRef}
          className={isClamped ? classNames(styles.clamped, styles[`clampedAt${maxLines}Lines`]) : undefined}
          title={isClamped ? content.text : undefined}
        >
          <Text
            role="body"
            weight={FONT_WEIGHT_BY_TEXT_WEIGHT[weight]}
            translate={false}
            display="inline"
            color="--agents-text-node-tone-color"
          >
            {escapePlainTextForText(content.text)}
          </Text>
        </span>
      ) : null}
      {childNodes.map((childNode, childIndex) => (
        <span key={childIndex} className={styles.inlineChild}>
          {childNode}
        </span>
      ))}
      {isShowingClampToggle ? (
        <button
          type="button"
          className={styles.clampToggle}
          data-testid={`${testId}-toggle`}
          aria-expanded={isExpanded}
          onClick={() => setIsExpanded((wasExpanded) => !wasExpanded)}
        >
          <Text role="caption" weight="medium" color="var(--agents-fg-light)">
            {isExpanded ? "Show less" : "Show more"}
          </Text>
        </button>
      ) : null}
    </Wrapper>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Text", component: TextNode };
