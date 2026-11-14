import { Text } from "../../../provider/components";
import { useEffect, useId, useRef, useState } from "react";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { loadDomPurify, loadMermaid } from "./rich-libraries";
import { sanitizeSvgMarkup } from "./sanitize-svg-markup";
import { RichNodeContentSkeleton, RichNodeFrame } from "./RichNodeFrame";
import { readDiagramThemeVariables, useColorSchemeRevision } from "./diagram-theme";
import styles from "./Diagram.module.scss";

type DiagramRendering =
  | { status: "loading" }
  | { status: "empty" }
  | { status: "rendered"; svgMarkup: string; removedPartNames: string[] }
  | { status: "failed"; detail: string };

/** The longest Mermaid parse message the report carries. */
const MAX_ERROR_DETAIL_LENGTH = 300;

const readMermaidErrorMessage = (error: unknown): string => {
  const message = error instanceof Error ? error.message : typeof error === "string" ? error : "unknown error";
  return message.replace(/\s+/g, " ").trim().slice(0, MAX_ERROR_DETAIL_LENGTH);
};

/**
 * The `Diagram` node (`DiagramComposedViewNode.php`): a Mermaid diagram,
 * Mermaid loaded on first use as its own chunk. Mermaid runs at `securityLevel: "strict"` with SVG labels (no HTML in
 * labels, no click handlers), its colours are the theme's tokens resolved at draw time (redrawn when the colour scheme
 * changes), and the drawn SVG is sanitised again before it is shown. Source Mermaid cannot read renders one muted line
 * and is reported `failed` with Mermaid's message.
 */
const DiagramNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, isPending, sizeClass, runtime, testId } = props;
  const { reportNodeStatus } = runtime;
  const source = typeof nodeProps.source === "string" ? nodeProps.source : "";
  const title = typeof nodeProps.title === "string" ? nodeProps.title : undefined;
  const caption = typeof nodeProps.caption === "string" ? nodeProps.caption : undefined;
  const [rendering, setRendering] = useState<DiagramRendering>({ status: "loading" });
  const themeProbeRef = useRef<HTMLDivElement>(null);
  const colorSchemeRevision = useColorSchemeRevision();
  // Mermaid needs a document-unique element id per drawing; React's id carries colons, which a CSS id selector refuses.
  const renderIdBase = `composed-diagram-${useId().replace(/[^a-zA-Z0-9_-]/g, "")}`;

  useEffect(() => {
    if (isPending) {
      setRendering({ status: "loading" });
      return;
    }
    if (source.trim() === "") {
      setRendering({ status: "empty" });
      return;
    }
    let isCurrent = true;
    const themeProbe = themeProbeRef.current;
    setRendering({ status: "loading" });
    const drawDiagram = async (): Promise<DiagramRendering> => {
      const [mermaid, domPurifyFactory] = await Promise.all([loadMermaid(), loadDomPurify()]);
      const themeVariables = themeProbe ? readDiagramThemeVariables(themeProbe) : {};
      mermaid.initialize({
        startOnLoad: false,
        securityLevel: "strict",
        theme: "base",
        themeVariables,
        htmlLabels: false,
        // A short step name stays on one line ("Widget auswählen"); only a long one wraps.
        flowchart: { htmlLabels: false, useMaxWidth: true, wrappingWidth: 260 },
        maxTextSize: 4000,
        suppressErrorRendering: true,
      });
      try {
        await mermaid.parse(source);
      } catch (parseError) {
        return {
          status: "failed",
          detail: `Mermaid could not read the diagram — ${readMermaidErrorMessage(parseError)}`,
        };
      }
      const { svg } = await mermaid.render(`${renderIdBase}-${colorSchemeRevision}`, source);
      const sanitizedSvg = sanitizeSvgMarkup(domPurifyFactory, svg, { keepStyleElement: true, label: title });
      if (sanitizedSvg.markup === null)
        return { status: "failed", detail: "the drawn diagram held nothing safe to show" };
      return { status: "rendered", svgMarkup: sanitizedSvg.markup, removedPartNames: sanitizedSvg.removedPartNames };
    };
    drawDiagram()
      .then((diagramRendering) => {
        if (isCurrent) setRendering(diagramRendering);
      })
      .catch((drawError: unknown) => {
        if (isCurrent)
          setRendering({
            status: "failed",
            detail: `the diagram could not be drawn — ${readMermaidErrorMessage(drawError)}`,
          });
      });
    return () => {
      isCurrent = false;
    };
  }, [source, title, isPending, renderIdBase, colorSchemeRevision]);

  useEffect(() => {
    if (rendering.status === "loading") reportNodeStatus("pending");
    if (rendering.status === "empty") reportNodeStatus("empty", "NO_DATA", "the diagram source is empty");
    if (rendering.status === "failed") reportNodeStatus("failed", "RENDER_FAILED", rendering.detail);
    if (rendering.status === "rendered") {
      if (rendering.removedPartNames.length === 0) reportNodeStatus("mounted");
      else {
        reportNodeStatus(
          "failed",
          "INVALID_PROP",
          `unsafe parts of the drawn diagram were removed (${rendering.removedPartNames.join(", ")}) — the rest is shown`,
        );
      }
    }
  }, [rendering, reportNodeStatus]);

  // No source: the node renders nothing and reports `empty` (one muted line or nothing).
  if (rendering.status === "empty") return null;
  return (
    <RichNodeFrame sizeClass={sizeClass} title={title} caption={caption} testId={testId}>
      <div ref={themeProbeRef} className={styles.diagramArea}>
        {rendering.status === "rendered" ? (
          <div className={styles.diagram} dangerouslySetInnerHTML={{ __html: rendering.svgMarkup }} />
        ) : null}
        {rendering.status === "loading" ? <RichNodeContentSkeleton height={240} testId={`${testId}-skeleton`} /> : null}
        {rendering.status === "failed" ? (
          <Text role="help" color="var(--agents-fg-light)" testId={`${testId}-error`}>
            {"This diagram could not be drawn."}
          </Text>
        ) : null}
      </div>
    </RichNodeFrame>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Diagram", component: DiagramNode };
