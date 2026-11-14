import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { useEffect, useState } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { loadKatex } from "./rich-libraries";
import { RichNodeBadge, RichNodeContentSkeleton, RichNodeFrame } from "./RichNodeFrame";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import styles from "./Math.module.scss";

type MathRendering =
  | { status: "loading" }
  | { status: "empty" }
  | { status: "rendered"; html: string }
  | { status: "failed"; detail: string };

/** KaTeX's own safety and size limits: no `\href` / `\url` / `\includegraphics` / HTML extensions, bounded macros. */
const KATEX_OPTIONS = {
  throwOnError: false,
  trust: false,
  strict: "ignore",
  output: "htmlAndMathml",
  maxSize: 20,
  maxExpand: 500,
} as const;

/** The parse message KaTeX writes into its error element when `throwOnError` is off, or null for a valid formula. */
const readKatexErrorMessage = (renderedHtml: string): string | null => {
  if (!renderedHtml.includes("katex-error")) return null;
  const template = document.createElement("template");
  template.innerHTML = renderedHtml;
  return template.content.querySelector(".katex-error")?.getAttribute("title") ?? "KaTeX could not read the formula";
};

/**
 * The `Math` node (`MathComposedViewNode.php`): a LaTeX formula typeset by
 * KaTeX, loaded on first use. A block formula sits centred under an optional heading with the "LaTeX" badge and an
 * optional caption line under a hairline; an inline formula flows in its line. A formula KaTeX cannot read shows its
 * source and one muted line, and is reported `failed` with KaTeX's parse message.
 */
const MathNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const { reportNodeStatus } = runtime;
  const latex = typeof nodeProps.latex === "string" ? nodeProps.latex : "";
  const isInline = nodeProps.display === "inline";
  const title = typeof nodeProps.title === "string" ? nodeProps.title : undefined;
  const caption = typeof nodeProps.caption === "string" ? nodeProps.caption : undefined;
  const [rendering, setRendering] = useState<MathRendering>({ status: "loading" });

  useEffect(() => {
    if (isPending) {
      setRendering({ status: "loading" });
      return;
    }
    if (latex.trim() === "") {
      setRendering({ status: "empty" });
      return;
    }
    let isCurrent = true;
    setRendering({ status: "loading" });
    loadKatex()
      .then((katex) => {
        if (!isCurrent) return;
        const renderedHtml = katex.renderToString(latex, { ...KATEX_OPTIONS, displayMode: !isInline });
        const errorMessage = readKatexErrorMessage(renderedHtml);
        setRendering(
          errorMessage === null
            ? { status: "rendered", html: renderedHtml }
            : { status: "failed", detail: `the formula is not valid LaTeX — ${errorMessage}` },
        );
      })
      .catch(() => {
        if (isCurrent) setRendering({ status: "failed", detail: "the formula renderer could not be loaded" });
      });
    return () => {
      isCurrent = false;
    };
  }, [latex, isInline, isPending]);

  useEffect(() => {
    if (rendering.status === "loading") reportNodeStatus("pending");
    if (rendering.status === "empty") reportNodeStatus("empty", "NO_DATA", "the formula is empty");
    if (rendering.status === "rendered") reportNodeStatus("mounted");
    if (rendering.status === "failed") reportNodeStatus("failed", "RENDER_FAILED", rendering.detail);
  }, [rendering, reportNodeStatus]);

  // Nothing to typeset: the node renders nothing and reports `empty` (one muted line or nothing).
  if (rendering.status === "empty") return null;
  if (isInline) {
    if (rendering.status === "rendered") {
      return (
        <span
          className={styles.inlineFormula}
          data-testid={testId}
          dangerouslySetInnerHTML={{ __html: rendering.html }}
        />
      );
    }
    if (rendering.status === "loading") {
      return (
        <span className={styles.inlineSkeleton} aria-busy="true" data-testid={`${testId}-skeleton`}>
          <SkeletonLine width={64} height={14} radius={4} />
        </span>
      );
    }
    return (
      <Text role="body" translate={false} className={styles.source} testId={`${testId}-error`}>
        {escapePlainTextForText(latex)}
      </Text>
    );
  }

  const footer = caption ? (
    <Text role="help" color="var(--agents-fg-light)" translate={false} className={styles.caption}>
      {escapePlainTextForText(caption)}
    </Text>
  ) : undefined;
  return (
    <RichNodeFrame
      sizeClass={sizeClass}
      title={title}
      badge={title ? <RichNodeBadge>LaTeX</RichNodeBadge> : undefined}
      footer={footer}
      testId={testId}
    >
      {rendering.status === "rendered" ? (
        <div className={styles.blockFormula} dangerouslySetInnerHTML={{ __html: rendering.html }} />
      ) : null}
      {rendering.status === "loading" ? <RichNodeContentSkeleton height={48} testId={`${testId}-skeleton`} /> : null}
      {rendering.status === "failed" ? (
        <div className={styles.failure} data-testid={`${testId}-error`}>
          <Text role="help" color="var(--agents-fg-light)">
            {"This formula could not be rendered."}
          </Text>
          <Text role="help" translate={false} className={styles.source}>
            {escapePlainTextForText(latex)}
          </Text>
        </div>
      ) : null}
    </RichNodeFrame>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Math", component: MathNode };
